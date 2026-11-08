# DormFind: secured Render deployment

Complete React + LavaLust project. Existing screens and workflows are retained. Database requests still go through LavaLust's connection manager, Query Builder and models. The connection implementation now uses **MySQLi prepared statements**. No PDO code or PDO MySQL extension is required.

Start with [DEPLOY-RENDER.md](docs/DEPLOY-RENDER.md). The [rubric mapping](docs/RUBRIC-EVIDENCE.md), [security changes](docs/SECURITY-CHANGES.md), [API reference](docs/API.md) and [verification results](docs/TEST-RESULTS.md) explain the implementation.

## Before replacing files

1. Back up the existing database and keep a private copy of your configuration.
2. This ZIP intentionally excludes live `.env`, Firebase service-account JSON, logs, sessions and `node_modules`. The original upload contained credentials. Rotate the database password, server API keys and JWT secrets, then enter the new values privately.
3. Use `.env.example` as a field checklist. It contains no usable secret values.
4. Run migrations once before using the updated app. Migration 008 adds security throttling and session activity tracking, and hashes any imported plaintext passwords without changing users' passwords. Existing users need to log in again after the upgrade.
5. Do **not** import `database/dormfind_db.sql` into the populated database. Its DROP TABLE statements are intended only for a new, empty database.

## Local setup

Requirements: PHP 8.4 with `mysqli`/`mysqlnd`, `curl`, `mbstring`, `fileinfo`, `openssl` and sessions. Node 22 is used for the frontend build. Laragon must use the same extensions.

Copy `.env.example` to `.env`, fill in your database/service settings, then generate separate application secrets:

```bash
php lava key:generate
php lava jwt:generate
php lava migration run
```

For the first setup of an empty database only, import the schema before running migrations. On an existing database, run the migrations directly. Verify the database backup before changing it.

Start the built interface and API together:

```bash
php -d upload_max_filesize=8M -d post_max_size=12M -S localhost:8000 -t public router.php
```

Open http://localhost:8000. Compiled React files are included. No frontend installation is needed just to use the included build.

For frontend editing:

```bash
cd frontend
npm ci
npm run dev
```

When ready to rebuild the included interface:

```bash
npm run build:backend
```

Do not upload Windows `node_modules` to Render. Docker performs a clean Linux `npm ci` using the lockfile.

## Service settings

| Service | Private configuration |
|---|---|
| MySQL/Aiven | `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `DB_SSL_CA`, `DB_SSL_REQUIRED` |
| reCAPTCHA v2 Checkbox | `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY`, `RECAPTCHA_ALLOWED_HOSTNAMES` |
| Cloudinary | `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET` |
| SendGrid | `SENDGRID_API_KEY`, verified `SENDGRID_FROM_EMAIL`, `SENDGRID_FROM_NAME` |
| Mapbox | `MAPBOX_PUBLIC_TOKEN`, with appropriate allowed URL restrictions |
| Firebase web config | `FIREBASE_API_KEY`, `FIREBASE_AUTH_DOMAIN`, `FIREBASE_DATABASE_URL`, `FIREBASE_PROJECT_ID`, `FIREBASE_APP_ID` |
| Firebase server | `FIREBASE_SERVICE_ACCOUNT`: absolute Linux path on Render, usually `/etc/secrets/firebase-service-account.json` |

Publish `firebase/database.rules.json` in Firebase Realtime Database. Keep Firebase Authentication authorized domains and reCAPTCHA allowed domains consistent with the deployed hostname. Mapbox/Firebase browser configuration is intentionally public; server secrets are never returned by `/api/config`.

The optional reCAPTCHA bypass works only with `APP_ENV=development`, `RECAPTCHA_DEV_BYPASS=true` and a loopback request. Production always disables it.

## Security and request behavior

Browser writes require the CSRF session cookie plus `X-CSRF-Token`. The supplied React API client fetches the token and sends both automatically. The recommended deployment serves the frontend and `/api` from the **same Render hostname**. Unrelated frontend/backend sites require a separate cookie-policy design and are not the default setup.

Access tokens last 15 minutes. Refresh tokens rotate, and authenticated sessions expire after 30 minutes without a protected request. Logout and password reset invalidate the associated access session on its next request. The browser clears local tokens on logout.

External services have connect/request time limits and share a 20-second budget per HTTP request. A timeout can still happen because of networking, the database provider, large datasets or hosting limits. For a write whose response is lost, check whether the operation saved before submitting it again. The client does not automatically retry timed-out writes.

`GET /api/health` checks that PHP and routing are running. It deliberately does not contact MySQL or external providers. `GET /api/readiness` requires an admin access token and checks required database tables. A healthy liveness response does not prove that all external services are configured.
