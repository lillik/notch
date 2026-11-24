<?php

declare(strict_types=1);

use App\Action\CreateAction;
use App\Action\HealthAction;
use App\Action\RedirectAction;
use App\Bootstrap;
use App\Domain\Port\ClickPublisher;
use App\Domain\Port\KeyLeaser;
use App\Domain\Repository\LinkCacheRepository;
use App\Domain\Repository\LinkRepository;
use App\Domain\Service\RedirectService;
use App\Domain\Service\ShortenService;
use App\Domain\ValueObject\UrlPolicy;
use App\Infrastructure\Key\BatchLeaser;
use App\Infrastructure\Messaging\AmqpClickPublisher;
use App\Infrastructure\Persistence\PostgresLinkRepository;
use App\Infrastructure\Persistence\RedisLinkCacheRepository;
use App\Infrastructure\RedisRateLimiter;
use Psr\Container\ContainerInterface;

return [
    'db.primary' => static fn (): \PDO => Bootstrap::pdoFromUrl(Bootstrap::env('DATABASE_URL')),
    'db.replica' => static fn (): \PDO => Bootstrap::pdoFromUrl(Bootstrap::env('DATABASE_REPLICA_URL')),
    Predis\Client::class => static fn () => Bootstrap::redis(),
    LinkRepository::class => static fn (ContainerInterface $container): LinkRepository => new PostgresLinkRepository(
        $container->get('db.primary'),
        $container->get('db.replica'),
    ),
    LinkCacheRepository::class => static fn (ContainerInterface $container): LinkCacheRepository => new RedisLinkCacheRepository(
        $container->get(Predis\Client::class),
    ),
    KeyLeaser::class => static fn (ContainerInterface $container): KeyLeaser => new BatchLeaser(
        $container->get('db.primary'),
    ),
    ClickPublisher::class => static fn (): ClickPublisher => new AmqpClickPublisher(
        Bootstrap::env('RABBITMQ_URL'),
    ),
    RedisRateLimiter::class => static fn (ContainerInterface $container): RedisRateLimiter => new RedisRateLimiter(
        $container->get(Predis\Client::class),
        60,
        60,
    ),
    ShortenService::class => static fn (ContainerInterface $container): ShortenService => new ShortenService(
        new UrlPolicy(),
        $container->get(KeyLeaser::class),
        $container->get(LinkRepository::class),
        $container->get(LinkCacheRepository::class),
    ),
    RedirectService::class => static fn (ContainerInterface $container): RedirectService => new RedirectService(
        $container->get(LinkCacheRepository::class),
        $container->get(LinkRepository::class),
        $container->get(ClickPublisher::class),
    ),
    CreateAction::class => static fn (ContainerInterface $container): CreateAction => new CreateAction(
        $container->get(ShortenService::class),
        $container->get(RedisRateLimiter::class),
        Bootstrap::env('PUBLIC_BASE_URL', 'http://localhost:8080'),
    ),
    RedirectAction::class => static fn (ContainerInterface $container): RedirectAction => new RedirectAction(
        $container->get(RedirectService::class),
    ),
    HealthAction::class => static fn (ContainerInterface $container): HealthAction => new HealthAction(
        $container->get('db.primary'),
        $container->get(Predis\Client::class),
    ),
];
