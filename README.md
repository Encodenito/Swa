# SWA I.T. Supplies Center — reservation system

Customers reserve I.T. supplies online, pay a downpayment through PayMongo, and pick the item up at the shop, where they pay the balance. The shop does not deliver.

- Spec: [docs/SPEC.md](docs/SPEC.md)
- Phase reports: `docs/PHASE_N_REPORT.md`

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `fileinfo`, `zip`, `curl` (XAMPP 8.2 works)
- MariaDB 10.4+ or MySQL 8 (XAMPP's MariaDB works)
- Composer 2, Node 20+ and npm

## Set up from scratch

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
```

Create two databases: one for development and a **separate** one for tests. The tests refuse to run on any database whose name does not end in `_test`.

```bash
mysql -u root -e "CREATE DATABASE swa_reservations CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                  CREATE DATABASE swa_reservations_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Check the `DB_*` values in `.env`, then migrate and seed:

```bash
php artisan migrate --seed
```

Seeding adds the default settings. In the `local` environment it also adds three dev accounts (password `password`):

| Email | Role |
|---|---|
| admin@swa.test | admin |
| staff@swa.test | staff |
| customer@swa.test | customer |

## Run it locally

Use four terminals:

```bash
php artisan serve          # http://127.0.0.1:8000
npm run dev                # Vite dev server
php artisan queue:work     # queued emails and webhook jobs (database queue)
php artisan schedule:work  # expire / forfeit / reminder jobs
```

In development `MAIL_MAILER=log`, so emails (including verification links) are written to `storage/logs/laravel.log`.

## Checks

```bash
php artisan test           # PHPUnit on swa_reservations_test
vendor/bin/pint --test     # PHP style (run without --test to fix)
npm run lint
npm run format:check
npx vue-tsc --noEmit
npm run build
```

If `php artisan test` reports that it is not on a `*_test` database, run `php artisan config:clear`. A cached config overrides `phpunit.xml`.

## Configuration notes

- Timezone: `Asia/Manila` (`APP_TIMEZONE`). Currency: PHP (₱).
- PayMongo keys live in `.env` only. Use **test** keys (`sk_test_…`). Never put live keys in this project.
- `RESEND_KEY` is only needed when `MAIL_MAILER=resend`.

Later phases add sections on simulating a payment (`php artisan paymongo:simulate-webhook`), a real PayMongo test through a tunnel, verifying a Resend domain, and backups.

## Before going to production

- Never copy `.env.example` as-is: set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` and a real `APP_URL`.
- Seed with `php artisan db:seed --class=SettingsSeeder`, not `migrate --seed`. The dev accounts are refused outside `local`/`testing`, but only if `APP_ENV` is set correctly.
- Create the first admin by hand (Phase 1 documents the command). Customers can't delete their own accounts; an admin deactivates accounts instead (Phase 1).
