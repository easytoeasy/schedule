<?php

namespace pzr\schedule;

class State
{
    const STOPPED = 0;
    const STARTING = 10;
    const RUNNING = 20;
    const BACKOFF = 30;
    const STOPPING = 40;
    const EXITED = 100;
    const FATAL = 200;
    const UNKNOWN = 1000;

    public static $desc = [
        self::STOPPED => 'stopped',
        self::STARTING => 'starting',
        self::RUNNING => 'running',
        self::BACKOFF => 'backoff',
        self::STOPPING => 'stopping',
        self::EXITED => 'exited',
        self::FATAL => 'fatal',
        self::UNKNOWN => 'unknown',
    ];

    public static function desc($state)
    {
        return self::$desc[$state];
    }

    public static function css($state)
    {
        if (in_array($state, [
            self::FATAL,
            self::UNKNOWN,
        ])) {
            return 'error';
        } elseif (in_array($state, [
            self::RUNNING
        ])) {
            return 'running';
        } else {
            return 'nominal';
        }
    }

    public static function stopedState()
    {
        return [
            self::STOPPED,
            self::EXITED,
            self::FATAL,
            self::UNKNOWN,
        ];
    }

    public static function runingState()
    {
        return [
            self::STARTING,
            self::RUNNING,
            self::BACKOFF,
        ];
    }
}
