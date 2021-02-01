<?php

namespace pzr\schedule;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\Utils;

class FileHandler extends AbstractProcessingHandler
{

    /** @var string 日志文件路径 */
    protected $file;
    protected $messageType = 3;
    protected $expandNewlines;

    public function __construct($file = '', $level = '', bool $bubble = false, bool $expandNewlines = false)
    {
        list($logfile, $loglevel) = IniParser::getCommLog();
        $level = $level?:$loglevel;
        parent::__construct($level, $bubble);
        $file = $file ?: $logfile;
        $this->file = $file;
        $this->expandNewlines = $expandNewlines;
    }


    /**
     * {@inheritDoc}
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter('[%datetime%] %channel%.%level_name%: %message%');
    }

    /**
     * {@inheritdoc}
     */
    protected function write(array $record): void
    {
        if (!$this->expandNewlines) {
            error_log((string) $record['formatted'] . PHP_EOL, $this->messageType, $this->file);
            return;
        }

        $lines = preg_split('{[\r\n]+}', (string) $record['formatted']);
        foreach ($lines as $line) {
            error_log($line, $this->messageType, $this->file);
        }
    }
}
