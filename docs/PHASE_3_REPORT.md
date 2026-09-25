# Phase 3 report: customer care

Branch: `phase-3-customer-care`, cut from `phase-2-money-rules`. It has 9 local commits and is not merged or pushed. Date: 2026-09-27.

## 1. What was built

**Customer records (Staff → Customers)**
- **Customer list:** search by name, email or phone. Filter by tag or by segment:
  - "Picked up in the last 90 days"
  - "No reservation in 180 days"
  - "Forfeited before"
  - "Has an open follow-up"
  - "Awaiting pickup now"

  Each row shows reservation counts and the date of the last reservation. The page runs the same number of queries however many customers there are (tested).
- **Customer profile:**
  - Contact details and totals (reservations, completed, cancelled, forfeited, amount spent) plus the reservation history.
  - **Notes** are staff-only, and the customer never sees them. Card numbers are refused (Luhn check). The author can delete a note within 10 minutes and an admin at any time; deletions are audit-logged.
  - **Tags:** staff attach and remove them, admins create them.
  - **Follow-ups** have a due date, can be assigned to a staff member, and are marked done once.
  - **Messages sent** to this customer.
- **Follow-ups page:** tabs for Overdue, Today, Upcoming and Done.

**Message log**
- Every email or SMS that was actually sent is recorded, from Laravel's `NotificationSent` event.
- Only a masked recipient (`c***@example.com`, `0917*****67`) and the subject are stored. Message contents are not.
- It appears on the customer profile and under Admin → Messages sent.

**FAQ chatbot**
- A floating "Questions? Chat with us" button on public and customer pages, but not on staff or admin pages.
- It scores keywords, with no AI or paid service, and handles English and Taglish ("magkano po ang downpayment", "pwede ba gcash").
- Answers are plain text and use the live settings, e.g. "Pick up within 3 days".
- Ten starter FAQs come from the spec. The "shop hours and address" answer is a placeholder for you to fill in.
- "Talk to a person" sends a chat request (handoff) to staff. It is rate-limited:
  - 3 per 10 minutes per IP address
  - 3 per hour per contact
  - 30 per hour for the whole shop
  - one open request per person
- Card numbers in chat are masked to the last 4 digits.
- Chat history lives in the browser, separately for each signed-in account.

**Chat handoffs (Staff)**
- Tabs: Waiting, Being handled, Closed.
- "I'll take it" claims a request, and only one staff member can win. Closing needs a note ("What did you tell them?").
- The chat history is shown labelled "as reported by the visitor, unverified".
- Closed requests are deleted after 90 days (`chat:purge-handoffs`, daily).

**FAQ editor (Admin)**
- Add, edit, delete and reorder FAQs, set keywords, and switch them on or off. The available `{placeholders}` are listed.
- "Test a question" shows the best match and the top 5 scores.

**Low-stock alert:** `stock:low-alert` runs daily at 08:00 and emails active admins a list of items at or below their low-stock level, lowest first. Nothing is sent when no item is low.

**Staff dashboard** (Staff home), with one card per topic:
- pickups due today
- overdue pickups
- unpaid reservations expiring in 10 minutes
- payments to review (needs review, refunds failed, refunds with no answer, unmatched payments)
- chats waiting
- follow-ups due
- low stock
- quick actions

Cards that need attention have a tinted border and a text label.

**Payments for an unknown reservation** (deferred from Phase 2)
- When PayMongo reports a payment for a reference that matches no reservation, it is saved as an "unmatched payment" instead of only being logged.
- It is listed under Payments to review. Staff can see it but not act on it. Admins can:
  - refund it, using the same rules as other refunds (one attempt, a claim before the call, and an admin reconciles unknown outcomes)
  - keep it
  - retry a failed refund
  - reconcile a stuck refund
- A reference that isn't in our `SWA-XXXXXXXX` format is flagged, so a payment from another system can't be refunded by mistake.
- `paymongo:simulate-webhook … --reference-override=…` creates one locally.

