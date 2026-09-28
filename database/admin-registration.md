# Admin registration approvals

Admin and Super Admin use `/admin_utsav_desk/access-codes/` to approve an email, role and expiry (1–90 days). The page displays and copies the active 16-character code. Share it privately with the approved person, along with the registration portal URL. No email is sent automatically.

The registration application validates the code, binds the session to the approved email, then allows profile and password entry for ten minutes. Final submission locks the invitation, checks expiry and revocation again, inserts the admin account with the approved role and consumes the invitation in one transaction. The account can then sign in to the admin portal. Reapproval invalidates earlier unused codes; used codes cannot create another account. Invitations cannot create Super Admin accounts.

## Database

Both applications use the admin database, not CMS user records. `admin_registration_invites` stores the SHA-256 verifier, approved email and role, UTC expiry, usage time and creation time. The migration adds encrypted code storage plus `created_by`, `revoked_at`, `revoked_by` and `used_by` attribution. Display encryption uses the existing `SETTINGS_ENCRYPTION_KEY`; preserve that key when deploying or restoring backups. Raw codes are excluded from audit logs. Legacy verifiers remain supported by the registration application until their existing expiry.

`admin_user` stores completed accounts and password hashes. `activity_log` records issuance and revocation. `admin_registration_limits` throttles issuance and serializes approval per email; `ud_registration_limits` throttles public registration. The existing Super Admin bootstrap data is not changed by issuing invitations.

## Deployment

1. Apply `migrations/20260928_admin_access_codes.sql` once to the existing admin database.
2. Deploy the updated registration `index.php`, `private/bootstrap.php` and `private/setup.php` in the sibling registration project.
3. Run `php database/migrations/20260928_registration_permissions.php` from the admin project. This grants the existing restricted registration account only the additional SELECT permission on `revoked_at` and UPDATE permission on `used_by`. It does not grant access to encrypted invitation codes.
4. Set `ADMIN_REGISTRATION_URL` to the registration portal URL. Local development defaults to the sibling localhost portal.

## Verification

Run `php tests/access-codes.php`. It uses the installed sibling registration bootstrap and connection-local temporary database tables, leaving real invitations and accounts unchanged. Checks cover code format and encrypted recovery, verifier compatibility, approved identity and role, password verification, reuse, existing accounts, expiry, revocation after verification, reapproval, issuer authorization, Super Admin protection and audit secrecy.

Local HTTP smoke checks: registration returns 200, unauthenticated access-code management redirects to login, and direct access to private configuration returns 403. The automated tests exercise server-side registration logic; they do not constitute a full interactive browser walkthrough.
