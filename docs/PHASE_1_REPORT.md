# Phase 1 report: core reservation

Branch: `phase-1-core-reservation`, cut from `phase-0-foundations`. It holds 13 local commits and is not merged or pushed. Dates: 2026-09-24 to 2026-09-25.

## 1. What was built

**Catalog**
- Categories, suppliers and items, with soft delete, an `active` flag and `superseded_by_id`.
- Public item list with search, category chips and pagination.
- Item detail page showing a Clearance tag, the current price with the regular price struck through, "Only N left", and "Sold out".
- A replaced model that has sold out is hidden from the list, and its page redirects (301) to the new item.
- Stock figures come from one aggregate query per page, with no per-item queries.

**Stock ledger (`StockService`)**
- Every quantity change writes a `stock_movements` row.
- Allocation takes the oldest batch first (`received_at, id`) using `lockForUpdate`. Items are locked in id order and deadlocks are retried 3 times.
- Each batch used becomes its own reservation line, with that batch's price.
- Releasing stock returns it to the exact batch, and runs only once per reservation.
- Manual adjustments (damaged or count correction) need a reason and a user, and can never take stock below 0.

**Stock Receiving (`DeliveryService`)**
- Staff record a delivery, then receive it. Receiving locks the delivery and can only happen once.
- Each received line becomes a batch plus a `received` movement.
- Clearance prices on deliveries and batches can only be set by an admin.

**Reservations (`ReservationService`)**
- The session cart holds item ids and quantities only. The review page shows server-computed prices, totals and the downpayment.
- The terms checkbox is required, and its text uses the live settings.
- Limits are enforced: maximum quantity per item, and maximum unpaid reservations per customer.
- Each reservation gets an `SWA-XXXXXXXX` code, and `expires_at` is set to now plus `pending_minutes`.

**PayMongo**
- Checkout uses `POST /v1/checkout_sessions`, called after the reservation is saved and never inside a transaction.
- If checkout creation fails for any reason, the reservation is cancelled and its stock released. The cart is kept so the customer can try again.
- The webhook (`/webhooks/paymongo`):
  - verifies the signature on the raw body with `hash_equals` and rejects anything invalid with 400
  - rejects signatures more than 5 minutes old, and runs without any session middleware
  - is idempotent through a row lock plus the unique `provider_ref`
  - confirms the reservation and sets `pickup_by` only when every check passes
  - stores the payment as `needs_review` if the reservation has moved on, the amount is wrong, or the payment came from a different checkout session
- After returning from PayMongo, the customer sees "Confirming your payment…", and the page keeps checking the status. The page itself never confirms anything.
- `php artisan paymongo:simulate-webhook` sends a signed test webhook to the app, and only runs locally.

**Expiry**
- `reservations:expire` runs every minute, and never twice at once. Its lock clears after 5 minutes if a run crashes.
- It re-checks each reservation under a lock, releases the stock, and closes the PayMongo checkout session.

**Staff screens**
- Reservations list with filters.
- Reservation detail: "Start preparing" asks for confirmation and uses a conditional update, so exactly one of two simultaneous clicks wins. Extending the deadline needs a reason.
- Pickup counter: search by reference code, phone or email; the exact balance must be entered, plus an ID-check tick-box.
- Stock Receiving, and stock per item with the full movement history.

**Admin screens**
- Items, including image upload.
- Categories: deleting one that still has items is refused.
- Suppliers.
- Clearance price per batch.
- Settings, with range validation.

**Customer screens**
- Cart and review, My reservations (list and detail, with a "Continue to payment" button while still in time), and the payment return page.
- Registration now asks for a mobile number (optional) and shows a privacy notice.

**Accounts**
- `users.deactivated_at`: deactivated users cannot log in and are signed out if already logged in.
- `php artisan swa:create-admin` creates the first admin account.

**Local data**
- `DemoCatalogSeeder` adds 7 sample items, one with a clearance batch, for local testing only.
- The README now covers the simulated payment, the real PayMongo tunnel test, and creating the first admin.

