<?php

namespace pzr\schedule\db;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use ErrorException;
use Monolog\Logger;
use pzr\schedule\Helper;
use pzr\schedule\IniParser;

class DBConn
{

    protected static $conns = array();

    protected $module;
    /** @var QueryBuilder */
    protected $queryBuilder;

    /** @var Logger */
    protected $logger;

    protected $conn;

    public function __construct($module)
    {
        $this->logger = Helper::getLogger('DBConn');
        $this->module = $module;
    }

    /**
     * @param string $module db.ini定义的模块
     * @return Connection
     */
    public function getConn()
    {
        /**
         * 如何解决 2006 MySQL server has gone away 问题？
         * 那就注释掉下面的方法，每次都创建一个新的。
         */
        // if ($this->conn)
        //     return $this->conn;
        return $this->conn = $this->connection();
    }

    /**
     * 之前碰到过的问题
     * 1）小心太多数据库链接数
     * 2）丢失连接
     */
    protected function connection()
    {
        if (empty($this->module))
            throw new ErrorException('module is empty');

        /**
         * 会丢失数据库连接
         */
        // $conn = isset(self::$conns[$this->module]) ? self::$conns[$this->module] : null;
        // if ($conn instanceof Connection) {
        //     return $conn;
        // }

        $dbConfig = IniParser::getConfig();
        if (!isset($dbConfig[$this->module]))
            throw new ErrorException(sprintf("undifined '[%s]' in db config", $this->module));

        $conn = self::$conns[$this->module] = DriverManager::getConnection($dbConfig[$this->module]);

        return $conn;
    }

    public function executeCacheQuery(int $cachetime = 120, $params = [], $types = [])
    {
        $conn = $this->getConn();
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
        return $data;
    }

    /**
     * @return QueryBuilder
     */
    public function createQueryBuilder()
    {
        $conn = $this->getConn();
        return $this->queryBuilder = $conn->createQueryBuilder();
    }

    /**
     * @param array $params
     * @param array $types
     * @return array
     */
    public function fetchAll($params = [], $types = [])
    {
        $conn = $this->getConn();
        $sql = $this->queryBuilder->getSQL();
        // 为了兼容我们服务器的版本不同，都用7.1的调用方式
        // $rs = $conn->fetchAllAssociative($sql, $params, $types);
        $stmt = $conn->executeQuery($sql, $params, $types);
        $rs = $stmt->fetchAll();
        return $rs;
    }

    /**
     * Get the value of queryBuilder
     */
    public function getQueryBuilder()
    {
        return $this->queryBuilder;
    }
}
