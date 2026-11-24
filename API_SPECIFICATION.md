# URL Shortener API Specification - PHP

## Overview
Complete REST API specification for the URL shortener service. All endpoints are stateless and designed to run behind a load balancer.

---

## Base Configuration
```
Base URL: https://api.shorturl.com
API Version: v1
Response Format: JSON (unless specified)
Authentication: JWT Bearer token (optional for anonymous)
Rate Limit: 100 requests/min per IP, 1000/min per user
```

---

## 1. Authentication Endpoints

### 1.1 Register User
**Purpose**: Create a new user account to track custom aliases and analytics.

**Endpoint**: `POST /api/v1/auth/register`

**Request**:
```json
{
  "email": "user@example.com",
  "password": "securePassword123!",
  "username": "myusername"
}
```

**Validation Rules**:
- Email: valid RFC 5322 format, unique
- Password: min 8 chars, uppercase, number, special char
- Username: 3-20 alphanumeric + underscore, unique

**Response (201 Created)**:
```json
{
  "status": "success",
  "user_id": "550e8400-e29b-41d4-a716-446655440000",
  "email": "user@example.com",
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "expires_in": 86400
}
```

**Error Responses**:
- `400 Bad Request`: Invalid email, weak password, username taken
- `409 Conflict`: Email already registered
- `429 Too Many Requests`: Registration rate limit exceeded

**Side Effects**:
- User record inserted into `users` table
- Welcome email sent asynchronously

---

### 1.2 Login
**Purpose**: Authenticate and retrieve a JWT token for authenticated requests.

**Endpoint**: `POST /api/v1/auth/login`

**Request**:
```json
{
  "email": "user@example.com",
  "password": "securePassword123!"
}
```

**Response (200 OK)**:
```json
{
  "status": "success",
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "expires_in": 86400,
  "user_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Error Responses**:
- `401 Unauthorized`: Invalid credentials
- `404 Not Found`: User not found
- `429 Too Many Requests`: Login rate limit (5 failed attempts/15min)

**Token Claims** (decoded JWT):
```json
{
  "user_id": "550e8400-e29b-41d4-a716-446655440000",
  "email": "user@example.com",
  "iat": 1700000000,
  "exp": 1700086400
}
```

---

### 1.3 Refresh Token
**Purpose**: Extend session without re-entering credentials.

**Endpoint**: `POST /api/v1/auth/refresh`

**Headers**:
```
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Response (200 OK)**:
```json
{
  "status": "success",
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "expires_in": 86400
}
```

**Error Responses**:
- `401 Unauthorized`: Token expired or invalid
- `403 Forbidden`: Token refresh limit exceeded (max 10 refresh per original token)

---

## 2. URL Shortening Endpoints

### 2.1 Create Short URL (Core API)
**Purpose**: Generate a short URL for a long URL. Supports both anonymous and authenticated creation.

**Endpoint**: `POST /api/v1/shorten`

**Request (Anonymous)**:
```json
{
  "long_url": "https://example.com/very/long/path?param=value&other=123",
  "custom_alias": null,
  "expires_in_days": null
}
```

**Request (Authenticated)**:
```json
{
  "long_url": "https://example.com/very/long/path?param=value&other=123",
  "custom_alias": "my-link",
  "expires_in_days": 30,
  "title": "My Link Title",
  "tags": ["marketing", "campaign-2024"]
}
```

**URL Validation**:
- Must be valid HTTP/HTTPS URL
- Private IPs (10.0.0.0/8, 172.16.0.0/12, 127.0.0.1) are blocked (security)
- Schemes: only `http://` and `https://` allowed
- Max length: 2048 characters
- Domain must be reachable (optional HEAD request to validate; async)

**Custom Alias Rules** (if provided):
- 3-30 alphanumeric + hyphen/underscore
- Must be unique across all users
- Reserved words blacklist (e.g., `api`, `admin`, `auth`, `www`)
- User can reserve max 10 custom aliases per day

**Response (201 Created)**:
```json
{
  "status": "success",
  "short_code": "aB12cd",
  "short_url": "https://short.url/aB12cd",
  "long_url": "https://example.com/very/long/path?param=value&other=123",
  "user_id": "550e8400-e29b-41d4-a716-446655440000",
  "created_at": "2024-11-24T21:12:59Z",
  "expires_at": "2024-12-24T21:12:59Z",
  "clicks": 0,
  "is_custom": false
}
```

