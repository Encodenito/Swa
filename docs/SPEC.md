# SWA I.T. Supplies Center — System Specification

Source: the master build prompt (Context section), saved verbatim below, followed by a
"Decisions log" of changes made during the build. When this file and the code disagree,
fix one of them in the same commit.

---

## A. Business and scope
SWA I.T. Supplies Center sells I.T. supplies (laptops, printers, monitors, cables, peripherals, ink) in the Philippines. Customers reserve items online, pay a downpayment, and pick up at the shop. There is no delivery, shipping, courier, or address handling. Walk-in customers buy at the counter.

In scope: everything in this document. Out of scope: delivery, cash downpayments, editing a paid reservation (customers cancel and reserve again), guest checkout, and payment methods that cannot be refunded (QR Ph, online banking via UnionBank, over-the-counter).

## B. Roles and permissions
| Capability | Customer | Staff | Admin |
|---|---|---|---|
| Browse items and prices (no login) | yes | yes | yes |
| Register, log in, edit own profile | yes | yes | yes |
| Reserve and pay the downpayment | yes | for a customer | yes |
| View own reservations, cancel before Processing | yes | | |
| View all reservations, Start processing, extend a deadline | | yes | yes |
| Pickup counter, record the balance | | yes | yes |
| Walk-in sales | | yes | yes |
| Stock Receiving, stock adjustments | | yes | yes |
| Cancel a reservation with a reason | | 0% refund only | 0, 50 or 100% refund |
| Customer notes, tags, follow-ups | | yes | yes |
| Items, prices, clearance prices, suppliers | | | yes |
| Settings, staff accounts, reports, audit log, voids | | | yes |
| Chatbot: use, answer handoffs, edit FAQ | use | handoffs | edit FAQ |

Route groups: `/account` (customer), `/staff` (staff and admin), `/admin` (admin only), `/webhooks/paymongo` (public, CSRF-exempt, no auth), `/chatbot/*` (public, throttled). Middleware `role:` on groups plus policies on every model access.

## C. Statuses and transitions
Statuses: `pending_payment`, `confirmed`, `processing`, `completed`, `cancelled`, `expired`, `forfeited`, and `voided` (walk-in sales only). Reservations have `type` = `reservation` or `walk_in`. Walk-ins are created as `completed`.

| From | To | Who or what | Guard |
|---|---|---|---|
| (new) | `pending_payment` | customer or staff | stock allocated in one transaction, `expires_at` = now + 30 min |
| `pending_payment` | `confirmed` | verified PayMongo webhook only | sets `pickup_by` = now + `pickup_days`, end of day |
| `pending_payment` | `expired` | `reservations:expire` | releases stock |
| `pending_payment` | `cancelled` | customer, or checkout creation failure | releases stock, nothing to refund |
| `confirmed` | `processing` | staff or admin | atomic conditional update; notify customer only if the update succeeded |
| `confirmed` | `cancelled` | customer (100% refund), staff (0%), admin (0, 50, 100%) | reason required for staff and admin |
| `processing` | `cancelled` | staff or admin only | customers can never cancel here |
| `confirmed` or `processing` | `completed` | staff or admin | balance must equal the exact remaining amount |
| `confirmed` or `processing` | `forfeited` | `reservations:forfeit` | `pickup_by` in the past; releases stock; 50% refund row |
| walk-in `completed` | `voided` | admin | required reason; stock returns via an `adjusted` movement |

Every transition locks the row (`lockForUpdate`) or uses a conditional update, and re-checks the current status. If the status has changed, it fails cleanly (HTTP 409 or a validation error).

## D. Money rules
| Event | Downpayment outcome |
|---|---|
| Not paid within 30 minutes | Nothing was paid. Expired, stock returns. |
| Customer cancels before Processing | 100% refunded (PayMongo keeps its fee). |
| Staff pressed Start processing | Customer cannot cancel. |
| Pickup deadline missed | 50% refunded (admin setting `forfeit_refund_percent`), stock returns, the shop keeps the rest. |
| Shop cancels (defective item, wrong price) | Admin chooses 0, 50 or 100%. |
| Payment arrives when the reservation is not `pending_payment` | Stored as `needs_review`. Status unchanged. Staff refund manually. |
| Refund API call fails | `refund_failed`. Appears on a staff screen. A person retries. Never automatic. |
| Walk-in | Paid in full at the counter (`payments.type = full`, cash). No downpayment. |