## 2. Extensions used and skipped
| Extension | Used for |
|---|---|
| `vue-builder` ×2, in parallel | (a) Customers, follow-ups and dashboard; (b) chat widget, handoffs, FAQ editor, messages and unmatched payments |
| `security-reviewer` | 0 critical or high, 1 medium, 6 low and 4 informational findings. The medium and all low findings are fixed; the informational points are recorded as decisions. |
| `payments-reviewer` | 0 high or medium, 4 low findings. All fixed. |
| Local Playwright (`playwright-core` 1.63, installed on your machine) | Scripted browser check at 375px: chat answer, handoff, dashboard, claiming a request, customer profile, note, FAQ test and messages. No console errors and no sideways scrolling. |
| impeccable design hook (your plugin) | It flagged two side-tab borders on Payments to review. Fixed, and the dashboard was changed the same way. No suppressions added. |

Skipped:
- **Playwright MCP:** failed to connect this session, so I used the local script instead.
- **context7:** the `NotificationSent` event was confirmed through `php artisan event:list` and the tests.
- **AI chatbot:** the spec asks for a cheap keyword bot.
- **GitHub:** local git only.

## 3. Decisions and assumptions
All eight are recorded in the `docs/SPEC.md` decisions log (2026-09-27):
- **Chatbot:** keyword scoring with a threshold, and live settings in answers.
- **Chat requests:** handled outside the app, with rate limits, one open request per person, and 90-day retention.
- **Card numbers:** refused in notes and masked in chats. ID numbers are not detected, because the check would also catch phone numbers; staff are told not to record them.
- **Message log:** stores metadata only.
- **Segments:** a fixed list.
- **Unmatched payments:** kept in their own table with their own refund path.
- **Follow-ups:** any staff member can complete one.
- **Chat widget:** appears on public and customer pages only.

Also:
- The chat requests "shop-wide" cap counts every IP together, so a spam wave could briefly hide real requests after 30 in an hour. Real customers can still reach the shop by phone.
- The "shop hours and address" FAQ answer is a placeholder until you fill it in.

## 4. Tests and command outputs
New tests (50):
- `Care/CrmTest`
- `Care/ChatbotTest` (7 sample questions in English and Taglish)
- `Care/MessagesAndAlertsTest`
- `Care/OrphanPaymentTest`
- `Care/Phase3ReviewFindingsTest`
- `PageSmokeTest`, extended with 5 new pages

Tests fixed along the way:
- The `confirmedReservation()` test helper always used the same PayMongo payment id. Its second use in a test was correctly rejected as a duplicate, so the helper now uses a unique id per call.

```
php artisan test          → Tests: 306 passed (1729 assertions)
vendor/bin/pint --test    → passed
npx vue-tsc --noEmit      → exit 0
npx eslint .              → exit 0 (the .claude/ folder is now ignored; see section 7)
npm run format:check      → All matched files use Prettier code style
npm run build             → built
```

## 5. Review findings

**Fixed**
- **Medium (security): spam could bury real chat requests.** Added a per-contact limit and a shop-wide limit, one open request per person, and a 90-day purge.
- **Low (security):**
  - A guest could plant text in staff email links: `mailto` links are now encoded, and email addresses containing `? & = %` are rejected.
  - Card numbers in chats are now masked.
  - A card number written with dots or slashes slipped past the note filter; it now uses a Luhn check across more separators.
  - The chat history in a request comes from the visitor's browser and could be faked; it is now labelled "unverified".
  - An admin closing a request could overwrite who claimed it; it no longer does.
  - The chat history is now separate for each signed-in account.
  - The FAQ test now uses the staff rate limit.
  - The profile counted only the latest 50 reservations; it now counts all of them.
- **Low (payments):**
  - Stuck refunds of unmatched payments now count on the dashboard, because one shared query serves both screens.
  - Unmatched payments now have their own read permission.
  - Failed and stuck ones are listed first.
  - Messages now say "queued" instead of "sent to PayMongo". This was also fixed in the Phase 2 messages.
  - References in a foreign format are flagged.
