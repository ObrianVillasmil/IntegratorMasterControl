---
name: rate-limit-plan
description: Detailed implementation plan for rate limiting across all API endpoints - covers webhook endpoints (Uber, Rappi, PedidosYa, Deuna), authenticated Bones endpoints, and infrastructure requirements.
license: MIT
compatibility: opencode
metadata:
  audience: developers
  workflow: security-implementation
---

## What I do

I provide a complete implementation plan for adding granular rate limiting to all API endpoints in the project, with different strategies for public webhooks vs authenticated endpoints.

## When to use me

Use this when implementing rate limiting, configuring throttling policies, or reviewing API security and abuse prevention measures.

## Current state

### Existing configuration

**RouteServiceProvider** (`app/Providers/RouteServiceProvider.php`):
```php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60);
});
```

**Kernel.php** (`app/Http/Kernel.php`):
```php
'api' => [
    'throttle:api',  // Applied to ALL /api routes
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
],
```

### Problems identified

1. **No segmentation**: All endpoints share the same 60 req/min limit globally
2. **No user identification**: Rate limit doesn't differentiate by IP, user, or platform
3. **Cache driver**: `CACHE_DRIVER=file` in `.env` - file-based cache is slow and not suitable for production rate limiting
4. **Webhook vulnerability**: Public webhook endpoints have no protection against abuse
5. **No burst handling**: No distinction between sustained traffic and legitimate bursts

## Implementation plan

### Phase 1: Infrastructure preparation

#### 1.1 Change cache driver to Redis (production) or database (development)

**File**: `.env`
```bash
# Production (recommended)
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Development (if Redis unavailable)
CACHE_DRIVER=database
```

**File**: `config/cache.php` - verify database store exists:
```php
'database' => [
    'driver' => 'database',
    'table' => 'cache',
],
```

**Migration** (if using database cache):
```bash
php artisan cache:table
php artisan migrate
```

#### 1.2 Install Redis (production)
```bash
# Ubuntu/Debian
sudo apt-get install redis-server
sudo systemctl enable redis-server

# Verify
redis-cli ping  # Should return PONG
```

### Phase 2: Define rate limiters

**File**: `app/Providers/RouteServiceProvider.php`

Replace the existing `configureRateLimiting()` method:

```php
protected function configureRateLimiting()
{
    // Global API fallback (should never be hit if specific limiters are applied)
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->ip());
    });

    // Webhook endpoints - per platform, identified by signature/IP
    RateLimiter::for('webhooks-uber', function (Request $request) {
        $storeId = $request->header('X-Uber-Signature') 
            ? 'uber-' . substr($request->header('X-Uber-Signature'), 0, 16)
            : 'uber-' . $request->ip();
        return Limit::perMinute(100)->by($storeId);
    });

    RateLimiter::for('webhooks-rappi', function (Request $request) {
        $signature = $request->header('Rappi-Signature');
        $identifier = $signature 
            ? 'rappi-' . substr($signature, 0, 16)
            : 'rappi-' . $request->ip();
        return Limit::perMinute(100)->by($identifier);
    });

    RateLimiter::for('webhooks-pedidosya', function (Request $request) {
        $vendorId = $request->route('vendorId') ?? $request->ip();
        return Limit::perMinute(100)->by('peya-' . $vendorId);
    });

    RateLimiter::for('webhooks-deuna', function (Request $request) {
        $branchId = $request->input('branchId') ?? $request->ip();
        return Limit::perMinute(50)->by('deuna-' . $branchId);
    });

    // Authenticated endpoints - per user
    RateLimiter::for('bones-api', function (Request $request) {
        return Limit::perMinute(30)->by($request->user()->id ?? $request->ip());
    });

    // Login endpoint - strict to prevent brute force
    RateLimiter::for('login', function (Request $request) {
        return Limit::perMinute(5)->by($request->ip());
    });
}
```

### Phase 3: Apply rate limiters to routes

**File**: `routes/api.php`

#### 3.1 Webhook endpoints (public)

