# Phase 0 report: foundations

Branch: `phase-0-foundations` (not merged, local only). Date: 2026-09-24.

## 1. What was built
- Laravel 12.69.2 with the official Vue starter kit (Inertia 2, Vue 3 + TypeScript, Tailwind 3, Ziggy), on PHP 8.2.12 and MariaDB 10.4 (XAMPP).
- Config: timezone `Asia/Manila`, currency `PHP`, database queue, `MAIL_MAILER=log` in dev, `config/paymongo.php` (v1 endpoints, test mode, keys from env only). `.env.example` holds placeholders only.
- Databases: dev `swa_reservations`, tests `swa_reservations_test`. Before any migration runs, the test base class refuses a database whose name doesn't end in `_test`.
- `Role` enum (`customer`, `staff`, `admin`), a `users.role` column (indexed, default customer, not mass-assignable), `users.phone`, `isStaff()` and `isAdmin()`.
- `role:` middleware on the `/account`, `/staff` and `/admin` groups. `/dashboard` sends each role to its own area. Email verification is enforced.
- Settings: a `settings` table, a `SettingKey` enum holding the six spec defaults, `SettingsService` (cached, flushed on write) and a `setting()` helper. The seeder never overwrites a value an admin changed.
- Dev accounts for local and testing only: `admin@swa.test`, `staff@swa.test`, `customer@swa.test` (password `password`).
- Pint (`laravel` preset). The whole scaffold is formatted.
- Frontend: `PublicLayout`, `AccountLayout`, `StaffLayout`, `AdminLayout`. Shop branding: a price-tag logo, an ink/cobalt/shelf-tag-yellow palette, Archivo type. `formatPeso()` for display. The home page explains the three steps using live settings values. Signed-in pages share the user's id, name, email, role and verification date, and nothing else.
- Security fixes from review: throttles on registration, login per IP, password change and confirm. Self-service account deletion removed.
- README: set up from scratch, run, checks, and production cautions.

## 2. Extensions used and skipped
| Extension | Used for |
|---|---|
| WebFetch (PayMongo docs, Claude Code docs) | Checkout, webhook signature and refund details for the `paymongo-integration` skill; exact formats for subagents and skills |
| `frontend-design` skill | Palette, type and layout direction |
| Playwright MCP | Checked the home page (signed out and signed in) and the staff landing at 375px; found and fixed a wrapping header; console clean |
| `web-security-auditor` agent (standing in for `security-reviewer`) | Phase security review. The project agents in `.claude/agents/` only load in a new session, so they were not available in this one |
| `code-review` skill (low) | Review of the branch diff: no findings |

Skipped:
- **context7:** the Laravel 12 details used here were already confirmed by the installed code and by passing tests.
- **`payments-reviewer` and `stock-tester`:** there is no payment or stock code yet.
- **Database MCP:** none is installed; used the `mysql` command line on the local database instead.
- **GitHub and GitKraken:** you chose local git only.
- **PDF skill:** Phase 4.
- **Unrelated connectors** (Canva, Shopify, Drive and others): not relevant to this project.

## 3. Decisions and assumptions
- **Laravel 12, not 13:** 13 needs PHP 8.3.
- **MariaDB 10.4 instead of MySQL:** same InnoDB locking. Note that 10.4 no longer gets updates; consider upgrading XAMPP's MariaDB before production.
- **PayMongo checkout endpoint:** `/v1/checkout_sessions`. The docs list no v2 checkout endpoint, contrary to the spec. The path lives in config.
- **Refund error codes:** the docs' names differ from the spec's. `resource_not_found` corresponds to the spec's `payment_not_found`, and `parameter_above_maximum` to `refund_amount_exceeds_payment`. Recorded in the `paymongo-integration` skill.
- **Resend env variable:** `RESEND_KEY`, because that is what `config/services.php` reads.
- **Pulled forward from Phase 1:** role middleware, email verification and dev accounts. Without them, the role placeholder pages would be open to anyone and the "verified" check would do nothing.
- **Account deletion:** removed. An admin will deactivate accounts instead (Phase 1).
- **Starter kit version:** the early 2025 release, which is what Composer installed for PHP 8.2. I fixed its TypeScript errors (tsconfig `types`, `SharedData` index signature, `tabindex` types) and removed unused components.
- **CI:** `.github/workflows` removed. They targeted SQLite on GitHub, which this project doesn't use.
- **Browser formatting:** `formatPeso()` uses the browser's `Intl`. PHP's `intl` extension is not installed, so PHP must not use `Number::currency()`.