- **Browser check:** the dashboard's payments card showed 2 while its breakdown showed 0, 0, 0; it was missing a "Refunds with no answer" line.
- **Design hook:** removed the side-tab borders.

**Deferred or not acted on**
- **Close permission lives in the service:** only the staff member who claimed a request (or an admin) can close it. That rule is enforced in the service query rather than the policy. It works and is tested.

## 6. Manual verification checklist
MariaDB must be running (start it from the XAMPP control panel). Then run `php artisan serve` and `npm run dev`. To load the FAQs into an existing dev database, run `php artisan db:seed --class=ChatbotFaqSeeder`; it only inserts into an empty table.

1. **Chatbot:** open http://127.0.0.1:8000/items on your phone or at 375px.
   - Tap "Questions? Chat with us", then "Can I cancel?". The answer mentions the free cancellation.
   - Ask "magkano po ang downpayment". The answer says 30%.
   - Ask something unrelated. It offers "Talk to a person". Send a request with your email.
   - Send a second one. It says "We already have your request".
2. **Handoffs:** log in as `staff@swa.test`, open **Chat handoffs**, and choose **I'll take it**. Then **Close with note**.
3. **Customers:** Staff → **Customers** → Dev Customer.
   - Add a note.
   - Try a note containing `4111 1111 1111 1111`. It is refused.
   - Add a follow-up due today. It appears on the dashboard and on **Follow-ups**. Mark it done.
   - As `admin@swa.test`, create a tag. Attach it to the customer, then filter the customer list by it.
4. **Segments:** in the customer list, pick "Awaiting pickup now" or "Forfeited before".
5. **Dashboard:** open Staff home. Check the counts against the pages each card links to.
6. **Low-stock alert:** run `php artisan stock:low-alert`. The alert email is written to `storage/logs/laravel.log` (with `queue:work` running). **Admin → Messages sent** shows it with a masked address.
7. **FAQ editor:** Admin → **Chatbot FAQ**.
   - Edit "What are your shop hours and address?" with your real hours.
   - Use **Test a question** with "pwede ba gcash", then ask the widget.
8. **Unmatched payment:** reserve something, then run:

   ```bash
   php artisan paymongo:simulate-webhook SWA-XXXXXXXX --reference-override=SWA-NOTREAL1
   ```

   The output says `unknown_reservation`. Admin → Payments to review shows it under "Payments for an unknown reservation". **Keep, mark reviewed** clears it. **Refund in full** would queue a real PayMongo test refund, so press it only with `queue:work` running and only if you want that.

## 7. Risks and open questions
1. **Your impeccable plugin files are in the project folder:** `.claude/skills/impeccable/`, `.claude/agents/impeccable-*.md` and `.claude/settings.local.json`. I haven't committed or changed them, and I set ESLint to skip `.claude/`. Should they be committed, or listed in `.gitignore`?
2. **Hooks and automated test checkouts:** both are still unanswered, so both stay off.
3. **Shop hours:** the FAQ answer is a placeholder. Please fill it in from Admin → Chatbot FAQ.
4. **Privacy notice and data retention:** chat requests are now deleted after 90 days. Customer notes and the message log are kept with no time limit. Someone familiar with the Data Privacy Act should confirm these retention periods and the registration wording.
5. **MariaDB:** it wasn't running at the start of this session; I started XAMPP's MariaDB myself. Consider running it as a Windows service so it starts with the PC.
6. **Still open from earlier:** Resend domain verification, a backup package (manual steps are documented), and your PayMongo tunnel test.

## 8. What's next
The spec's Phase 4 ("only if I ask") covers:
- a loyalty points ledger (earned when a reservation is completed)
- serial numbers for high-value items
- a real SMS provider behind the existing `SmsSender`
- a QR code on the confirmation
- printable receipts (the walk-in receipt already prints)
- bundles

Nothing is planned unless you ask for it. The other option is a hardening pass before going live:
- run MariaDB as a service
- deploy checklist
- the real PayMongo tunnel test and Resend domain
- backups
