<?php

namespace pzr\schedule\db;

class Db
{

    protected $dbConn;
    protected $servTags;
    protected $server;

    /**
     * @param string $name DB name
     * @param int $server 服务器编号
     */
    public function __construct($name, $server)
    {
        $this->dbConn = new DBConn($name);
        $this->server = $server;
    }

    /**
     * jobs内command的占位符，由此配置
     *
     * @param int $server 服务器的编号
     * @return array
     */
    public function getVars()
    {
        $dbConn = $this->dbConn;
        $dbConn->createQueryBuilder()
            ->select('name', 'value')
            ->from('scheduler_vars')
            // ->where('server_id = :server')          //两种写法都有问题，难道我下了一个假的包？
            // ->setParameter(':server_id', $server)
            // ->where('server_id = ?')
            // ->setParameter(0, $server)
            ->where('server_id = ?');

        $tags = $dbConn->executeCacheQuery(0, [$this->server]);
        $rs = [];
        foreach ($tags as $v) {
            $key = '{' . $v['name'] . '}';
            $rs[$key] = $v['value'];
        }

        return $rs;
    }

    /** 所有标签，用来快速搜索 */
    public function getTags()
    {
        $dbConn = $this->dbConn;
        $dbConn->createQueryBuilder()
            ->select('id', 'name')
            ->from('scheduler_tags');

        $tags = $dbConn->executeCacheQuery(0);
        $rs = [];
        foreach ($tags as $v) {
            $rs[$v['id']] = $v['name'];
        }

        return $rs;
    }

    /**
     * 获取该服务下的所有任务
     *
     * @param int $serverId 确切的说是对jobs下命令的分类，由于是线上库，就不在改字段
     * @return array
     */
    public function getJobs($serverId)
    {
        if (empty($serverId)) return false;
        $dbConn = $this->dbConn;
        $fields = [
            'id', 'name', 'command', 'cron', 'output', 'max_concurrence', 'server_id', 'tag_id'
        ];
        $dbConn->createQueryBuilder()
            ->select($fields)
            ->from('scheduler_jobs')
            ->where('server_id = ?')
            ->andWhere('status = 1');

        $data = $dbConn->fetchAll([$serverId]);
        $tags = $this->getTags();
        $vars = $this->getVars();
        $jobs = [];
        foreach ($data as $v) {
            if (!isset($this->servTags[$v['tag_id']])) {
                $this->servTags[$v['tag_id']] = $tags[$v['tag_id']];
            }
            $v['command'] = str_replace(array_keys($vars), array_values($vars), $v['command']);
            $md5 = md5(json_encode($v));
            $v['md5'] = $md5;
            $jobs[$md5] = new Job($v);
        }
        unset($data);
        return $jobs;
    }

    /**
     * Get the value of servTags
     */
    public function getServTags()
    {
        return $this->servTags;
    }
}