## 4. Tests and command output
New tests:
- `RoleTest` (3)
- `SettingsTest` (5)
- `EnvironmentTest` (4)
- `DashboardTest` (rewritten: 9 cases covering guests, each role's landing, verification, cross-role 403s and shared-prop allow-list)
- `RateLimitAndRoutesTest` (3)
- `ProfileUpdateTest::test_accounts_cannot_be_self_deleted`

```
php artisan test          → Tests: 48 passed (133 assertions)
vendor/bin/pint --test    → passed
npx vue-tsc --noEmit      → no errors
npm run lint              → no errors
npm run format:check      → All matched files use Prettier code style
npm run build             → built
```

## 5. Review findings
**Fixed**
- **Medium:** registration had no throttle. It is now 5 per hour per IP.
- **Medium:** login was throttled per email+IP pair only. Added 20 per minute per IP.
- **Medium:** self-service hard deletion of accounts would conflict with audit and history rules. Route, controller method and UI removed.
- **Low:** a mistyped role name in the middleware gave a 500 with a stack trace. It now raises a clear error, and a test checks every `role:` parameter.
- **Low:** password change and password confirmation had no throttle. Both are now 6 per minute.

**Deferred**
- **Low:** the dev-account seeder is guarded only by `APP_ENV`. It is safe unless production is misconfigured; a README production checklist was added. Revisit with the deploy guide.
- **Test-coverage gap:** the Phase 1 privacy notice at registration and the admin account-deactivation flow are not built yet.

## 6. Manual verification checklist
1. Run `php artisan migrate --seed` (already done on `swa_reservations`), then `php artisan serve` and `npm run dev`.
2. Open http://127.0.0.1:8000 at phone width (browser devtools, 375px). The header fits on one line, the 3 steps show "30%" and "3 days", and the footer says "Pickup only".
3. Log in as `customer@swa.test` / `password`. You land on "My reservations" at `/account`.
4. Visit `/staff` and `/admin` as the customer. Both show 403.
5. Log in as `staff@swa.test`. You land on `/staff`, and `/admin` gives 403. Log in as `admin@swa.test`. You land on `/admin`, and `/staff` opens.
6. Register a new account. You are sent to "verify email". Copy the link from `storage/logs/laravel.log`, open it, and you land on `/account`.
7. Change a value: `php artisan tinker` → `app(App\Services\SettingsService::class)->set(App\Enums\SettingKey::PickupDays, 5)`. Reload the home page and it says "5 days". Set it back to 3.
8. Settings → Profile no longer has a "Delete account" section.
9. Run `php artisan test`: 48 passed.

## 7. Risks and open questions
- **Hooks (your decision):** (a) run Pint after each PHP edit; (b) block edits to `.env`. They were proposed but not added.
- **Privacy notice:** the Data Privacy Act wording at registration (Phase 1) should be checked by someone qualified.
- **MariaDB 10.4:** end of life. Locking tests in Phase 1 will confirm the concurrency behaviour on this server.
- **Project subagents:** the ones in `.claude/agents/` become available after Claude Code restarts. Phase 1 reviews will use `payments-reviewer` and `security-reviewer` directly.

## 8. Next phase (Phase 1: core reservation)
- Privacy notice and phone number at registration.
- Policies, admin deactivation of accounts, and a command to create the first admin.
- Catalog: categories, items, suppliers, public list and detail with the Clearance badge and "Only N left".
- Stock Receiving with batches and movements, and stock adjustments.
- `ReservationService`: oldest-batch allocation with locking and deadlock retry, per-line prices, limits.
- Review page with the terms checkbox, PayMongo checkout, the verified webhook, the return page that polls, and `paymongo:simulate-webhook`.
- `reservations:expire`.
- Staff reservations: list, Start processing, deadline extension, pickup counter.
- Customer reservations list and the admin settings page.
