<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class ClickConsumer
{
    public function __construct(
        private readonly \PDO $primary,
        private readonly string $amqpUrl,
    ) {
    }

    public function consume(): void
    {
        $parts = parse_url($this->amqpUrl);
        if ($parts === false || !isset($parts['host'])) {
            throw new \RuntimeException('Invalid RabbitMQ URL');
        }

        $vhost = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        if ($vhost === '') {
            $vhost = '/';
        }

        $connection = new AMQPStreamConnection(
            $parts['host'],
            (int) ($parts['port'] ?? 5672),
            isset($parts['user']) ? rawurldecode($parts['user']) : 'guest',
            isset($parts['pass']) ? rawurldecode($parts['pass']) : 'guest',
            $vhost,
        );
        $channel = $connection->channel();
        $channel->queue_declare(AmqpClickPublisher::QUEUE, false, true, false, false);
        $channel->basic_qos(0, 20, false);

        $statement = $this->primary->prepare(
            'INSERT INTO click_counts (code, hits) VALUES (:code, 1)
             ON CONFLICT (code) DO UPDATE SET hits = click_counts.hits + 1',
        );

        $channel->basic_consume(
            AmqpClickPublisher::QUEUE,
            '',
            false,
            false,
            false,
            false,
            static function (AMQPMessage $message) use ($statement): void {
                try {
                    $payload = json_decode($message->getBody(), true);
                    $code = is_array($payload) ? (string) ($payload['code'] ?? '') : '';
                    if ($code === '') {
                        $message->ack();

                        return;
                    }
                    $statement->execute(['code' => $code]);
                    $message->ack();
                } catch (\Throwable $exception) {
                    error_log('click consume failed: ' . $exception->getMessage());
                    $message->nack(true);
                }
            },
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }
}
