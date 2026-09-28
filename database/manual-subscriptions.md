# Manual annual subscriptions

Installed in the configured CMS database using `migrations/20260928_user_subscriptions.sql`.

## Plan and payment workflow

- Utsav Annual — All Features: INR 24,999 list price, INR 15,000 discount, INR 9,999 payable, one calendar year per user.
- The server fixes the price, plan and duration. Posted price/duration changes cannot alter them. A plan catalogue can be added later without changing historical receipt values.
- Billing → Record Payment selects an active CMS user from an active caterer, payer name, payment method, reference, payment date, requested start date and optional notes. The admin confirms full payment receipt. Non-cash methods require a unique method/reference pair. No gateway call or money transfer is performed.
- Only SUPER_ADMIN, ADMIN and FINANCE may record or void payments. VIEWER has read-only access. The existing authenticated page role policy and CSRF checks protect all writes, receipts and exports.
- Saving locks the selected user, inserts the paid payment, creates the annual subscription and writes an audit entry in one transaction. Failures roll back all three. A unique request key prevents repeat submissions, and a unique external reference prevents duplicate payment entry.
- Renewals start at the later of the requested date and the last uncancelled term's end date. This preserves purchased days and prevents overlapping terms even under concurrent requests for the same user.
- Terms use an exclusive `ends_on` date internally; the UI shows the final included day. Leap-day anniversaries clamp to February 28 in the following year.
- ACTIVE, SCHEDULED and EXPIRED are computed from dates, so no daily cron is needed. CANCELLED is stored when the associated payment is voided.
- Voiding requires a reason, cancels only the linked subscription and preserves the payment and audit record. It does not refund money, delete history, or reposition later renewals. The original external reference remains reserved.

## Tables

| Table | Purpose |
| --- | --- |
| `user_subscription_payment` | Immutable receipt, per-user payment details, price/discount snapshot, recorder identity, idempotency key and void metadata. |
| `user_subscription` | Per-user annual term, linked unique payment and `all_features=1` entitlement. |
| `user_subscription_audit` | Append-only record/void history with platform admin identity. |

User foreign keys reference `cms.user_info`. Platform admin IDs belong to the separate admin database and are recorded without a cross-database foreign key. Event payment tables remain independent of subscription payments.

## Connected pages

- Billing: real summaries, filtered/paginated history, CSV export, printable receipts and audit details.
- Subscriptions: annual plan display and live active/scheduled/expired/cancelled terms.
- Users: current subscription status and role-appropriate links to subscription history.
- Dashboard: active subscriber count, monthly subscription receipts and expiring terms.
- Analytics: real subscription receipts for the selected period and current subscriber count, separate from catering collections.
- Shared sidebar: consistent caterer, active-user and active-subscriber counts on every page. Fake support counts removed. Existing role-aware links preserved.
- Billing/subscriptions refresh every 30 seconds when visible and idle. Forms with unsaved input, open modals and receipts are not automatically refreshed.

This change records the all-features entitlement in CMS. It does not introduce subscription enforcement into the separate `app_utsav_desk` application's login or feature middleware; existing app role permissions continue to apply.

## Verification

`php tests/billing.php` tests fixed pricing, annual entitlement, idempotency, reference uniqueness, renewal scheduling, leap dates, invalid inputs, transaction rollback, void audit and role write policy. Billing writes in this suite use connection-local temporary tables; no test transactions are inserted into persistent billing tables.

`php tests/analytics.php` verifies period aggregation and chart/KPI consistency. PHP lint and JavaScript syntax checks cover modified sources. Anonymous HTTP requests to billing and subscriptions redirect to login.
