# CMS analytics schema analysis

Inspected all 24 live CMS tables, their columns, row counts and declared foreign keys on 2026-09-27. No schema or business data was modified.

`catering_info.id` is the tenant root. Most business records reference it through `catering_id`. Enquiries link to quotations and events; events own payments, refunds, expenses, menus, staffing and operational records. Aggregate enquiries and events independently before joining to caterers to avoid multiplying totals.

| Table | Rows at inspection | Purpose and analytics treatment |
| --- | ---: | --- |
| activity_log | 298 | Audit actions including LOGIN. Not used as a user count; repeated actions are not unique users. |
| catering_images | 1 | Tenant branding assets; not a business activity metric. Uses `created_on`, not `created_at`. |
| catering_info | 1 | Total/active caterers, growth, currency and tenant identity. |
| customer_query | 5 | Leads, status distribution and conversion using `is_converted`. |
| customer_query_followup | 3 | Follow-up history; one-to-many with enquiries, excluded from lead counts. |
| customer_quotation | 3 | Active quotation records created in the reporting period; revisions count as records. |
| customer_quotation_menu | 22 | Quotation menu sections; excluded from quotation counts and financial sums. |
| event_checklist | 13 | Tasks with completion timestamps; no `status` or `is_active` columns. |
| event_details | 4 | Events, status distribution, upcoming events and non-cancelled contract value. |
| event_document | 0 | Document uploads; has `status` but no `is_active`. |
| event_expense | 2 | Approved expense amount by expense date, excluding inactive/deleted records. |
| event_inventory | 6 | Procurement records; not added to expenses to avoid overlapping cost accounting. |
| event_menu | 22 | Event menu sections; not independent bookings. |
| event_note | 2 | Operational notes; has `status` but no `is_active`. |
| event_payment | 7 | Only SUCCESS payments, by `payment_date`, count toward collections. |
| event_payment_deadline | 1 | Event due-date metadata; no payment amount or creation timestamp. |
| event_refund | 1 | Only PROCESSED refunds, by `refund_date`, reduce net collections. |
| event_staff | 9 | Event staffing assignments; rate/total fields are not proof of payment. |
| staff_ledger | 2 | WAGE/ADVANCE/SETTLEMENT entries, with void markers; excluded from collections. |
| staff_member | 4 | Staff directory; distinct from platform login users. |
| staff_shift | 6 | Attendance linked to assignments; no `created_at`, status or amount. |
| supplier_account | 1 | Supplier balances; not customer receipts or revenue. |
| supplier_transaction | 3 | Supplier bills/payments/adjustments with posting state; excluded to avoid double-counting expenses. |
| user_info | 3 | Active users, roles and users whose latest login occurred today. |

## Metric rules

- Reporting periods are 1, 3, 6 or 12 calendar months, through the current CMS server time. The first month starts on day 1. Future-dated timestamps are excluded. Date-only expenses include today.
- Total caterers includes inactive records. Active caterers and users are current snapshots requiring both `is_active=1` and `status=1`. Business record aggregates use their own active/status flags across all tenants; the ranking specifically includes active caterers.
- Upcoming events are a current snapshot of UPCOMING or CONFIRMED events dated today or later. Other event counts use creation time, including cancelled events in status counts.
- Lead conversion measures the current converted state of leads created in the selected period. It does not divide event count by enquiry count.
- Booking value is contract value for non-cancelled active events created in the selected period. It is not realized revenue.
- Collections and refunds are transaction totals. Net collections are not profit. Approved expenses do not imply payment and are displayed separately.
- At initial inspection there were no subscription or billing tables. The subsequent [manual subscription implementation](manual-subscriptions.md) adds three tables; analytics now shows real active subscriptions and subscription receipts separately from catering event payments.
- Currency comes from tenant configuration. Financial displays/CSV totals are withheld if multiple tenant currencies exist; no conversion is attempted. Historical transactions do not store a separate currency snapshot.
- Charts and cards share the same snapshot and period. Charts fill missing months with zero. Both financial chart series use full currency units.
- The authenticated page and CSV use the existing analytics role policy. Refresh reads the same route every 30 seconds, pauses in hidden tabs, and retains the last good snapshot on failure. Exports query fresh data and neutralize spreadsheet formula prefixes.

## Original defects corrected

The old page queried a nonexistent `event_document.is_active` column and called PDO `query()` with unbound named parameters. Either error caused analytics to fall back to zeros. It also displayed hardcoded growth percentages, duplicated contract values as revenue, ignored the reporting selector, and had no export or automatic refresh.

## Verification

Run `php tests/analytics.php` for read-only live database consistency checks. PHP syntax checks and `node --check assets/js/analytics.js` cover syntax. Browser verification requires a working browser attachment and authenticated admin session.
