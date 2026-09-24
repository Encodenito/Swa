---
name: swa-business-rules
description: SWA reservation statuses and allowed transitions, money and refund rules, stock batch rules, and admin settings. Use when writing or reviewing reservation, payment, refund, stock, or settings code in this project.
user-invocable: false
---

# SWA business rules (spec sections C, D, E, F)

## Reservation statuses (`App\Enums\ReservationStatus`)
`pending_payment`, `confirmed`, `processing`, `completed`, `cancelled`, `expired`, `forfeited`, `voided` (walk-in only).
`type`: `reservation` | `walk_in`. Walk-ins are created `completed`.

## Allowed transitions — anything else is a 409/validation error
| From | To | Actor | Guard / side effect |
|---|---|---|---|
| new | pending_payment | customer, staff | allocate stock in one transaction; `expires_at = now + pending_minutes` |
| pending_payment | confirmed | verified webhook ONLY | `pickup_by = (now + pickup_days)->endOfDay()`; notify ReservationConfirmed |
| pending_payment | expired | `reservations:expire` | release stock |
| pending_payment | cancelled | customer, checkout-creation failure | release stock; nothing to refund |
| confirmed | processing | staff, admin | conditional update `WHERE status='confirmed'`; notify only if 1 row changed |
| confirmed | cancelled | customer 100%, staff 0%, admin 0/50/100% | reason required for staff/admin; release stock; refund row |
| processing | cancelled | staff, admin ONLY | customers can never cancel here |
| confirmed, processing | completed | staff, admin | balance paid must equal exact remaining amount |
| confirmed, processing | forfeited | `reservations:forfeit` | `pickup_by < now`; release stock; refund `forfeit_refund_percent` |
| walk-in completed | voided | admin | required reason; stock back via `adjusted` movement |

Every transition: lock row (`lockForUpdate`) or conditional UPDATE, re-check status inside the lock.

## Money
- `decimal(10,2)`; compute with bcmath or integer centavos, never floats. Round half up to 2 dp.
- `total = Σ(quantity × unit_price)` over lines. `downpayment_amount = round(total × downpayment_percent / 100, 2)`. `balance = total − downpayment_amount`.
- Payment types: `downpayment`, `balance`, `full`, `refund`. Statuses: `paid`, `needs_review`, `refund_pending`, `refunded`, `refund_failed`. Refund rows point to the original payment via `parent_id`.
- Late payment (reservation not `pending_payment`): store as `needs_review`, do not change reservation status.
- Refund HTTP call happens AFTER commit: mark `refund_pending` in the transaction → call → `refunded` / `refund_failed`. Never auto-retry.
- Walk-in: one `full` cash payment, no downpayment, no email.

## Stock
- Allocate oldest batch first: `WHERE item_id=? AND quantity_remaining>0 ORDER BY received_at, id FOR UPDATE`. Lock items in ascending item id.
- Line price = batch `sale_price` (clearance) if not null, else `items.price`. Spanning batches → one `reservation_items` line per batch.
- Movements (signed quantity): `received` (+), `reserved` (−), `released` (+), `sold` (−), `damaged` (−), `adjusted` (±, reason + user required).
- Release returns quantity to the exact `batch_id` of each line.
- Available = `SUM(quantity_remaining)` via one aggregate query (withSum/subquery), never per-item loops.
- `superseded_by_id`: when the old item's available stock is 0, hide it and redirect its page to the new item.

## Settings (`settings` table, cached; helper `setting('key')`)
| key | default |
|---|---|
| downpayment_percent | 30 |
| pickup_days | 3 |
| forfeit_refund_percent | 50 |
| pending_minutes | 30 |
| max_unpaid_reservations | 2 |
| max_qty_per_item | 10 |

Checkout terms text (required checkbox, live values): "You can cancel for free (full refund) until we start preparing your order. Once it is marked Processing, it cannot be cancelled. Pick up within {pickup_days} days of confirmation, or only {forfeit_refund_percent}% of your downpayment is refunded."
