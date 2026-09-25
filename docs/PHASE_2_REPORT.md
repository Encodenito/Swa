# Phase 2 report: money rules and safety

Branch: `phase-2-money-rules`, cut from `phase-1-core-reservation`. Local commits only, not merged or pushed. Date: 2026-09-26.

## 1. What was built

**Refunds (`RefundService`, `SendRefund` job)**
- A refund row is created as `refund_pending` inside the same transaction as the cancel or forfeit.
- After commit, a queued job sends it to PayMongo (`POST /v1/refunds`, amount in centavos, reason `others`, notes, and `metadata.refund_id`).
- Each refund is capped at what is still refundable on that payment, and skipped below ₱1.00.
- **Never retried automatically.** Each refund is attempted once. The job claims the row before calling PayMongo, so a re-run of the same job can't send it twice.
- A definite PayMongo refusal (for example `available_balance_insufficient`) becomes `refund_failed`. A person presses Retry, and the retry claims the row, so a double click still sends only one call.
- A timeout, connection error or 5xx leaves the refund `refund_pending` marked "outcome unknown". The refund may already exist at PayMongo, so it is never resent blindly. An admin reconciles it (see Payments to review).

**Cancel (`ReservationService::cancel`)**
- **Customer:** from Awaiting payment (nothing to refund) or Confirmed, with a 100% refund. Refused once the order is Processing, with the message "We have already started preparing this order…".
- **Staff:** with a reason, 0% refund only. **Admin:** with a reason, choosing 0%, 50% or 100%. Both are enforced in the Form Request and again in the service, under the row lock.
- **Stale page:** a staff or admin cancel carries the status the page showed. If a payment confirmed the reservation meanwhile, the cancel is refused.
- **Race:** a customer cancel racing Start processing lets exactly one win (tested with two processes).
- **Unpaid cancels:** cancelling an unpaid reservation closes its PayMongo checkout session.

**Forfeit (`reservations:forfeit`, hourly)**
- Reservations past their pickup deadline become Forfeited, their stock returns to its batches, and `forfeit_refund_percent`% (default 50%) of the downpayment is refunded.

**Walk-in sales (Staff → Walk-in sale)**
- Items are picked on screen. Prices come from the server (oldest batch first, clearance price first) and are paid in full in cash; stock leaves through `sold` movements.
- The receipt page has a Print button.
- **Admin void:** requires a reason. Stock returns to the exact batches through `adjusted` movements, and a cash refund row is recorded.

**Payments to review (staff)**
- **Paid but not applied** (late, wrong amount, or from another checkout session):
  - Refund in full (staff), or
  - Keep payment, mark reviewed (admin only, needs a reason).
- **Refunds that failed:** Retry refund.
- **Refunds with no answer** (outcome unknown for more than 15 minutes): an admin Reconcile dialog, after checking the PayMongo dashboard.
  - "Found": enter the `ref_` id, and the refund is recorded as refunded.
  - "Not found": the refund is marked failed, and Retry becomes available.
  - Nothing is sent to PayMongo from this dialog.

**Emails (Resend) and SMS**
- **Emails:** ReservationConfirmed, ReservationProcessing, PickupReminder, ReservationCancelled (says card refunds can take up to 30 days to appear) and ReservationForfeited.
- **How they're sent:** queued, only after the change is saved, 3 tries with backoff, and only when the state really changed. Walk-ins never get email.
- **Reminders:** `reservations:remind` runs daily at 08:00, once per reservation, for pickups due within 24 hours.
- **SMS:** an `SmsSender` interface; the log implementation masks phone numbers until Phase 4. `resend/resend-php` is installed; dev stays on `MAIL_MAILER=log`.

**Audit log (admin screen, with filters)**
- Covers refunds (created, sent, failed, outcome unknown, retried, reconciled), cancels, forfeits, voids, item price and clearance changes, stock adjustments, deadline extensions (replacing the Phase 1 log line), settings changes, payment reviews, and account creation, role changes and deactivation.
- Each entry records who, when, IP, and before → after.

**Reports (admin)**
- Sales per day: reservations plus walk-ins, voids excluded.
- Forfeited downpayments kept.
- Slow-moving items (in stock with no sale for N days).
- Each report is one aggregate query.

**Accounts (admin)**
- Create staff or admin accounts; the temporary password must be replaced at first login, and staff/admin passwords need 12+ characters.
- Change role (reason required) and deactivate or reactivate.
- Nobody can change their own role, and the last active admin cannot be demoted or deactivated.

**Staff reserve for a customer**
- Pick a verified customer account, then the items. Tick "The customer agreed to these terms", which shows the live terms text.
- The reservation belongs to the customer, and staff get the PayMongo payment link to hand over.

**Safety**
- Rate limits on every staff and admin write (30/min per user; account changes 10/min).
- Walk-in phone numbers must be Philippine mobile numbers; spaces and dashes are accepted.
- The cancel form tells staff that the customer sees the reason.

**README:** background workers, how refunds behave, Resend domain verification, backups (`mysqldump` plus images, Task Scheduler, restore), and production notes.

