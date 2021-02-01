<?php

namespace pzr\schedule\db;

class Db
{

    protected $dbConn;
    protected $servTags;

    public function __construct($name)
    {
        $this->dbConn = new DBConn($name);
    }

    /** 所有标签，用来快速搜索 */
    public function getTags()
    {
        $dbConn = $this->dbConn;
        $dbConn->createQueryBuilder()
            ->select('id', 'name')
            ->from('scheduler_tags');

        $tags = $dbConn->executeCacheQuery();
        $rs = [];
        foreach ($tags as $v) {
            $rs[$v['id']] = $v['name'];
        }

        return $rs;
    }

    /**
     * 获取该服务下的所有任务
     *
     * @param int $serverId
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
        $jobs = [];
        foreach ($data as $v) {
            if (!isset($this->servTags[$v['tag_id']])) {
                $this->servTags[$v['tag_id']] = $tags[$v['tag_id']];
            }
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
