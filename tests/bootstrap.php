<?php

error_reporting(0);

require_once __DIR__ . '/../index.php';

new Kirby([
    'roots' => [
        'index' => __DIR__,
        'content' => __DIR__ . '/kirby/content',
        'site' => __DIR__ . '/kirby/site',
    ],
]);
