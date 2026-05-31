---
name: order-management
description: Shared order management system - MpFunctionController orchestrates order creation, update, cancellation, and deletion in MasterPos via queued jobs. Covers validation, precuenta creation, retry logic, and ping-based availability checks.
license: MIT
compatibility: opencode
metadata:
  audience: developers
  workflow: order-lifecycle
---

## What I do

I provide complete context for the shared order management system used by all delivery integrations (Uber, Rappi, PedidosYa) to create, update, cancel, and delete orders in MasterPos.

## When to use me

Use this when working on order creation logic, modifying how precuentas are built, debugging job retry failures, understanding validation rules, or fixing ping/connectivity issues.

## Controller: MpFunctionController

**File**: `app/Http/Controllers/MpFunctionController.php`

Central orchestrator called by all delivery webhook controllers. All methods are `static`.

### createMpOrder(Request)

1. Validates input with extensive rules:
   - `id_branch_office` (required, numeric)
   - `order_id` (required, string, min 3)
   - `name` (required, string, min 3)
   - `ordering_platform` (required, string, min 3)
   - `total` (required, numeric, min 0)
   - `connect` (required, base64-encoded connection name, must exist in `companies.connect`)
   - `payment_type_id` (required, validated against `tipo_pago` table in client DB)
   - `sale_type_id` (required, validated against `cliente` table in client DB)
   - `items` (required, JSON array, each item validated for id, name, type R/I, tax, quantity, ingredient 0/1, sub_total_price, discount, optional id_pcpp)
2. On success: dispatches `RetrySendOrderMp` job to queue `retry-send-order-mp`
3. On error: sends email notification via `ContificoIntegrationController::sendMail()`

### updateMpOrderAppDelivery(Request)

1. Validates: `ordering_platform`, `status`, `tiempo_preparacion`, `connect`, `order_id` (must exist in `precuenta.default_name`)
2. Dispatches `RetryUpdateOrderMp` job to queue `retry-update-order-mp`

### cancelMpOrderAppDelivery(Request)

1. Validates: `order_id`, `connect`
2. Dispatches `RetryCancelOrderMp` job to queue `retry-cancel-order-mp`

### deleteMpOrderAppDelivery(Request)

1. Validates: `order_id`, `connect`
2. Executes synchronously (no job):
   - Deletes `detalle_precuenta`, `precuenta_base_impuesto`, `precuenta_app_delivery`, `precuenta` records
   - Uses DB transaction

## Base Controller

**File**: `app/Http/Controllers/Controller.php`

### pingMp($conexion) (static)

- Uses `fsockopen` to check TCP connectivity to the client DB host:port
- Timeout: 10 seconds
- Returns `true`/`false`
- Used by all jobs before attempting DB operations

## Jobs

All jobs implement `ShouldQueue` and use the database queue driver.

### RetrySendOrderMp (`app/Jobs/RetrySendOrderMp.php`)

Queue: `retry-send-order-mp`

1. Pings MasterPos; fails job if unreachable (triggers retry)
2. Checks for duplicate order (`precuenta.default_name = order_id`)
3. Creates/updates `comprador` (customer) record:
   - ID type by length: 10 chars = cedula (2), 13 chars = RUC (1), other = pasaporte (3)
   - Auto-increments `id_comprador` if new
4. Creates `precuenta` record:
   - Auto-increments `id_precuenta` (with collision retry on duplicate key)
   - Sets `venta_web=true`, `default_name=order_id`
   - Stores `json_descuento` if discounts present
5. For delivery apps (`app_deliverys=true`):
   - Creates `precuenta_app_delivery` with logo based on platform
   - Logo mapping: `UBER_EATS→ubereats.webp`, `PEDIDOS_YA→pedidosya.png`, `RAPPI→rappi.webp`
   - Sets initial `estado_app=OFFERED`
6. Creates `detalle_precuenta` for each item:
   - Resolves `id_impuesto` from `impuesto.valor`
   - Sets `ingrediente` boolean from item.ingredient
   - Stores `json_descuento` per item if present
7. If branch has `monitor=true`: inserts into `monitor` table
8. On duplicate key errors: calls `$this->fail()` to trigger retry

### RetryUpdateOrderMp (`app/Jobs/RetryUpdateOrderMp.php`)

Queue: `retry-update-order-mp`

1. Pings MasterPos; fails if unreachable
2. Looks up order by `precuenta.default_name` joined with `precuenta_app_delivery`
3. Deactivates current `precuenta_app_delivery` record (`estado=false`)
4. Inserts new `precuenta_app_delivery` with updated status, body, and preparation time

### RetryCancelOrderMp (`app/Jobs/RetryCancelOrderMp.php`)

Queue: `retry-cancel-order-mp`

1. Pings MasterPos; fails if unreachable
2. Looks up `precuenta` by `default_name`
3. Marks `precuenta.procesado = true`
4. Deactivates current `precuenta_app_delivery` (`estado=false`)
5. Updates `cuerpo` JSON with cancellation status and message
6. Inserts new `precuenta_app_delivery` with cancelled state

### RetrySendDeUnaNotificationMp (`app/Jobs/RetrySendDeUnaNotificationMp.php`)

Queue: `deuna-notification`

See the `deuna-integration` skill for details.

## Key DB tables (client connection)

| Table | Purpose |
|-------|---------|
| `precuenta` | Pre-check/order header, `default_name` = external order ID |
| `precuenta_app_delivery` | Delivery state per order, `cuerpo` = full API response JSON |
| `detalle_precuenta` | Order line items |
| `precuenta_base_impuesto` | Tax base breakdown per order |
| `comprador` | Customer records |
| `impuesto` | Tax rates |
| `tipo_pago` | Payment types |
| `cliente` | Sale types |
| `pos_configuracion_producto` | Product configuration |
| `pos_configuracion_producto_pregunta` | Product modifier questions |
| `monitor` | Kitchen display monitor |
| `sucursal` | Branch/store data |

## Queue configuration

- Driver: `database` (`QUEUE_CONNECTION=database`)
- Run worker: `php artisan queue:work`
- Queue names: `retry-send-order-mp`, `retry-update-order-mp`, `retry-cancel-order-mp`, `deuna-notification`
- Jobs fail and retry automatically when `$this->fail()` is called (Laravel's built-in retry)

## Gotchas

- `connect` parameter is base64-encoded throughout the flow (encoded by webhook controllers, decoded by MpFunctionController and jobs)
- `id_precuenta` and `id_detalle_precuenta` are manually auto-incremented (not DB sequences) - collision detection triggers job retry
- `SerializesModels` trait is commented out in all jobs - they serialize raw data, not Eloquent models
- `pingMp` uses `fsockopen` (TCP ping), not actual DB connection - a reachable host doesn't guarantee DB availability
- The `deleteMpOrderAppDelivery` method runs synchronously (no job), unlike create/update/cancel
- `RetrySendOrderMp` sends email on error via `ContificoIntegrationController::sendMail()` - this creates a cross-dependency
- Item type `R` = receta (recipe), `I` = item (individual product)
- `id_usuario = 1000` is the system user for all automated operations
