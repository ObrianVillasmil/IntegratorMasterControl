---
name: deuna-integration
description: Deuna payment webhook integration - handles payment approval notifications for bank transfer payments. Validates transaction data and updates payment status in MasterPos.
license: MIT
compatibility: opencode
metadata:
  audience: developers
  platform: deuna
---

## What I do

I provide complete context for the Deuna payment webhook integration that receives payment approval notifications and updates the payment status in MasterPos.

## When to use me

Use this when working on Deuna payment notifications, debugging payment approval flow, fixing transaction matching, or handling queue retry logic for Deuna payments.

## Entry point

- **Route**: `POST /api/integracion-deuna` (public, no JWT)
- **Controller**: `DeunaWebhookController@getNotification` (`app/Http/Controllers/DeunaWebhookController.php`)
- **Job**: `RetrySendDeUnaNotificationMp` (`app/Jobs/RetrySendDeUnaNotificationMp.php`)

## Flow

1. **Validate required fields**: `branchId`, `posId`, `status` (must be `SUCCESS`), `idTransaction`, `internalTransactionReference`, `customerFullName`, `amount`, `transferNumber`
2. **Company lookup**: `Company::where('token', $data->branchId)` - branchId maps to company token
3. **Persist webhook**: Saves to `webhook_deunas` table with `connection` and `data`
4. **Ping MasterPos**: Checks if client DB is reachable via `Controller::pingMp()`
   - If reachable: dispatches `RetrySendDeUnaNotificationMp` synchronously (`dispatchNow`)
   - If unreachable: dispatches to queue `deuna-notification` for retry

## Job: RetrySendDeUnaNotificationMp

1. Pings MasterPos again; fails the job if unreachable (triggers retry)
2. Begins transaction on client connection
3. Looks up existing transaction in `pago_deuna` table by `transaccion_id` and `referencia`
4. Updates `pago_deuna` record:
   - `status` → `APPROVED`
   - `id_sucursal` → first active branch
   - `nombre_comprador`, `numero_transferencia`, `pos`, `monto`, `data`
5. Inserts into `pago_deuna_status`:
   - `status` → `APPROVED`
   - `id_usuario` → `1000` (system user)
   - `fecha` → current timestamp

## Key DB tables (client connection)

- `pago_deuna` - Payment records, matched by `transaccion_id` + `referencia`
- `pago_deuna_status` - Payment status history/audit log
- `sucursal` - Branch lookup (first active branch used)

## Models

- `WebhookDeuna` - fillable: `data`, `connection`

## Queue

- Queue name: `deuna-notification`
- Driver: database (`QUEUE_CONNECTION=database`)
- Job fails with descriptive message if ping fails, triggering Laravel's built-in retry

## Gotchas

- No signature/HMAC verification - relies on field validation only
- `branchId` in the webhook maps to `Company.token` (same pattern as other integrations)
- The job uses the first active `sucursal` regardless of the `posId` received
- `id_usuario` is hardcoded to `1000` (system user)
- Returns 403 on validation failure but 200 on success, even if the job is queued for retry
- No authentication mechanism beyond field presence validation
