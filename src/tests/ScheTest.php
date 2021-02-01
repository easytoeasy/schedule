<?php

$i = 0;
while ($i < 3) {
    $i++;
    file_put_contents(
        __DIR__ . '/schetest.log',
        date('Y-m-d H:i:s') . PHP_EOL,
        FILE_APPEND
    );
    sleep(60);
}

file_put_contents(
    __DIR__ . '/schetest.log',
    '-------------------' . PHP_EOL,
    FILE_APPEND
);
