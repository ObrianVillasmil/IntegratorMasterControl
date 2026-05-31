---
name: pedidosya-integration
description: PedidosYa webhook integration - handles new order creation and order status updates via JWT-based authentication. Covers order creation with toppings, discount processing, and status change routing.
license: MIT
compatibility: opencode
metadata:
  audience: developers
  platform: pedidosya
---

## What I do

I provide complete context for the PedidosYa webhook integration that receives delivery orders and status updates, creating/updating orders in MasterPos.

## When to use me

Use this when working on PedidosYa webhook handling, debugging JWT authentication, fixing order creation with toppings/modifiers, or handling order status transitions.

## Entry points (public, no JWT)

| Route | Method | Handler |
|-------|--------|---------|
| `/api/integracion-peya/order/{vendorId}` | POST | `getNotification` (new order) |
| `/api/integracion-peya/remoteId/{remoteId}/remoteOrder/{remoteOrderId}/posOrderStatus` | PUT | `getNotification` (status update) |
| `/api/integracion-peya` | POST | Inline closure (general log) |
| `/api/integracion-peya/{catalogImportCallback}` | POST | Inline closure (menu import log) |

**Controller**: `PedidosYaWebhookController` (`app/Http/Controllers/PedidosYaWebhookController.php`)

## Authentication (JWT)

PedidosYa sends a JWT Bearer token in the `Authorization` header:

1. Extract `vendorId` from URL path by matching against `Company.token` values that have `secret_key_pedidosya` set
2. Decode JWT header (base64) to get algorithm
3. Decode JWT payload (base64) to get `iss` and `service`
4. Verify JWT using `firebase/php-jwt`: `JWT::decode($token, new Key($company->secret_key_pedidosya, $alg))`
5. Cross-validate `iss` and `service` between decoded header and payload

## Method details

### getNotification

Routes based on URL path:
- Contains `order/` → calls `createNewOrder()` (new order)
- Contains `posOrderStatus` → calls `updateOrder()` (status change)

Generates a `remoteOrderId` as `PREC-{random}` for tracking.

### createNewOrder (static)

1. Persists webhook to `webhook_pedidoyas` table
2. Resolves branch via `sucursal_tienda_peya` table (matched by `posvendorid`)
3. Extracts customer data:
   - Name from `customer.firstName` + `customer.lastName`
   - Email parsed from `comments.customerComment` (format: `Email del Cliente:xxx`)
   - Corporate billing info parsed from `comments.customerComment` (format: `Facturar a empresa:NAME:ID`)
   - Phone from `customer.mobilePhone`
   - Address from `delivery.address`
4. Processes items:
   - `remoteCode` format: `TYPE-ID-PCPID-TYPE2-ID2` (exploded by `-`)
   - Tax from `pos_configuracion_producto` → `impuesto`
   - Net price: `unitPrice / (1 + tax/100)`
   - Handles `selectedToppings` with nested `children` array
5. Processes discounts:
   - Discount percentage: `(discountAmountTotal * 100) / subTotal`
   - Applied to net subtotal proportionally
6. Calls `MpFunctionController::createMpOrder()`
7. Returns `remoteOrderId` in response for PedidosYa to track

### updateOrder (static)

1. Extracts `remoteOrderId` from URL path (position 5 in exploded path)
2. Looks up order in `precuenta_app_delivery` by `cuerpo->remoteOrderId`
3. If `status === 'ORDER_CANCELLED'`: calls `MpFunctionController::cancelMpOrderAppDelivery()`
4. Otherwise: calls `MpFunctionController::updateMpOrderAppDelivery()`

## Key DB tables (client connection)

- `sucursal_tienda_peya` - Maps PedidosYa vendorId to local branch, stores `id_tipo_pago_peya` and `id_tipo_venta_peya`
- `precuenta` / `precuenta_app_delivery` - Order state, `cuerpo->remoteOrderId` for lookup
- `pos_configuracion_producto` - Product config for tax and item resolution

## Models

- `WebhookPedidoya` - fillable: `order`

## Gotchas

- `vendorId` extraction is done by iterating URL path segments and matching against all company tokens with `secret_key_pedidosya` set
- Toppings have a nested structure: `selectedToppings[].children[]` with their own `remoteCode`
- The `remoteCode` for toppings uses position `[4]` for `id_pcpp` (product configuration question)
- Discount calculation uses PedidosYa's own `discountAmountTotal` and `subTotal` from `price` object
- The `token` field in the request body is used as the order ID for MasterPos (not PedidosYa's own ID)
- `comments.customerComment` is a pipe-delimited string with special parsing for email and corporate billing