## 2. Extensions used, and skipped
| Extension | Used for |
|---|---|
| WebFetch (PayMongo docs) | Checkout, webhook signature and refund error pages. Confirmed that only v1 exists. |
| `stock-tester` subagent | Wrote 26 stock tests (allocation, release, receiving, adjustments). Found no bugs in the code. |
| `vue-builder` subagents ×2, in parallel | Customer/public pages and staff/admin pages, each in separate folders |
| `payments-reviewer` subagent | 0 high-severity findings, 1 medium (test isolation), 3 low. All fixed. |
| `security-reviewer` subagent | 0 critical or high findings, 1 medium, 6 low. All fixed. |
| `qa-e2e` subagent plus Playwright MCP | Browser run at 375px and 1280px. The agent hit a usage limit partway; I finished the staff and admin flows myself in Playwright. |
| `frontend-design` skill | Used through `vue-builder` for page layout |

Skipped:
- **context7:** the Laravel and Inertia APIs used were already confirmed by the installed code and by passing tests.
- **Database MCP:** none is installed. I used the `mysql` command line for schema and data checks.
- **GitHub:** git is local only, by your choice.

## 3. Decisions and assumptions
The ten Phase 1 rows added to the decisions log in `docs/SPEC.md`:
- **Webhook confirmation runs immediately** inside the webhook request. Only the Phase 2 notifications will be queued.
- **Mismatched payments go to review.** A paid event with the wrong amount, or from another checkout session, is stored as `needs_review`.
- **An unreadable paid event returns 422** so PayMongo retries it and it shows as failed in their dashboard.
- **Expired sessions are closed on PayMongo** after the reservation expires (best effort).
- **The placeholder webhook secret is refused** outside `APP_ENV=local`.
- **Only PayMongo checkout URLs are used for redirects.**
- **Staff reserving for a customer is deferred to Phase 2.**
- **Pickup requires an ID-check tick-box.**
- **`stock_batches.received_at` is a `DATETIME`** (see section 5).
- **Audit logging waits for Phase 2.** Price, clearance and stock-adjustment changes are not audit-logged yet, and deadline extensions go to the application log for now.

Also:
- **Admins can't reserve.** The `/account` area is for customers only, so an admin who wants to reserve uses a customer account.
- **PHP extensions:** `intl` and `gd` aren't needed. Money is formatted with bcmath, and item images are validated without GD.
- **Webhook route location:** the webhook lives in `routes/webhooks.php`, outside the `web` middleware group, so it has no session, cookies, CSRF or Inertia.

## 4. Tests and command outputs
New tests, by area:
- **Stock:** allocation, release and receiving (26 tests); `BatchReceivedAtTest`.
- **Payments:** `PayMongoWebhookTest` (15).
- **Reservations:** `ReservationFlowTest`, `StaffActionsTest`.
- **Cross-cutting:** `AuthorizationTest`, `CatalogTest`, `AdminTest`, `SecurityHardeningTest`, `PageSmokeTest` (20 pages), `LastUnitConcurrencyTest`, `Unit/MoneyTest`.

The concurrency test starts **two separate PHP processes** that race for the last unit. I confirmed it is a real test: with the row locks removed, both customers got the unit ("OK | OK").

Every Phase 1 "done when" item has a test:

| Done when | Test |
|---|---|
| A simulated paid webhook confirms and sets the pickup deadline | `PayMongoWebhookTest::test_a_verified_paid_event_confirms…` |
| An unpaid reservation expires and its stock returns | `ReservationFlowTest::test_an_unpaid_reservation_expires…`, `StockReleaseTest` |
| Two customers cannot both take the last unit | `LastUnitConcurrencyTest` (two processes, 3 rounds) |
| Old and new batches become two priced lines | `StockAllocationTest` (spanning plus clearance) |
| A forged or unsigned webhook gets 400 | `PayMongoWebhookTest` (missing, forged, other secret, tampered, stale, wrong mode, placeholder) |
| The same webhook twice creates one payment | `PayMongoWebhookTest::test_the_same_event_delivered_twice…` |

