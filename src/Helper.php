<?php

namespace pzr\schedule;

use DateTimeZone;
use Monolog\Logger;

class Helper
{

    public static function isProcessAlive($pid)
    {
        // if (empty($pid)) return false;
        // $pidinfo = `ps co pid {$pid} | xargs`;
        // $pidinfo = trim($pidinfo);
        // $pattern = "/.*?PID.*?(\d+).*?/";
        // preg_match($pattern, $pidinfo, $matches);
        // return empty($matches) ? false : ($matches[1] == $pid ? true : false);
        $rs = `ps aux | awk '{print $2}' | grep -w $pid`;
        return $rs;
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


    public static function delTree($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }
}
