<?php

namespace pzr\schedule\db;

use Doctrine\DBAL\DriverManager;
use pzr\schedule\IniParser;

class Db
{
    /** 所有标签，用来快速搜索 */
    public function getTags()
    {
        $dbConn = new DBConn('db1');
        $dbConn->createQueryBuilder()
            ->select('id', 'name')
            ->from('scheduler_tags');

        $tags = $dbConn->fetchAll();
        return $tags;
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
        $dbConn = new DBConn('db1');
        $fields = [
            'id', 'name', 'command', 'cron', 'output', 'max_concurrence', 'server_id'
        ];
        $dbConn->createQueryBuilder()
            ->select($fields)
            ->from('scheduler_jobs')
            ->where('server_id = ?')
            ->andWhere('status = 1');

        $data = $dbConn->fetchAll([$serverId]);
        $jobs = [];
        foreach($data as $v) {
            $md5 = md5(json_encode($v));
            $v['md5'] = $md5;
            $jobs[$md5] = new Job($v);
        }
        unset($data);
        return $jobs;
    }
}