```
php artisan test          → Tests: 170 passed (891 assertions), 55.8 s
vendor/bin/pint --test    → passed
npx vue-tsc --noEmit      → exit 0
npx eslint .              → exit 0
npm run format:check      → All matched files use Prettier code style
npm run build             → built
```

## 5. Review and QA findings

**Fixed**
- **High (found by me in the QA data): the batch "received" time was being rewritten.** MariaDB 10.4 silently gave `stock_batches.received_at` `ON UPDATE CURRENT_TIMESTAMP`, so every allocation reset the batch's received time. That broke oldest-first order and clearance pricing. The fix changes the column to `DATETIME`. A regression test uses the database's own clock, and another test fails if any column auto-updates. The dev database was reset and reseeded to clear the corrupted dates.
- **High (found in QA): the pickup counter crashed.** It returned a 500 when opened without a search term, because of PHP operator precedence. It's fixed, and the new page smoke test would have caught it.
- **Medium (security): the webhook would accept the public placeholder secret** and did not check the checkout session. Both fixed.
- **Medium (payments review): the concurrency test leaked data** into later tests. It now cleans up after itself.
- **Low (payments review):**
  - Checkout failures other than PayMongo errors left stock held. Now any failure cancels the reservation, and the cart is kept.
  - The expiry lock could block the job for 24 hours after a crash. It now clears after 5 minutes.
  - An unreadable webhook logged the full payload, including billing details. It now logs identifiers only.
  - A payment for an unknown reservation is now logged as an error.
- **Low (security):**
  - The checkout redirect host is now checked.
  - Admin writes moved to Form Requests.
  - Category names that produce the same or an empty slug are rejected.
  - `%` and `_` in searches are treated as plain text.
  - Browsing and account pages are rate-limited.
  - `nosniff`, frame and referrer headers are sent, including for uploads under `/storage`.
  - Inactive items can only be removed from the cart, never increased.
  - The webhook no longer starts a session.
- **Low (QA):** after saving a long form on a phone, the confirmation message appeared off-screen. Messages now stay at the top of the screen and can be dismissed.

**Deferred**
- **Refund minimum:** `downpayment_percent` can go as low as 1%. The Phase 2 forfeit and refund code must respect PayMongo's ₱1.00 refund minimum.
- **PayMongo checkout minimum (not confirmed):** PayMongo may have a minimum checkout amount. If a tiny downpayment is rejected, the failure is handled cleanly, but the customer only sees a generic message. To confirm on the tunnel test.
- **Timestamps on PayMongo retries:** it's unknown whether PayMongo re-signs retries with a fresh timestamp. If it doesn't, a retry more than 5 minutes after the first attempt fails. To confirm on the tunnel test; the tolerance is in `config/paymongo.php`.
- **Shared types:** staff/admin page types are defined inside each page instead of in `types/index.ts`. Tidy-up for Phase 2.

## 6. Manual verification checklist
Setup: run `php artisan serve` and `npm run dev` (or `npm run build`). The dev database is already migrated and seeded. To start over, run `php artisan migrate:fresh --seed`, which only touches `swa_reservations`.

1. **Catalog:** open http://127.0.0.1:8000/items at phone width.
   - The Acer Aspire 5 shows **Clearance ₱29,999.00** with ₱32,999.00 struck through.
   - The Canon and the HDMI cable show "Only N left".
   - Search for "epson" and try a category chip.
2. **Reserve:** log in as `customer@swa.test` / `password`.
   - Add **3** Acer laptops to the cart. The cart splits the price: 2 × ₱29,999.00 clearance plus 1 × ₱32,999.00.
   - Check the total, downpayment (30%), balance, and the terms text.
   - Submit without ticking the box and check that an error shows.
3. **Pay (test mode):** tick the box and choose **Pay downpayment with GCash or card**. You go to PayMongo's **test** checkout, using your `sk_test_` key. You don't need to pay there. Come back to **My reservations** and copy the `SWA-…` code.
4. **Simulate the webhook:** run `php artisan paymongo:simulate-webhook SWA-XXXXXXXX`.
   - The output ends with `{"result":"confirmed"}`.
   - Reload the reservation: it shows **Confirmed** with a pickup deadline 3 days away at 11:59 PM.
   - Run the command again with `--payment-id=<printed id>` and you get `duplicate`.
