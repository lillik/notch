# Short URL

Anonymous URL shortener. A Slim 4 API stores an HTTPS address under a new base62 code and redirects callers to it. Click counts are updated by a worker, off the redirect path.

The API, worker, and edge proxy are one Compose profile. Postgres (primary and replica), Redis, and RabbitMQ start with the project.

## Requirements

- Docker with Compose v2
- PHP 8.3 and Composer, only if you run the tests or regenerate the OpenAPI document on the host

## Run

From the repository root:

```bash
docker compose --profile app up --build -d
```

The first start builds the PHP image and applies `deploy/postgres/init/01-schema.sql` to an empty database volume. When the API is healthy:

| URL | What it is |
| --- | --- |
| http://localhost:8080/health | Liveness of Postgres and Redis |
| http://localhost:8080/docs/ | Swagger UI |
| http://localhost:8080/openapi.json | OpenAPI 3 document |

Stop the stack with `docker compose --profile app down`. Add `-v` only when you intend to delete the database, Redis, and RabbitMQ volumes.

## Call the API

Create a link. The request body is JSON.

```bash
curl -sS -D - \
  -H 'Content-Type: application/json' \
  -d '{"long_url":"https://example.com/docs"}' \
  http://localhost:8080/links
```

```http
HTTP/1.1 201 Created
Content-Type: application/json

{"code":"1000000","long_url":"https://example.com/docs","short_url":"http://localhost:8080/1000000","expires_at":null}
```

Open the `short_url`, or request the code directly. A live link answers **302** with `Location` set to the long URL and `Cache-Control: no-store`.

```bash
curl -sS -o /dev/null -D - http://localhost:8080/1000000
```

`POST /shorten` is the same operation as `POST /links`.

Ask for XML with `Accept`. `Content-Type` describes the request body and stays `application/json`.

```bash
curl -sS \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/xml' \
  -d '{"long_url":"https://example.com/docs","expires_at":"2026-12-31T00:00:00Z"}' \
  http://localhost:8080/links
```

An `Accept` value other than JSON or XML returns **406** and does not create a link. Omitting `Accept` returns JSON.

## API

| Method | Path | Success | Errors |
| --- | --- | --- | --- |
| `GET` | `/health` | 200 `{"status":"ok"}` | 503 when Postgres or Redis does not answer |
| `POST` | `/links`, `/shorten` | 201 created link | 400 invalid URL or expiry, 429 over the create limit |
| `GET` | `/{code}` | 302 redirect | 404 unknown code, 410 expired link |

`POST` body:

| Field | Required | Meaning |
| --- | --- | --- |
| `long_url` | yes | HTTPS URL, at most 2048 characters. `longUrl` is accepted as an alias. |
| `expires_at` | no | ISO-8601 timestamp in the future. `expiresAt` is an alias. Omit it and the link does not expire. |

The long URL must be `https`, without embedded credentials. `localhost`, `*.localhost`, `metadata.google.internal`, and literal private or reserved IP addresses are rejected. Any other hostname is allowed.

Create requests are limited to 60 per minute per client address. NGINX sends that address in `X-Real-IP`.

## What the service guarantees

- The same long URL creates a new code on every request. Codes are not reused.
- Codes are base62 (`0-9`, `A-Z`, `a-z`) and start at 7 characters.
- `expires_at` null means the row never expires. A past timestamp returns **410** and the row stays in Postgres, so the code does not later become **404**. Nothing deletes expired rows.
- A redirect publishes one click to the RabbitMQ queue `clicks`. The API still returns 302 when publishing fails. `bin/worker.php` increments `click_counts`.
- Reads try the Postgres replica, then the primary. Writes go to the primary.

## Configuration

Compose sets the variables the API and worker need. `.env.example` lists the same values. You do not need a `.env` file to start the stack.

| Variable | Compose value |
| --- | --- |
| `DATABASE_URL` | `postgresql://shorturl:shorturl@postgres:5432/shorturl` |
| `DATABASE_REPLICA_URL` | `postgresql://shorturl:shorturl@postgres-replica:5432/shorturl` |
| `REDIS_ADDR` | `redis:6379` |
| `RABBITMQ_URL` | `amqp://guest:guest@rabbitmq:5672/` |
| `PUBLIC_BASE_URL` | `http://localhost:8080` |

`PUBLIC_BASE_URL` is the prefix of `short_url` in the create response.

Published ports:

| Service | Port |
| --- | --- |
| NGINX (public API) | 8080 |
| Postgres primary | 5432 |
| Postgres replica | 5433 |
| Redis | 6379 |
| RabbitMQ | 5672, management UI 15672 |

The database password and the RabbitMQ `guest` account are for this local stack. Replace them before any shared or public deployment.

Schema changes in `deploy/postgres/init/01-schema.sql` apply only when the Postgres volume is created. An existing volume keeps its current tables.

## Tests and the OpenAPI document

The tests cover encoding, the URL policy, link expiry, and response negotiation. They do not need Docker.

```bash
composer install
composer test
```

Attributes on the actions and `src/OpenApi/` are the source of the OpenAPI document. Regenerate it after changing those attributes:

```bash
composer openapi
```

The image build runs the same generator, so `public/openapi.json` inside the container matches the source that was copied in.

## Layout

```
src/Domain            Link, Click, URL policy, shorten and redirect services
src/Infrastructure    Postgres, Redis, key batches, RabbitMQ
src/Action            HTTP handlers
src/Http              Accept negotiation (JSON or XML)
app                   routes and dependency wiring
public                front controller, OpenAPI document, Swagger UI
bin/worker.php        click consumer
deploy                Postgres init, NGINX, RabbitMQ, PHP image config
compose.yaml          primary, replica, Redis, RabbitMQ, API, worker, NGINX
```

The domain depends on interfaces. SQL, Redis, and AMQP stay in `src/Infrastructure`. `SUMMARY.md` describes the classes and the two request flows in more detail.
