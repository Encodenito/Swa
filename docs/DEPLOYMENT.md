# Going live: deployment guide

This guide is for whoever sets up the live shop server: the owner, or a technician helping them. It assumes you have read the README and the app runs locally.

**The rule for every step:** run `php artisan swa:preflight` and fix everything marked **FAIL** before customers use the site. The same checks are on the admin page **Admin → System health**.

---

## 1. What the server needs

| Need | Notes |
|---|---|
| PHP 8.2+ | With `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `fileinfo`, `zip`, `curl` |
| MariaDB 10.4+ or MySQL 8 | A database used only by this app |
| A web server serving `public/` | Apache (XAMPP) or Nginx. Only the `public` folder may be reachable from the internet. |
| An https address | Required by PayMongo and for logins. Use a real domain with a certificate (e.g. Let's Encrypt). |
| Always-on processes | The **queue worker** (emails, refunds) and the **scheduler** (expiry, forfeits, reminders, backups) |
| Outbound internet | To `api.paymongo.com` and Resend |

A PC that is switched off at night cannot run the shop. Unpaid reservations would not expire and refunds would not be sent. Use a machine that stays on, or a small hosted server.

## 2. First deployment

1. **Get the code** onto the server (copy the project folder, or clone your repository). Do not copy your local `.env`.
2. **Install:**
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   ```
3. **Create `.env`** from `.env.example` and set:

   | Key | Value |
   |---|---|
   | `APP_ENV` | `production` |
   | `APP_DEBUG` | `false` |
   | `APP_URL` | `https://your-domain` |
   | `APP_KEY` | run `php artisan key:generate` **once**, and never change it afterwards |
   | `DB_*` | the live database |
   | `SESSION_SECURE_COOKIE` | `true` |
   | `LOG_STACK=daily`, `LOG_DAILY_DAYS=30`, `LOG_LEVEL=warning` | one log file per day, kept 30 days |
   | PayMongo and Resend keys | see sections 5 and 6 |

4. **Database:**
   ```bash
   php artisan migrate --force
   php artisan db:seed --class=SettingsSeeder --force
   php artisan db:seed --class=ChatbotFaqSeeder --force
   ```
   Never run `migrate --seed`, `migrate:fresh` or the demo seeders on the live server.
5. **First admin:** `php artisan swa:create-admin you@yourshop.ph --name="Your Name"`. Then create staff accounts under **Admin → Accounts**. Each person must change the temporary password at first login.
6. **Images and caches:**
   ```bash
   php artisan storage:link
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
7. **Background processes:** see section 3.
8. **Check:** run `php artisan swa:preflight`. Only warnings you understand may remain.
9. **Content:** under **Admin → Chatbot FAQ**, replace the "shop hours and address" answer. Under **Admin → Settings**, confirm the downpayment %, pickup days and forfeit %.

## 3. Keeping the background processes running

**Two things must run all the time.** If either stops, the staff dashboard shows a red "Something in the background needs attention" box.

| Process | Command | What happens if it stops |
|---|---|---|
| Scheduler | `php artisan schedule:run`, **every minute** | Unpaid reservations never expire (stock stays held), no forfeits, no reminders, no backups |
| Queue worker | `php artisan queue:work --tries=1 --max-time=3600` | No emails. Refunds wait and are not sent. |

The worker's `--tries=1` matches the app. Refund jobs never retry themselves. `--max-time=3600` restarts the worker every hour, so memory stays clean after updates.

### Windows (XAMPP)
- **MariaDB and Apache as services:** in the XAMPP Control Panel (run as administrator), tick the **Service** box next to Apache and MySQL. They then start with Windows.
- **Scheduler:** Task Scheduler → Create Task:
  - Triggers: "At startup", repeat every **1 minute**, indefinitely.
  - Action: `C:\xampp\php\php.exe`, arguments `artisan schedule:run`, start in `C:\xampp\htdocs\SWA` (your path).
  - Tick "Run whether user is logged on or not".
- **Queue worker:** a second task:
  - Triggers: "At startup", and also every 1 hour.
  - Action: `C:\xampp\php\php.exe`, arguments `artisan queue:work --tries=1 --max-time=3600`.
  - Settings: "If the task is already running: Do not start a new instance".

### Linux
```cron
* * * * * cd /var/www/swa && php artisan schedule:run >> /dev/null 2>&1
```
```ini
; /etc/supervisor/conf.d/swa-worker.conf
[program:swa-worker]
command=php /var/www/swa/artisan queue:work --tries=1 --max-time=3600
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/www/swa/storage/logs/worker.log
```

## 4. Backups

`php artisan swa:backup` runs every night at 02:00 through the scheduler:
- It saves the database and the uploaded item photos to `storage/app/backups`.
- It keeps 14 days (`BACKUP_KEEP_DAYS`).
- It never includes `.env`.

**Copy that folder somewhere else every week** (external drive or cloud storage). A backup on the same disk does not survive a disk failure.

Restore steps are in the README. **Test a restore once** on a spare database before you rely on backups.

## 5. PayMongo: from test to live

Do this only after the tunnel test in the README worked in test mode.

1. **Replace the test keys** that were shared in chat during development, in the PayMongo dashboard.
2. **Activate your PayMongo account for live payments.** PayMongo verifies the business first.
3. **Register the live webhook** in the dashboard (Live mode → Developers → Webhooks):
   - URL `https://your-domain/webhooks/paymongo`
   - Event: only `checkout_session.payment.paid`