**Request Flow (Detailed)**:
1. Validate long URL format and scheme
2. Check if long URL + user_id already exists (dedup within user scope)
   - If yes and not custom: return existing code
   - If yes and custom alias: return 409 Conflict
3. If custom_alias provided:
   - Validate format and check blacklist
   - Verify uniqueness (SELECT code FROM mappings WHERE code = ?)
4. Otherwise, allocate code from KeyService batch
5. Insert into `mappings` table (code, long_url, user_id, created_at, expires_at)
6. Write-through to Redis: `SET code:aB12cd "longUrl|userId|expiresAt" EX 86400`
7. Emit async event to ClickQueue: `{ event_type: "link_created", ... }`
8. Return 201 with short URL

**Error Responses**:
- `400 Bad Request`: Invalid URL format, URL too long, private IP, unsupported scheme
- `401 Unauthorized`: Token required for custom alias (or custom alias limit exceeded)
- `403 Forbidden`: Domain blacklisted (phishing, malware)
- `409 Conflict`: Custom alias already taken
- `429 Too Many Requests`: Rate limit exceeded (100 creates/min per IP, 1000/day per user)
- `503 Service Unavailable`: KeyService offline

**Side Effects**:
- Database write to `mappings` table
- Redis cache write-through
- Async click event emitted

---

### 2.2 Retrieve Short URL Info
**Purpose**: Get metadata about a short URL (requires authentication, only for owner or admin).

**Endpoint**: `GET /api/v1/links/{code}`

**Headers**:
```
Authorization: Bearer <token>
```

**Response (200 OK)**:
```json
{
  "status": "success",
  "short_code": "aB12cd",
  "short_url": "https://short.url/aB12cd",
  "long_url": "https://example.com/very/long/path?param=value&other=123",
  "user_id": "550e8400-e29b-41d4-a716-446655440000",
  "created_at": "2024-11-24T21:12:59Z",
  "expires_at": "2024-12-24T21:12:59Z",
  "clicks": 1247,
  "last_clicked": "2024-11-24T21:11:00Z",
  "is_custom": false,
  "is_expired": false,
  "title": "My Link",
  "tags": ["marketing"]
}
```

**Error Responses**:
- `401 Unauthorized`: No token provided
- `403 Forbidden`: User does not own this link
- `404 Not Found`: Link does not exist

---

## 3. Redirect Endpoint (Core API)

### 3.1 Get Redirect
**Purpose**: Follow the short URL to the target. This is the user-facing endpoint (highest traffic).

**Endpoint**: `GET /{code}`

**Query Parameters** (optional, for tracking):
```
?utm_source=email&utm_medium=newsletter&utm_campaign=sale
```

**Request Flow (Optimized)**:
1. Extract code from URL path
2. Query Redis: `GET code:aB12cd`
3. If hit: deserialize `longUrl|userId|expiresAt`, validate expiry, emit click async
4. If miss:
   - Query primary DB: `SELECT long_url, expires_at FROM mappings WHERE code = ?`
   - If found: backfill Redis (TTL = max(remaining_ttl, 24h))
   - If not found: return 404
5. Emit click event to ClickQueue (non-blocking, with query params if present)
6. Return 302 (temporary redirect) with Location header

**Response (302 Found)**:
```
HTTP/1.1 302 Found
Location: https://example.com/very/long/path?param=value&other=123&utm_source=email&utm_medium=newsletter&utm_campaign=sale
Server: nginx
Cache-Control: no-cache, no-store, must-revalidate
Pragma: no-cache
Expires: 0
```

**Cache-Control Rationale**:
- `no-cache`: Prevents browser from caching (why? because short URLs can be edited or deleted)
- Do NOT use 301 (permanent): If you do, browsers cache the redirect, analytics break

**Error Responses**:
- `404 Not Found`: Code does not exist
- `410 Gone`: Link has expired
- `451 Unavailable For Legal Reasons`: Link marked as spam/phishing (takedown)
- `429 Too Many Requests`: Rate limit exceeded (1000 redirects/sec per code)

**Timing Budget**:
- Redis hit: <5ms (target: <1ms)
- Database miss: <50ms (target: <30ms)
- Total: <55ms (goal: respond before browser timeout)

---

