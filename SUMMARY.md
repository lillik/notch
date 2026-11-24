# URL Shortener

Aplicație Slim 4. Linkurile sunt anonime: nu există `user_id`. Același `long_url` primește de fiecare dată un cod nou. Domeniul depinde de interfețe; PDO, Redis și RabbitMQ stau în infrastructură.

## Structura

```
shortUrl/
├── src/
│   ├── Domain/
│   │   ├── Entity/
│   │   │   ├── Link.php                  agregat: code, longUrl, createdAt, expiresAt
│   │   │   └── Click.php                 eveniment: code, clickedAt
│   │   ├── ValueObject/
│   │   │   ├── Base62.php                int ↔ 0-9A-Za-z
│   │   │   └── UrlPolicy.php             doar https, max 2048, fără userinfo / host privat
│   │   ├── Service/
│   │   │   ├── ShortenService.php        validare, alocare, insert, cache
│   │   │   └── RedirectService.php       cache, citire, expirare, publicare click
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
│   │   │   ├── PostgresLinkRepository.php   primary la scriere, replica apoi primary la citire
│   │   │   ├── RedisLinkCacheRepository.php cheie link:{code}, Predis
│   │   │   └── LinkMapper.php               rând SQL / JSON Redis ↔ Link
│   │   ├── Key/
│   │   │   └── BatchLeaser.php              loturi din key_counter
│   │   ├── Messaging/
│   │   │   ├── AmqpClickPublisher.php       coada clicks, circuit breaker
│   │   │   └── ClickConsumer.php            upsert click_counts
│   │   └── RedisRateLimiter.php             60 creări / minut / IP
│   ├── Action/
│   │   ├── CreateAction.php                 POST /links și POST /shorten
│   │   ├── RedirectAction.php               GET /{code}
│   │   ├── HealthAction.php                 GET /health
│   │   └── Json.php
│   ├── OpenApi/
│   │   └── Spec.php                         Info și schemele OpenAPI
│   └── Bootstrap.php
├── app/
│   ├── dependencies.php                     leagă interfețele de implementări
│   └── routes.php
├── public/
│   ├── index.php
│   ├── openapi.json                         generat, nu se editează de mână
│   └── docs/index.html                      Swagger UI
├── bin/
│   ├── worker.php                           ClickConsumer
│   └── openapi.php                          scanează src/ și scrie public/openapi.json
├── tests/
│   ├── Base62Test.php
│   ├── UrlPolicyTest.php
│   └── LinkTest.php
└── deploy/
    ├── postgres/init/01-schema.sql
    ├── nginx/
    └── rabbitmq/
```

Nu există strat de use case. `CreateAction` și `RedirectAction` sunt handlere PSR-15 și apelează direct serviciile de domeniu.

## Dependențe

```
Action
  → ShortenService / RedirectService
      → UrlPolicy, Link
      → LinkRepository, LinkCacheRepository, KeyLeaser, ClickPublisher
          ↑ implementate de
Infrastructure
  PostgresLinkRepository, RedisLinkCacheRepository
  BatchLeaser, AmqpClickPublisher
```

`app/dependencies.php` face legătura. Domeniul nu importă PDO, Predis sau php-amqplib.

## Date

```sql
links (code PK, long_url, created_at, expires_at)
key_counter (id = 1, next_value)
click_counts (code PK, hits)
```

`expires_at` NULL înseamnă că linkul nu expiră. Un rând expirat rămâne în `links` și răspunde 410. Nu există job care să șteargă linkurile expirate. Ștergerea ar transforma același cod în 404, iar `key_counter` nu refolosește numerele.

## Coduri

`BatchLeaser` rezervă 1000 de numere într-o singură tranzacție:

```sql
UPDATE key_counter
SET next_value = next_value + 1000
WHERE id = 1
RETURNING next_value
```

Fiecare număr primește offset-ul `62^6`, apoi `Base62::encode`. Primul cod are 7 caractere. Alfabetul este `0-9A-Za-z`, deci codul este alfanumeric. Coliziunea pe cheia primară (`SQLSTATE 23505`) devine `DuplicateCode`, iar `ShortenService` reîncearcă de până la 3 ori.

## Creare

`POST /links` și `POST /shorten` fac același lucru.

```
CreateAction
  RedisRateLimiter.allow("create", IP)     429 dacă s-a depășit limita
  ShortenService.shorten(longUrl, expiresAt)
    UrlPolicy.assertAllowed                400
    expires_at în trecut                   400
    BatchLeaser.take()
    Link::create
    PostgresLinkRepository.save            INSERT, fără căutare după long_url
    RedisLinkCacheRepository.set
  201 { code, long_url, short_url, expires_at }
```

Body: `long_url` obligatoriu, `expires_at` opțional (ISO-8601). Un `user_id` trimis în body este ignorat.

## Redirect

```
GET /{code}
  RedirectAction
    RedirectService.redirect(code)
      Redis get
        miss → Postgres replica, apoi primary
        lipsă → LinkNotFound → 404
      Link.isExpired
        da → Redis DEL → LinkExpired → 410
      Redis set
      AmqpClickPublisher.publish(Click)    eșecul nu oprește redirectul
    302 Location + Cache-Control: no-store
```

Cache-ul Redis trăiește 24 de ore, sau mai puțin dacă `expires_at` este mai devreme. Linkul din Postgres nu este șters odată cu cheia Redis.

`bin/worker.php` consumă coada `clicks` (prefetch 20) și face upsert în `click_counts`. Coada este folosită doar pe acest drum. Crearea nu publică nimic.

## HTTP

| Metodă | Cale | Rezultat |
|--------|------|----------|
| GET | `/health` | 200 `{status: ok}` sau 503 `{status: down}` |
| POST | `/links`, `/shorten` | 201, 400, 429 |
| GET | `/{code}` | 302, 404, 410 |

Spec-ul este generat din atributele `OpenApi\Attributes` de pe acțiuni și din `src/OpenApi/Spec.php`. `composer openapi` rescrie `public/openapi.json`. Swagger UI este la `/docs/`.

## Runtime

Compose pornește Postgres 16 primary (`5432`) și replica (`5433`), Redis 7, RabbitMQ 3. Profilul `app` pornește API-ul PHP 8.3, worker-ul și NGINX pe `8080`. Schema din `deploy/postgres/init/01-schema.sql` se aplică doar pe un volum gol.
