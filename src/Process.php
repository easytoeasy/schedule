<?php

namespace pzr\schedule;

use Cron\CronExpression;
use ErrorException;
use Exception;
use Monolog\Logger;
use pzr\schedule\db\Db;
use pzr\schedule\db\Job;




class Process
{
    /** 待回收子进程 */
    protected $childPids = array();
    /** 待执行的任务数组 */
    protected $taskers = array();
    /** @var Logger */
    public $logger;
    /** @var string 操作记录 */
    protected $message = 'Init Process OK';
    /** @var string 保存父进程的pid */
    protected $ppidFile;

    /** 服务ID */
    protected $serverId;
    /** @var Db 数据表 */
    protected $db;
    protected $servTags = array();
    /** @var Server $server */
    protected $server;
    /*
     * 主进程每分钟执行，超过的话则记录。
     * 但是鉴于可能存在几秒中的误差是正常的，所以宽限5s
     * 之所以默认值为-1，因为一开始进去就会+1
     */
    protected $outofMin = -1;
    protected $extSeconds = 5;

    public function __construct($serverId)
    {
        if (empty($serverId)) {
            throw new ErrorException('invalid value of serverId');
        }
        $this->server = IniParser::getServer($serverId);
        $this->serverId = $serverId;
        $this->ppidFile = IniParser::getPpidFile($serverId);
        $this->logger = Helper::getLogger('process');
        $this->db = new Db('db1', $this->server->server);
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
            exit;
        } elseif ($pid > 0) {
            exit;
        }
        if (!posix_setsid()) {
            exit;
        }

        @cli_set_process_title('Schedule: server-' . $this->serverId);

        /**
         * 1）无法捕获到require中的错误
         * 2）无法捕获到DBAL中的异常，但是通过try/catch 主进程的入口函数可以捕获到
         */
        /* set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE) & $errno)) {
                return false;
            }
            $this->logger->error(sprintf(
                "%s, %s, %s, %s",
                $errno,
                $errstr,
                $errfile,
                $errline
            ));
        }); */

        $stream = new Stream($this->server->host, $this->server->port);
        // 将主进程ID写入文件
        file_put_contents($this->ppidFile, getmypid());


        // pcntl_async_signals(TRUE);

        /**
         * stream设置成每秒钟block，则waitpid每秒都会被执行。则不再需要信号处理
         * @see https://www.php.net/manual/zh/function.pcntl-signal.php 用户的评论
         * 1）仅仅只靠事件通知的siginfo['pid']去回收子进程，还是会出现僵尸进程。
         * 2）最好的做法还是结合所有的子进程通过childPid管理，然后循环监控子进程。
         */
        // pcntl_signal(SIGCHLD, [$this, 'sigHandler']);