## 4. Analytics Endpoints

### 4.1 Get Link Stats
**Purpose**: Retrieve click counts and referrer data for a short URL (authenticated, owner or admin).

**Endpoint**: `GET /api/v1/links/{code}/stats`

**Query Parameters**:
```
?from=2024-11-01&to=2024-11-30&group_by=day
```

**Response (200 OK)**:
```json
{
  "status": "success",
  "short_code": "aB12cd",
  "stats": {
    "total_clicks": 1247,
    "unique_visitors": 892,
    "last_clicked": "2024-11-24T21:11:00Z",
    "clicks_by_day": [
      {
        "date": "2024-11-24",
        "clicks": 143,
        "unique": 87
      },
      {
        "date": "2024-11-23",
        "clicks": 156,
        "unique": 92
      }
    ],
    "top_referrers": [
      {
        "referrer": "twitter.com",
        "clicks": 450
      },
      {
        "referrer": "facebook.com",
        "clicks": 320
      },
      {
        "referrer": "direct",
        "clicks": 150
      }
    ],
    "top_countries": [
      {
        "country": "US",
        "clicks": 600
      },
      {
        "country": "GB",
        "clicks": 300
      }
    ]
  }
}
```

**Data Source**:
- Real-time counts: Redis aggregated counters
- Historical stats: PostgreSQL analytics table (ClickEvents)
- Delay: up to 60 seconds for real-time, immediate for historical

**Error Responses**:
- `401 Unauthorized`: No token
- `403 Forbidden`: User does not own this link
- `404 Not Found`: Link does not exist

---

### 4.2 Get User Dashboard
**Purpose**: Retrieve all links and aggregate stats for authenticated user.

**Endpoint**: `GET /api/v1/links`

**Query Parameters**:
```
?page=1&limit=20&sort=clicks&order=desc&tag=marketing
```

**Response (200 OK)**:
```json
{
  "status": "success",
  "pagination": {
    "page": 1,
    "limit": 20,
    "total": 145,
    "pages": 8
  },
  "links": [
    {
      "short_code": "aB12cd",
      "short_url": "https://short.url/aB12cd",
      "long_url": "https://example.com/path1",
      "created_at": "2024-11-24T21:12:59Z",
      "clicks": 1247,
      "last_clicked": "2024-11-24T21:11:00Z",
      "is_custom": false,
      "title": "My Link",
      "tags": ["marketing"]
    },
    {
      "short_code": "my-link",
      "short_url": "https://short.url/my-link",
      "long_url": "https://example.com/path2",
      "created_at": "2024-11-23T10:00:00Z",
      "clicks": 892,
      "last_clicked": "2024-11-24T18:30:00Z",
      "is_custom": true,
      "title": "Campaign Q4",
      "tags": ["campaign", "2024"]
    }
  ],
  "aggregate_stats": {
    "total_links": 145,
    "total_clicks": 125643,
    "total_unique_visitors": 98123
  }
}
```

**Error Responses**:
- `401 Unauthorized`: No token

---

## 5. Link Management Endpoints

### 5.1 Update Link
**Purpose**: Edit title, tags, or expiry of a short URL (authenticated, owner only).

**Endpoint**: `PATCH /api/v1/links/{code}`

**Request**:
```json
{
  "title": "Updated Title",
  "tags": ["marketing", "updated"],
  "expires_at": "2024-12-31T23:59:59Z"
}
```

**Response (200 OK)**:
```json
{
  "status": "success",
  "short_code": "aB12cd",
  "title": "Updated Title",
  "tags": ["marketing", "updated"],
  "expires_at": "2024-12-31T23:59:59Z"
}
```

**Side Effects**:
- Database update to `mappings` table
- Redis invalidation: `DEL code:aB12cd` (forces next redirect to query DB and backfill)
- Audit log entry created

**Error Responses**:
- `401 Unauthorized`: No token
- `403 Forbidden`: User does not own this link
- `404 Not Found`: Link does not exist

---

### 5.2 Delete Link
**Purpose**: Permanently delete a short URL and all associated analytics.

**Endpoint**: `DELETE /api/v1/links/{code}`

**Response (204 No Content)**:
```
HTTP/1.1 204 No Content
```

**Side Effects**:
- Database delete from `mappings` table
- Redis invalidation: `DEL code:aB12cd`
- Analytics data archived (not deleted for compliance)
- Audit log entry created