```php
// Uber - 100 req/min per store
Route::middleware(['api', 'throttle:webhooks-uber'])
    ->post('/integracion-uber', [UberWebhookController::class, 'getNotification']);

// Deuna - 50 req/min per branch
Route::middleware(['api', 'throttle:webhooks-deuna'])
    ->post('/integracion-deuna', [DeunaWebhookController::class, 'getNotification']);

// PedidosYa - 100 req/min per vendor
Route::middleware(['api', 'throttle:webhooks-pedidosya'])
    ->post('/integracion-peya/order/{vendorId}', [PedidosYaWebhookController::class, 'getNotification']);

Route::middleware(['api', 'throttle:webhooks-pedidosya'])
    ->put('/integracion-peya/remoteId/{remoteId}/remoteOrder/{remoteOrderId}/posOrderStatus', 
          [PedidosYaWebhookController::class, 'getNotification']);

// PedidosYa general endpoints - keep default
Route::middleware(['api', 'throttle:webhooks-pedidosya'])
    ->post('/integracion-peya', function(Request $request) {
        info('WEBHOOK GENERAL PEDIDOS YA');
        info("Info recibida: \n\n " . $request->__toString());
        return response("", 200);
    });

Route::middleware(['api', 'throttle:webhooks-pedidosya'])
    ->post('/integracion-peya/{catalogImportCallback}', function(Request $request) {
        info("WEBHOOK IMPORTACION DE MENU PEDIDOS YA:\n");
        info("Info recibida: \n\n " . $request->__toString());
        return response("", 200);
    });

// Rappi - 100 req/min per store
Route::middleware(['api', 'throttle:webhooks-rappi'])
    ->post('/integracion-rappi/new-order', [RappiWebhookcontroller::class, 'newOrder']);

Route::middleware(['api', 'throttle:webhooks-rappi'])
    ->post('/integracion-rappi/order-event-cancel', [RappiWebhookcontroller::class, 'orderEventCancel']);

Route::middleware(['api', 'throttle:webhooks-rappi'])
    ->post('/integracion-rappi/order-other-event', [RappiWebhookcontroller::class, 'orderOtherEvent']);

Route::middleware(['api', 'throttle:webhooks-rappi'])
    ->post('/integracion-rappi/order-rt-tracking', [RappiWebhookcontroller::class, 'orderRtTracking']);

Route::middleware(['api', 'throttle:webhooks-rappi'])
    ->post('/integracion-rappi/menu-approved', [RappiWebhookcontroller::class, 'menuApproved']);

Route::middleware(['api', 'throttle:webhooks-rappi'])
    ->post('/integracion-rappi/menu-rejected', [RappiWebhookcontroller::class, 'menuRejected']);

Route::middleware(['api', 'throttle:webhooks-rappi'])
    ->post('/integracion-rappi/ping', [RappiWebhookcontroller::class, 'pingRappi']);

Route::middleware(['api', 'throttle:webhooks-rappi'])
    ->post('/integracion-rappi/store-connectvity', [RappiWebhookcontroller::class, 'storeConnectvity']);
```

#### 3.2 Authenticated endpoints

```php
Route::group([
    'middleware' => 'api',
    'prefix' => 'auth'
], function ($router) {
    
    // Login - strict rate limit (5 req/min per IP)
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::group(['middleware' => ['jwt.verify']], function() {
        
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('me', [AuthController::class, 'me']);

        // Bones endpoints - 30 req/min per user
        Route::get('bones-ventas', [BonesIntegrationController::class, 'getSales'])
            ->middleware('throttle:bones-api');
        
        Route::post('bones-recepcion-ventas', [BonesIntegrationController::class, 'receptionSales'])
            ->middleware('throttle:bones-api');

        Route::get('bones-compras', [BonesIntegrationController::class, 'getPurchase'])
            ->middleware('throttle:bones-api');
        
        Route::post('bones-recepcion-compras', [BonesIntegrationController::class, 'receptionPurchase'])
            ->middleware('throttle:bones-api');

        Route::get('bones-costeo', [BonesIntegrationController::class, 'getCosteos'])
            ->middleware('throttle:bones-api');
    });
});
```

### Phase 4: Custom rate limit response (optional)

**File**: `app/Exceptions/Handler.php`

Add custom response for rate limit exceeded:

```php
use Illuminate\Http\Exceptions\ThrottleRequestsException;

public function render($request, Throwable $e)
{
    if ($e instanceof ThrottleRequestsException) {
        return response()->json([
            'success' => false,
            'msg' => 'Demasiadas solicitudes. Por favor espere antes de intentar nuevamente.',
            'retry_after' => $e->getHeaders()['Retry-After'] ?? 60,
        ], 429);
    }

    return parent::render($request, $e);
}
```

### Phase 5: Testing

#### 5.1 Manual testing with curl

