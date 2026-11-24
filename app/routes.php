<?php

declare(strict_types=1);

use App\Action\CreateAction;
use App\Action\HealthAction;
use App\Action\RedirectAction;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', HealthAction::class);
    $app->post('/links', CreateAction::class);
    $app->post('/shorten', CreateAction::class);
    $app->get('/{code:[0-9A-Za-z]{1,16}}', RedirectAction::class);
};
