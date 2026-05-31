---
name: rappi-integration
description: Rappi webhook integration - handles new orders, cancellations, status updates, menu approvals, ping health checks, and store connectivity alerts. Covers HMAC signature verification and discount processing.
license: MIT
compatibility: opencode
metadata:
  audience: developers
  platform: rappi
---

## What I do

I provide complete context for the Rappi webhook integration that receives delivery events and creates/updates/cancels orders in MasterPos.

## When to use me

Use this when working on Rappi webhook handling, debugging order creation, fixing signature verification, handling Rappi-specific discount logic, or diagnosing store connectivity issues.

## Entry points (all public, no JWT)

| Route | Method | Handler |
|-------|--------|---------|
| `/api/integracion-rappi/new-order` | POST | `newOrder` |
| `/api/integracion-rappi/order-event-cancel` | POST | `orderEventCancel` |
| `/api/integracion-rappi/order-other-event` | POST | `orderOtherEvent` |
| `/api/integracion-rappi/order-rt-tracking` | POST | `orderRtTracking` (no-op) |
| `/api/integracion-rappi/menu-approved` | POST | `menuApproved` |
| `/api/integracion-rappi/menu-rejected` | POST | `menuRejected` |
| `/api/integracion-rappi/ping` | POST | `pingRappi` |
| `/api/integracion-rappi/store-connectvity` | POST | `storeConnectvity` |

**Controller**: `RappiWebhookcontroller` (`app/Http/Controllers/RappiWebhookcontroller.php`)

## Signature verification

- Header: `Rappi-Signature`
- Format: `t=<timestamp>,sign=<hmac>`
- Algorithm: `hash_hmac('sha256', "{$timestamp}.{$body}", $secret->secret)`
- Secrets stored in `secret_web_hook_rappis` table, keyed by `event` + `type` (DEVELOPMENT/PRODUCTION)
- Environment detection: `store.internal_id === env('DEVELOPMENT_RAPPI_STORE_ID', '900163592')` determines DEVELOPMENT vs PRODUCTION

## Method details

### newOrder
1. Validates signature with `NEW_ORDER` secret
2. Persists to `webhook_rappis` table
3. Looks up company by `store.internal_id` → `Company.token`
4. Resolves branch via `sucursal_tienda_rappi` table
5. Extracts customer from `customer` and `billing_information` (billing overrides customer)
6. Processes items:
   - SKU format: `TYPE-ID-PCPID-...` (exploded by `-`)
   - Tax from `pos_configuracion_producto` → `impuesto`
   - Calculates net price: `price / (1 + tax/100)`
   - Handles subitems (modifiers/ingredients)
7. Processes discounts:
   - Item-level: `offer_by_product` type, matched by SKU
   - Total-level: discounts without SKU, excluding `free_shipping`
   - Percentage-based discount calculation against subtotal
8. Calls `MpFunctionController::createMpOrder()`

### orderEventCancel
1. Validates with `ORDER_EVENT_CANCEL` secret (PRODUCTION only)
2. Calls `MpFunctionController::cancelMpOrderAppDelivery()`

### orderOtherEvent
1. Validates with `ORDER_OTHER_EVENT` secret
2. Looks up existing order in `precuenta` by `default_name = order_id`
3. Updates `current_status` in the `cuerpo` JSON
4. On `taken_visible_order` event: fetches handoff code from Rappi API (`/restaurants/orders/v1/stores/{storeId}/orders/{orderId}/handoff`)
5. Calls `MpFunctionController::updateMpOrderAppDelivery()`

### pingRappi
1. Validates with `PING` secret
2. Pings the MasterPos DB via `Controller::pingMp()`
3. Returns `{"status": "OK"}` or `{"status": "KO"}` with HTTP 200/500

### storeConnectvity
1. Validates with `STORE_CONNECTIVITY` secret (PRODUCTION only)
2. If `enabled === false`: sends alert email via Brevo about store being offline

### menuApproved / menuRejected
- Validate signature only, log the event, return 200

### orderRtTracking
- No-op, returns 200 immediately

## Key DB tables (client connection)

- `sucursal_tienda_rappi` - Maps Rappi store_id to local branch, stores token and store_id
- `precuenta` / `precuenta_app_delivery` - Order and delivery state
- `pos_configuracion_producto` - Product config for tax and item resolution
- `secret_web_hook_rappis` (main DB) - HMAC secrets per event type

## Models

- `WebhookRappi` - fillable: `order`
- `SecretWebHookRappi` - stores event secrets (event, type, secret)

## Rappi API calls

- `clientRappiCurl()` - private static method for Rappi API calls
- Auth: `x-authorization: Bearer {token}` header
- Used for handoff code retrieval on `taken_visible_order`

## Gotchas

- Note the typo in class name: `RappiWebhookcontroller` (lowercase 'c')
- `orderOtherEvent` has unreachable `return response("",200)` statements after the main return
- `orderEventCancel` always sets `$success = true` after the if-block, even on failure
- Discount logic for subitems with `includes_toppings` applies the same percentage discount
- `DEVELOPMENT_RAPPI_STORE_ID` env var controls dev vs prod mode per event
- `pingRappi` has undefined `$success` and `$msg` variables in the catch block