**Error Responses**:
- `401 Unauthorized`: No token
- `403 Forbidden`: User does not own this link
- `404 Not Found`: Link does not exist

---

## 6. Admin Endpoints

### 6.1 Takedown Request
**Purpose**: Report a link as phishing, malware, or abusive (admin or moderation queue).

**Endpoint**: `POST /api/v1/admin/takedown`

**Request** (public, no auth required):
```json
{
  "short_code": "aB12cd",
  "reason": "phishing",
  "description": "Link pretends to be PayPal login"
}
```

**Reason Values**: `phishing`, `malware`, `spam`, `copyright`, `harassment`, `other`

**Response (202 Accepted)**:
```json
{
  "status": "success",
  "message": "Takedown request submitted for review",
  "ticket_id": "TKD-20241124-001"
}
```

**Workflow**:
1. Insert into `takedown_requests` table (code, reason, description, submitted_at, status=pending)
2. Emit event to moderation queue
3. Human reviewer inspects link (async)
4. If approved:
   - Set link status to `takedown` in DB
   - Return 451 (Unavailable For Legal Reasons) for all future redirects
   - Notify link owner
5. If rejected:
   - Set status to `rejected`, notify requester

**Error Responses**:
- `400 Bad Request`: Invalid reason
- `404 Not Found`: Code does not exist
- `429 Too Many Requests`: Takedown limit exceeded (5 per IP per day)

---

### 6.2 Admin List Users
**Purpose**: List all users (admin only, requires elevated token with `admin` scope).

**Endpoint**: `GET /api/v1/admin/users`

**Headers**:
```
Authorization: Bearer <admin-token>
X-Admin-Token: <elevated-token>
```

**Response (200 OK)**:
```json
{
  "status": "success",
  "users": [
    {
      "user_id": "550e8400-e29b-41d4-a716-446655440000",
      "email": "user@example.com",
      "created_at": "2024-11-01T00:00:00Z",
      "total_links": 145,
      "total_clicks": 125643,
      "last_active": "2024-11-24T21:11:00Z",
      "is_suspended": false
    }
  ]
}
```

**Error Responses**:
- `401 Unauthorized`: No token
- `403 Forbidden`: User is not admin

---

## 7. Health & Monitoring Endpoints

### 7.1 Health Check
**Purpose**: Monitor service health (used by load balancer).

**Endpoint**: `GET /health`

**Response (200 OK)**:
```json
{
  "status": "ok",
  "timestamp": "2024-11-24T21:12:59Z",
  "checks": {
    "redis": "ok",
    "database": "ok",
    "keyservice": "ok",
    "queue": "ok"
  }
}
```

**Load Balancer Behavior**:
- If `status != "ok"`: return 503, remove from rotation
- Check interval: 10 seconds
- Timeout: 5 seconds

---

### 7.2 Metrics
**Purpose**: Prometheus-compatible metrics (internal use).

**Endpoint**: `GET /metrics`

**Response (200 OK, text/plain)**:
```
# HELP shorturl_redirects_total Total number of redirects
# TYPE shorturl_redirects_total counter
shorturl_redirects_total{code="aB12cd"} 1247

# HELP shorturl_creates_total Total number of links created
# TYPE shorturl_creates_total counter
shorturl_creates_total 12543

# HELP shorturl_cache_hits_total Cache hits
# TYPE shorturl_cache_hits_total counter
shorturl_cache_hits_total 125000

# HELP shorturl_cache_misses_total Cache misses
# TYPE shorturl_cache_misses_total counter
shorturl_cache_misses_total 1200

# HELP shorturl_response_time_seconds Response time
# TYPE shorturl_response_time_seconds histogram
shorturl_response_time_seconds_bucket{le="0.001"} 125000
shorturl_response_time_seconds_bucket{le="0.01"} 125900
shorturl_response_time_seconds_bucket{le="0.1"} 126100
shorturl_response_time_seconds_bucket{le="+Inf"} 126500
```

---

## Error Handling & Status Codes

### Standard Error Response
```json
{
  "status": "error",
  "error": "invalid_request",
  "message": "Long URL is required",
  "timestamp": "2024-11-24T21:12:59Z",
  "request_id": "req-550e8400-e29b-41d4-a716-446655440000"
}
```

