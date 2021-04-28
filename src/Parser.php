<?php

namespace pzr\schedule;

class Parser
{

    const INIFILE = __DIR__ . '/config/schedule.ini';

    public function __construct()
    {
        $this->_getLocalip();
        $this->parse();
    }

    public function parse()
    {
        $ini = parse_ini_file(self::INIFILE);
        if (
            !isset($ini['server_vars']) ||
            !isset($ini['db']) ||
            !isset($ini['server_id'])
        ) {
            Logger::error("parse error: undefined module ");
            exit(3);
        }

        if (
            empty($ini['server_vars'][HOST]) ||
            !array_key_exists(SERVER_ID, $ini['server_id']) ||
            empty($ini['server_id'][SERVER_ID])
        ) {
            Logger::error("parser error:  invalid value");
            exit(3);
        }

        $pidfile = $ini['pidfile'] ?? '/tmp/sched{%d}.pid';
        $logfile = $ini['logfile'] ?? '/dev/null';
        $memoryLimit = isset($ini['memory_limit']) && $ini['memory_limit'] >= 128
            ? $ini['memory_limit'] : 128;
        if (!array_key_exists($ini['loglevel'] ?? '', Logger::getNames())) {
            Logger::$level = Logger::INFO;
        } else {
            Logger::$level = Logger::getNames()[$ini['loglevel']];
        }

        $pidfile = str_replace('{%d}', SERVER_ID, $pidfile);
        $logfile = str_replace('{%d}', SERVER_ID, $logfile);
        $dbConfig = $ini['db'];

        define('BACKLOG', $ini['backlog'] ?? 16);
        define('MEMORY_LIMIT', $memoryLimit . 'M');
        define('SERVER_VAR_ID', $ini['server_vars'][HOST]);
        define('PORT', $ini['server_id'][SERVER_ID]);
        define('KEEPALIVE', boolval($ini['keepalive']) ?? false);
        define('PIDFILE', $pidfile);
        define('LOGFILE', $logfile);
        define('DBCONFIG', $dbConfig);
        unset($ini, $pidfile, $logfile, $memoryLimit);
    }

    private function _getLocalip()
    {
        $localip = gethostbyname(gethostname());
        if (count(explode('.', $localip)) != 4) {
            Logger::error('localip:' . $localip);
            exit(3);
        }
        define('HOST', $localip);
    }
}
