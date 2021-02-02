<?php

namespace pzr\schedule\db;

use pzr\schedule\BaseObject;

class Job extends BaseObject
{

    public $id;                 //自增id
    public $name;               //命令名称用途
    public $command;            //执行的命令
    public $cron;               //cron表达式
    public $output;             //输出定向文件
    public $stderr;             //输出错误文件
    public $max_concurrence;    //并行数量
    public $state;              //状态
    public $pid;                //进程ID
    public $md5;                //对command的md5
    public $server_id;          //服务ID
    public $uptime;             //启动时间
    public $directory;          //执行目录
    public $refcount = 0;       //当前该任务执行的数量
    public $tag_id;             //标签ID，可用于快速搜索
    public $outofCron = 0;      //定时任务周期内没有跑完
}