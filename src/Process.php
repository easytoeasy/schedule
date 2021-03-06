<?php

namespace pzr\schedule;

use Cron\CronExpression;
use ErrorException;
use Exception;
use pzr\schedule\db\Db;
use pzr\schedule\db\Job;
use Throwable;

class Process
{
    /** 待回收子进程 */
    protected $childPids = array();
    /** 待执行的任务数组 */
    protected $taskers = array();
    /** @var string 操作记录 */
    protected $message = 'Init Process OK';
    /** @var string 保存父进程的pid */
    /** 所有子进程的标签集合 */
    protected $servTags = array();
    /*
     * 主进程每分钟执行，超过的话则记录。
     * 但是鉴于可能存在几秒的误差是正常的，所以宽限5s
     * 之所以默认值为-1，因为一开始进去就会+1
     */
    protected $outofMin = -1;
    protected $extSeconds = 5;
    /** 由于DB记录改变，内存中即将被删除的命令 */
    protected $beDelIds = array();
    /** 记录主进程启动的时间 */
    protected $createAt;
    /** 解决没有配置output输出文件却又有输出的子进程 */
    protected $output;
    /** 保存父进程退出之前的子进程pid */
    protected $saveChildpids = __DIR__ . '/childsPids.txt';
    protected $preChildpids = array();

    protected $stdout_fd;
    protected $stderr_fd;
    protected $nullfd;

    public function __construct()
    {
        fclose(STDOUT);
        fclose(STDERR);
        $this->stdout_fd = fopen(LOGFILE, 'a');
        $this->stderr_fd = fopen(LOGFILE, 'a');
        $this->nullfd = LOGFILE == '/dev/null' ? $this->stdout_fd : fopen('/dev/null', 'w');
    }

    public function run()
    {
        // 父进程还在，不让重启
        if ($this->isMasterAlive()) {
            exit('master still alive');
        }

        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new ErrorException('fork error');
        } elseif ($pid > 0) {
            exit(0);
        }
        if (!posix_setsid()) {
            exit(3);
        }