```bash
# Test Uber webhook rate limit (should fail after 100 requests)
for i in {1..105}; do
  curl -X POST http://localhost:8000/api/integracion-uber \
    -H "Content-Type: application/json" \
    -H "X-Uber-Signature: test123" \
    -d '{"test": true}' \
    -w "\nRequest $i: %{http_code}\n"
done

# Test login rate limit (should fail after 5 requests)
for i in {1..10}; do
  curl -X POST http://localhost:8000/api/auth/login \
    -H "Content-Type: application/json" \
    -d '{"name": "test", "password": "wrong"}' \
    -w "\nRequest $i: %{http_code}\n"
done
```

#### 5.2 Automated test

**File**: `tests/Feature/RateLimitTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RateLimitTest extends TestCase
{
    public function test_login_rate_limit()
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'name' => 'test',
                'password' => 'wrong'
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->postJson('/api/auth/login', [
            'name' => 'test',
            'password' => 'wrong'
        ]);
        
        $this->assertEquals(429, $response->status());
    }

    public function test_uber_webhook_rate_limit()
    {
        for ($i = 0; $i < 100; $i++) {
            $response = $this->postJson('/api/integracion-uber', 
                ['test' => true],
                ['X-Uber-Signature' => 'test-signature']
            );
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->postJson('/api/integracion-uber', 
            ['test' => true],
            ['X-Uber-Signature' => 'test-signature']
        );
        
        $this->assertEquals(429, $response->status());
    }
}
```

### Phase 6: Monitoring and alerts

#### 6.1 Log rate limit hits

**File**: `app/Exceptions/Handler.php`

```php
if ($e instanceof ThrottleRequestsException) {
    \Log::warning('Rate limit exceeded', [
        'ip' => $request->ip(),
        'path' => $request->path(),
        'user' => $request->user() ? $request->user()->id : null,
        'limit' => $e->getHeaders()['X-RateLimit-Limit'] ?? null,
    ]);
    
    // Send alert if critical endpoint
    if (strpos($request->path(), 'integracion-') !== false) {
        \App\Http\Controllers\ContificoIntegrationController::sendMail([
            'subject' => 'Rate limit excedido en webhook',
            'sucursal' => 'INTEGRADOR',
            'ccEmail' => env('MAIL_NOTIFICATION'),
            'html' => "<p>Se excedió el rate limit en {$request->path()} desde IP {$request->ip()}</p>"
        ]);
    }
}
```

#### 6.2 Rate limit headers

Laravel automatically adds these headers:
- `X-RateLimit-Limit`: Maximum requests allowed
- `X-RateLimit-Remaining`: Requests remaining in window
- `Retry-After`: Seconds until limit resets (only on 429)

## Rate limit strategy summary

| Endpoint Type | Limiter | Limit | Identifier |
|---------------|---------|-------|------------|
| Uber webhooks | `webhooks-uber` | 100/min | Store signature or IP |
| Rappi webhooks | `webhooks-rappi` | 100/min | Store signature or IP |
| PedidosYa webhooks | `webhooks-pedidosya` | 100/min | Vendor ID or IP |
| Deuna webhooks | `webhooks-deuna` | 50/min | Branch ID or IP |
| Login | `login` | 5/min | IP address |
| Bones endpoints | `bones-api` | 30/min | User ID or IP |
| Global fallback | `api` | 60/min | IP address |

## Implementation order

1. **Phase 1**: Infrastructure (cache driver) - 1 hour
2. **Phase 2**: Define rate limiters - 30 minutes
3. **Phase 3**: Apply to routes - 30 minutes
4. **Phase 4**: Custom responses - 15 minutes
5. **Phase 5**: Testing - 1 hour
6. **Phase 6**: Monitoring - 30 minutes

**Total estimated time**: 3.5 hours

## Rollback plan

If issues arise, revert to original configuration:

**File**: `app/Providers/RouteServiceProvider.php`
```php
protected function configureRateLimiting()
{
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60);
    });
}
```

**File**: `routes/api.php` - Remove all `->middleware('throttle:...')` calls except the global `throttle:api` in Kernel.

## Gotchas

- `CACHE_DRIVER=file` will cause race conditions and inaccurate rate limiting under load
- Rate limiters use the cache, so cache clearing (`php artisan cache:clear`) resets all limits
- Webhook platforms may retry failed requests, potentially hitting rate limits faster
- Consider whitelisting known platform IPs if rate limits cause legitimate webhook rejections
- The `by()` identifier must be consistent - mixing IP and signature will create separate buckets
- Redis is strongly recommended for production; database cache is acceptable for development only