Payments table types: `downpayment`, `balance`, `full`, `refund`. Statuses: `paid`, `needs_review`, `refund_pending`, `refunded`, `refund_failed`. A refund row links to its original payment through `parent_id`.

## E. Settings (admin editable, cached)
| Key | Default |
|---|---|
| `downpayment_percent` | 30 |
| `pickup_days` | 3 |
| `forfeit_refund_percent` | 50 |
| `pending_minutes` | 30 |
| `max_unpaid_reservations` | 2 |
| `max_qty_per_item` | 10 |

Show the policy text at checkout with a required checkbox: "You can cancel for free (full refund) until we start preparing your order. Once it is marked Processing, it cannot be cancelled. Pick up within N days of confirmation, or only P% of your downpayment is refunded." Use the live setting values in the text.

## F. Stock and delivery rules
"Deliveries" means supplier deliveries arriving at the shop. In the UI call the screen "Stock Receiving".

- Receiving a delivery line creates one `stock_batches` row (`quantity_remaining`, optional `sale_price` as the clearance price, `received_at`) and a `received` movement.
- Every reservation and walk-in sale takes from the oldest batch with stock (`ORDER BY received_at, id`, `lockForUpdate`). The line price is the batch `sale_price` if set, otherwise the item price. An order spanning batches becomes several `reservation_items` lines, each with its own `batch_id` and `unit_price`.
- Expire, cancel and forfeit return stock to the exact batch of each line and log a `released` movement.
- Stock changes only through movements: `received`, `reserved`, `released`, `sold`, `damaged`, `adjusted`. Corrections need a reason and a user.
- Items are soft-deleted or deactivated, never hard-deleted once referenced.
- Different-model replacement: `items.superseded_by_id` (nullable). When the old item's stock reaches 0 it is hidden and its page redirects to the new item.
- Available stock is the sum of `quantity_remaining`. Compute it with an aggregate query, not per-item queries (no N+1).

## G. Data model
| Table | Key columns |
|---|---|
| `users` | name, email (unique), phone, password, `role`, `email_verified_at` |
| `categories`, `suppliers` | name; suppliers also phone, email |
| `items` | category_id, name, brand, model, sku (unique, nullable), description, price, image, low_stock_threshold, active, superseded_by_id, soft deletes |
| `deliveries` | supplier_id, reference, expected_at, received_at, status (`ordered`, `received`, `cancelled`), received_by |
| `delivery_items` | delivery_id, item_id, quantity_ordered, quantity_received, unit_cost |
| `stock_batches` | item_id, delivery_item_id, quantity_remaining, sale_price (nullable), received_at; index (item_id, received_at) |
| `stock_movements` | item_id, batch_id, type, quantity (signed), reservation_id, reason, created_by |
| `reservations` | user_id (nullable for walk-ins), type, reference_code (unique, `SWA-XXXXXXXX`), status, total, downpayment_amount, balance, expires_at, pickup_by (datetime), terms_accepted_at, processing_at, cancelled_at, cancelled_by, cancel_reason, forfeited_at, paymongo_checkout_id, checkout_url, walk_in_name, walk_in_phone, created_by |
| `reservation_items` | reservation_id, item_id, batch_id, quantity, unit_price |
| `payments` | reservation_id, parent_id, type, amount, method (`paymongo`, `cash`), provider_ref (unique, nullable), status, created_by |
| `settings` | key (primary), value |
| `audit_logs` (Phase 2) | user_id, action, subject_type, subject_id, before, after, ip, created_at |
| `customer_notes`, `tags`, `tag_user`, `follow_ups`, `message_logs`, `chatbot_faqs`, `chat_handoffs` (Phase 3) | as named |
| `loyalty_transactions`, `item_serials` (Phase 4) | as named |

Money columns are `decimal(10,2)`. Convert to integer centavos only at the PayMongo boundary.

## H. Integrations

### PayMongo (test mode only)
Source of truth: https://docs.paymongo.com/llms.txt (docs index). Read the Checkout, Webhooks and Refunds pages before coding. The docs show webhook payloads in slightly different shapes, so parse defensively and log the first real test payload in dev.

