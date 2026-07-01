# Multi-Tenant API Manager — Project Documentation

> **[TR]** Bu doküman projenin tamamını hem Türkçe hem İngilizce anlatır.  
> **[EN]** This document covers the entire project in both Turkish and English.

---

## İçindekiler / Table of Contents

- [Türkçe Dokümantasyon](#-türkçe-dokümantasyon)
  - [Proje Nedir?](#proje-nedir)
  - [Sistem Mimarisi](#sistem-mimarisi)
  - [Nasıl Çalışır?](#nasıl-çalışır)
  - [Klasör Yapısı](#klasör-yapısı)
  - [Veritabanı Şeması](#veritabanı-şeması)
  - [Redis Veri Modeli](#redis-veri-modeli)
  - [Kurulum](#kurulum)
  - [Kullanım](#kullanım)
  - [Mimari Kararlar](#mimari-kararlar)
- [English Documentation](#-english-documentation)
  - [What Is This Project?](#what-is-this-project)
  - [System Architecture](#system-architecture)
  - [How It Works](#how-it-works)
  - [Folder Structure](#folder-structure)
  - [Database Schema](#database-schema)
  - [Redis Data Model](#redis-data-model)
  - [Installation](#installation)
  - [Usage](#usage)
  - [Architectural Decisions](#architectural-decisions)

---

## 🇹🇷 Türkçe Dokümantasyon

### Proje Nedir?

**Multi-Tenant API Manager**, birden fazla müşteriye (tenant) aynı anda hizmet verebilen, **kurumsal düzeyde** bir API yönetim katmanıdır. Temel işlevi şudur:

- Her müşteriye (tenant) bir veya birden fazla **API anahtarı** üretmek
- Gelen her isteği bu anahtarla **kimlik doğrulamak**
- Her anahtar için **hız sınırlaması** (rate limiting) uygulamak
- Tüm API kullanımını **asenkron olarak loglamak**

SaaS ürünler için "API Gateway" katmanı olarak kullanılmak üzere tasarlanmıştır. Örnek kullanım senaryoları:

- Bir SaaS platformu müşterilerine API erişimi satıyor ve her müşterinin kaç istek yapabileceğini kontrol etmek istiyor
- Bir mobil uygulama backend'i, farklı organizasyonlara farklı istek limitleriyle hizmet veriyor
- Bir B2B API servisi, hangi müşterinin hangi endpoint'leri ne kadar kullandığını izliyor

---

### Sistem Mimarisi

```
                        ┌─────────────────────────────────────────────────────┐
                        │                    HTTP Request                      │
                        │              X-API-KEY: mtam_<uuid>_<hash>           │
                        └───────────────────────┬─────────────────────────────┘
                                                │
                        ┌───────────────────────▼─────────────────────────────┐
                        │             Nginx (Port 8080)                        │
                        │          Reverse Proxy / Static Files                │
                        └───────────────────────┬─────────────────────────────┘
                                                │
                        ┌───────────────────────▼─────────────────────────────┐
                        │         ApiGatewayGuardMiddleware                    │
                        │                                                      │
                        │  1. Token'ı SHA-256 ile hash'le                     │
                        │  2. Redis'ten cache'e bak (HGETALL)                 │
                        │  3. Tenant ve key durumunu kontrol et                │
                        │  4. Sliding-window rate limit uygula (Lua/EVAL)     │
                        │  5. İsteği ilet → Response al                       │
                        │  6. terminate() → Async log job dispatch            │
                        └──────────┬──────────────────┬───────────────────────┘
                                   │                  │
               ┌───────────────────▼───┐    ┌─────────▼──────────────────────┐
               │   Redis (Port 6379)    │    │   MySQL (Port 3306)            │
               │                        │    │                                │
               │  api_keys:{hash}       │    │  tenants                       │
               │    → HSET payload      │    │  api_keys                      │
               │                        │    │  api_usage_logs                │
               │  rate_limit:{t}:{k}    │    │                                │
               │    → ZSET sliding win  │    └────────────────────────────────┘
               │                        │
               │  api_usage_logs:buffer │
               │    → LIST async buffer │
               └───────────────────────┘
                           │
               ┌───────────▼───────────────────────────────────────────────┐
               │            ProcessApiUsageLogs Job (Queue Worker)          │
               │                                                            │
               │  LPUSH → buffer'a ekle                                     │
               │  LLEN  → batch boyutu doldu mu?                           │
               │  RPOP  → toplu çek (pipeline)                             │
               │  bulkInsert() → MySQL'e tek seferde yaz                   │
               └───────────────────────────────────────────────────────────┘
```

---

### Nasıl Çalışır?

#### 1. API Anahtarı Üretimi

`ApiKeyService::create()` çağrıldığında şu adımlar gerçekleşir:

```
TokenGenerator::generate()
    │
    ├─ key_id   = prefix + UUID (örn: "mtam_a1b2c3d4")  ← plain text, görüntüleme amaçlı
    ├─ secret   = 32 byte CSPRNG (kriptografik rasgele)
    ├─ plain    = key_id + "_" + hex(secret)              ← SADECE BİR KEZ gösterilir
    └─ hash     = SHA-256(plain)                          ← veritabanında saklanır
```

> **Güvenlik notu:** Plain token hiçbir zaman veritabanına yazılmaz. Sadece oluşturulurken kullanıcıya gösterilir. Tıpkı GitHub Personal Access Token mantığı gibi.

#### 2. İstek Kimlik Doğrulama (Hot Path)

Middleware her gelen istekte şu sırayla işlem yapar:

```
1. Header oku: X-API-KEY: mtam_uuid_hex
2. SHA-256 hash hesapla (PHP, ~1μs)
3. Redis HGETALL api_keys:{hash} → ApiKeyCachePayload
   ├─ Miss → 401 Unauthorized (MySQL'e HİÇ bakılmaz)
   └─ Hit  → payload.status kontrol et
4. Status "revoked" veya "expired" → 401
5. Redis EVAL (Lua script) → sliding-window rate limit
   ├─ Exceeded → 429 Too Many Requests + Retry-After header
   └─ Allowed  → isteği uygulamaya ilet
6. Response'u döndür (response time ölç)
7. terminate() → ProcessApiUsageLogs.dispatchAfterResponse()
```

**Kritik tasarım kararı:** Hot path'te (5–6 arası) **MySQL'e hiç erişilmez.** Tüm auth ve rate-limit kararları Redis'ten alınır. Bu, her isteğin ortalama **< 2ms** ek gecikmeyle işlenmesini sağlar.

#### 3. Rate Limiting (Atomik Lua Script)

Race condition'ı önlemek için `ZREMRANGEBYSCORE → ZCARD → ZADD` işlemleri tek bir **atomik Lua script** içinde çalışır:

```lua
-- Pencerenin dışına çıkan kayıtları temizle
redis.call('ZREMRANGEBYSCORE', key, '-inf', now_ms - window_ms)

-- Şu anki penceredeki istek sayısını oku
local count = tonumber(redis.call('ZCARD', key))

-- Limit aşıldı mı?
if count >= limit then
    local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
    local retry_after_ms = tonumber(oldest[2]) + window_ms - now_ms
    return {0, 0, math.ceil(retry_after_ms / 1000)}
end

-- Bu isteği kaydet
redis.call('ZADD', key, now_ms, member)
redis.call('PEXPIRE', key, window_ms)
return {1, limit - count - 1, 0}
```

**Neden Lua?** ZREMRANGEBYSCORE ile ZCARD arasında iki ayrı komut olsaydı, iki eşzamanlı istek aynı sayıyı okuyup ikisi de geçebilirdi (TOCTOU race condition). Lua, Redis'in single-thread event loop'unda atomik olarak çalışır.

#### 4. Asenkron Log Yazımı

İstemciye yanıt döndükten **sonra** (HTTP bağlantısı kapatıldıktan sonra) şu akış çalışır:

```
terminate() tetikler
    │
    ▼
ProcessApiUsageLogs::dispatchAfterResponse()
    │
    ▼
Job Worker alır:
    1. LPUSH api_usage_logs:buffer → log entry'yi Redis listesine ekle
    2. LLEN → buffer 50 entry'ye (batch_size) ulaştı mı?
    3. Evet → pipeline ile 50x RPOP → entries[]
    4. bulkInsert(entries) → MySQL'e TEK sorguda yaz
```

**Neden bu yaklaşım?** MySQL yazma işlemi her istek için ~5–20ms ek gecikme yaratır. Asenkron buffering ile bu maliyet sıfırlanır. 50 log tek bir INSERT ile yazılır.

---

### Klasör Yapısı

```
app/Domain/Api/Gateway/
│
├── Config/
│   └── GatewayConfig.php          # Config DTO — tüm ayarları typed olarak sarmalar
│
├── Contracts/
│   ├── ApiKeyRepositoryInterface.php       # Eloquent bağımsız repo sözleşmesi
│   └── ApiUsageLogRepositoryInterface.php  # Log repo sözleşmesi
│
├── Data/                           # Immutable value objects (readonly)
│   ├── ApiKeyCachePayload.php     # Redis hash ↔ PHP arasındaki köprü DTO
│   ├── ApiUsageLogEntry.php       # Tek bir log kaydının temsili
│   ├── CreatedApiKeyResult.php    # ApiKeyService::create() dönüş tipi
│   ├── GeneratedToken.php         # TokenGenerator dönüş tipi
│   └── RateLimitResult.php        # Lua eval sonuç DTO'su
│
├── Database/
│   └── Migrations/
│       ├── 2026_06_22_000001_create_tenants_table.php
│       ├── 2026_06_22_000002_create_api_keys_table.php
│       └── 2026_06_22_000003_create_api_usage_logs_table.php
│
├── Enums/
│   ├── TenantStatus.php           # active | suspended | deleted
│   └── ApiKeyStatus.php           # active | revoked | expired
│
├── Http/
│   └── Middleware/
│       └── ApiGatewayGuardMiddleware.php  # Ana kapı bekçisi
│
├── Jobs/
│   └── ProcessApiUsageLogs.php    # Async log buffer + bulk insert worker
│
├── Models/
│   ├── Tenant.php                 # getTable() → config'den okur
│   ├── ApiKey.php                 # getTable() → config'den okur
│   └── ApiUsageLog.php            # getTable() → config'den okur
│
├── Observers/
│   └── ApiKeyObserver.php         # created/updated/deleted → Redis cache sync
│
├── Providers/
│   └── GatewayServiceProvider.php # Tüm binding'leri ve route'ları kaydeder
│
├── Repositories/
│   ├── EloquentApiKeyRepository.php
│   └── EloquentApiUsageLogRepository.php
│
├── Services/
│   ├── ApiKeyCacheService.php        # Redis HSET/HGETALL/DEL wrapper
│   ├── ApiKeyService.php             # Ana iş mantığı (create, revoke, list)
│   └── SlidingWindowRateLimiter.php  # Atomik Lua rate limiter
│
└── Support/
    ├── RedisKeyBuilder.php        # Tüm Redis key isimleri tek yerden
    └── TokenGenerator.php         # CSPRNG token üretimi
```

---

### Veritabanı Şeması

#### `tenants` tablosu
| Kolon | Tip | Açıklama |
|-------|-----|----------|
| `id` | UUID (PK) | Tenant benzersiz kimliği |
| `name` | VARCHAR(255) | Tenant adı |
| `status` | ENUM | `active` / `suspended` / `deleted` |
| `created_at` | TIMESTAMP | Oluşturulma tarihi |
| `updated_at` | TIMESTAMP | Son güncelleme tarihi |

#### `api_keys` tablosu
| Kolon | Tip | Açıklama |
|-------|-----|----------|
| `id` | BIGINT AUTO_INCREMENT (PK) | Sayısal birincil anahtar |
| `tenant_id` | UUID (FK) | Bağlı tenant |
| `name` | VARCHAR(255) | Anahtara verilen isim |
| `key_id` | VARCHAR(64) | Token'ın public kısmı (görüntüleme) |
| `key_hash` | CHAR(64) | SHA-256 hash (auth için) |
| `rate_limit_max` | SMALLINT | Penceredeki maks istek sayısı |
| `rate_limit_window` | SMALLINT | Pencere süresi (saniye) |
| `status` | ENUM | `active` / `revoked` / `expired` |
| `expires_at` | TIMESTAMP NULL | Opsiyonel son kullanma tarihi |
| `created_at` | TIMESTAMP | Oluşturulma tarihi |

#### `api_usage_logs` tablosu
| Kolon | Tip | Açıklama |
|-------|-----|----------|
| `id` | BIGINT AUTO_INCREMENT (PK) | Log satır ID'si |
| `tenant_id` | UUID | Hangi tenant'a ait |
| `api_key_id` | BIGINT | Hangi anahtarla yapıldı |
| `endpoint` | VARCHAR(500) | İstek yapılan URL yolu |
| `method` | VARCHAR(10) | HTTP metodu |
| `status_code` | SMALLINT | Dönen HTTP kodu |
| `response_time_ms` | INT | Yanıt süresi (ms) |
| `ip_address` | VARCHAR(45) | İstemci IP (IPv6 destekli) |
| `created_at` | TIMESTAMP | Kaydedilme tarihi |

> `api_usage_logs` tablosunda kasıtlı olarak **Foreign Key constraint yoktur.** Bu, MySQL yazım gecikmesini azaltır ve tablonun yüksek hacimli insert'lere dayanmasını sağlar.

---

### Redis Veri Modeli

```
# API Key Cache (HSET)
api_keys:{sha256_of_token}
    tenant_id        → "550e8400-e29b-..."
    api_key_id       → "42"
    rate_limit_max   → "100"
    rate_limit_window → "60"
    status           → "active"
    TTL: 3600s (varsayılan)

# Rate Limit Sorted Set (Sliding Window Log)
rate_limit:{tenant_id}:{api_key_id}
    Members: "{timestamp_ms}:{random_hex}"
    Scores:  Unix timestamp (ms)
    TTL: window_ms (otomatik silinir)

# Async Log Buffer (List)
api_usage_logs:buffer
    Yön: LPUSH (sol) → RPOP (sağ) = FIFO
    İçerik: JSON-encoded ApiUsageLogEntry nesneleri
```

---

### Kurulum

#### Gereksinimler
- Docker & Docker Compose
- PHP 8.3+ (container içinde)
- MySQL 8.0+
- Redis 7+

#### 1. Projeyi klonlayın
```bash
git clone <repo-url>
cd multi-tenant-api-manager
```

#### 2. Environment dosyasını oluşturun
```bash
cp .env.example .env
# .env içindeki değerleri Docker servis adlarına göre düzenleyin
```

#### 3. Docker container'larını başlatın
```bash
docker-compose up -d
```

#### 4. Bağımlılıkları yükleyin
```bash
docker exec laravel_app composer install
```

#### 5. Uygulama anahtarını oluşturun
```bash
docker exec laravel_app php artisan key:generate
```

#### 6. Migration'ları çalıştırın
```bash
docker exec laravel_app php artisan migrate
```

#### 7. Queue worker'ı başlatın
```bash
docker exec laravel_app php artisan queue:work redis \
  --queue=api-analytics-processing \
  --tries=3
```

#### Testleri çalıştırın
```bash
# Test veritabanını oluşturun (bir kez):
docker exec laravel_mysql mysql -uroot \
  -e "CREATE DATABASE IF NOT EXISTS multi_tenant_api_test; \
      GRANT ALL PRIVILEGES ON multi_tenant_api_test.* TO 'laravel'@'%';"

# Testleri çalıştırın:
docker exec laravel_app php artisan test --filter=ApiGatewayGuardMiddlewareTest
```

---

### Kullanım

#### API Anahtarı Oluşturma (Programmatic)
```php
// Container'dan çözümle
$service = app(ApiKeyService::class);

$result = $service->create(
    tenantId:        'tenant-uuid-here',
    name:            'Production Key',
    rateLimitMax:    1000,    // dakikada maks istek
    rateLimitWindow: 60,      // 60 saniyelik pencere
);

echo $result->plainToken; // Sadece bir kez! Sakla.
// Örnek: "mtam_a1b2c3d4-..._ff3a9b..."
```

#### API'ye İstek Gönderme
```bash
curl -H "X-API-KEY: mtam_a1b2c3d4-..._ff3a9b..." \
     http://localhost:8080/api/health
```

#### Başarılı Yanıt
```json
HTTP/1.1 200 OK
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999

{"status": "ok"}
```

#### Hata Yanıtları
```json
# 401 — Eksik header
{"error": "missing_api_key", "message": "..."}

# 401 — Geçersiz / revoke edilmiş key
{"error": "invalid_api_key", "message": "..."}

# 429 — Rate limit aşıldı
HTTP/1.1 429 Too Many Requests
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 0
Retry-After: 47

{"error": "rate_limit_exceeded", "retry_after": 47}
```

---

### Mimari Kararlar

| Karar | Gerekçe |
|-------|---------|
| **Redis-first, MySQL yasak (hot path)** | MySQL bağlantısı her istek için 5–20ms gecikme yaratır. Redis P99 < 1ms. |
| **SHA-256 hash, plain token saklanmaz** | Veritabanı sızıntısında token'lar kullanılamaz hale gelir. |
| **Atomik Lua script** | ZREMRANGEBYSCORE+ZCARD arasındaki TOCTOU race condition'ı sıfırlar. |
| **terminate() ile async log** | HTTP bağlantısı kapatıldıktan sonra çalışır — istemci gecikme hissetmez. |
| **Redis List buffer + bulk insert** | 50 log tek INSERT → MySQL I/O %98 azalır. |
| **`config('api-gateway.tables.*')` ile loose coupling** | Tablo isimleri migration'a hard-code edilmez; future package olarak çıkartılabilir. |
| **Contracts (Interface) katmanı** | Eloquent bağımlılığı tersine çevrilir; test isolation ve mock'lama kolaylaşır. |
| **`readonly` DTO'lar** | Veri nesneleri immutable; mutation bug'ları compile-time'da yakalanır. |

---

---

## 🇬🇧 English Documentation

### What Is This Project?

**Multi-Tenant API Manager** is an enterprise-grade API management layer capable of serving multiple customers (tenants) simultaneously. Its core responsibilities are:

- Generating one or more **API keys** per tenant
- **Authenticating** every incoming request against those keys
- Enforcing **rate limiting** per key using a sliding-window algorithm
- **Asynchronously logging** all API usage with zero impact on response latency

It is designed as an "API Gateway" layer for SaaS products. Example use cases:

- A SaaS platform that sells API access to customers and wants to control how many requests each customer can make
- A mobile app backend serving different organizations with different rate limits
- A B2B API service that needs to track which customer uses which endpoint and how often

---

### System Architecture

```
                        ┌─────────────────────────────────────────────────────┐
                        │                    HTTP Request                      │
                        │              X-API-KEY: mtam_<uuid>_<hash>           │
                        └───────────────────────┬─────────────────────────────┘
                                                │
                        ┌───────────────────────▼─────────────────────────────┐
                        │             Nginx (Port 8080)                        │
                        │          Reverse Proxy / Static Files                │
                        └───────────────────────┬─────────────────────────────┘
                                                │
                        ┌───────────────────────▼─────────────────────────────┐
                        │         ApiGatewayGuardMiddleware                    │
                        │                                                      │
                        │  1. Hash the token with SHA-256                      │
                        │  2. Look up Redis cache (HGETALL)                   │
                        │  3. Check tenant and key status                      │
                        │  4. Apply sliding-window rate limit (Lua/EVAL)      │
                        │  5. Forward request → receive response               │
                        │  6. terminate() → dispatch async log job            │
                        └──────────┬──────────────────┬───────────────────────┘
                                   │                  │
               ┌───────────────────▼───┐    ┌─────────▼──────────────────────┐
               │   Redis (Port 6379)    │    │   MySQL (Port 3306)            │
               │                        │    │                                │
               │  api_keys:{hash}       │    │  tenants                       │
               │    → HSET payload      │    │  api_keys                      │
               │                        │    │  api_usage_logs                │
               │  rate_limit:{t}:{k}    │    │                                │
               │    → ZSET sliding win  │    └────────────────────────────────┘
               │                        │
               │  api_usage_logs:buffer │
               │    → LIST async buffer │
               └───────────────────────┘
                           │
               ┌───────────▼───────────────────────────────────────────────┐
               │            ProcessApiUsageLogs Job (Queue Worker)          │
               │                                                            │
               │  LPUSH → add to buffer                                     │
               │  LLEN  → has batch_size entries been reached?              │
               │  RPOP  → drain in bulk (pipeline)                         │
               │  bulkInsert() → single MySQL INSERT for all entries        │
               └───────────────────────────────────────────────────────────┘
```

---

### How It Works

#### 1. API Key Generation

When `ApiKeyService::create()` is called, the following steps occur:

```
TokenGenerator::generate()
    │
    ├─ key_id   = prefix + UUID  (e.g. "mtam_a1b2c3d4")  ← plain text, display only
    ├─ secret   = 32 bytes CSPRNG (cryptographically random)
    ├─ plain    = key_id + "_" + hex(secret)               ← shown ONCE to the user
    └─ hash     = SHA-256(plain)                           ← stored in database
```

> **Security note:** The plain token is never stored in the database. It is only shown once at creation time — similar to how GitHub Personal Access Tokens work.

#### 2. Request Authentication (Hot Path)

The middleware processes each incoming request in this exact order:

```
1. Read header: X-API-KEY: mtam_uuid_hex
2. Compute SHA-256 hash (PHP, ~1μs)
3. Redis HGETALL api_keys:{hash} → ApiKeyCachePayload
   ├─ Miss → 401 Unauthorized (MySQL is NEVER consulted)
   └─ Hit  → check payload.status
4. Status "revoked" or "expired" → 401
5. Redis EVAL (Lua script) → sliding-window rate limit
   ├─ Exceeded → 429 Too Many Requests + Retry-After header
   └─ Allowed  → forward request to application
6. Return response (measure response time)
7. terminate() → ProcessApiUsageLogs.dispatchAfterResponse()
```

**Critical design decision:** The hot path **never touches MySQL**. All authentication and rate-limit decisions come from Redis. This keeps the per-request overhead below **< 2ms** on average.

#### 3. Rate Limiting (Atomic Lua Script)

To eliminate race conditions, the `ZREMRANGEBYSCORE → ZCARD → ZADD` sequence runs inside a single **atomic Lua script**:

```lua
-- Evict entries that have slid out of the current window
redis.call('ZREMRANGEBYSCORE', key, '-inf', now_ms - window_ms)

-- Count requests currently in the window
local count = tonumber(redis.call('ZCARD', key))

-- Enforce the ceiling
if count >= limit then
    local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
    local retry_after_ms = tonumber(oldest[2]) + window_ms - now_ms
    return {0, 0, math.ceil(retry_after_ms / 1000)}
end

-- Record this request
redis.call('ZADD', key, now_ms, member)
redis.call('PEXPIRE', key, window_ms)
return {1, limit - count - 1, 0}
```

**Why Lua?** If ZREMRANGEBYSCORE and ZCARD were separate commands, two concurrent requests could read the same count and both succeed when only one should (TOCTOU race condition). Lua scripts run atomically inside Redis's single-threaded event loop.

#### 4. Asynchronous Log Writing

**After** the response is sent to the client (after the HTTP connection is closed), the following flow executes:

```
terminate() fires
    │
    ▼
ProcessApiUsageLogs::dispatchAfterResponse()
    │
    ▼
Job Worker picks it up:
    1. LPUSH api_usage_logs:buffer → push log entry to Redis list
    2. LLEN → has buffer reached batch_size (50)?
    3. Yes → pipeline 50x RPOP → entries[]
    4. bulkInsert(entries) → single MySQL INSERT
```

**Why this approach?** MySQL writes add ~5–20ms of latency per request. Async buffering reduces this cost to zero from the client's perspective. 50 log entries are written in a single INSERT statement.

---

### Folder Structure

```
app/Domain/Api/Gateway/
│
├── Config/
│   └── GatewayConfig.php          # Config DTO — wraps all settings as typed properties
│
├── Contracts/
│   ├── ApiKeyRepositoryInterface.php       # Eloquent-agnostic repository contract
│   └── ApiUsageLogRepositoryInterface.php  # Log repository contract
│
├── Data/                           # Immutable value objects (readonly)
│   ├── ApiKeyCachePayload.php     # Bridge DTO between Redis hash and PHP
│   ├── ApiUsageLogEntry.php       # Represents one log record
│   ├── CreatedApiKeyResult.php    # Return type of ApiKeyService::create()
│   ├── GeneratedToken.php         # Return type of TokenGenerator
│   └── RateLimitResult.php        # Lua eval result DTO
│
├── Database/
│   └── Migrations/
│       ├── 2026_06_22_000001_create_tenants_table.php
│       ├── 2026_06_22_000002_create_api_keys_table.php
│       └── 2026_06_22_000003_create_api_usage_logs_table.php
│
├── Enums/
│   ├── TenantStatus.php           # active | suspended | deleted
│   └── ApiKeyStatus.php           # active | revoked | expired
│
├── Http/
│   └── Middleware/
│       └── ApiGatewayGuardMiddleware.php  # The main gatekeeper
│
├── Jobs/
│   └── ProcessApiUsageLogs.php    # Async log buffer + bulk insert worker
│
├── Models/
│   ├── Tenant.php                 # getTable() reads from config
│   ├── ApiKey.php                 # getTable() reads from config
│   └── ApiUsageLog.php            # getTable() reads from config
│
├── Observers/
│   └── ApiKeyObserver.php         # created/updated/deleted → Redis cache sync
│
├── Providers/
│   └── GatewayServiceProvider.php # Registers all bindings and routes
│
├── Repositories/
│   ├── EloquentApiKeyRepository.php
│   └── EloquentApiUsageLogRepository.php
│
├── Services/
│   ├── ApiKeyCacheService.php        # Redis HSET/HGETALL/DEL wrapper
│   ├── ApiKeyService.php             # Core business logic (create, revoke, list)
│   └── SlidingWindowRateLimiter.php  # Atomic Lua rate limiter
│
└── Support/
    ├── RedisKeyBuilder.php        # Single source of truth for all Redis key names
    └── TokenGenerator.php         # CSPRNG token generation
```

---

### Database Schema

#### `tenants` table
| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID (PK) | Unique tenant identifier |
| `name` | VARCHAR(255) | Tenant display name |
| `status` | ENUM | `active` / `suspended` / `deleted` |
| `created_at` | TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | Last update timestamp |

#### `api_keys` table
| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT AUTO_INCREMENT (PK) | Numeric primary key |
| `tenant_id` | UUID (FK) | Owning tenant |
| `name` | VARCHAR(255) | Human-readable key label |
| `key_id` | VARCHAR(64) | Public part of the token (display only) |
| `key_hash` | CHAR(64) | SHA-256 hash (used for auth) |
| `rate_limit_max` | SMALLINT | Max requests per window |
| `rate_limit_window` | SMALLINT | Window duration in seconds |
| `status` | ENUM | `active` / `revoked` / `expired` |
| `expires_at` | TIMESTAMP NULL | Optional expiry date |
| `created_at` | TIMESTAMP | Creation timestamp |

#### `api_usage_logs` table
| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT AUTO_INCREMENT (PK) | Log row ID |
| `tenant_id` | UUID | Which tenant |
| `api_key_id` | BIGINT | Which key was used |
| `endpoint` | VARCHAR(500) | Requested URL path |
| `method` | VARCHAR(10) | HTTP method |
| `status_code` | SMALLINT | HTTP response code |
| `response_time_ms` | INT | Response time in milliseconds |
| `ip_address` | VARCHAR(45) | Client IP (IPv6-compatible) |
| `created_at` | TIMESTAMP | Log entry timestamp |

> The `api_usage_logs` table deliberately has **no foreign key constraints** to minimize MySQL write latency and support high-volume insert throughput.

---

### Redis Data Model

```
# API Key Cache (HSET)
api_keys:{sha256_of_token}
    tenant_id         → "550e8400-e29b-..."
    api_key_id        → "42"
    rate_limit_max    → "100"
    rate_limit_window → "60"
    status            → "active"
    TTL: 3600s (default)

# Rate Limit Sorted Set (Sliding Window Log)
rate_limit:{tenant_id}:{api_key_id}
    Members: "{timestamp_ms}:{random_hex}"
    Scores:  Unix timestamp (ms)
    TTL: window_ms (auto-expires)

# Async Log Buffer (List)
api_usage_logs:buffer
    Direction: LPUSH (left) → RPOP (right) = FIFO queue
    Content: JSON-encoded ApiUsageLogEntry objects
```

---

### Installation

#### Requirements
- Docker & Docker Compose
- PHP 8.3+ (inside container)
- MySQL 8.0+
- Redis 7+

#### 1. Clone the repository
```bash
git clone <repo-url>
cd multi-tenant-api-manager
```

#### 2. Create the environment file
```bash
cp .env.example .env
# Edit values to match your Docker service names
```

#### 3. Start Docker containers
```bash
docker-compose up -d
```

#### 4. Install dependencies
```bash
docker exec laravel_app composer install
```

#### 5. Generate application key
```bash
docker exec laravel_app php artisan key:generate
```

#### 6. Run migrations
```bash
docker exec laravel_app php artisan migrate
```

#### 7. Start the queue worker
```bash
docker exec laravel_app php artisan queue:work redis \
  --queue=api-analytics-processing \
  --tries=3
```

#### Running Tests
```bash
# Create the test database once:
docker exec laravel_mysql mysql -uroot \
  -e "CREATE DATABASE IF NOT EXISTS multi_tenant_api_test; \
      GRANT ALL PRIVILEGES ON multi_tenant_api_test.* TO 'laravel'@'%';"

# Run the test suite:
docker exec laravel_app php artisan test --filter=ApiGatewayGuardMiddlewareTest
```

---

### Usage

#### Creating an API Key (Programmatic)
```php
$service = app(ApiKeyService::class);

$result = $service->create(
    tenantId:        'tenant-uuid-here',
    name:            'Production Key',
    rateLimitMax:    1000,    // max requests per window
    rateLimitWindow: 60,      // 60-second window
);

echo $result->plainToken; // Save this — shown only once!
// Example: "mtam_a1b2c3d4-..._ff3a9b..."
```

#### Making an API Request
```bash
curl -H "X-API-KEY: mtam_a1b2c3d4-..._ff3a9b..." \
     http://localhost:8080/api/health
```

#### Successful Response
```json
HTTP/1.1 200 OK
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999

{"status": "ok"}
```

#### Error Responses
```json
// 401 — Missing header
{"error": "missing_api_key", "message": "..."}

// 401 — Invalid or revoked key
{"error": "invalid_api_key", "message": "..."}

// 429 — Rate limit exceeded
HTTP/1.1 429 Too Many Requests
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 0
Retry-After: 47

{"error": "rate_limit_exceeded", "retry_after": 47}
```

---

### Architectural Decisions

| Decision | Rationale |
|----------|-----------|
| **Redis-first, no MySQL on hot path** | MySQL connection overhead adds 5–20ms per request. Redis P99 < 1ms. |
| **SHA-256 hash, plain token never stored** | In the event of a database breach, tokens cannot be used by attackers. |
| **Atomic Lua script for rate limiting** | Eliminates the TOCTOU race condition between ZREMRANGEBYSCORE and ZCARD. |
| **Async logging via terminate()** | Fires after HTTP connection closes — zero client-perceived latency. |
| **Redis List buffer + bulk INSERT** | 50 log entries written in a single INSERT — reduces MySQL I/O by ~98%. |
| **`config('api-gateway.tables.*')` for loose coupling** | Table names are not hard-coded; the module can be extracted as a Composer package. |
| **Contracts (Interface) layer** | Inverts Eloquent dependency; enables test isolation and easy mocking. |
| **`readonly` DTOs** | Data objects are immutable; mutation bugs are caught at compile time. |

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Framework | Laravel 11 |
| Language | PHP 8.3 (strict_types=1) |
| Primary DB | MySQL 8.0 |
| Cache / Rate Limit | Redis 7 (phpredis 6.3) |
| Queue | Redis (api-analytics-processing) |
| Web Server | Nginx |
| Runtime | PHP-FPM 8.3 |
| Container | Docker + Docker Compose |
| Tests | PHPUnit (Laravel Feature Tests) |

---

## Docker Services

| Service | Container Name | Port | Purpose |
|---------|---------------|------|---------|
| PHP-FPM | `laravel_app` | 9000 | Application runtime |
| Nginx | `laravel_nginx` | 8080 | HTTP reverse proxy |
| MySQL | `laravel_mysql` | 3306 | Persistent data storage |
| Redis | `laravel_redis` | 6379 | Cache + Rate limit + Queue |

---

*Built with ❤️ — Enterprise-grade, Redis-first, zero-MySQL-on-hot-path API Gateway for Laravel SaaS applications.*


*Commercial Support & Enterprise Customization*
This project is maintained as an open-source core engine under the GPL-3.0 license. If your organization requires a production-ready API Management setup with an Admin Dashboard, Stripe Billing Integration, or Enterprise Infrastructure Support, feel free to contact me at [ahmetbozac@gmail.com].