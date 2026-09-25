# Hardening report: getting ready for launch

Branch: `phase-hardening`, cut from `phase-3-customer-care`. It has 8 local commits and is not merged or pushed. Date: 2026-09-25.

## 1. What was built

**Launch checks: `php artisan swa:preflight` and Admin → System health**
- Read-only, no network calls, never shows a secret value. The command exits with an error if anything fails.
- **Application:** environment, debug off, app key, https `APP_URL`, secure session cookie, timezone.
- **Payments:**
  - PayMongo keys are not placeholders
  - both keys are in the same mode (test/live) and match `PAYMONGO_LIVEMODE`
  - the webhook secret is a `whsk_…` value
- **Email:** Resend in production with a key, and a sender address that isn't example.com.
- **Server:**
  - queue driver
  - image storage link
  - writable folders
  - **web server folder** (warns while the project sits inside htdocs)
  - **last backup** (warns after 26 hours)
- **Database:** connection, pending migrations, settings present, no `*@swa.test` accounts in production.
- **Operations:**
  - scheduler heartbeat (fails after 3 minutes)
  - jobs waiting more than 10 minutes (worker stopped)
  - failed jobs
  - refunds waiting on a stopped worker
  - the FAQ "shop hours" answer still being a placeholder

**Staff dashboard warning**
A red "Something in the background needs attention" box appears only when an operations check is not OK. It says what is wrong and how to fix it.

**Backups: `php artisan swa:backup`, nightly at 02:00**
- `mysqldump` of the database plus a zip of item photos, kept 14 days. No package.
- The password goes through the environment, never the command line. `.env` is never included.
- The backup folder gets a deny `.htaccess`, file names have a random suffix, and a folder inside `public/` or a relative path is refused.
- The files are owner-only. Pruning never deletes the backup just made.

**Web exposure on XAMPP (found in review, fixed)**
- A project-root `.htaccess` denies everything; `public/.htaccess` re-allows its own folder.
- `docs/DEPLOYMENT.md` explains pointing Apache's DocumentRoot at `public/`, which is the real fix.

**Production defaults**
- **Error pages:** friendly pages for 403/404/500/503 without code or settings. JSON requests still get JSON. The 503 page keeps `Retry-After`.
- **Expired sessions:** the page reloads with "Your session expired".
- **HSTS:** sent on secure production requests only.
- **Logging:** placeholders in `.env.example` for daily logs kept 30 days.

**Documents**
- `docs/DEPLOYMENT.md`:
  - server needs
  - first deploy, including the web server folder
  - keeping the worker and scheduler running (XAMPP/Task Scheduler and Linux)
  - backups
  - PayMongo test → live
  - Resend
  - updating and rollback
  - launch-day checklist
- **README:** backup and restore, including a safe test restore on a copy, and why `APP_KEY` matters.

## 2. Extensions used and skipped
- **Used:** the `security-reviewer` subagent (it found the XAMPP exposure) and the local Playwright script (dashboard warning, System health page at 375 px, no secrets on the page, no console errors).
- **Skipped:**
  - `payments-reviewer`: no money logic changed
  - `vue-builder`: two small pages
  - the Playwright MCP: it was unavailable
  - no packages or paid services were added

## 3. Decisions and assumptions
Recorded in the `docs/SPEC.md` decisions log (2026-09-25). In short:
- Health checks are shared by the command, the admin page and the dashboard.
- Backups run without a package.
- `.htaccess` is a safety net; DocumentRoot on `public/` is the fix.
- `trustProxies` is documented but left off, because trusting every proxy without one in front is unsafe.

## 4. Tests and command outputs

| Check | Result |
|---|---|
| `php artisan test` | 332 passed (1851 assertions) |
| `vendor/bin/pint --test` | passed |
| `npx vue-tsc --noEmit` | no errors |
| `npx eslint .` | no problems |
| `npm run format:check` | all files formatted |
| `npm run build` | built |

New tests cover:
- **Preflight:**
  - a good production setup passes
  - debug, placeholder or mismatched keys, the log mailer and dev accounts fail
  - a stale heartbeat and a stopped worker are caught
  - no secrets are printed