## 2. Extensions used, and skipped
| Extension | Used for |
|---|---|
| WebFetch (PayMongo docs) | Refund request fields and reasons, the 100-centavo minimum, metadata. Confirmed that no refund lookup API is documented, which led to manual reconciliation. |
| `vue-builder` ×2, in parallel | Customer and staff pages; admin pages (Accounts, Reports, Audit log) |
| `stock-tester` | 17 tests: walk-in sale, void and forfeit stock. Found no bugs in the code. |
| `payments-reviewer` | 1 high-severity finding, 4 medium, 2 low. All fixed (section 5). |
| `security-reviewer` | 0 critical or high, 2 medium, 4 low, plus 3 design points. All fixed or recorded as decisions. |
| Playwright MCP (by me) | Admin 50% cancel, walk-in sale, receipt and void, customer cancel with refund status, at 375px. Console clean. Found 2 UI defects, fixed. |

**Skipped:**
- **`qa-e2e` agent:** I drove the browser myself, because the previous run was cut off by a usage limit.
- **context7:** not needed.
- **GitHub:** local git only.

## 3. Decisions and assumptions
All ten are recorded in the `docs/SPEC.md` decisions log (2026-09-26).
- **How refunds are sent:** a one-attempt queued job, sent only after the change is saved, reason `others`, skipped below ₱1.00.
- **Unknown refund outcomes** are never resent. An admin reconciles them against the dashboard.
- **Who can cancel an unpaid reservation:** staff and admin can too, as well as the customer. Cancelling one closes its PayMongo checkout.
- **Admin accounts:** admins can create admin accounts. There is no separate "owner" role; the guards are "not yourself" and "not the last active admin".
- **"Keep payment, mark reviewed"** is admin-only.
- **Walk-in void** records a cash refund.
- **The cancel reason is visible to the customer.**
- **Deferred:** a PayMongo payment for an unknown reference code is only logged. There is no reservation to attach it to.

## 4. Tests and command outputs
New test files (80 tests):
- **Money:** `RefundTest`, `CancelTest`, `PaymentReviewTest`, `NotificationTest`, `ReviewFindingsTest`
- **Stock:** `WalkInStockTest`, `WalkInVoidTest`, `ForfeitStockTest`
- **Admin:** `AccountsTest`, `ReportsAndAuditTest`
- **Concurrency:** `CancelVersusProcessingRaceTest`
- **Smoke test:** extended with 6 new pages

Phase 2 "done when" items:

| Done when | Test |
|---|---|
| Cancelling a confirmed reservation creates a 100% refund row and returns the stock | `CancelTest::test_customer_cancels_a_confirmed_reservation_with_a_full_refund…` |
| Customer cancel of a processing reservation is blocked; exactly one of cancel and Start processing wins | `CancelTest` (both orders, sequential) and `CancelVersusProcessingRaceTest` (two processes, 4 rounds). I confirmed it catches the bug: without the row lock, both requests won. |
| A backdated deadline forfeits with a 50% refund row | `ForfeitStockTest`, `NotificationTest::test_forfeit_email…`, `ReportsAndAuditTest::test_forfeited_report…` |
| A failed refund becomes `refund_failed` and is never retried automatically | `RefundTest::test_a_failed_refund…` (the whole scheduler runs 2 hours later and there is still exactly one HTTP call), plus the manual retry and double-click tests |
| A payment after expiry is `needs_review`, status unchanged | `PayMongoWebhookTest` (Phase 1) and `PaymentReviewTest` (review → refund in full) |

```
php artisan test          → Tests: 256 passed (1484 assertions)
vendor/bin/pint --test    → passed
npx vue-tsc --noEmit      → exit 0
npx eslint .              → exit 0
npm run format:check      → All matched files use Prettier code style
npm run build             → built
```

## 5. Review findings

**Fixed**
- **High (payments): a timed-out refund could be refunded twice.** It was treated as failed, and Retry would resend a refund that may already exist. Now only a definite refusal is marked failed. Unknown outcomes stay pending, can't be retried, and must be reconciled by an admin. Tests: timeout, 502, definite refusal, reconcile found and not found.
- **Medium (payments): a re-run of the same job could send a refund again.** It now claims the row (`refund_attempted_at`) before calling PayMongo. Test: running the job twice makes one HTTP call.
- **Medium (payments): stuck refunds had no way forward.** Added the admin Reconcile action.
- **Medium (payments): a staff cancel could land on a reservation just confirmed at 0%.** The cancel now carries the status the page showed.
- **Medium (both reviewers): an unpaid cancel left its checkout open.** The checkout is now closed.
- **Medium (security): staff could reserve for unverified customers.** Now refused, and re-checked under the lock.
- **Low:**
  - A reminder could be sent for a reservation cancelled a moment earlier.
  - The forfeit report counted refunds of other payments.
  - Staff and admin write routes had no rate limits.
  - The cancel reason visible to customers is now flagged to staff.
  - New staff accounts now must replace the temporary password (12+ characters).
  - Walk-in phone format is now checked.
