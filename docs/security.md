# Security Notes

## Authentication & authorization

- Admin UI uses server-side PHP sessions.
- API uses per-user API keys.
- Role checks are enforced for admin-sensitive endpoints/pages.

## Password lifecycle

- Password hashing uses bcrypt (`PASSWORD_BCRYPT`).
- Minimum complexity rules enforced on create/reset/change.
- Bootstrap admin is marked `must_change_password=1`.
- Admin reset flow supports one-time temporary password issuance.

## MFA

- Optional per-user TOTP MFA.
- Setup/disable in `/admin/mfa.php`.
- Login accepts MFA code when enabled.

## Brute-force / lockout protection

- Failed login attempts are tracked by username/IP.
- Repeated failures trigger temporary lockout.

## CSRF protection

- Admin/session POST actions require CSRF token validation.
- CSRF token is stored in session and submitted with forms or `X-CSRF-Token`.

## API rate limiting

- Per-key fixed window limits.
- Exposed through `X-RateLimit-*` headers.
- `429` + `Retry-After` returned when exceeded.

## Input validation

- Username/password/role validation.
- Task validation for title/status/priority/datetime/tags.
- Attachment URL validation.

## Secrets management

- Do **not** commit secrets.
- Use environment variables or untracked `public/includes/secrets.php`.
- See `public/includes/secrets.php.example`.