- **Pages:** the dashboard warning, and System health is admin-only.
- **Backups:**
  - the password is not on the command line; no `.env`
  - pruning; a failed dump
  - the deny file and random names
  - refused public/relative paths and odd database names
  - `keep_days=0`
- **Health checks:** old or missing backups, and the htdocs warning.
- **Errors and headers:**
  - error pages in production; JSON stays JSON
  - `Retry-After` on 503
  - 419 redirect
  - HSTS

Checked by hand on this PC through Apache:
- `http://localhost/SWA/.env`, `/SWA/storage/app/backups/…sql` and `/SWA/vendor/…` now return **403**; before the fix they returned 200.
- `/SWA/public/index.php` still returns 200.

## 5. Review findings

| # | Finding | Result |
|---|---|---|
| 1 | **High:** XAMPP served the whole project, so `.env` and the backup were downloadable | Fixed: root and backup-folder deny `.htaccess`, path guard, random names, preflight warning, DEPLOYMENT section. The served dev dump was deleted. |
| 2 | An empty `BACKUP_PATH` became an empty path | Fixed: falls back to the default; relative paths are refused |
| 3 | `keep_days` ≤ 0 would delete the new backup | Fixed: at least 1, and this run's files are never pruned |
| 4 | Backup file permissions | Fixed: 0600 files, 0700 folder (Windows ignores this; the folder is outside the web) |
| 5 | 419 redirect status | Fixed: `back(303)` |
| 6 | The error page could fail, and 503 lost `Retry-After` | Fixed |
| 7 | mysqldump inherits the environment | Accepted: it runs as the same user |
| 8 | Database name could look like an option | Fixed: only letters, digits, `_` and `$` |
| 9 | Zip result not checked | Fixed |
| 10 | No "last backup" check | Added |
| 11 | README restore targeted the live database; no `APP_KEY` note | Fixed |
| 12 | HSTS behind a proxy | Documented (`trustProxies`) |
| 13 | Pending migrations count used `+` on arrays | Fixed: `array_merge` |

## 6. Manual verification checklist
- [ ] Run `php artisan swa:preflight`. Expect warnings for:
  - `APP_ENV=local`, test keys and the log mailer
  - the placeholder webhook secret
  - "inside htdocs"
  - dev accounts
  - fails for the scheduler/worker if they aren't running

  No secret values should appear.
- [ ] Open **Admin → System health**: the same list, grouped.
- [ ] Stop `php artisan queue:work` (or don't start it), reserve something, wait 10 minutes, and open the staff dashboard. The red box names the worker. Start the worker; the box goes away.
- [ ] Stop `schedule:work` for 3 minutes. The dashboard shows the scheduler warning.
- [ ] Run `php artisan swa:backup`. A `.sql` file appears in `storage/app/backups`, and `http://localhost/SWA/storage/app/backups/` shows Forbidden.
- [ ] Do a test restore into `swa_restore_test` (README "Backups"), then drop that database.
- [ ] Open `http://localhost/SWA/.env`: it must show **Forbidden**.
- [ ] With `APP_DEBUG=false` (then `php artisan config:clear`), open `/does-not-exist`: the friendly "Page not found" page. Set `APP_DEBUG=true` back afterwards.

## 7. Risks and open questions
- **Your `.env` was downloadable** on port 80 of this PC before today's fix, by anyone on the same network while Apache was running. Rotate the PayMongo test keys and the Resend key (they were also pasted in chat). Pointing Apache at `public/` is the proper fix before launch.
- Backups stay on the same disk until someone copies them elsewhere. That step is manual.
- The scheduler and worker must be set up as Windows tasks on the live PC (DEPLOYMENT section 3). I did not change system settings on this PC.
- Still open from earlier phases:
  - Claude Code hooks (run Pint after PHP edits, block `.env` edits)
  - whether automated QA may create PayMongo test checkouts
  - whether to commit or gitignore the impeccable design files in `.claude/`
  - the privacy notice wording
  - Resend domain verification
  - the real tunnel test

## 8. What's next
- After you approve: merge the branches (your call) and do the launch steps in `docs/DEPLOYMENT.md`.
- Phase 4 only if you ask for it.