5. **Expiry:** reserve something else and don't pay. Set **Time to pay** to 10 minutes in Admin → Settings, or wait 30 minutes. Then run `php artisan reservations:expire`. The reservation shows **Expired**, and the stock is back (check Staff → Stock).
6. **Staff:** log in as `staff@swa.test`.
   - Open the confirmed reservation and click **Start preparing** (with confirmation). The status becomes "Being prepared".
   - Extend the deadline with a reason.
7. **Pickup counter:** search for the code.
   - A wrong amount is refused, and a missing ID tick is refused.
   - The exact balance plus the tick completes it. The reservation disappears from the search.
8. **Stock Receiving:** record a delivery (Metro Tech, Epson ink × 5), open it, and choose **Receive into stock**. Staff → Stock → Epson ink shows a new batch and a +5 movement.
9. **Adjustment:** on a batch, record Damaged, 1 unit, with a reason. The ledger shows −1 with your name. The batch's "Received" date must **not** change.
10. **Admin:** log in as `admin@swa.test`.
    - Settings: change a value and check the confirmation stays visible.
    - Add an item, then untick "Show in the catalog" and check it disappears from /items.
    - Try to delete the "Laptops" category and check you get an error.
    - Set a clearance price on a batch, check the catalog price changes, then remove it.
11. **Access control:** as the customer, open http://127.0.0.1:8000/staff and check you get 403. While logged out, `/admin/items` redirects to login.

## 7. Risks and open questions
1. **Hooks (still waiting for your answer):** (a) run Pint after PHP edits; (b) block edits to `.env`. They are not added.
2. **Automated test checkouts (needs your answer):** so far the automated QA never pressed "Pay", because that creates a checkout session in your PayMongo test account. Do you allow automated QA runs to create PayMongo **test** checkouts? Nothing is charged, but each run creates a session in your test account.
3. **Privacy notice:** the wording on the registration page needs review by someone qualified, because of the Data Privacy Act of 2012.
4. **MariaDB 10.4:** it no longer gets updates, and it caused the timestamp bug above. Consider upgrading before going live.
5. **Real tunnel test:** this is yours to run (README section "Real PayMongo test through a tunnel"). It will also answer the two PayMongo questions in section 5.
6. **Keys pasted in chat:** the PayMongo secret key and the Resend key were pasted into our chat earlier. Rotating them is still recommended.

## 8. What Phase 2 will do (money rules and safety)
- **Cancellation:**
  - Customers can cancel before Processing with a 100% refund; the button shows on the reservation page only while that is allowed.
  - Staff cancel with a required reason and a 0% refund.
  - Admins choose 0, 50 or 100%.
  - A customer cancel racing "Start preparing" lets exactly one win.
  - Staff can also reserve on a customer's behalf.
- **Refunds:**
  - `RefundService` marks the refund `refund_pending` inside the transaction, calls PayMongo after commit, then sets `refunded` or `refund_failed`.
  - Retries are manual only and claimed atomically.
  - A "Payments to review" screen covers `needs_review` and `refund_failed`.
- **`reservations:forfeit`:** runs hourly and refunds 50% (the `forfeit_refund_percent` setting).
- **Walk-in sales and admin void:** voided stock returns through an `adjusted` movement.
- **Resend notifications:** Confirmed, Processing, PickupReminder (the `reservations:remind` job), Cancelled and Forfeited. All queued, sent after commit, and never sent to walk-in customers.
- **Audit log:** an `audit_logs` table and an admin view, covering refunds, voids, cancels, price and clearance changes, stock adjustments, deadline extensions and role changes.
- **Accounts:** a staff accounts screen, and a deactivate button for admins.
- **Reports:** sales per day, forfeited downpayments kept, and slow-moving items.
- **README:** backup steps. I'll ask you before adding any backup package.