        /* 引用：理论上一次fork就可以了
         * 但是，二次fork，这里的历史渊源是这样的：在基于system V的系统中，
         * 通过再次fork，父进程退出，子进程继续，保证形成的daemon进程绝对不
         * 会成为会话首进程，不会拥有控制终端。
         * 现象：一旦成为守护进程之后，就会与控制终端失去联系。在关闭控制终端之前，
         * 子进程的输出都会写到终端。但是如果关闭后，则子进程无法输出到终端。那么
         * 观察到的影响就是子进程退出。所以需要在启动子进程时指定输出*/
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new ErrorException('fork error');
            exit;
        } elseif ($pid > 0) {
            exit;
        }

        @cli_set_process_title('Schedule server_' . $this->serverId);

        // 将主进程ID写入文件
        file_put_contents(PIDFILE, getmypid());

        // pcntl_async_signals(TRUE);

        /**
         * stream设置成每秒钟block，则waitpid每秒都会被执行。则不再需要信号处理
         * @see https://www.php.net/manual/zh/function.pcntl-signal.php 用户的评论
         * 1）仅仅只靠事件通知的siginfo['pid']去回收子进程，还是会出现僵尸进程。
         * 2）最好的做法还是结合所有的子进程通过childPid管理，然后循环监控子进程。
         */
        // pcntl_signal(SIGCHLD, [$this, 'sigHandler']);
        /* 父进程退出之前，将正在执行的子进程pid写入文件。
         * 在父进程重新启动时检测已启动的子进程，从而防止子进程重复启动 */
        pcntl_signal(SIGINT,  [$this, 'sigHandler']);
        pcntl_signal(SIGQUIT, [$this, 'sigHandler']);
        pcntl_signal(SIGTERM, [$this, 'sigHandler']);

        $stream = new Stream();

        $wait = 60;
        $next = 0;
        $diff = 1;
        while (true) {
            $stamp = time();
            do {
                if ($stamp >= $next) {
                    break;
                }
                // 设置成1s，则每秒都会回收可能产生的僵尸进程
                // Logger::debug('before stream:' . memory_get_usage());
                /**
                 * $diff = $next - $stamp;
                 * 将原 sleep($diff) 改成stream的阻塞
                 * 子进程发出信号时，select阻塞状态会被打断
                 */
                $stream->accept($diff, function () {
                    $this->handle();
                    return $this->display();
                });

                // Logger::debug('after stream:' . memory_get_usage());

                // 将进程回收放在这里，则至少可以每秒钟都触发进程的回收.
                $this->waitpid();
                // Logger::debug('after waitpid:' . memory_get_usage());
                $stamp = time();
            } while ($stamp < $next);
            $next = $stamp + $wait;
            $this->syncFromDB();
            $this->initTasker();
            gc_collect_cycles();
            gc_mem_caches();
            // Logger::debug('memory usage:' . memory_get_usage());
            // 1min内执行不完则记录
            if (time() - $stamp > $wait) {
                $this->outofMin++;
            }
        }
    }

    protected function syncFromDB()
    {
        $db = new Db();
        $taskers = $db->getJobs();
        // fixed：当子进程任务为空时，也需要处理。
        // if (empty($taskers)) return;
        $this->servTags = $db->getServTags();

        if ($this->preChildpids)
            foreach ($this->preChildpids as $pid => $md5) {
                if (!$this->isAlive($pid)) {
                    Logger::info(sprintf("child pid:%s is stoped.", $pid));
                    ($this->taskers[$md5])->refcount--;
                    unset($this->preChildpids[$pid]);
                    if (empty($this->preChildpids)) {
                        unlink($this->saveChildpids);
                    }
                }
            }

        if (empty($this->taskers)) {
            // 保护父进程退出后的子进程不会发生重复执行
            if (
                is_file($this->saveChildpids) &&
                ($saveChildPids = file_get_contents($this->saveChildpids))
            ) {
                $saveChildPids = json_decode($saveChildPids, true);
                foreach ($saveChildPids as $pid => $md5) {
                    if (isset($taskers[$md5]) && $this->isAlive($pid)) {
                        Logger::info(sprintf("child pid:%s still running...", $pid));
                        ($taskers[$md5])->refcount++;
                        ($taskers[$md5])->pid = $pid;
                        ($taskers[$md5])->state = State::WAITING;
                        // 不是当前父进程的子进程，保证还能够refcount--
                        $this->preChildpids[$pid] = $md5;
                    }
                }
            }
            $this->taskers = $taskers;
        }



        // DB里面变更的命令
        $newAdds = array_diff_key($taskers, $this->taskers);

        // 内存中等待被删除的命令
        $beDels = array_diff_key($this->taskers, $taskers);

        /**
         * 正在运行的任务不能清除
         * 主进程每分钟跑一次，此时待删除的子进程可能需要运行数分钟。但是每分钟都会
         * 执行到这里。直到待删除的子进程全部结束。
         */
        foreach ($beDels as $key => $value) {
            $c = &$this->taskers[$key];
            if ($c->refcount > 0) {
                $this->beDelIds[$c->id] = $c->id;
                $c->state = State::DELETING;
                continue;
            }
            unset($this->beDelIds[$c->id]);
            unset($this->taskers[$key]);
        }

        /** 
         * 子任务的某些字段值被更改后，会重新生成md5值。主进程会重新加载这个命令到内存并且执行。
         * 为了防止同一个id的命令被多次执行，在即将执行命令之前先按id排重。
         */
        foreach ($newAdds as $key => $value) {
            $value->state = State::WAITING;
            $this->taskers[$key] = $value;
        }

        unset($taskers, $newAdds, $beDels, $db);
        // Logger::debug('after DB:' . memory_get_usage());
    }

    /**
     * 操作子进程。所有的子进程操作都在这里执行
     * @return void
     */
    protected function initTasker()
    {
        /** @var Job $c */
        foreach ($this->taskers as $c) {
            // 单任务是否可多进程同时跑？
            if (!$this->isAllowedRun($c)) {
                continue;
            }

            if ($c->state == State::STARTING) {
                $this->fork($c);
                continue;
            }
            if ($c->pid && $c->state == State::STOPPING) {
                posix_kill($c->pid, SIGTERM);
                continue;
            }

            try {
                $cronExpression = CronExpression::factory($c->cron);
                if ($cronExpression->isDue()) {
                    $this->fork($c);
                }
            } catch (Exception $e) {
                Logger::error(sprintf("id:%d CronExpression: %s", $c->id, $e->getMessage()));
                continue;
            }
        }
    }

    /**
     * 允许最大并行子进程
     *
     * @param Job $c
     * @return bool
     */
    protected function isAllowedRun(Job $c)
    {
        // 该id对应的原子进程等待被删除，此时不能启动此id下的命令
        if (in_array($c->id, $this->beDelIds)) {
            return false;
        }
        // 说明设置的定时任务内没跑完
        try {
            if (
                $c->state == State::RUNNING
                && CronExpression::factory($c->cron)->isDue()
            ) {
                $c->outofCron++;
            }
        } catch (Exception $e) {
            Logger::error(sprintf("id:%d CronExpression: %s", $c->id, $e->getMessage()));
            return false;
        }

        if ($c->refcount >= $c->max_concurrence) {
            Logger::debug(sprintf(
                "id %s is %s, max:refcount(%s:%s)",
                $c->id,
                State::$desc[$c->state],
                $c->max_concurrence,
                $c->refcount
            ));
            return false;
        }

        return true;
    }

    protected function handle()
    {
        $md5 = $_GET['md5'] ?? '';
        $action = $_GET['action'] ?? '';
        if ($md5 && isset($this->taskers[$md5])) {
            $c = &$this->taskers[$md5];
        }

        if (!empty($action)) {
            $info = $c ? $c->id : '';
            $this->message = sprintf("%s %s at %s", $action, $info, date('Y-m-d H:i:s'));
            // Logger::debug(sprintf("md5:%s, action:%s", $info, $action));
        }

        switch ($action) {
            case 'start':
                if (!in_array($c->state, State::runingState())) {
                    $c->state = State::STARTING;
                }
                break;
            case 'stop':
                if ($c->state == State::RUNNING) {
                    $rs = $c->pid && posix_kill($c->pid, SIGTERM);
                    $rs && $c->state  = State::STOPPED;
                    $this->message .= ' result:' . intval($rs);
                } else if (in_array($c->state, State::runingState())) {
                    $c->state = State::STOPPED;
                }
                break;
            case 'flush':
                $rs = Helper::delTree(__DIR__ . '/db/cache');
                $this->message .= ' result:' . intval($rs);
                break;
            case 'clear':
                $this->clearLog($this->logfile);
                break;
            case 'clear_1':
                $this->clearLog($c->output);
                break;
            case 'clear_2':
                $this->clearLog($c->stderr);
                break;
            default:
                break;
        }
    }

    protected function clearLog($logfile)
    {
        if (empty($logfile) || !is_file($logfile)) {
            return;
        }
        $rs = file_put_contents($logfile, '');
        $this->message .= ' result:' . $rs;
    }

    /**
     * 主动回收子进程，防止信号通知的子进程回收失败
     */
    protected function waitpid()
    {
        /**
         * 当启用sigHandler时，`pcntl_signal_dispatch`这一行是否还有必要？
         * 有必要，因为之前设置的pcntl_async_signals似乎只是针对handler的处理，但是
         * 偶尔会出现信号通知丢失的问题，导致子进程退出后无法立即回收。这时候就需要主动回收.
         * 
         * 后来去掉了sigHandler的回收方式，取而代之的是每秒钟都主动回收下已死的子进程。
         */
        pcntl_signal_dispatch();
        foreach ($this->childPids as $pid => $md5) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result != $pid && $result != -1) {
                unset($result);
                continue;
            }
            unset($this->childPids[$pid]);
            if (!isset($this->taskers[$md5])) continue;
            // $normal = pcntl_wexitstatus($status);
            $code = pcntl_wexitstatus($status);
            $c = &$this->taskers[$md5];
            /**
             * @see https://www.jb51.net/article/73377.htm
             * 0 正常退出
             * 1 一般性未知错误
             * 2 不适合的shell命令
             * 126 调用的命令无法执行
             * 127 命令没找到
             * 128 非法参数导致退出
             * 128+n Fatal error signal ”n”：如`kill -9` 返回137
             * 130 脚本被`Ctrl C`终止
             * 255 脚本发生了异常退出了。那么为什么是255呢？因为状态码的范围是0-255，超过了范围。
             */
            switch ($code) {
                case 1:
                    $state = State::EXITED;
                    break;
                case 126:
                case 127:
                    $state = State::FATAL;
                    break;
                case 255:
                    $state = State::UNKNOWN;
                    break;
                default:
                    break;
            }
            if ($code != 0) { //正常退出是0
                Logger::error(sprintf("the id %s,pid %s exited unexcepted about code %s", $c->id, $pid, $code));
                $c->state = $state;
            } else {
                $c->state = $c->state == State::DELETING ?: State::STOPPED;
            }
            $c->refcount--;
            $c->pid = '';
            $c->endtime = date('m/d H:i');
            unset($state, $code, $key);
            unset($result, $pid, $md5, $status);
        }
    }

    /**
     * 防止产生多个父进程
     */
    protected function isMasterAlive()
    {
        if (!is_file($this->ppidFile)) return false;
        $pid = file_get_contents($this->ppidFile);
        return $this->isAlive($pid);
    }

    /**
     * fork一个新的子进程
     *
     * @param string $queueName
     * @param integer $qos
     * @return Job
     */
    protected function fork(Job &$c)
    {
        $desc[1] = $c->output ? ['file', $c->output, 'a'] : $this->nullfd;
        $desc[2] = $c->stderr ? ['file', $c->stderr, 'a'] : $this->nullfd;
        $process = proc_open('exec ' . $c->command, $desc, $pipes, $c->directory);
        if ($process) {
            $ret = proc_get_status($process);
            if ($ret['running']) {
                $c->refcount++;
                $c->state = State::RUNNING;
                $c->pid = $ret['pid'];
                $c->uptime = date('m/d H:i');
                $this->childPids[$c->pid] = $c->md5;
                Logger::info(sprintf("id %d pid %d start at %s", $c->id, $c->pid, $c->uptime));
            } else {
                $c->state = State::BACKOFF;
                Logger::error(sprintf("id %d start failed with state %s", $c->id, $c->state));
            }
            unset($ret);
        } else {
            $c->state = State::FATAL;
            Logger::error(sprintf("id %d start failed with state %s", $c->id, $c->state));
        }
        unset($process, $pipes, $desc);
    }

    protected function display()
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $scriptName = in_array($scriptName, ['', '/']) ? '/index.php' : $scriptName;
        if (trim($scriptName) == '/index.html') {
            $location = sprintf("%s://%s:%s?1", 'http', HOST, PORT);
            if (isset($_GET['id'])) $location .= '&id=' . $_GET['id'];
            if (isset($_GET['tagid'])) $location .= '&tagid=' . $_GET['tagid'];
            return Http::status_301($location);
        }

        if ($scriptName == '/stderr.html') {
            $location = sprintf(
                "%s://%s:%s/stderr.php?md5=%s&type=%d",
                'http',
                HOST,
                PORT,
                $_GET['md5'],
                $_GET['type']
            );
            return Http::status_301($location);
        }

        $sourcePath = Http::$basePath . $scriptName;
        if (!is_file($sourcePath)) {
            return Http::status_404();
        }

        // Logger::debug('before source:' . memory_get_usage());
        try {
            ob_start();
            require $sourcePath;
            $response = ob_get_contents();
            ob_end_clean();
        } catch (Throwable $e) {
            $response = $e->__toString();
        }

        // Logger::debug('after source:' . memory_get_usage());
        unset($sourcePath);
        return Http::status_200($response);
    }

    /* 只监听父进程退出状态，并将childPids保存到文件。
     * 下次启动时防止重复启动 */
    public function sigHandler($signo, $siginfo)
    {
        if ($this->childPids)
            file_put_contents($this->saveChildpids, json_encode($this->childPids));
        exit(0);
    }

    protected function isAlive($pid)
    {
        if (empty($pid)) return false;
        return `ps aux | awk '{print $2}' | grep -w $pid`;
    }

    protected function doAction($action, Job $job = null)
    {
        $str = 'index.html?action=' . $action;
        if (isset($_GET['id'])) {
            $str .= '&id=' . $_GET['id'];
        }
        if (isset($_GET['tagid'])) {
            $str .= '&tagid=' . $_GET['tagid'];
        }
        if ($job) $str .= '&md5=' . $job->md5;
        return $str;
    }

    public function __destruct()
    {
        $this->stdout_fd && fclose($this->stdout_fd);
        $this->stderr_fd && fclose($this->stderr_fd);
        $this->nullfd && fclose($this->nullfd);
    }
}

if (PHP_SAPI != 'cli') {
    throw new ErrorException('非cli模式不可用');
}

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('Asia/Shanghai');
umask(0);
// chdir()
// chown()
// chgrp()

$serverId = $argv[1];
if (empty($serverId) || !is_numeric($serverId)) {
    exit('invalid value of serverId');
}
define('SERVER_ID', $serverId);
unset($serverId);

$parser = new Parser();
unset($parser);

$cli = new Process();

if (ini_set('memory_limit', MEMORY_LIMIT) === false) {
    Logger::error('ini set memory limit faild');
    exit(3);
}

set_exception_handler(function ($e) {
    Logger::error($e->getMessage());
});

// 捕获不到 fatal error
set_error_handler(function (int $errno, string $errstr, $errfile, $errline) {
    Logger::error(sprintf("errno %s %s in %s on line %s", $errno, $errstr, $errfile, $errline));
});

// 捕获fatal error
register_shutdown_function(function() {
    Logger::error(error_get_last());
});

$cli->run();
