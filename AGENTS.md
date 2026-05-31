# AGENTS.md

## Project Overview

Laravel 8 (PHP 7.3+) API backend — integration hub connecting delivery platforms (Uber, Rappi, PedidosYa, Deuna) with a POS system (MasterControl/Bones) and accounting (Contifico). No frontend; `web.php` is unused.

## Skills - Load First

**IMPORTANT**: Before searching or reading project files, always load the relevant skill first. Skills contain pre-analyzed context about each integration, including file locations, function descriptions, DB tables, flows, and known gotchas.

**Workflow**:
1. Load the skill that matches the user's question or task
2. If the skill has enough context to answer/resolve, proceed directly
3. Only if the skill lacks the needed detail, search the project codebase
4. After resolving a problem that the skill didn't cover, **update the skill** with the new knowledge

### Available skills

| Skill | When to load |
|-------|-------------|
| `uber-integration` | Uber Eats webhooks, order creation, promotions, signature verification, delivery state changes |
| `rappi-integration` | Rappi webhooks, new orders, cancellations, status updates, menu events, ping, store connectivity |
| `pedidosya-integration` | PedidosYa webhooks, JWT auth, order creation with toppings, status updates |
| `deuna-integration` | Deuna payment notifications, transaction approval, payment status updates |
| `bones-integration` | Bones sales/purchases/costeos extraction, reception confirmations, Contifico invoicing, email notifications |
| `order-management` | Shared order lifecycle: MpFunctionController, precuenta creation, Jobs (RetrySendOrderMp, RetryUpdateOrderMp, RetryCancelOrderMp), ping logic, queue config |
| `rate-limit-plan` | Rate limiting implementation plan: infrastructure (Redis/cache), per-platform webhook limits, authenticated endpoint limits, testing, monitoring |
| `opencode-skill-creator` | Creating or modifying OpenCode skills, SKILL.md format and conventions |

## Commands

```bash
# Install deps
composer install

# Serve
php artisan serve

# Migrations (default pgsql connection)
php artisan migrate
php artisan migrate --database=sandry_mc      # target a specific connection

# Tests
./vendor/bin/phpunit
./vendor/bin/phpunit tests/Unit               # unit only
./vendor/bin/phpunit --filter TestClassName    # single test class

# Queue (database driver)
php artisan queue:work

# Assets (rarely needed, minimal frontend)
npm run dev
```

## Architecture

- **Multi-tenant / multi-DB**: `config/database.php` defines multiple PostgreSQL connections (`pgsql`, `sandry_mc`, `marenostrum_mc`, `topten_mc`, `mipclocal`), one per client company. Models query specific connections via `$connection` property or `DB::connection('name')`.
- **Auth**: JWT via `tymon/jwt-auth`. Custom middleware `jwt.verify` (`app/Http/Middleware/Verify.php`) validates both the JWT and the company token from the `Authorization` header against `user_companies` + `companies` tables.
- **Webhook controllers** (`UberWebhookController`, `RappiWebhookcontroller`, `PedidosYaWebhookController`, `DeunaWebhookController`) receive delivery platform events — these are public API endpoints (no JWT).
- **BonesIntegrationController**: authenticated endpoints that sync sales, purchases, and cost data between Bones POS and client databases.
- **MpFunctionController**: shared order orchestrator — validates and dispatches Jobs for create/update/cancel/delete operations on MasterPos.
- **Jobs** (`app/Jobs/`): `RetrySendOrderMp`, `RetryUpdateOrderMp`, `RetryCancelOrderMp`, `RetrySendDeUnaNotificationMp` — all use ping-before-write pattern, queued to database.
- **External APIs**: Contifico (accounting/invoicing), GeFacture, Brevo (transactional email).

## Conventions

- Codebase is in **Spanish** — variable names, route paths, comments, error messages.
- Route prefix pattern: `/integracion-{platform}` for webhooks, `/api/auth/bones-*` for authenticated Bones endpoints.
- Form Request validation classes in `app/Http/Requests/` for Bones endpoints.
- `.http-request` files in repo root are manual API test files (VS Code REST Client format).
- `connect` parameter is base64-encoded when passed between controllers and jobs.
- Item types: `R` = receta (recipe), `I` = item (individual product).
- `id_usuario = 1000` is the system user for all automated operations.

## Gotchas

- `.env` contains real secrets (JWT_SECRET, DB passwords, API keys). Never commit `.env`.
- `phpunit.xml` has SQLite in-memory DB **commented out** — tests use the default `pgsql` connection unless configured otherwise.
- `QUEUE_CONNECTION=database` — ensure `jobs` table migration has run before processing queues.
- The `Verify` middleware expects the `Authorization` header to contain both a valid JWT **and** a company token registered in the DB. Requests will fail with "token no registrado" if the company token doesn't match.
- PHP 7.3 — no named arguments, no enums, no fibers, no `match` expressions, no union types.
- `pingMp()` uses `fsockopen` (TCP check), not actual DB connection — reachable host doesn't guarantee DB availability.
- `id_precuenta` and `id_detalle_precuenta` are manually auto-incremented (not DB sequences) — collision triggers job retry.
- `SerializesModels` trait is commented out in all jobs — they serialize raw data arrays, not Eloquent models.
