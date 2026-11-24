<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Entity\Click;
use App\Domain\Port\ClickPublisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class AmqpClickPublisher implements ClickPublisher
{
    public const QUEUE = 'clicks';

    private const FAILURES_BEFORE_OPEN = 5;

    private const OPEN_SECONDS = 10;

    private static ?AMQPStreamConnection $connection = null;

    private static ?AMQPChannel $channel = null;

    private static int $failures = 0;

    private static int $openUntil = 0;

    public function __construct(private readonly string $amqpUrl)
    {
    }

    public function publish(Click $click): void
    {
        if (self::$openUntil > time()) {
            return;
        }

        try {
            $this->channel()->basic_publish(
                new AMQPMessage(
                    json_encode([
                        'code' => $click->code(),
                        'at' => $click->clickedAt()->format(DATE_ATOM),
                    ], JSON_THROW_ON_ERROR),
                    ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT],
                ),
                '',
                self::QUEUE,
            );
            self::$failures = 0;
        } catch (\Throwable $exception) {
            self::reset();
            self::$failures++;
            if (self::$failures >= self::FAILURES_BEFORE_OPEN) {
                self::$openUntil = time() + self::OPEN_SECONDS;
            }
            error_log('click publish failed: ' . $exception->getMessage());
        }
    }

    private function channel(): AMQPChannel
    {
        if (self::$channel instanceof AMQPChannel && self::$channel->is_open()) {
            return self::$channel;
        }

        $parts = parse_url($this->amqpUrl);
        if ($parts === false || !isset($parts['host'])) {
            throw new \RuntimeException('Invalid RabbitMQ URL');
        }

        $vhost = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        if ($vhost === '') {
            $vhost = '/';
        }

        self::$connection = new AMQPStreamConnection(
            $parts['host'],
            (int) ($parts['port'] ?? 5672),
            isset($parts['user']) ? rawurldecode($parts['user']) : 'guest',
            isset($parts['pass']) ? rawurldecode($parts['pass']) : 'guest',
            $vhost,
        );
        self::$channel = self::$connection->channel();
        self::$channel->queue_declare(self::QUEUE, false, true, false, false);

        return self::$channel;
    }

    private static function reset(): void
    {
        try {
            self::$channel?->close();
        } catch (\Throwable) {
        }
        try {
            self::$connection?->close();
        } catch (\Throwable) {
        }
        self::$channel = null;
        self::$connection = null;
    }
}
