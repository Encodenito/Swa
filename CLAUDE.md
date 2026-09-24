# SWA I.T. Supplies Center — reservation system

Full spec: `docs/SPEC.md` (sections A–M). Phase reports: `docs/PHASE_N_REPORT.md`.
Business rules: skill `swa-business-rules`. PayMongo details: skill `paymongo-integration`.

## Stack
- Laravel 12 on PHP 8.2 (XAMPP), MariaDB 10.4. Dev DB `swa_reservations`, test DB `swa_reservations_test`.
- Inertia 2 + Vue 3 `<script setup>` + TypeScript, Vite, Tailwind 3 (official Vue starter kit), Ziggy `route()`.
- PHPUnit. Laravel Pint. ESLint + Prettier. Timezone `Asia/Manila`, currency PHP (₱). PHP `intl` is NOT installed: do not use `Number::currency()`.

## Commands
- Tests: `php artisan test` (uses `swa_reservations_test`, never the dev DB)
- PHP style: `vendor/bin/pint` (check: `vendor/bin/pint --test`)
- Frontend: `npm run build`, `npm run lint`, `npm run format:check`
- Queue worker: `php artisan queue:work` · Scheduler: `php artisan schedule:work`
- Dev server: `php artisan serve` + `npm run dev`
- Simulated payment (local only): `php artisan paymongo:simulate-webhook SWA-XXXXXXXX`

## Conventions
- Business logic in `app/Services`; thin controllers; Form Requests; Policies on every model access; enums in `app/Enums`.
- Money is `decimal(10,2)` everywhere; integer centavos only at the PayMongo boundary.
- Stock changes only through `stock_movements`. Migrations only, never manual schema edits.
- Git: local only, branch `phase-N-short-name`, small imperative commits. Never push, never merge phase branches.

## Integrity invariants (never violate — spec section K)
1. Server computes every price, total and downpayment; never trust client amounts.
2. Only a verified PayMongo webhook confirms a reservation; never a browser redirect.
3. Verify `Paymongo-Signature` on the raw body with `hash_equals` before parsing; reject with 400.
4. Webhook handling is idempotent (row lock + unique `payments.provider_ref`).
5. Every status change is atomic (`lockForUpdate` or conditional update) and re-checks current status; conflicts fail cleanly (409/validation).
6. Stock allocation: one transaction, `lockForUpdate` on batches, oldest first (`received_at, id`), items locked in item-id order, retry deadlocks up to 3 times.
7. No external HTTP call inside a transaction that holds locks.
8. Refunds are never retried automatically; manual retries claim the row atomically (`refund_failed` → `refund_pending`).
9. Policies on every model access (customers see only their own); `role:` middleware on route groups.
10. Form Requests, `$fillable`, rate limits on login, reservation creation, chatbot.
11. Secrets only from env; placeholders only in `.env.example`; never log secrets; never commit `.env`.
12. Never store card data.
13. Audit-log refunds, voids, cancels, price/clearance changes, stock adjustments, deadline extensions, role changes.
14. Minimal personal data; privacy notice at registration.

## Safety
- Test keys only (PayMongo `sk_test_`, Resend test). Tests use `Http::fake()`, `Notification::fake()`, `Queue::fake()`.
- No `migrate:fresh`/`db:wipe` except on `swa_reservations` / `swa_reservations_test`. Do not touch other `swa_*` databases.
- Do not start tunnels or register PayMongo webhooks.
