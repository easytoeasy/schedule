<?php

$i = 0;
while($i++ <= 3)
{
    echo 'output to output log' . PHP_EOL;
    @error_log('output test', 3, __DIR__ . '/normal.log');
    sleep(60);
}
