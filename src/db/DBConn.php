<?php

namespace pzr\schedule\db;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use ErrorException;
use pzr\schedule\Parser;

class DBConn
{
    protected $module;
    protected $conn;
    protected $queryBuilder;

    public function __construct($module)
    {
        $this->module = $module;
        /* 每分钟初始化一次DB连接 */
        $this->conn = $this->connection();
    }

    /**
     * 之前碰到过的问题
     * 1）小心太多数据库链接数
     * 2）丢失连接
     */
    protected function connection()
    {
        if (empty($this->module))
            throw new ErrorException('params of module is empty');

        $parser = new Parser();
        $dbConfig = $parser->getDBConfig($this->module);
        unset($parser);
        return DriverManager::getConnection($dbConfig);
    }

    public function executeCacheQuery(int $cachetime = 120, $params = [], $types = [])
    {
        $conn = $this->conn;
        $cache = new \Doctrine\Common\Cache\ArrayCache();
        $config = $conn->getConfiguration();
        $config->setResultCacheImpl($cache);
        $query = $this->queryBuilder->getSQL();
        $cache = new FilesystemCache(__DIR__ . '/cache');

        $key = md5(sprintf(
            "%s%s%s",
            $query,
            !empty($params) ? json_encode($params) : '',
            !empty($types) ? json_encode($types) : ''
        ));
        $stmt = $conn->executeCacheQuery($query, $params, $types, new QueryCacheProfile($cachetime, $key, $cache));
        // $data = $stmt->fetchAllAssociative();
        $data = $stmt->fetchAll();
        $stmt->free();
        unset($stmt, $key, $cache, $query, $config);
        return $data;
    }

    /**
     * @return QueryBuilder
     */
    public function createQueryBuilder()
    {
        return $this->queryBuilder = $this->conn->createQueryBuilder();
    }

    /**
     * @param array $params
     * @param array $types
     * @return array
     */
    public function fetchAll($params = [], $types = [])
    {
        $conn = $this->conn;
        $sql = $this->queryBuilder->getSQL();
        // 为了兼容我们服务器的版本不同，都用7.1的调用方式
        // $rs = $conn->fetchAllAssociative($sql, $params, $types);
        $stmt = $conn->executeQuery($sql, $params, $types);
        $rs = $stmt->fetchAll();
        $stmt->free();
        unset($stmt, $sql);
        return $rs;
    }

    /**
     * Get the value of conn
     */ 
    public function getConn()
    {
        return $this->conn;
    }


    public function __destruct()
    {
        unset($this->queryBuilder);
        $this->conn->close();
    }
}