        $wait = 60;
        $next = 0;
        while (true) {
            $stamp = time();
            do {
                if ($stamp >= $next) {
                    break;
                }
                // 设置成1s，则每秒都会回收可能产生的僵尸进程
                $diff = 1;
                /**
                 * $diff = $next - $stamp;
                 * 将原 sleep($diff) 改成stream的阻塞
                 * 子进程发出信号时，select阻塞状态会被打断
                 */
                $stream->accept($diff, function ($md5, $action) {
                    $this->handle($md5, $action);
                    return $this->display();
                });

                /**
                 * 将进程回收放在这里，则可以每秒钟都触发进程的回收
                 */
                $this->waitpid();
                $stamp = time();
            } while ($stamp < $next);

            // 保障1min内能够执行完，但是如果1min内执行不完呢？

            $this->syncFromDB();
            $this->initTasker();

            /**
             * 已经有信号处理器了，为什么还要这个呢？
             * 防止某个进程没有被信号处理函数处理成为僵尸进程，这里作为一个保障
             */
            // $this->waitpid();
            // 超过限定的每分钟跑一次了
            if ($stamp - $next > $wait + $this->extSeconds) {
                $this->outofMin++;
            }
            $next = $stamp + $wait;
        }
    }

    protected function syncFromDB()
    {
        $taskers = $this->db->getJobs($this->serverId);
        $this->servTags = $this->db->getServTags();
        $this->logger->debug(sprintf("sync %d records from db", count($taskers)));

        if (empty($this->taskers)) {
            $this->taskers = $taskers;
            return;
        }

        // DB里面变更的命令
        $newAdds = array_diff_key($taskers, $this->taskers);

        // 内存中等待被删除的命令
        $beDels = array_diff_key($this->taskers, $taskers);

        if (empty($newAdds) && empty($beDels)) return;

        foreach ($beDels as $key => $value) {
            $this->logger->debug('taskers del row:' . $value->id);
            unset($this->taskers[$key]);
        }

        foreach ($newAdds as $key => $value) {
            $this->logger->debug('taskers add row:' . $value->id);
            $this->taskers[$key] = $value;
        }

        unset($taskers, $newAdds, $beDels);
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
            if (!$this->isAllowMulti($c)) {
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

            $cronExpression = CronExpression::factory($c->cron);
            if ($cronExpression->isDue()) {
                $this->logger->debug(
                    sprintf(
                        "id:%s, refcount:%s",
                        $c->id,
                        $c->refcount
                    )
                );
                $this->fork($c);
            }
        }
    }

    /**
     * 允许最大并行子进程
     *
     * @param Job $c
     * @return bool
     */
    protected function isAllowMulti(Job $c)
    {
        // 说明设置的定时任务内没跑完
        if (
            $c->state == State::RUNNING
            && CronExpression::factory($c->cron)->isDue()
        ) {
            $c->outofCron++;
        }
        if ($c->refcount >= $c->max_concurrence) {
            $this->logger->debug(sprintf(
                "pid %s is %s, max allowed is %s, refcount is %s",
                $c->pid,
                State::$desc[$c->state],
                $c->max_concurrence,
                $c->refcount
            ));
            return false;
        }

        return true;
    }

    protected function handle($md5, $action)
    {
        if ($md5 && isset($this->taskers[$md5])) {
            $c = &$this->taskers[$md5];
        }

        if (!empty($action)) {
            $info = $c ? $c->id : '';
            $this->message = sprintf("%s %s at %s", $action, $info, date('Y-m-d H:i:s'));
            $this->logger->debug(sprintf("md5:%s, action:%s", $info, $action));
        }

        switch ($action) {
            case 'start':
                if (!in_array($c->state, State::runingState())) {
                    $c->state = State::STARTING;
                }
                break;
            case 'stop':
                if ($c->state == State::RUNNING && $c->pid) {
                    $rs = posix_kill($c->pid, SIGTERM);
                    $this->message .= ' result:' . intval($rs);
                }
                break;
            case 'flush':
                $rs = Helper::delTree(__DIR__ . '/db/cache');
                $this->message .= ' result:' . intval($rs);
                break;
            case 'clear':
                if (empty($c)) {
                    [$logfile] = IniParser::getCommLog();
                } else {
                    $logfile = $c->output;
                }
                if ($logfile) {
                    file_put_contents($logfile, '');
                }
                break;
            default:
                break;
        }
    }

    /**
     * 主动回收子进程，防止信号通知的子进程回收失败
     */
    protected function waitpid()
    {
        /**
         * 这一行是否还有必要？
         * 有必要，因为之前设置的pcntl_async_signals似乎只是针对handler的处理，但是
         * 偶尔会出现信号通知丢失的问题，导致子进程退出后无法立即回收。这时候就需要主动回收
         */
        pcntl_signal_dispatch();
        foreach ($this->childPids as $pid => $md5) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result == $pid || $result == -1) {
                unset($this->childPids[$pid]);
                if (!isset($this->taskers[$md5])) continue;
                $normal = pcntl_wexitstatus($status);
                $code = pcntl_wexitstatus($status);
                $c = &$this->taskers[$md5];
                if ($code != 0) { //正常退出是0
                    $c->state = State::BACKOFF;
                } else {
                    $c->state = State::STOPPED;
                }
                $c->refcount--;
                $c->pid = '';
            }
        }
    }

    /**
     * 防止产生多个父进程
     */
    protected function isMasterAlive()
    {
        if (!is_file($this->ppidFile)) return false;
        $ppid = file_get_contents($this->ppidFile);
        $isAlive = Helper::isProcessAlive($ppid);
        if (!$isAlive) return false;
        return true;
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
        $descriptorspec = [];
        if ($c->output) { //stdout
            $descriptorspec[1] = ['file', $c->output, 'a'];
            $descriptorspec[2] = ['file', $c->output, 'a'];
        }
        // 等待数据库提案通过
        // if ($c->stderr) { //stderr
        //     $descriptorspec[2] = ['file', $c->stderr, 'a'];
        // }
        /**
         * 1）由于主进程setsid失去了控制台的联系，当如执行的命令脚本不存在是报：
         * Could not open input file，会直接输出到启动脚本的控制台。所以
         * 为了避免无法捕获到这种错误，应该每条记录设置output。
         * 2）还可以执行主进程时，如：`sudo Process.php >> output.log`
         * 3) 虽然日志等信息可以捕获了，但是记得要清理日志
         * 4) 对于因为异常而停止运行的脚本，状态应该区分
         */
        $process = proc_open('exec ' . $c->command, $descriptorspec, $pipes, $c->directory);
        if ($process) {
            $ret = proc_get_status($process);
            if ($ret['running']) {
                $c->refcount++;
                $c->state = State::RUNNING;
                $c->pid = $ret['pid'];
                $c->uptime = date('m-d H:i');
                $this->childPids[$c->pid] = $c->md5;
            } else {
                $c->state = State::BACKOFF;
                proc_close($process);
            }
        } else {
            $c->state = State::FATAL;
        }
        return $c;
    }

    protected function display()
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $scriptName = in_array($scriptName, ['', '/']) ? '/index.php' : $scriptName;

        if ($scriptName == '/index.html') {
            $location = sprintf("%s://%s:%s", 'http', $this->server->host, $this->server->port);
            return Http::status_301($location);
        }

        $sourcePath = Http::$basePath . $scriptName;
        if (!is_file($sourcePath)) {
            return Http::status_404();
        }

        try {
            ob_start();
            require $sourcePath;
            $response = ob_get_contents();
            ob_clean();
        } catch (Exception $e) {
            $this->logger->error($e);
            $response = $e->getMessage();
        }

        return Http::status_200($response);
    }

    /**
     * 
     * 1）stream_select会被打断
     * 2）tail.php中的`tail`命令产生的子进程会被这里捕获到
     * 
     * @param int $signo
     * @param array $siginfo
     * 
     * siginfo: {"signo":20,"errno":0,"code":1,"status":0,"pid":16769,"uid":501}
     */
    public function sigHandler($signo, $siginfo)
    {
        if ($signo != SIGCHLD) return;
        $pid = $siginfo['pid'];
        $result = pcntl_waitpid($pid, $status, WNOHANG);
        if ($result == $pid || $result == -1) {
            if (!isset($this->childPids[$pid])) return;
            $md5 = $this->childPids[$pid];
            unset($this->childPids[$pid]);
            if (!isset($this->taskers[$md5])) return;
            $c = &$this->taskers[$md5];
            $c->refcount--;
            $c->pid = '';
            $c->state = State::STOPPED;
        }
    }
}

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('Asia/Shanghai');

if (PHP_SAPI != 'cli') {
    throw new ErrorException('非cli模式不可用');
}

// 当前进程创建的文件权限为755
umask(0);

$serverId = $argv[1];
if (empty($serverId) || !is_numeric($serverId)) {
    exit('invalid value of serverId');
}

try {
    $cli = new Process($serverId);
    $cli->run();
} catch (Exception $e) {
    echo $e;
}
