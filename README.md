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

Seeding adds the default settings. In the `local` environment it also adds a small demo catalog and three dev accounts (password `password`):

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
- PHP `gd` and `intl` are not needed. Item images are validated without GD.
- Run `php artisan storage:link` once so uploaded item images are served.

## Try a reservation locally (simulated payment)

PayMongo cannot reach `localhost`, so a local command sends the same signed webhook PayMongo would.

1. Log in as `customer@swa.test`, open **Browse items**, add something to the cart and open the cart.
2. Tick the terms box and choose **Pay downpayment**. The app creates a PayMongo **test** checkout (your `sk_test_` key) and sends you to it. You don't need to pay there.
3. Copy the reference code (`SWA-XXXXXXXX`) from **My reservations**, then run:

   ```bash
   php artisan paymongo:simulate-webhook SWA-XXXXXXXX
   ```

   Expected output ends with `200 {"result":"confirmed"}`. The reservation is now **Confirmed** with a pickup deadline.
   - Run it again with `--payment-id=<the id it printed>` to see duplicate handling (`duplicate`).
   - Run it with `--amount=1` to see a payment stored for review (`needs_review`).
4. Unpaid reservations expire after `pending_minutes` (30). Either keep `php artisan schedule:work` running, or wait and run `php artisan reservations:expire`.

The simulator signs with `PAYMONGO_WEBHOOK_SECRET`. Any value works locally, as long as the app and the command read the same `.env`. It refuses to run outside `APP_ENV=local`.

## Real PayMongo test through a tunnel (done by the owner)

This test is manual. The app never starts tunnels or registers webhooks by itself.

1. Start a tunnel to `http://127.0.0.1:8000`, for example `cloudflared tunnel --url http://127.0.0.1:8000` or `ngrok http 8000`. Set `APP_URL` to the https tunnel URL and run `php artisan config:clear`.
2. In the PayMongo dashboard, switch to **Test mode**, then go to **Developers → Webhooks → Create webhook**:
   - URL: `https://<tunnel>/webhooks/paymongo`
   - Events: only `checkout_session.payment.paid`
3. Copy the webhook's secret key (`whsk_…`) into `PAYMONGO_WEBHOOK_SECRET` in `.env`, then run `php artisan config:clear`.
4. Reserve an item and pay on PayMongo's test checkout with a test card or test GCash. The return page shows "Confirming your payment…" until the webhook arrives, then the reservation opens as **Confirmed**.
5. In local mode the first webhook payload is written to `storage/logs/laravel.log`. Check it against the parser if anything looks off.
6. Afterwards, disable the test webhook in the dashboard and set `APP_URL` back.

## First admin account (non-local environments)

```bash
php artisan swa:create-admin owner@example.com --name="Shop Owner"
```

It prompts for a password. Run it on an existing email to promote that account to admin.

## Background workers and scheduled jobs

Emails and PayMongo refunds are queued, so `php artisan queue:work` must be running. Without a worker nothing is sent; refunds wait as "refund pending". Scheduled jobs (`php artisan schedule:list`):

| Command | When | What it does |
|---|---|---|
| `reservations:expire` | every minute | Expires unpaid reservations and returns their stock |
| `reservations:forfeit` | hourly | Ends reservations past their pickup deadline, returns the stock, refunds `forfeit_refund_percent`% |
| `reservations:remind` | daily 08:00 | Emails (and logs an SMS) for pickups due within 24 hours, once per reservation |

Refunds are **never retried automatically**. A refund PayMongo refuses shows under **Staff → Payments to review**, and a person presses **Retry refund** once the cause is fixed (for example after topping up the PayMongo balance). The same screen shows payments that arrived after a reservation expired (**Refund in full**), and refunds with no answer after 15 minutes (check the PayMongo dashboard before acting).

## Emails with Resend

In development `MAIL_MAILER=log`, so every email is written to `storage/logs/laravel.log`. To send real emails:

1. In Resend, **add and verify your domain** (Domains → Add domain). Add the DNS records it shows (SPF/DKIM) at your domain host and wait until Resend shows *Verified*. Until then Resend only delivers to your own Resend account address.
2. Create an API key with **Sending access** only, and put it in `.env` as `RESEND_KEY`.
3. In `.env` set `MAIL_MAILER=resend` and `MAIL_FROM_ADDRESS` to an address **on the verified domain** (for example `reservations@yourshop.ph`), then run `php artisan config:clear`.
4. Test with a single email to yourself:

   ```bash
   php artisan tinker --execute="Illuminate\Support\Facades\Mail::raw('Resend test from SWA', fn (\$m) => \$m->to('you@example.com')->subject('SWA test'));"
   ```

The API key is secret: keep it only in `.env`, never in git or chat. Walk-in customers never receive email.

## Backups

No backup package is installed; these are manual steps (ask before adding a package).

**What to back up:** the database `swa_reservations` and the uploaded images in `storage/app/public`. `.env` holds secrets; keep a copy somewhere safe, but separately from the other backups.

**Back up** (run from the project folder in Git Bash):

```bash
mkdir -p ~/swa-backups
STAMP=$(date +%Y%m%d-%H%M)
/c/xampp/mysql/bin/mysqldump -u root --single-transaction --routines swa_reservations > ~/swa-backups/swa-$STAMP.sql
tar -czf ~/swa-backups/swa-images-$STAMP.tar.gz storage/app/public
```

**Schedule it daily** with Windows Task Scheduler: create a basic task that runs `"C:\Program Files\Git\bin\bash.exe" -c "cd /c/xampp/htdocs/SWA && ./backup.sh"` after saving the commands above as `backup.sh`. Copy the backup folder to a second place (external drive or cloud storage) regularly, and delete backups older than 30 days.

**Restore** (this overwrites the database, so try it on a copy first):

```bash
/c/xampp/mysql/bin/mysql -u root -e "CREATE DATABASE IF NOT EXISTS swa_reservations CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
/c/xampp/mysql/bin/mysql -u root swa_reservations < ~/swa-backups/swa-YYYYMMDD-HHMM.sql
tar -xzf ~/swa-backups/swa-images-YYYYMMDD-HHMM.tar.gz
php artisan storage:link
```

Test a restore at least once before relying on the backups.

## Before going to production

- Never copy `.env.example` as-is: set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` and a real `APP_URL`.
- Seed with `php artisan db:seed --class=SettingsSeeder`, not `migrate --seed`. The dev accounts are refused outside `local`/`testing`, but only if `APP_ENV` is set correctly.
- Create the first admin with `php artisan swa:create-admin`, then manage staff under Admin → Accounts. Customers cannot delete their own accounts; admins deactivate accounts instead (history is kept).
- Run `php artisan queue:work` and the scheduler (`php artisan schedule:run` every minute from cron or Task Scheduler) under a process manager.