4. **In `.env`:**
   ```
   PAYMONGO_SECRET_KEY=sk_live_…
   PAYMONGO_PUBLIC_KEY=pk_live_…
   PAYMONGO_WEBHOOK_SECRET=whsk_…   (from the live webhook)
   PAYMONGO_LIVEMODE=true
   ```
   Then run `php artisan config:cache` and `php artisan swa:preflight`. Preflight fails if the keys and `PAYMONGO_LIVEMODE` don't match.
5. **One real test:** reserve a cheap item with your own GCash or card. Confirm it becomes **Confirmed**. Then cancel it and check the refund reaches you. Note that PayMongo keeps its fee on refunds.
6. **Keep the secret key only in `.env`.** Never email or chat it.

## 6. Emails: Resend

1. Resend → Domains → add your domain, add the DNS records it shows, and wait for **Verified**.
2. Create an API key with **Sending access**, and put it in `RESEND_KEY`.
3. `.env`: set `MAIL_MAILER=resend` and `MAIL_FROM_ADDRESS=reservations@your-domain`, then run `php artisan config:cache`.
4. Send yourself a test (README, "Emails with Resend"). **Admin → Messages sent** then lists every email the shop sends.

## 7. Updating the live site

```bash
php artisan down --secret="choose-a-long-random-word"   # staff can still open https://your-domain/choose-a-long-random-word
git pull            # or copy the new files
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
php artisan swa:preflight
```

**Take a backup before updating:** run `php artisan swa:backup`.

**If an update goes wrong:** put the previous files back, then restore the backup taken just before the update (README restore steps), then run `php artisan up`. Never "fix" money or stock by editing the database by hand. Use the app's cancel, refund, adjustment and void screens, so the audit log stays complete.

## 8. Launch-day checklist

- [ ] `php artisan swa:preflight` shows **0 failed**
- [ ] Development accounts `*@swa.test` do not exist on the live database
- [ ] Your admin and staff accounts exist, and temporary passwords have been changed
- [ ] Scheduler and queue worker start automatically after a reboot (reboot once and check the dashboard)
- [ ] https works, and http redirects to https (a web server setting)
- [ ] PayMongo is live: one real payment and one refund done
- [ ] The Resend domain is verified, and you received the test email
- [ ] The nightly backup ran, and a copy exists off the server
- [ ] The chatbot "shop hours" answer and the shop settings are correct
- [ ] The privacy notice wording has been checked (Data Privacy Act)
