# Security Threats and Prevention: applied changes

Based on the supplied Unit 6 material. These controls reduce identified risks; they are not a claim of a completed penetration test or guaranteed availability.

| Threat | Implemented prevention | Main files |
|---|---|---|
| SQL injection | MySQLi native prepared statements; values bound separately. Query Builder/ORM continue through LavaLust. Sort direction and table selection are restricted. Migration metadata queries use positional placeholders too. | `scheme/database/Database.php`, `Database_result.php`, `DBForge.php`, `app/models/Dorm_model.php` |
| XSS | React's escaped text rendering is retained. No user HTML insertion is introduced. Names, text lengths, enums and search inputs are validated. CSP restricts scripts, frames, connections and workers to required providers. API JSON and nosniff headers remain enabled. | `frontend/src/`, `app/controllers/DormController.php`, `app/config/config.php` |
| CSRF | Random per-session token, strict HttpOnly cookie, SameSite=Lax, production Secure flag, Origin checks and a token header for every write. Session locks close before database/network work. | `app/libraries/Dorm_security.php`, `frontend/src/services/api.js` |
| Broken authentication | Proper password hashes, byte-preserved password input, single-use OTP/reset verification under row locks, fresh token session IDs, refresh rotation, session revocation on logout/reset and 30-minute inactivity expiration. Migration 008 hashes imported plaintext passwords before login. | `Dorm_api.php`, `DormController.php`, migration 008, framework `Api.php` |
| Unsafe file upload | Real upload verification, actual file size, server MIME allowlist, decoded image dimensions, pixel/dimension caps and random Cloudinary public IDs. PHP uploads are not stored in executable public directories. | `app/libraries/Dorm_services.php` |
| Sensitive data exposure | Blank example environment file; credentials/private JSON/logs omitted from delivery and Docker context. Generic production errors, no SQL binding logs, verified DB TLS when required, server secrets excluded from public config. | `.env.example`, `.dockerignore`, `Errors.php`, `Routine.php`, `Database.php` |
| Security misconfiguration | Production Docker defaults, OPcache, bounded workers/memory, public-only web root, no directory listings, server version hiding and security headers. Platform variables take precedence over a local `.env`. | `Dockerfile`, `deploy/`, `LavaLust.php` |
| Broken access control/IDOR | Existing server-side admin/student/landlord and ownership checks retained and tested. Banned accounts are checked against the database. A valid JWT alone is insufficient after session revocation. | `DormController.php`, `Dorm_api.php` |
| Brute force | Atomic locked general request limiter, persistent database throttles for sensitive auth/OTP actions, account-based failed login lockouts and Retry-After headers. Cleanup is bounded and indexed. | `Dorm_api.php`, `Dorm_model.php`, migration 008 |

CSP permits inline **styles** because the existing UI and map/reCAPTCHA widgets require them. Inline scripts and inline event-handler attributes remain disallowed. Mapbox workers require `blob:`. Browser widget behavior with real provider keys still needs deployment verification.

Authenticated limits use the verified user ID; anonymous limits conservatively use the direct peer address. Behind Render, anonymous visitors can share a proxy-IP limit. Sensitive login/OTP actions also have account-based limits independent of that address. The code does not blindly trust client-provided forwarding headers.

## Reliability changes

- MySQL connect and socket-read deadlines: 5 and 10 seconds by default; lock waits limited to 5 seconds. A slow dependency produces a controlled error rather than an unlimited wait.
- Provider calls: up to 3 seconds to connect, at most 12 seconds for one call, with a shared 20-second budget across calls in a web request. Existing SendGrid HTTPS delivery remains synchronous, so an unavailable provider can still cause an email-related operation to fail.
- Listing queries use an indexed existence test for photos and batch-load images/amenities instead of making multiple queries for every result.
- Public health checks avoid database metadata loops. Detailed database table checks require admin authentication.
- Browser requests have explicit time limits. Only a CSRF failure before a business action is retried automatically; timed-out writes are not retried.
- Refresh-token and OTP/reset operations use transactions. Nested transactions use MySQL savepoints. Last-photo checks execute under the property lock.

Property/image deletion still involves MySQL and Cloudinary, which cannot share one database transaction. After an individual photo's database removal, a failed Cloudinary cleanup is logged and may leave an orphaned cloud asset for manual cleanup. A durable cleanup queue would be appropriate for a larger production deployment.

Secrets present in the original upload must be rotated at their providers. Removing them from the new ZIP does not revoke an exposed key or remove it from repository history.
