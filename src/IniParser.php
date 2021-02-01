<?php

namespace pzr\schedule;

use ErrorException;
use Monolog\Logger;

class IniParser
{

    public static $ini = [
        __DIR__ . '/config/schedule.ini',
        '/etc/schedule.ini',
        '/etc/config/schedule.ini',
        '/etc/schedule/schedule.ini',
    ];

    /** @var array */
    private static $config = null;
    /** @var Logger $logger */
    private static $logger = null;
    private static $serverId = 0;

    /**
     * 解析配置文件
     */
    public static function getConfig()
    {
        if (self::$config) return self::$config;

        $config = array();
        foreach (self::$ini as $ini_file) {
            if (!is_file($ini_file)) continue;
            $config = self::parse_ini_file_extended($ini_file);
        }

        if (empty($config)) {
            throw new ErrorException(sprintf("parse file %s failed", $ini_file));
        }

        $allowedServer = [];
        $ports = [];
        foreach ($config as $k => $v) {
            if (preg_match('/^server_(\d+)$/', $k, $match)) {
                $allowedServer[] = $match[1];
                if (empty($v['host']) || empty($v['port'])) {
                    throw new ErrorException(sprintf(
                        "invalid module of %s about host:%s or port:%s",
                        $k,
                        $v['host'],
                        $v['port']
                    ));
                }
                if (isset($ports[$v['port']])) {
                    throw new ErrorException('invalid value of port：' . $v['port']);
                }
                $ports[$v['port']] = 1;
            }
        }
        if (empty($allowedServer)) {
            throw new ErrorException('has no server_id be parsed');
        }
        $config['allowed_serverids'] = $allowedServer;
        return self::$config = $config;
    }

    public static function getCommLog()
    {
        $config = self::getConfig();
        $logfile = isset($config['base']['logfile']) ?
            $config['base']['logfile'] : '/var/log/schedule{%d}.log';
        $logfile = str_replace('{%d}', self::$serverId, $logfile);

        $loglevel = isset($config['base']['loglevel']) ?
            $config['base']['loglevel'] : 'DEBUG';

        $loglevel = strtoupper($loglevel);
        if (!array_key_exists($loglevel, Logger::getLevels())) {
            throw new ErrorException('invalid value of loglevel：' . $loglevel);
        }

        $level = Logger::getLevels()[$loglevel];

        unset($config);
        return [
            $logfile, $level
        ];
    }

    public static function getPpidFile($serverId)
    {
        $config = self::getConfig();
        $pidfile = isset($config['base']['pidfile']) ?
            $config['base']['pidfile'] : '/tmp/schedule{%d}.pid';
        if (strpos($pidfile, '{%d}') === false) {
            throw new ErrorException(sprintf("pidfile：%s must include '{\%d}'", $pidfile));
        }
        $pidfile = str_replace('{%d}', $serverId, $pidfile);
        unset($config);
        return $pidfile;
    }

    public static function getServer($serverId)
    {
        $config = self::getConfig();
        if (!in_array($serverId, $config['allowed_serverids'])) {
            throw new ErrorException(sprintf(
                "serverId %s not allowed, checked are %s",
                $serverId,
                implode(',', $config['allowed_serverids'])
            ));
        }
        if (!isset($config['server_' . $serverId])) {
            throw new ErrorException(sprintf("undifined module server_%s", $serverId));
        }
        $module = $config['server_' . $serverId];
        self::$serverId = $serverId;
        unset($config);
        return [$module['host'], $module['port']];
    }

    /**
     * Parses INI file adding extends functionality via ":base" postfix on namespace.
     *
     * @param string $filename
     * @return array
     */
    public static function parse_ini_file_extended($filename)
    {
        $p_ini = parse_ini_file($filename, true);
        $config = array();
        foreach ($p_ini as $namespace => $properties) {
            if (strpos($namespace, ':') === false) {
                $config[$namespace] = $properties;
                continue;
            }
            list($name, $extends) = explode(':', $namespace);
            $name = trim($name);
            $extends = trim($extends);
            // create namespace if necessary
            if (!isset($config[$name])) $config[$name] = array();
            // inherit base namespace
            if (isset($p_ini[$extends])) {
                foreach ($p_ini[$extends] as $prop => $val)
                    $config[$name][$prop] = $val;
            }
            // overwrite / set current namespace values
            foreach ($properties as $prop => $val)
                $config[$name][$prop] = $val;
        }
        return $config;
    }
}
