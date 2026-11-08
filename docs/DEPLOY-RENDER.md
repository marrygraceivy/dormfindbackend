# Deploying the updated project on Render

## 1. Prepare the database

Back up the existing database. Enable MySQLi in your local PHP. Configure the database settings privately, then run from the project root:

```bash
php lava migration run
```

Migration 008 must run before the updated login/API is used. It adds `security_rate_limits`, `refresh_tokens.last_used_at`, a cleanup index, and hashes old plaintext passwords in batches. It does not drop existing application tables. Never use migration refresh or import the full schema dump over existing data.

If using Aiven, download the CA certificate from your own database service. Local migrations can use its local absolute path. Use `DB_SSL_REQUIRED=true` with a valid CA for the remote production database. Certificate verification remains enabled.

## 2. Update the repository

Put the contents of `LavaLust/` in the repository folder that contains the Dockerfile. Commit the updated source, package lockfile and deployment files. Exclude `.env`, private JSON files, logs, sessions and `frontend/node_modules`.

Do not copy the local test settings from any other environment. `.env.example` is a blank template.

## 3. Set Render environment variables

Keep your existing Render web service and select its Docker deployment configuration. Set these values in its Environment settings:

| Variable | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_URL` | Full HTTPS Render/custom-domain URL, with trailing slash |
| `FRONTEND_ORIGINS` | The same HTTPS origin, without trailing slash |
| `APP_KEY` | New random secret, at least 32 characters |
| `JWT_SECRET` | Different new random secret, at least 32 characters |
| `REFRESH_TOKEN_KEY` | Third different new random secret, at least 32 characters |
| `DB_DRIVER` | `mysqli` |
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | Your production MySQL provider settings |
| `DB_CHARSET` | `utf8mb4` |
| `DB_SSL_REQUIRED` | `true` |
| `DB_SSL_CA` | `/etc/secrets/ca.pem` |
| `DB_CONNECT_TIMEOUT` | `5` |
| `DB_READ_TIMEOUT` | `10` |
| `RECAPTCHA_ALLOWED_HOSTNAMES` | Hostname only, such as `your-app.onrender.com` |
| `RECAPTCHA_DEV_BYPASS` | `false` |

Also configure the service values listed in README. Use a SendGrid HTTPS API key and verified sender. Do not configure SMTP for this implementation.

Add these Render Secret Files:

- `ca.pem`: your database provider's CA certificate.
- `firebase-service-account.json`: your Firebase server credentials. Set `FIREBASE_SERVICE_ACCOUNT=/etc/secrets/firebase-service-account.json`.

A Windows path such as `C:/laragon/...` cannot work inside the Linux container.

Render provides `PORT`; `deploy/start.sh` makes Apache listen on that value. The fallback is 10000. No custom start command is needed because Dockerfile defines one. Docker installs MySQLi, cURL, mbstring and OPcache and uses `public/` as the web root.

## 4. Set the health check and redeploy

Set the service's **Health Check Path** to:

```text
/api/health
```

Redeploy the current commit. This health endpoint has no database, sessions, credentials or external API dependencies. It helps prevent unnecessary service restarts during a temporary provider outage. It is a liveness check, not a full readiness check.

The image sets bounded Apache workers and PHP memory limits to reduce memory pressure. Watch Render's memory/CPU metrics with representative data. A workload larger than the available plan can still exceed its resources.

## 5. Verify the deployed app

1. Open the HTTPS root and `/api/health`.
2. Log in with an existing account. Old tokens are intentionally rejected after the security upgrade.
3. As admin, test `GET /api/readiness` using the Bearer access token, and review the admin dashboard.
4. Test registration OTP, password reset, Cloudinary upload, Mapbox pins and Firebase messaging with your actual provider configuration.
5. Test a student and a landlord in separate browser sessions, including access restrictions, inquiries and contracts.
6. Confirm errors are generic in the browser and server errors reach the Render logs.

Keep the built frontend and API together on one hostname. If JavaScript previously cached an older build, reload the page after deployment so that the CSRF-aware client is active.

## Hosting limits that code cannot remove

Render Free web services spin down after 15 idle minutes and can take about one minute to wake. The initial configuration fetch allows 90 seconds for that wake-up. A paid compute instance avoids the free-plan idle spin-down.

Render's local filesystem is ephemeral. Database records remain in the external database and uploaded property photos remain in Cloudinary. Local CSRF sessions/cache can disappear on restart; the frontend requests a fresh security token. A multi-instance deployment needs shared CSRF session storage and a shared general rate limiter before scaling beyond this single-instance configuration.

References: https://render.com/docs/docker, https://render.com/docs/health-checks, https://render.com/docs/free.
