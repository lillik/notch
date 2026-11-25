# URL Shortener

Slim 4 application. Links are anonymous: there is no `user_id`. The same `long_url` receives a new code every time. The domain depends on interfaces. PDO, Redis, and RabbitMQ stay in infrastructure.

## Structure

```
shortUrl/
├── src/
│   ├── Domain/
│   │   ├── Entity/
│   │   │   ├── Link.php                  aggregate: code, longUrl, createdAt, expiresAt
│   │   │   └── Click.php                 event: code, clickedAt
│   │   ├── ValueObject/
│   │   │   ├── Base62.php                int ↔ 0-9A-Za-z
│   │   │   └── UrlPolicy.php             https only, max 2048, no userinfo or private host
│   │   ├── Service/
│   │   │   ├── ShortenService.php        validate, allocate, insert, cache
│   │   │   └── RedirectService.php       cache, read, expiry, publish click
│   │   ├── Repository/
│   │   │   ├── LinkRepository.php        save, find
│   │   │   └── LinkCacheRepository.php   set, get, invalidate
│   │   ├── Port/
│   │   │   ├── KeyLeaser.php             take()
│   │   │   └── ClickPublisher.php        publish(Click)
│   │   └── Exception/
│   │       ├── InvalidLongUrl.php
│   │       ├── LinkNotFound.php
│   │       ├── LinkExpired.php
│   │       └── DuplicateCode.php
│   ├── Infrastructure/
│   │   ├── Persistence/
│   │   │   ├── PostgresLinkRepository.php   primary for writes, replica then primary for reads
│   │   │   ├── RedisLinkCacheRepository.php key link:{code}, Predis
│   │   │   └── LinkMapper.php               SQL row / Redis JSON ↔ Link
│   │   ├── Key/
│   │   │   └── BatchLeaser.php              batches from key_counter
│   │   ├── Messaging/
│   │   │   ├── AmqpClickPublisher.php       queue clicks, circuit breaker
│   │   │   └── ClickConsumer.php            upsert click_counts
│   │   └── RedisRateLimiter.php             60 creates / minute / IP
│   ├── Action/
│   │   ├── CreateAction.php                 POST /links and POST /shorten
│   │   ├── RedirectAction.php               GET /{code}
│   │   └── HealthAction.php                 GET /health
│   ├── Http/
│   │   ├── PayloadResponse.php              status and data, no format
│   │   ├── NegotiateResponseMiddleware.php  picks a writer from Accept
│   │   ├── Json.php                         Json::write()
│   │   └── Xml.php                          Xml::write()
│   ├── OpenApi/
│   │   ├── Spec.php                         OpenAPI info and schemas
│   │   └── AcceptHeader.php                 Accept parameter
│   └── Bootstrap.php
├── app/
│   ├── dependencies.php                     binds interfaces to implementations
│   └── routes.php
├── public/
│   ├── index.php
│   ├── openapi.json                         generated, not edited by hand
│   └── docs/index.html                      Swagger UI
├── bin/
│   ├── worker.php                           ClickConsumer
│   └── openapi.php                          scans src/ and writes public/openapi.json
├── tests/
│   ├── Base62Test.php
│   ├── UrlPolicyTest.php
│   ├── LinkTest.php
│   └── ResponseFormatTest.php
└── deploy/
    ├── postgres/init/01-schema.sql
    ├── nginx/
    └── rabbitmq/
```

`CreateAction` and `RedirectAction` are PSR-15 handlers and call the domain services directly.

## Dependencies

```
Action
  → ShortenService / RedirectService
      → UrlPolicy, Link
      → LinkRepository, LinkCacheRepository, KeyLeaser, ClickPublisher
          ↑ implemented by
Infrastructure
  PostgresLinkRepository, RedisLinkCacheRepository
  BatchLeaser, AmqpClickPublisher
```

`app/dependencies.php` wires the interfaces. The domain does not import PDO, Predis, or php-amqplib.

Actions return a `PayloadResponse`. `NegotiateResponseMiddleware` reads `Accept` and calls `Json::write()` or `Xml::write()`. A 302 passes through unchanged. `Content-Type` describes the request body, which stays JSON.

## Data

```sql
links (code PK, long_url, created_at, expires_at)
key_counter (id = 1, next_value)
click_counts (code PK, hits)
```

`expires_at` NULL means the link does not expire. An expired row stays in `links` and keeps answering 410. `key_counter` never reuses numbers, so deleting the row would turn that code into a 404.

## Codes

`BatchLeaser` reserves 1000 numbers in one transaction:

```sql
UPDATE key_counter
SET next_value = next_value + 1000
WHERE id = 1
RETURNING next_value
```

Each number receives the offset `62^6`, then `Base62::encode`. The first code has 7 characters. The alphabet is `0-9A-Za-z`, so the code is alphanumeric. A primary-key collision (`SQLSTATE 23505`) becomes `DuplicateCode`, and `ShortenService` retries up to 3 times.

## Create

`POST /links` and `POST /shorten` do the same work.

```
CreateAction
  RedisRateLimiter.allow("create", IP)     429 when the limit is exceeded
  ShortenService.shorten(longUrl, expiresAt)
    UrlPolicy.assertAllowed                400
    expires_at in the past                 400
    BatchLeaser.take()
    Link::create
    PostgresLinkRepository.save            INSERT, no lookup by long_url
    RedisLinkCacheRepository.set
  201 { code, long_url, short_url, expires_at }
```

Body: `long_url` is required, `expires_at` is optional (ISO-8601). A `user_id` sent in the body is ignored.

## Redirect

```
GET /{code}
  RedirectAction
    RedirectService.redirect(code)
      Redis get
        miss → Postgres replica, then primary
        missing → LinkNotFound → 404
      Link.isExpired
        yes → Redis DEL → LinkExpired → 410
      Redis set
      AmqpClickPublisher.publish(Click)    a publish failure still returns the redirect
    302 Location + Cache-Control: no-store
```

The Redis cache lives for 24 hours, or less when `expires_at` is sooner. The Postgres row stays when the Redis key disappears.

`bin/worker.php` consumes the `clicks` queue (prefetch 20) and upserts `click_counts`. The queue is used only on this path. Create publishes nothing.

## HTTP

| Method | Path | Result |
|--------|------|--------|
| GET | `/health` | 200 `{status: ok}` or 503 `{status: down}` |
| POST | `/links`, `/shorten` | 201, 400, 429 |
| GET | `/{code}` | 302, 404, 410 |

The spec is generated from `OpenApi\Attributes` on the actions and from `src/OpenApi/`. `composer openapi` rewrites `public/openapi.json`. Swagger UI is at `/docs/`.

## Runtime

Compose starts Postgres 16 primary (`5432`) and replica (`5433`), Redis 7, and RabbitMQ 3. The `app` profile starts the PHP 8.5 API, the worker, and NGINX on `8080`. The schema in `deploy/postgres/init/01-schema.sql` applies only to an empty volume.
