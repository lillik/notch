# URL Shortener Architecture

## Overview
Stateless API layer, durable primary storage, in-memory cache layer, and async analytics. Read and write paths are designed separately.

---

## Component Legend
- **LoadBalancer**: NGINX or similar; distributes traffic.
- **ApiServers**: Stateless; handle creates and redirects independently.
- **KeyService**: Distributed key-generation service; each API node pulls batches to avoid single point of failure.
- **PrimaryDB**: MongoDB or PostgreSQL; system of record for `code → longURL` mappings.
- **ReadReplica**: Read-only copy of PrimaryDB (optional if replication lag is acceptable).
- **Redis**: In-memory cache; serves hot redirects.
- **ClickQueue**: Async queue (Kafka, RabbitMQ, or Redis Streams).
- **AnalyticsWorker**: Consumes click events, aggregates metrics.

---

## Flow 1: Create Short URL

```
flowchart LR
  client1[Client]
  lb1[LoadBalancer]
  api1[ApiServer]
  kgs1[KeyService]
  primary1[(PrimaryDB)]
  redis1[(Redis)]
  
  client1 -->|POST /shorten<br/>body: longURL| lb1
  lb1 --> api1
  api1 -->|"pull key batch<br/>(if depleted)"| kgs1
  api1 -->|"write mapping<br/>code → longURL"| primary1
  api1 -->|"write-through cache<br/>set code: longURL, TTL=24h"| redis1
  api1 -->|"201 Created<br/>{ code, shortURL }"| client1
```

**Key decisions:**
- **Key batching**: API nodes pre-fetch ranges from KeyService; allocation is in-process. Avoids 1:1 RPC per create and reduces KeyService to a low-QPS generator.
- **Write-through**: Redis is populated on create. Guarantees that immediately after creation, a click hits the cache (no race).
- **Response**: Return the short code and full short URL to the client immediately (synchronous).

---

## Flow 2: Redirect (Lookup)

```
flowchart LR
  client2[Client]
  lb2[LoadBalancer]
  api2[ApiServer]
  redis2[(Redis)]
  primary2[(PrimaryDB)]
  target[Target Website]
  queue[ClickQueue]
  
  client2 -->|"GET /code"| lb2
  lb2 --> api2
  
  api2 -->|"1. lookup code"| redis2
  redis2 -->|"hit: return longURL"| api2
  redis2 -->|"miss: continue"| api2
  
  api2 -->|"2. query code (miss path)"| primary2
  primary2 -->|"return longURL"| api2
  api2 -->|"3. backfill cache<br/>set code: longURL, TTL=24h"| redis2
  
  api2 -->|"4. emit click event<br/>(non-blocking)"| queue
  api2 -->|"5. 302 Location: longURL"| client2
  client2 -->|"6. browser follows redirect"| target
```

**Key decisions:**
- **Cache hierarchy**: Check Redis first (sub-ms), fall through to database on miss.
- **Status code**: 302 Found (not 301 Moved Permanently). 301 is cached by browsers and CDNs; every click after the first bypasses analytics.
- **Non-blocking analytics**: Emit to queue without awaiting. Click event does not sit on redirect latency.
- **Write-through on miss**: After reading the database, fill Redis to accelerate subsequent clicks.

---

## Critical Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| **Short code length** | 7 chars base62 | ~3.5 trillion possible codes; custom aliases handled separately. |
| **Cache TTL** | 24 hours | Mappings are immutable; long TTL is safe. Adjust for edit/delete capability. |
| **HTTP status** | 302 (temporary) | Allows per-click analytics. 301 breaks tracking. |
| **Deduplication** | None; always new code | Simpler than managing duplicate long URLs. Most public shorteners do this. |
| **Read path** | Redis → Primary | Replica optional (replication lag → potential 404 for immediate clicks). Write-through mitigates. |
| **Shard key** (if sharded) | Short code | Point lookups are the hot path. Avoid sharding on user ID for read path. |
| **Anonymous vs. auth** | Both supported | Anonymous: sequential IDs risk enumeration (use random codes). Auth: enables user link lists. |
| **Abuse controls** | Required | Rate limits per IP/user, scheme allowlist (https only), private IP blocks, manual takedown path. |

---

## Scaling Notes

**Single-region (this diagram):**
- API servers scale horizontally behind load balancer.
- Redis becomes a bottleneck on viral links; run multiple replicas and distribute reads by code hash.
- Database reads scale with replicas; writes stay on primary.

**Multi-region (future):**
- Replicate short code generation across regions (Snowflake-style epoch + machine ID + counter).
- Edge cache at CDN layer; origin handles creates.
- Global database (or local replica with async replication).

---

## Validation Checklist

- [ ] Is the redirect path sub-100ms (Redis hit) and <500ms (database hit)?
- [ ] Does the create path block on cache write-through?
- [ ] Does analytics drop events if the queue fills? (Add a circuit breaker.)
- [ ] Are code collisions handled? (Unique constraint on code column.)
- [ ] Is there a rate limit? (Global and per-user/IP.)
- [ ] Are private IPs and dangerous schemes blocked?
- [ ] Is there a manual takedown process?
- [ ] Are expired/edited links invalidated in the cache?