### HTTP Status Codes Used
| Code | Meaning | Example |
|------|---------|---------|
| **200** | OK | Health check passes, stats retrieved |
| **201** | Created | Short URL created |
| **202** | Accepted | Takedown request queued |
| **204** | No Content | Link deleted |
| **302** | Found | Redirect to target URL |
| **400** | Bad Request | Invalid URL format |
| **401** | Unauthorized | Missing/invalid token |
| **403** | Forbidden | User not authorized |
| **404** | Not Found | Link does not exist |
| **409** | Conflict | Custom alias taken |
| **410** | Gone | Link expired |
| **429** | Too Many Requests | Rate limit exceeded |
| **451** | Unavailable (Legal) | Link taken down |
| **503** | Service Unavailable | KeyService offline |

---

## Rate Limiting

### Per IP
- Redirects: 10,000/min (basically unlimited for single user)
- Creates: 100/min
- Login attempts: 5 failed attempts → locked 15 min

### Per Authenticated User
- Creates: 1,000/day
- Custom aliases: 10/day
- API calls: 100,000/day

### Response Headers
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1700086400
Retry-After: 73
```

---

## Authentication Strategy

### JWT Structure
```
Header: { "alg": "HS256", "typ": "JWT" }
Payload: {
  "user_id": "550e8400-e29b-41d4-a716-446655440000",
  "email": "user@example.com",
  "scope": ["read", "write"],
  "iat": 1700000000,
  "exp": 1700086400
}
Signature: HMAC-SHA256(base64(header) + "." + base64(payload), secret)
```

### Bearer Token Usage
```
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyX2lkIjoiNTUwZTg0MDAtZTI5Yi00MWQ0LWE3MTYtNDQ2NjU1NDQwMDAwIiwiaWF0IjoxNzAwMDAwMDAwLCJleHAiOjE3MDAwODY0MDB9.signature
```

---

## Request/Response Best Practices

### CORS Headers (from /shorten, GET)
```
Access-Control-Allow-Origin: https://trusted-domain.com
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization
Access-Control-Max-Age: 86400
```

### Request ID Tracking
Every response includes:
```
X-Request-ID: req-550e8400-e29b-41d4-a716-446655440000
```
Used for debugging and audit logs.

### Response Compression
- All responses: gzip compression if Accept-Encoding: gzip
- Threshold: 1KB (compress anything larger)

---

## Idempotency

### Create Link Idempotency
If the same long_url is submitted twice by the same user within 60 seconds, return the existing short code (not a duplicate).

**Idempotency Key Header** (optional, recommended):
```
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

If provided and a request with same key was processed in last 24h, return cached response (201 or 400, same as before).

---

## Webhook Events (Future Enhancement)

For users who want real-time notifications:

```
POST https://user-webhook-url.com/shorturl
{
  "event": "link_clicked",
  "short_code": "aB12cd",
  "timestamp": "2024-11-24T21:12:59Z",
  "country": "US",
  "referrer": "twitter.com",
  "user_agent": "Mozilla/5.0..."
}
```

Retry policy: exponential backoff, 5 retries over 24 hours.

---

## Summary Table: All Endpoints

| Method | Endpoint | Purpose | Auth | Rate Limit |
|--------|----------|---------|------|-----------|
| POST | /api/v1/auth/register | Create account | No | 10/hour |
| POST | /api/v1/auth/login | Login | No | 5 failed/15min |
| POST | /api/v1/auth/refresh | Refresh token | Yes | 10/token lifetime |
| **POST** | **/api/v1/shorten** | **Create short URL** | **Optional** | **100/min** |
| **GET** | **/{code}** | **Redirect** | **No** | **10,000/min** |
| GET | /api/v1/links/{code} | Get link info | Yes | 1000/min |
| GET | /api/v1/links/{code}/stats | Get analytics | Yes | 1000/min |
| GET | /api/v1/links | List user links | Yes | 1000/min |
| PATCH | /api/v1/links/{code} | Update link | Yes | 100/min |
| DELETE | /api/v1/links/{code} | Delete link | Yes | 100/min |
| POST | /api/v1/admin/takedown | Report link | No | 5/IP/day |
| GET | /api/v1/admin/users | List users | Yes (admin) | 100/min |
| GET | /health | Health check | No | Unlimited |
| GET | /metrics | Metrics | No | Unlimited |

