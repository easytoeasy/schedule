<?php

use PHPUnit\Framework\TestCase;
use pzr\schedule\db\Db;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class TestDB extends TestCase
{

    public function testDb1()
    {
        $db = new Db();
        $data = $db->getJobs($serverId = 99);
        // var_export($data);
    }

}