- Create checkout: `POST https://api.paymongo.com/v2/checkout_sessions` (see Decisions log: the docs show `/v1`) with HTTP Basic auth (secret key as the username, empty password). Body `data.attributes`: `line_items` (name "Downpayment - {reference_code}", `amount` in integer centavos, `currency` "PHP", `quantity` 1), `payment_method_types` `["gcash","card"]`, `reference_number` = the reservation reference code, `success_url`, `cancel_url`, `metadata.reservation_id`. Store the session id and `checkout_url`. Use Laravel's `Http` client with a timeout, no SDK. If creation fails, release the stock and mark the reservation `cancelled`.
- The customer's return to the site never confirms anything. The return page shows "Confirming your payment..." and polls until the status changes.
- Webhook event: register only `checkout_session.payment.paid`. Header `Paymongo-Signature` is `t=<timestamp>,te=<test signature>,li=<live signature>`. Compute HMAC-SHA256 over `"{t}.{rawBody}"` with the webhook secret, and compare with `te` for test-mode events (`li` for live) using `hash_equals`. Use the raw body (`$request->getContent()`). Reject anything else with 400. Acknowledge quickly with 200 (PayMongo retries non-success responses up to 12 times), and queue heavy work.
- Payload variants: read `data.attributes.type` and `data.attributes.data`, or `data.type` and `data.data`. Find the reservation by the session's `reference_number`. The payment id is `payments[0].id` (expected to start with `pay_`).
- Idempotency: lock the reservation row, skip if a payment with that `provider_ref` exists, and enforce the unique index. Confirm only when the status is `pending_payment`, otherwise store the payment as `needs_review`.
- Refunds: `POST https://api.paymongo.com/v1/refunds` with `data.attributes`: `amount` (integer centavos, minimum 100), `payment_id` (`pay_...`), `reason` "others", `notes` (max 255 characters). Only paid payments can be refunded, a started refund cannot be cancelled, PayMongo keeps its transaction fee, and some methods reject same-day partial refunds. Refunds can also fail when the payout balance is insufficient. Handle `payment_not_found` and `refund_amount_exceeds_payment`. Never make the HTTP call inside a database transaction holding locks: mark `refund_pending` inside the transaction, call after commit, then set `refunded` or `refund_failed`.
- Retry: a manual staff action claims the row atomically (`refund_failed` to `refund_pending`) before calling again, so a double click cannot refund twice.
- Local development: PayMongo cannot reach localhost. Build `php artisan paymongo:simulate-webhook {reference}` (local environment only) that creates a properly signed test payload and posts it to the app's own webhook route. A real end-to-end test through a tunnel is done by the owner. Document the steps in the README. Do not start tunnels or register webhooks.

### Resend and notifications
- Laravel mail driver: `composer require resend/resend-php`, add `'resend' => ['transport' => 'resend']` to the mailers in `config/mail.php`. Use `MAIL_MAILER=log` in dev and `resend` in production. The API key variable name is whichever `config/services.php` reads (`RESEND_API_KEY` or `RESEND_KEY`).
- `MAIL_FROM_ADDRESS` must belong to a domain verified in Resend. Until then real customers cannot be emailed. Assume it is not verified and document the steps.
- All notifications implement `ShouldQueue`, call `afterCommit()`, `$tries = 3`, `$backoff = [60, 300]`. Send only after the atomic state change succeeded. Walk-ins have no account and get no email. These are transactional messages.

| Notification class | Trigger |
|---|---|
| `ReservationConfirmed` | Webhook confirms the payment: reference code, items, pickup deadline |
| `ReservationProcessing` | Start processing: "cannot be cancelled now", deadline, balance, forfeit rule |
| `PickupReminder` | `reservations:remind`: deadline within the next 24 hours |
| `ReservationCancelled` | Cancel: refund notice (card refunds can take up to 30 days to appear) |
| `ReservationForfeited` | Forfeit job: deadline missed, refund percent |

SMS: define an `SmsSender` interface with a log implementation. A real provider is Phase 4.

## I. Scheduled jobs (all `withoutOverlapping`)
| Command | Schedule | Purpose |
|---|---|---|
| `reservations:expire` | every minute | Expire unpaid reservations, release stock |
| `reservations:forfeit` | hourly | Forfeit overdue pickups, create and send the refund |
| `reservations:remind` | daily 08:00 Asia/Manila | Pickup reminders |
| `stock:low-alert` | daily 08:00 Asia/Manila | Email admin about low stock |

## J. Screens
| Role | Screens |
|---|---|
| Customer | Item list and detail (public), register, login, verify email, review order with terms, my reservations (status, deadline, cancel button while allowed), profile, chat widget |
| Staff | Dashboard (pickups today, expiring reservations, failed refunds), reservations list and detail, pickup counter, walk-in sale, Stock Receiving, stock adjustment, customers with notes, tags and follow-ups, payments to review, chatbot handoffs |
| Admin | All staff screens plus items and categories, clearance prices on batches, suppliers, staff accounts, settings, chatbot FAQs, reports, audit log, voids |

