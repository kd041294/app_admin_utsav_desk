# Platform settings

Settings are stored in `platform_settings` in the admin database. Existing CMS billing records remain separate. SUPER_ADMIN may edit and test SMTP; ADMIN may view every section. All writes and SMTP tests require a valid authenticated session and CSRF token.

## Functional controls

- General: brand name updates page titles and sidebar; billing/support contacts appear on receipts. The date format applies to receipt calendar dates; timezone applies to the system display clock. CMS reporting boundaries remain in CMS server time. Subscription currency is fixed to INR.
- Security: admin idle timeout and new failed-login lock thresholds/durations use the saved values. Limits are clamped defensively at runtime. The existing IP rate limit remains active.
- SMTP: saved host, port, username and TLS/SSL configuration. Passwords use AES-256-GCM and are never placed back in HTML. Blank preserves the credential; explicit removal clears it. A CSRF-protected, rate-limited connection test performs TLS and optional authentication, then QUIT. It does not send mail or enable automated delivery.
- Notifications: billing expiry and security-alert preferences control real sidebar alerts for roles authorized to view the linked information.
- Subscription/features: accurate read-only summary of the fixed INR 9,999 annual all-features plan and links to live management pages. Obsolete free-plan, trial, gateway and feature toggles have been removed from the UI.
- System: real database connectivity, environment, PHP version, session name and current clocks. No fictitious cache-clear operation is offered; configuration is read per request.

Saving validates the whole editable form, locks a revision row, detects stale concurrent edits, and writes settings plus a secret-free activity record in the same transaction. Unknown legacy keys are preserved but not editable. Reset restores the loaded form values and performs no database write. The UI prevents accidental navigation with unsaved edits and opens hidden sections containing invalid fields.

## Deployment

`SETTINGS_ENCRYPTION_KEY` is a base64-encoded 32-byte random key in the protected environment configuration. It has been generated locally. Preserve it across deployments/backups; existing encrypted passwords cannot be recovered without it. Set a separate key for each deployed environment before saving SMTP credentials. Existing plaintext SMTP credentials must be encrypted with `platformSettingsEncrypt()` before connection tests will use them. No credential values are written to the audit metadata.

The separate Utsav app and public registration service are outside this page's configuration scope. Automated mail delivery and app access gating need consumers in those services and are not presented as working switches here.

## Verification

Maintenance UI, runtime behavior and stored keys have been removed by `migrations/20260928_remove_maintenance_settings.sql`. Settings are key/value rows; the migration removes those entries without altering the shared table columns. Stale forms cannot restore removed keys.

Dummy SMTP values are currently configured at the user's request (`smtp.example.test`, TLS port 587, demo username and an encrypted placeholder password). The page labels this as demo configuration and blocks connection testing until real details are saved.

Run `php tests/settings.php`, `php tests/billing.php` and `php tests/analytics.php`. Settings tests use connection-local temporary tables and cover validation, optimistic concurrency, encrypted credentials, blank/clear behavior, audit redaction and rollback. No live SMTP email is sent by verification.

`node tests/settings-ui.cjs` exercises navigation, validation across sections, reset, dirty-form safeguards and submit behavior with a simulated DOM. `php tests/settings-route.php <case>` tests the actual PHP controllers with in-memory sessions and temporary database tables. Cases: `render`, `readonly`, `save`, `bad_input`, `bad_csrf`, `array_token`, `forbidden_save`, `forbidden_view`, `anonymous`, `smtp_empty`, `smtp_rate`, `auth_lock`, `auth_success`. Login tests verify the configured failed-attempt threshold and lock duration and successful-login resets. These tests do not create persistent accounts or alter live settings.
