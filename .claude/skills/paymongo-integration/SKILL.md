---
name: paymongo-integration
description: PayMongo checkout, webhook signature verification, idempotent webhook handling, refunds and the local simulate-webhook command for this project. Use when writing or reviewing PayMongoService, the webhook controller, refund code, or payment tests.
user-invocable: false
---

# PayMongo integration (test mode only, spec section H)

Verified against docs.paymongo.com on 2026-09-24. Re-check the docs before changing request shapes.
Never use `sk_live_` keys. Tests always use `Http::fake()`. No SDK: Laravel `Http` with `->timeout(15)`.

## Config (`config/paymongo.php`, env only)
`PAYMONGO_SECRET_KEY` (sk_test_...), `PAYMONGO_WEBHOOK_SECRET` (whsk_...), `PAYMONGO_BASE_URL=https://api.paymongo.com`, checkout path `/v1/checkout_sessions` (docs list only v1; spec said v2 — see SPEC decisions log). Auth: `Http::withBasicAuth($secret, '')`.

## Create checkout session
`POST /v1/checkout_sessions`
```json
{"data":{"attributes":{
  "line_items":[{"name":"Downpayment - SWA-XXXXXXXX","amount":150000,"currency":"PHP","quantity":1}],
  "payment_method_types":["gcash","card"],
  "reference_number":"SWA-XXXXXXXX",
  "success_url":"...", "cancel_url":"...",
  "metadata":{"reservation_id":"123"}
}}}
```
`metadata` values must be strings. Response: `data.id` (session id, `cs_...`), `data.attributes.checkout_url`.
Flow: reservation + stock committed first → HTTP call outside any transaction → store id/url. On failure: in a new transaction lock the reservation, if still `pending_payment` release stock and set `cancelled`.

## Webhook `POST /webhooks/paymongo` (no auth, CSRF-exempt)
1. Raw body `$request->getContent()`. Header `Paymongo-Signature: t=<ts>,te=<test sig>,li=<live sig>`.
2. Parse by splitting on `,` then `=` (first `=` only). Missing t or the needed sig → 400.
3. `expected = hash_hmac('sha256', "{$t}.{$raw}", webhook_secret)`. Compare with `hash_equals(expected, te)` in test mode (`li` in live; choose by `livemode` in config, not by the payload). Mismatch → 400. Optional: reject |now − t| > 5 min (replay).
4. Only after verification, `json_decode`. Event type: `data.attributes.type` ?? `data.type`. Resource: `data.attributes.data` ?? `data.data`.
5. Event not `checkout_session.payment.paid` → 200 and ignore (4xx/5xx makes PayMongo retry, up to 12 times; 30 s timeout).
6. Session attributes: `reference_number`, `metadata.reservation_id`, `payments[0].id` (`pay_...`), `payments[0].attributes.amount` (centavos), `payments[0].attributes.status`.
7. Log the first real test payload in the local environment (never log headers/secrets).
8. In a transaction: lock reservation by `reference_code`; if a payment with that `provider_ref` exists → return 200 (duplicate). If status `pending_payment` → create `downpayment` payment `paid`, set `confirmed`, `pickup_by`. Else → create payment `needs_review`, leave status. Unique index on `payments.provider_ref` backs this up (catch the duplicate-key error → 200).
9. Notify `ReservationConfirmed` with `afterCommit()` only when the status actually changed.

## Refunds
`POST /v1/refunds` `{"data":{"attributes":{"amount":<centavos ≥100>,"payment_id":"pay_...","reason":"others","notes":"<≤255 chars>"}}}`
reason ∈ `duplicate`, `fraudulent`, `others`. Only `paid` payments. Fee is kept by PayMongo. Cards: may take up to 30 days to reflect.
Pattern: create refund row `refund_pending` inside the state-change transaction → after commit call API → set `refunded` (store `data.id` as provider_ref) or `refund_failed` (store error code). Never retry automatically. Manual retry: `UPDATE payments SET status='refund_pending' WHERE id=? AND status='refund_failed'`; proceed only if 1 row changed.
Error codes to handle (from docs): `resource_not_found` (payment missing; spec calls it payment_not_found), `parameter_above_maximum` (amount exceeds; spec calls it refund_amount_exceeds_payment), `available_balance_insufficient`, `resource_failed_state`, `resource_pending_state`/`resource_processing_state`, `allowed_date_exceeded`, `resource_disputed_state`, `livemode_mismatched`, `provider_processing_error`. All become `refund_failed` with the code shown to staff.

## Local simulation
`php artisan paymongo:simulate-webhook {reference}` — refuses unless `app()->environment('local')`. Builds a `checkout_session.payment.paid` payload with `reference_number`, fake `pay_` id and the downpayment in centavos, signs with `te`, POSTs to `route('webhooks.paymongo')`. Real tunnel testing is done by the owner only.