- **Test harness (not app code):** the race test's fake gave every refund the same PayMongo id, which crashed the child process on the unique index. It now uses unique ids. The shared test database was also hit when two agents ran tests at once; test runs are now kept serial.
- **QA (browser):**
  - Cancelled reservations still showed a "Balance". It is now hidden.
  - The phone placeholder shows spaces, which validation rejected. Spaces and dashes are now accepted.

**Deferred**
- **Unknown reference:** a PayMongo payment for an unknown reference is only logged; it doesn't reach the review screen. It needs an "orphan payments" table (Phase 3).
- **Admin-only reconciliation:** reconciling means reading the PayMongo dashboard; no refund lookup API is documented. If your tunnel test shows `GET /v1/payments/{id}` lists refunds, reconciliation can be automated later.
- **No owner role:** any admin can manage other admins, except themselves and the last active one.

## 6. Manual verification checklist
Start `php artisan serve` and `npm run dev`. Don't start `queue:work` if you want refunds to stay local. With a worker running, refunds go to PayMongo **test mode** with your `sk_test_` key.

1. **Customer cancel (full refund):** log in as `customer@swa.test`, reserve an item and go to PayMongo's test page, then run `php artisan paymongo:simulate-webhook SWA-…`. Open the reservation, choose **Cancel reservation** and confirm. It shows **Cancelled**, and the payments list shows "Refund on its way". `storage/logs/laravel.log` contains the cancellation email once `queue:work` runs.
2. **Processing lock:** confirm another reservation. As `staff@swa.test`, open it and press **Start preparing**. As the customer, the Cancel button is gone and the page says it can no longer be cancelled.
3. **Staff and admin cancel:** as staff, the cancel form offers "No refund" only and requires a reason. As `admin@swa.test`, choose 50%, and the refund row shows half the downpayment.
4. **Forfeit:** confirm a reservation, then in `php artisan tinker` run `App\Models\Reservation::where('reference_code','SWA-…')->update(['pickup_by' => now()->subHour()])`, then `php artisan reservations:forfeit`. The status is **Forfeited**, a 50% refund row appears, and the stock is back.
5. **Walk-in and void:** Staff → **Walk-in sale**, add items and record. The receipt shows the server total; print it if you like. As admin, **Void sale** with a reason: the stock returns, and the cash refund appears in the payment record.
6. **Payments to review:** simulate a payment for an expired reservation (let one expire, run `reservations:expire`, then `paymongo:simulate-webhook`). It appears under "Paid but not applied"; try **Refund in full**. The two QA refunds already in your dev database (from my browser test) have no job, so after 15 minutes they show under "Refunds with no answer". As admin, try **Reconcile → There is no such refund**, and Retry becomes available.
7. **Emails:** run `php artisan queue:work` and watch `storage/logs/laravel.log` for the rendered emails. Run `php artisan reservations:remind` after moving a deadline to within 24 hours.
8. **Accounts:** Admin → **Accounts** → add a staff account. Log in as it and you're sent to change the password first. Try to deactivate yourself and it is refused.
9. **Reports and audit log:** Admin → **Reports** (sales per day, forfeited kept, slow-moving) and **Audit log** (filter by "Reservation cancelled").
10. **Reserve for a customer:** Staff → **Reserve for a customer**, search `customer@`, pick items, tick the terms box. You land on the reservation with the payment link (this creates a PayMongo **test** checkout).

## 7. Risks and open questions
1. **Hooks (still unanswered):** add Pint after PHP edits, and block `.env` edits?
2. **Automated test checkouts and refunds:** should automated QA be allowed to create PayMongo **test** checkouts and refunds? Until you say yes, QA stays local.
3. **PayMongo refund behaviour to confirm on your tunnel test:**
   - whether same-day partial refunds are rejected for GCash and card (a 50% forfeit or cancel is a partial refund)
   - whether `GET /v1/payments/{id}` lists refunds (this would allow automatic reconciliation)
   - what a refund response looks like (`data.id`, status)
4. **Privacy notice:** the registration page wording still needs checking by someone qualified.
5. **Your PayMongo balance:** refunds fail with `available_balance_insufficient` when it is too low. They then wait under "Payments to review" until someone retries.
6. **Resend:** the domain is not verified yet (README steps). Until it is, no customer receives email.
7. **Backups:** documented but not automated. Say if you want a backup package (I'll ask before adding one).

## 8. What Phase 3 will do (customer care)
- **CRM:** customer profile with reservation history, notes, tags, follow-ups with due dates, and simple segments (for example "no pickup in 90 days" or "forfeited before").
- **Message log** from Laravel's `NotificationSent` event.
- **FAQ chatbot:** keyword scoring, an admin FAQ editor, a handoff list for staff, and a Vue chat widget. Throttled.
- **`stock:low-alert`:** daily 08:00 email to admins.
- **Staff dashboard:** pickups today, reservations about to expire, failed and stuck refunds, and payments to review.
- **Orphan payments:** a place for payments that match no reservation (deferred from this phase).
