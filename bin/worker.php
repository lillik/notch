<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Infrastructure\Messaging\ClickConsumer;

require __DIR__ . '/../vendor/autoload.php';

$consumer = new ClickConsumer(
    Bootstrap::pdoFromUrl(Bootstrap::env('DATABASE_URL')),
    Bootstrap::env('RABBITMQ_URL'),
);

$consumer->consume();
