---
name: uber-integration
description: Uber Eats webhook integration - handles order notifications, delivery state changes, order failures, and fulfillment issues. Covers signature verification, order creation, status updates, and promotions handling.
license: MIT
compatibility: opencode
metadata:
  audience: developers
  platform: uber-eats
---

## What I do

I provide complete context for the Uber Eats webhook integration that receives delivery platform events and creates/updates orders in MasterPos via the multi-tenant database system.

## When to use me

Use this when working on Uber Eats webhook handling, debugging order creation from Uber, fixing signature verification issues, or modifying how Uber promotions/discounts are processed.

## Entry point

- **Route**: `POST /api/integracion-uber` (public, no JWT)
- **Controller**: `UberWebhookController@getNotification` (`app/Http/Controllers/UberWebhookController.php`)
- **Notification processor**: `UberNotificationController` (`app/Http/Controllers/UberNotificationController.php`)

## Event types handled

### Order notifications (create/update orders)
- `orders.notification` - New order received, creates precuenta in MasterPos
- `delivery.state_changed` - Delivery status update (PICKED_UP, DROPPED_OFF, etc.)
- `orders.release` - Order preparation status update
- `orders.fulfillment_issues.resolved` - Deletes existing order then recreates it
- `order.fulfillment_issues.resolved` - Same as above (alternate event name)

### Order failures
- `orders.failure` - Cancels the order in MasterPos

## Flow

1. **Signature verification**: HMAC-SHA256 using `company.signing_key_webhook_uber` from DB
   - Header: `X-Uber-Signature`
   - Compares: `hash_hmac('sha256', $content, $company->signing_key_webhook_uber)`
2. **Store identification**: Extracts `store_id` from `meta.user_id` or looks up by `meta.order_id` in `webhook_ubers` table
3. **Company lookup**: `Company::where('token', $storeId)` - token maps to Uber's store user_id
4. **Persist webhook**: Saves raw data to `webhook_ubers` table
5. **Process by event_type**: Routes to `UberNotificationController::orderNotification` or `orderNotificationFailure`

## Order creation flow (UberNotificationController::orderNotification)

1. Fetches full order from Uber API using `resource_href` with `expand=carts,deliveries,payment`
2. Uses store token from `sucursal_tienda_uber.token` for Bearer auth
3. Extracts customer data from `tax_profiles` (tax_id, email, legal_entity_name, billing_address)
4. Processes promotions (only for `pos_master` connection):
   - Item-level promotions: matched by `external_id` against cart item IDs
   - Subtotal-level promotions: aggregated as discount
5. Parses cart items from `external_data` field (format: `TYPE-ID-PCPID-...-TAX-...-PRICE`)
6. Handles modifier groups (toppings/ingredients) from `selected_modifier_groups`
7. Calls `MpFunctionController::createMpOrder()` which dispatches `RetrySendOrderMp` job

## Order update flow

- Dispatches `RetryUpdateOrderMp` job via `MpFunctionController::updateMpOrderAppDelivery()`
- Status comes from `meta.current_state` or `meta.status` for delivery events
- For `orders.release`, uses `order.preparation_status`

## Promotions handling

- Uber amounts are in `amount_e5` format (divide by 100000 to get actual value)
- Item prices in `external_data` are in cents (divide by 100)
- Promotions from `payment.payment_detail.promotions.details`:
  - `DISCOUNTED_ITEM` type: has `discount_items` array with per-item discounts
  - Other types: subtotal-level discounts with `discount_value`
- Promotions from `payment.tax_reporting.breakdown.promotions`: used for modifier-level discounts

## Key DB tables (client connection)

- `sucursal_tienda_uber` - Maps Uber store_id to local branch (id_sucursal), stores token
- `precuenta` - Pre-check/order table, `default_name` stores Uber order ID
- `precuenta_app_delivery` - Delivery app state, `cuerpo` stores full Uber API response
- `detalle_precuenta` - Order line items
- `pos_configuracion_producto` - Product configuration, used to resolve item types

## Models

- `WebhookUber` (`app/Models/WebhookUber.php`) - fillable: `data`
- `Company` (`app/Models/Company.php`) - fillable: `name`, `connect`, `error_emails`; uses SoftDeletes

## Gotchas

- `orders.fulfillment_issues.resolved` deletes and recreates the order (calls `deleteMpOrderAppDelivery` first)
- Promotion processing is hardcoded to only work for `pos_master` connection (`$data->connect === 'pos_master'`)
- Uber API returns prices in cents (`amount_e5` for payment, `/100` for item external_data)
- The `external_data` field on cart items encodes product mapping: positions vary but typically `[0]=type, [1]=id, [5]=tax, [7]=price`
