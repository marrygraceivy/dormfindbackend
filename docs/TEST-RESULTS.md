# Verification results — 8 November 2026

Tests ran against a disposable local MariaDB 11.8.6 database and PHP 8.4.24 with MySQLi/mysqlnd, cURL, mbstring, fileinfo, OpenSSL and sessions. No request used the uploaded production database or the uploaded live provider credentials.

| Check | Result |
|---|---|
| All PHP source files | Syntax checks passed |
| Fresh schema plus all nine migrations, including 008 | Passed |
| Existing plaintext fixture passwords become hashes | Passed; original password remains usable |
| MySQLi CRUD, affected rows, insert IDs, bound malicious input, NULL results, result formats and nested savepoint rollback | Passed |
| 62 API/application checks | Passed; individual names recorded in `test-run.json` |
| External-service request budget exhausted | Controlled failure before any network call |
| React production build and copy into `public/` | Passed using clean `npm ci` and `npm run build:backend` |
| Deployment start script | Shell syntax checked |

The API checks cover CSRF rejection, foreign origins, unauthenticated and role-restricted requests, public listings, invalid filters, profile updates, preferences, inquiries, landlord/admin views, contract creation/signatures, reports, refresh rotation/replay, logout revocation, failed-login lockout, registration OTP/replay, password whitespace preservation, password reset/replay, access/refresh revocation after reset, fake JPEG MIME rejection, retaining the last property photo, OTP throttling and idle session expiration.

The database server was deliberately stopped during the test. `/api/health` and `/api/config` stayed available, while database-backed requests returned a controlled 503 promptly. Production-mode boot checks also verified generic errors, disabled development CAPTCHA bypass, CSP/nosniff headers and the bundled SPA response.

Remaining checks require the user's environment: Docker image build, actual Render deployment and metrics, MySQL-provider TLS/permissions/network access, real SendGrid email delivery, real reCAPTCHA, successful Cloudinary uploads/deletions, Mapbox tiles and Firebase chat with published rules. The tests above do not claim that these third-party integrations succeeded.

The frontend build retains Vite's warning about the large lazy-loaded Mapbox chunk. It completed successfully. The existing map module already loads dynamically; the warning is not a PHP/server crash.

## Included regression checks

`tests/database_smoke.php` requires a disposable database whose name ends in `_test`. Supply the test database variables through the process environment, never point it at production:

```bash
php tests/database_smoke.php
php tests/service_bounds.php
```

For the application walkthrough, use the README and `DEPLOY-RENDER.md`. Do not import the schema dump into an existing populated database.
