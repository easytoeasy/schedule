<?php

namespace pzr\schedule\db;

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
    /** @var Connection */
    protected $conn;
    /** @var QueryBuilder */
    protected $queryBuilder;

    /** @var Logger */
    protected $logger;

    public function __construct($module)
    {
        $this->logger = Helper::getLogger('DBConn');
        $this->module = $module;
        $this->conn = $this->getConn();
    }

    /**
     * @param string $module db.ini定义的模块
     * @return Connection
     */
    public function getConn()
    {
        if (empty($this->module))
            throw new ErrorException('module is empty');

        if (isset(self::$conns[$this->module])) {
            if (self::$conns[$this->module] instanceof Connection) {
                return self::$conns[$this->module];
            }
        }

        $dbConfig = IniParser::getConfig();
        if (!isset($dbConfig[$this->module]))
            throw new ErrorException(sprintf("undifined '[%s]' in db config", $this->module));

        $conn = DriverManager::getConnection($dbConfig[$this->module]);
        self::$conns[$this->module] = $conn;
        return $conn;
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
        $sql = $this->queryBuilder->getSQL();
        return $this->conn->fetchAllAssociative($sql, $params, $types);
    }




    /**
     * Get the value of queryBuilder
     */
    public function getQueryBuilder()
    {
        return $this->queryBuilder;
    }
}
