<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$openapi = \OpenApi\Generator::scan([__DIR__ . '/../src']);
$target = $argv[1] ?? __DIR__ . '/../public/openapi.json';
$json = $openapi->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if (file_put_contents($target, $json . "\n") === false) {
    fwrite(STDERR, "Could not write {$target}\n");
    exit(1);
}

fwrite(STDOUT, $target . "\n");
