<?php

namespace pzr\schedule;

use DateTimeZone;
use ErrorException;
use Monolog\Logger;

class Parser
{
    protected $ini = [
        __DIR__ . '/config/schedule.ini',
        '/etc/schedule.ini',
        '/etc/config/schedule.ini',
        '/etc/schedule/schedule.ini',
    ];

    /** @var array */
    private $config = array();
    private $serverId = 0;
    private $inifile;
    private $logfile;
    private $level;

    public function __construct($serverId = 0)
    {
        $this->serverId = $serverId;
        $this->iniFile();
    }

    protected function iniFile()
    {
        foreach ($this->ini as $file) {
            if (is_file($file)) {
                $this->inifile = $file;
                return;
            }
        }
        if (empty($this->inifile)) {
            throw new ErrorException(sprintf("find ini failed in:%s", json_encode($this->ini)));
        }
    }

    /**
     * 解析配置文件
     */
    public function getConfig()
    {
        if ($this->config) return $this->config;
        $config = $this->parse_ini_file_extended($this->inifile);
        if (empty($config)) {
            throw new ErrorException('config is empty of parse file ' . $this->inifile);
        }
        $allowedServer = $ports = [];
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
        if (!in_array($this->serverId, $allowedServer)) {
            throw new ErrorException(sprintf(
                "invalid of server_id %s, usage [%s]",
                $this->serverId,
                implode(',', $allowedServer)
            ));
        }
        if (!isset($config['server_' . $this->serverId])) {
            throw new ErrorException(sprintf("undifined module of server_%s", $this->serverId));
        }
        unset($allowedServer, $ports);
        return $this->config = $config;
    }

    public function getDBConfig($module)
    {
        $config = parse_ini_file($this->inifile, true);
        if (!isset($config[$module]))
            throw new ErrorException(sprintf("undifined '[%s]' in db config", $module));
        return $config[$module];
    }

    public function getLogger()
    {
        $config = $this->getConfig();
        $logfile = isset($config['base']['logfile'])
            ? $config['base']['logfile']
            : '/var/log/schedule{%d}.log';

        $loglevel = isset($config['base']['loglevel'])
            ? $config['base']['loglevel']
            : 'DEBUG';

        $logfile = str_replace('{%d}', $this->serverId, $logfile);
        $loglevel = strtoupper($loglevel);

        if (!array_key_exists($loglevel, Logger::getLevels())) {
            throw new ErrorException('invalid value of loglevel：' . $loglevel);
        }
        $level = Logger::getLevels()[$loglevel];
        $this->logfile = $logfile;
        $this->level = $level;
        return Helper::getLogger('Schedule', $logfile, $level);
    }

    public function getOutput()
    {
        $config = $this->getConfig();
        $output = $config['base']['output'];
        if (empty($output))
            throw new ErrorException('invalid value of output');

        if (strpos($output, '{%d}') === false)
            throw new ErrorException(sprintf("output %s must include '{\%d}'", $output));

        return str_replace('{%d}', $this->serverId, $output);
    }

    public function getPpidFile()
    {
        $config = $this->getConfig();
        $pidfile = isset($config['base']['pidfile'])
            ? $config['base']['pidfile']
            : '/tmp/schedule{%d}.pid';

        if (strpos($pidfile, '{%d}') === false) {
            throw new ErrorException(sprintf("pidfile：%s must include '{\%d}'", $pidfile));
        }
        return str_replace('{%d}', $this->serverId, $pidfile);
    }

    public function getServer()
    {
        $config = $this->getConfig();
        $server = $config['server_' . $this->serverId];
        return new Server($server);
    }

    public function getMemoryLimit()
    {
        $config = parse_ini_file($this->inifile);
        return isset($config['memory_limit'])
            ? intval($config['memory_limit'])
            : 1024;
    }

    /**
     * Parses INI file adding extends functionality via ":base" postfix on namespace.
     *
     * @param string $filename
     * @return array
     */
    public function parse_ini_file_extended($filename)
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

    /**
     * Get the value of inifile
     */
    public function getInifile()
    {
        return $this->inifile;
    }

    /**
     * Get the value of logfile
     */
    public function getLogfile()
    {
        return $this->logfile;
    }

    /**
     * Get the value of level
     */
    public function getLevel()
    {
        return $this->level;
    }
}