UI: mobile-first (customers will mostly use phones), Tailwind, Vue 3 `<script setup>`, one layout per role, empty, loading and error states, currency shown as ₱, English copy. Hiding menu items by role is cosmetic. Real protection is server-side.

## K. Integrity and security invariants (never violate)
1. The server computes every price, total and downpayment. Never trust client-sent amounts.
2. A reservation is confirmed only by a verified webhook. Never from a browser redirect.
3. Verify the webhook signature on the raw body with `hash_equals` before parsing.
4. Webhook handling is idempotent (row lock, unique `provider_ref`).
5. Every status change is atomic and re-checks the current status.
6. Stock allocation runs in a transaction with `lockForUpdate` on batches, locking items in a consistent order (sorted by item id) to avoid deadlocks. Retry a deadlocked transaction up to 3 times.
7. No external HTTP call inside a transaction that holds locks.
8. Refunds are never retried automatically. Retries are manual and claimed atomically.
9. Every model access goes through a policy (customers see only their own records). Role middleware on route groups.
10. Validate with Form Requests, protect against mass assignment (`$fillable`), rate-limit login, reservation creation and the chatbot.
11. Secrets only from environment variables. Only placeholders in `.env.example`. Never log secrets. Never commit `.env`.
12. Never store card data. Payment happens on PayMongo's hosted page.
13. Audit-log sensitive actions: refunds, voids, cancels, price and clearance changes, stock adjustments, deadline extensions, role changes.
14. Collect only the personal data the system uses, and include a short privacy notice at registration (Data Privacy Act of 2012; the wording must be checked by someone qualified).

## L. Environment and constraints
- Local development machine. Local database for dev and a separate database for tests (locking behaviour needs MySQL/MariaDB). Never run tests against dev data.
- Queue: `database` driver. Worker: `php artisan queue:work`. Local scheduler: `php artisan schedule:work`.
- Never use live PayMongo or Resend keys. Automated tests use `Http::fake()`, `Notification::fake()`, `Queue::fake()`, and never contact real services.
- Never run destructive commands (`migrate:fresh`, `db:wipe`, force pushes, `git reset --hard`) outside the local dev or test database and repo; ask before any git history rewrite.
- Ask before adding paid services, global installs, or new hooks.

## M. Assumptions and open decisions (defaults applied)
- Only the account holder collects an order. Expensive items also need an ID check at the counter.
- Loyalty points are Phase 4.
- SMS is simulated (logged) until Phase 4.
- Same-product-new-batch and different-model-replaces-old are both supported (batches plus `superseded_by_id`).

---

