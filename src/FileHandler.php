<?php

namespace pzr\schedule;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractHandler;
use Monolog\Logger;
use Monolog\Utils;

class FileHandler extends AbstractHandler
{

    /** @var string 日志文件路径 */
    protected $file;
    protected $messageType = 3;
    protected $expandNewlines;

    public function __construct($file, $level = Logger::DEBUG, bool $bubble = false, bool $expandNewlines = false)
    {
        parent::__construct($level, $bubble);
        $this->file = $file;
        $this->expandNewlines = $expandNewlines;
    }

    public function handle(array $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        $record['formatted'] = $this->getDefaultFormatter()->format($record);

        $this->write($record);

        return false === $this->bubble;
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
