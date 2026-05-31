---
name: bones-integration
description: Bones/Contifico integration - handles sales extraction, purchase extraction, cost data (costeos), sales/purchase reception confirmation, and Contifico accounting invoice/credit note creation with email notifications.
license: MIT
compatibility: opencode
metadata:
  audience: developers
  platform: bones-contifico
---

## What I do

I provide complete context for the Bones integration (sales, purchases, costeos sync with external accounting) and the Contifico integration (invoice/credit note creation in the Contifico accounting API).

## When to use me

Use this when working on Bones sales/purchase data extraction, reception confirmations, costeo reports, Contifico invoice creation, credit note processing, or email notification flows.

## Bones Integration

**Controller**: `BonesIntegrationController` (`app/Http/Controllers/BonesIntegrationController.php`)

All endpoints require JWT auth + company token (middleware `jwt.verify`).

### Endpoints

| Route | Method | Handler | Description |
|-------|--------|---------|-------------|
| `/api/auth/bones-ventas` | GET | `getSales` | Extract sales + credit notes for date range |
| `/api/auth/bones-recepcion-ventas` | POST | `receptionSales` | Confirm sales received externally |
| `/api/auth/bones-compras` | GET | `getPurchase` | Extract purchases + credit notes for date range |
| `/api/auth/bones-recepcion-compras` | POST | `receptionPurchase` | Confirm purchases received externally |
| `/api/auth/bones-costeo` | GET | `getCosteos` | Extract inventory cost transactions |

### Form Request validators

- `ValidateRequestSales` - validates `from`, `to`, `company`, optional `branch_office`
- `ValidateRequestPurchase` - same as sales
- `ValidateRequestCosteos` - same as sales
- `ValidateReceptionSales` - validates `company` + `salesid` array (format: `{branchId}-{saleId}` or `{branchId}-{saleId}-CN`)
- `ValidateReceptionPurchase` - validates `company` + `purchasesid` array (format: `{branchId}-{invoiceId}` or `{branchId}-{invoiceId}-CN`)

### getSales

1. Resolves active branches for the company
2. Queries `venta` table: `estado=true`, `venta_confirmada_externo=false`, date range, no credit note sequential
3. For each sale: builds structured output with DOC_ID, customer info, tax breakdown, line items, payment methods
4. Queries credit notes: `estado=false`, `cn_confirmada_externo=false`, with `json_cn` data
5. DOC_ID format: `{branchId}-{saleId}` for sales, `{branchId}-{saleId}-CN-{creditNoteId}` for credit notes
6. Returns merged sales + credit notes

### receptionSales

1. For each `salesid`: parses `{branchId}-{saleId}[-CN]`
2. Updates `venta.venta_confirmada_externo = true` (or `cn_confirmada_externo = true` for CN)
3. Uses DB transaction with rollback on error

### getPurchase

1. Queries `factura` table: `forma=1` (invoices), `estado=true`, `compra_confirmada_externo=false`
2. Excludes invoices with negative quantities in `detalle_factura`
3. For each: builds structured output with supplier info, line items, retention codes
4. Queries credit notes: `forma=2`, includes only invoices with negative quantities
5. DOC_ID format: `{branchId}-{invoiceId}` for purchases, `{branchId}-{invoiceId}-CN` for credit notes

### receptionPurchase

Same pattern as `receptionSales` but for `factura` table.

### getCosteos

1. Raw SQL query on `transaccion_inventario` joined with `item` and `sub_categoria_item`
2. Filters by transaction types: `DETALLE_VENTA`, `DETALLE_PRECUENTA`, `BAJA_ITEM_PRODUCTO`, `AUDITORIA_TOMA_FISICA_AERA_SUCURSAL`
3. Groups by date, subcategory, and transaction type
4. Maps transaction types to friendly names: VENTA, TOMA_FISICA, BAJA

### Key DB tables (client connection)

- `venta` - Sales, `venta_confirmada_externo` and `cn_confirmada_externo` flags
- `factura` - Purchases, `compra_confirmada_externo` and `cn_confirmada_externo` flags
- `detalle_venta` / `detalle_factura` - Line items
- `venta_base_impuesto` - Tax base breakdown for sales
- `venta_tipo_pago` - Payment methods per sale
- `comprador` - Customer data (identification type: 1=RUC, 2=CEDULA, 3=PASAPORTE)
- `pos_configuracion_producto` - Product accounting codes (`cc_general`, `cc_general_nombre`)
- `transaccion_inventario` - Inventory movements for costeos

## Contifico Integration

**Controller**: `ContificoIntegrationController` (`app/Http/Controllers/ContificoIntegrationController.php`)

### sendInvoices (static)

Called internally (not via route), processes sales for a company and creates invoices/credit notes in Contifico:

1. Queries unconfirmed sales (`venta_confirmada_externo=false`, no `id_externo`, with `secuencial`)
2. For each sale:
   - Builds Contifico invoice payload (type `FAC`)
   - Creates invoice via `POST {CREAR_FACTURA_CONTIFICO}`
   - On success: updates `venta.id_externo` and `venta_confirmada_externo=true`
   - Creates payment records (cobros) via `POST {CREAR_FACTURA_CONTIFICO}/{id}/cobro/`
   - Payment types: `EF` (cash, id_tipo_pago 1,6), `TC` (credit card, id_tipo_pago 2,3)
3. Processes credit notes (type `NCT`):
   - Requires `json_cn`, `secuencial_nota_credito`, and `id_externo` (links to original invoice)
   - Creates via same Contifico endpoint
4. Sends summary emails via Brevo (success, failure, external alerts)

### curlStoreTransaction (static)

Generic HTTP POST client for Contifico API:
- Headers: `Content-Type: application/json`, `Authorization: {company.token}`
- Returns `['http' => statusCode, 'response' => decodedJson]`

### sendMail (static)

Sends transactional emails via Brevo API:
- Uses `getbrevo/brevo-php` SDK
- Sender: `MAIL_FROM_BREVO` env
- Recipients: `MAIL_NOTIFICATION` env + CC

### Key env vars

- `CONTIFICO` - Base API URL (`https://api.contifico.com`)
- `CREAR_FACTURA_CONTIFICO` - Invoice creation endpoint
- `PRODUCTO_CONTIFICO_{COMPANY}_{BRANCH}` - Contifico product ID per branch
- `API_POS_CONTIFICO_{COMPANY}_{BRANCH}` - POS identifier per branch
- `CUENTA_*_CONTIFICO_*` - Accounting account IDs (currently commented out in code)
- `API_KEY_BREVO` - Brevo API key for emails

### Error handling

- Contifico error codes `1508` (bad cedula) and `1502` (bad RUC) trigger external alert emails to `company.error_email`
- Failed invoices store the error response in `venta.id_externo` as JSON
- All errors send notification emails to `MAIL_NOTIFICATION`

## Gotchas

- `getSales` and `getPurchase` use `whereBetween` with `DB::raw("fecha::date")` for PostgreSQL date casting
- Credit note data is stored as JSON in `venta.json_cn` (from external invoicing system)
- The Contifico integration has large blocks of commented-out code for accounting entries (asientos) that were disabled
- `sleep(2)` calls between Contifico API requests to avoid rate limiting
- Customer identification type mapping: 10 chars = cedula, 13 chars = RUC, RUC[2] == '9' = juridical person
- `sendInvoices` is not exposed via route - it's called from a scheduled command or external trigger
- The `consultarFactura` method has a hardcoded token and `dd()` call (debugging leftover)
