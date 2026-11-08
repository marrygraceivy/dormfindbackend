> Current deployment and security setup: see [DEPLOY-RENDER.md](DEPLOY-RENDER.md) and [SECURITY-CHANGES.md](SECURITY-CHANGES.md). Migration 008 is required for the updated API.

# DormFind Email Reports and Profile Setup

## Database update

Run the new migration after copying this project:

```powershell
cd C:\laragon\www\dormfindfinal\LavaLust
php lava migration run
```

Use admin-only `/api/readiness` after migration 008 to check the required database tables.

## Rental expiration email schedule

The existing CLI command now sends reminders to the registered email addresses of both the tenant and landlord 30, 7, 3, 1, and 0 days before the rental end date. Run it manually with:

```powershell
php lava reminders
```

For automatic daily email delivery, create a Windows Task Scheduler task that runs the command once every day from the LavaLust directory. The database unique key prevents duplicate reminders for the same person and reminder day.

## Rental agreement email

When a landlord creates an agreement, DormFind emails the generated agreement to both registered addresses. The agreement includes the property, dates, rent, no-refund rule, and only the amenity rules that apply to the selected dorm. Each signature emails the other party. When both parties have signed, both receive the completed agreement by email.

## Reports and privacy

Landlords can view and export only their own properties and tenants. Administrators can export all data or select users, landlords, properties, or tenants. Exports intentionally omit passwords, tokens, signature IP addresses, inquiry messages, and profile images.

## Security controls applied

- All new database queries use bound parameters and server-side role or ownership checks.
- React escapes displayed account and report values to reduce XSS risk.
- CSV exports neutralize spreadsheet formulas that begin with `=`, `+`, `-`, or `@`.
- Email OTP codes expire after 10 minutes and stop after five failed attempts.
- Login attempts lock for 15 minutes after five failures for the same email and IP pair.
- Existing password hashing, JWT sessions, rate limiting, CORS, generic error responses, and MIME-checked image uploads remain in place.