## Decisions log
| Date | Decision | Reason |
|---|---|---|
| 2026-09-24 | Laravel 12 on PHP 8.2.12 (XAMPP) | Laravel 13 requires PHP 8.3+; owner chose to keep XAMPP's PHP. |
| 2026-09-24 | Database is MariaDB 10.4 (XAMPP), dev `swa_reservations`, test `swa_reservations_test` | Installed server; `SELECT ... FOR UPDATE` semantics match MySQL/InnoDB. Existing `swa_*` databases are left untouched. |
| 2026-09-24 | Git is local only (no push, no PRs) | Owner's choice. Owner pushes and merges. |
| 2026-09-24 | Checkout endpoint `POST /v1/checkout_sessions`, path kept in `config/paymongo.php` | PayMongo docs (the stated source of truth) document only v1; no v2 checkout endpoint exists in the reference. |
| 2026-09-24 | Official Laravel Vue starter kit (Inertia + Vue 3 + TypeScript) instead of Breeze | Breeze is superseded by the starter kits in Laravel 12. |
| 2026-09-24 | Resend key env var is `RESEND_KEY` | It is the name `config/services.php` reads (spec allows either). |
| 2026-09-24 | Self-service account deletion removed | Hard-deleting users would break reservation/payment history and the audit trail (K.13), and could lock out the only admin. Admin deactivation comes in Phase 1. |
| 2026-09-24 | Rate limits: registration 5/hour per IP, login 20/min per IP (plus the kit's 5 per email+IP), password change and confirm 6/min | K.10; from the Phase 0 security review. |
| 2026-09-25 | Webhook confirmation runs inline (one short transaction); only notifications will be queued | The brief allows queueing "heavy work"; confirming is not heavy and inline keeps the return page fast. |
| 2026-09-25 | A paid webhook is stored as `needs_review` (status unchanged) when the amount differs from the downpayment, or when the paid checkout session is not the one the app created | Extends section D's late-payment rule to other mismatches; staff resolve manually. |
| 2026-09-25 | An unreadable `checkout_session.payment.paid` event gets 422 (PayMongo retries); other event types get 200 | A paid event must never be dropped silently. |
| 2026-09-25 | After expiring a reservation, its PayMongo checkout session is closed (best effort, after commit) | Makes a late payment unlikely. |
| 2026-09-25 | `PAYMONGO_WEBHOOK_SECRET` must start with `whsk_`; the `.env.example` placeholder is accepted only when `APP_ENV=local` | Security review: the public placeholder must never verify a webhook in production. |
| 2026-09-25 | Customers are redirected only to `https://checkout.paymongo.com` URLs | Security review: no open redirect through a bad PayMongo response. |
| 2026-09-25 | Staff reserving on a customer's behalf is deferred to Phase 2 | It belongs with staff cancel, refund and the audit log. Admins reserve with a customer account for now. |
| 2026-09-25 | Pickup requires a staff tick-box "I checked this is the account holder (and their ID for high-value items)" | Spec M: only the account holder collects. |
| 2026-09-25 | `stock_batches.received_at` is `DATETIME`, not `TIMESTAMP` | MariaDB 10.4 silently adds ON UPDATE CURRENT_TIMESTAMP to the first NOT NULL timestamp; a test now fails if any column auto-updates. |
| 2026-09-25 | Price, clearance and stock-adjustment changes are not audit-logged until the Phase 2 `audit_logs` table; deadline extensions are written to the application log meanwhile | Section K.13 is completed in Phase 2. |
| 2026-09-26 | Refunds are sent by a queued `SendRefund` job with `$tries = 1`, dispatched after commit | Spec H/K.7: no HTTP inside the transaction; K.8: never retried automatically. |
| 2026-09-26 | Only a definite PayMongo refusal (4xx with an error code) becomes `refund_failed`. Timeouts, connection errors and 5xx leave the refund `refund_pending` with "outcome unknown"; after 15 minutes it shows as stuck and an admin reconciles it against the PayMongo dashboard (found → `refunded` with the `ref_` id; not found → `refund_failed`, then Retry) | Payments review: a timed-out refund may exist at PayMongo, so resending it could refund twice. PayMongo's docs do not document a refund lookup API, so reconciliation is a person reading the dashboard; each refund carries `metadata.refund_id`. |
| 2026-09-26 | `SendRefund` claims the row (`refund_attempted_at`) before calling PayMongo; Retry clears the claim | A re-run of the same job (queue:retry, redelivery) can never send a second refund. |
| 2026-09-26 | Refund reason sent to PayMongo is always `others`; refunds below ₱1.00 are skipped | Spec H; PayMongo's 100-centavo minimum. |
| 2026-09-26 | Staff and admin may also cancel `pending_payment` reservations (nothing to refund); customers may cancel `pending_payment` and `confirmed` | Spec C lists the customer for pending→cancelled; extending it to staff lets the counter clean up abandoned holds. Staff cancels carry the status the page showed and are refused if it changed. |
| 2026-09-26 | Cancelling a `pending_payment` reservation closes its PayMongo checkout session (best effort, after commit) | Same reason as expiry: no payment for a cancelled reservation. |
| 2026-09-26 | Admins can create staff **and admin** accounts; admin-created accounts must replace the temporary password on first login; staff/admin passwords are 12+ characters | Security review. There is no separate owner role; the last active admin cannot be demoted or deactivated, and nobody can change their own role. |
| 2026-09-26 | "Keep payment, mark reviewed" (no refund) is admin-only and audit-logged | A money decision. |
| 2026-09-26 | Walk-in void records a cash refund row (`refunded` at once) and returns stock through `adjusted` movements | Spec C; keeps sales reports reconciled. |
| 2026-09-26 | The cancel reason is shown to the customer (page and email); staff see a note saying so | Security review: avoid internal remarks reaching customers. |
| 2026-09-26 | Stuck refunds of a PayMongo payment for an unknown reservation are not stored (no reservation to attach them to); they are logged at error level only | Deferred: needs a place for orphan payments (Phase 3 or later). |
