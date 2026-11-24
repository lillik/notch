<?php

declare(strict_types=1);

use App\Http\NegotiateResponseMiddleware;
use App\Http\ResponseFormat;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Handlers\Strategies\RequestHandler;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container(require __DIR__ . '/../app/dependencies.php');
AppFactory::setContainer($container);

$app = AppFactory::create();
$app->getRouteCollector()->setDefaultInvocationStrategy(new RequestHandler(true));
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware((getenv('APP_DEBUG') ?: '') === '1', true, true);
$app->add(new NegotiateResponseMiddleware(ResponseFormat::create()));

(require __DIR__ . '/../app/routes.php')($app);

$app->run();
