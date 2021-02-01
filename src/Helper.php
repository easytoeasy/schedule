<?php

namespace pzr\schedule;

use DateTimeZone;
use Monolog\Logger;

class Helper
{

    /**
	 * 保证每次执行时都是从这分钟的0秒开始
	 */
	public static function secondsToNextMin()
	{
		$nextMin = strtotime(date('Y-m-d H:i') . ' +1 minute');
		return $nextMin - time();
	}

    public static function getLogger($name, $file='', $level = Logger::DEBUG)
    {
        $handler = new FileHandler($file, $level);
        $logger = new Logger($name, [$handler]);
        $logger->setTimezone(new DateTimeZone('Asia/Shanghai'));
        $logger->useMicrosecondTimestamps(false);
        return $logger;
    }

    public static function isProcessAlive($pid)
    {
        if (empty($pid)) return false;
        $pidinfo = `ps co pid {$pid} | xargs`;
        $pidinfo = trim($pidinfo);
        $pattern = "/.*?PID.*?(\d+).*?/";
        preg_match($pattern, $pidinfo, $matches);
        return empty($matches) ? false : ($matches[1] == $pid ? true : false);
    }

       /**
     * Configures an object with the initial property values.
     * @param object $object the object to be configured
     * @param array $properties the property initial values given in terms of name-value pairs.
     * @return object the object itself
     */
    public static function configure($object, $properties)
    {
        foreach ($properties as $name => $value) {
            $object->$name = $value;
        }

        return $object;
    }

}