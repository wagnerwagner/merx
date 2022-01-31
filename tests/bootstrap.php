<?php

error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

new Kirby([
    'roots' => [
        'index' => __DIR__,
        'content' => __DIR__ . '/kirby/content',
        'site' => __DIR__ . '/kirby/site',
    ],
]);
