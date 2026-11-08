# DormFind REST API

Base: `/api`. All database access is through LavaLust. Successful response: `{"data": ...}`. Errors: `{"error":"message"}` (the framework rate limiter additionally returns rate-limit metadata). Protected calls require `Authorization: Bearer <access_token>`. Every POST/PUT/PATCH/DELETE also requires the CSRF cookie and `X-CSRF-Token` from `/csrf`. The included React client handles this. Serve the frontend and API on the same hostname.

| Method | Path | Access / purpose |
|---|---|---|
| GET | `/health` | Lightweight liveness, no database dependency |
| GET | `/csrf` | CSRF token; stores an HttpOnly browser session cookie |
| GET | `/readiness` | Admin only; verifies database and required tables |
| GET | `/config` | Public provider settings only |
| POST | `/auth/send-email-otp` | email; sends a six-digit registration OTP |
| POST | `/auth/register` | reCAPTCHA; first_name, last_name, email, email_otp, password, phone, role |
| POST | `/auth/login` | reCAPTCHA; email, password; returns user and tokens |
| POST | `/auth/forgot-password` | reCAPTCHA; email; sends reset link |
| POST | `/auth/reset-password` | token and new password |
| POST | `/auth/refresh` | refresh_token; consumes old token and returns rotated tokens |
| POST | `/auth/logout` | refresh_token; revokes matching token |
| GET | `/auth/me` | Current authenticated account |
| GET | `/properties` | Public approved, available listings; q, min_price, max_price, amenities (comma-separated), lat, lng, radius_km |
| GET | `/properties/{id}` | Public approved/fully_leased detail; unpublished listing requires owner |
| GET | `/landlord/properties` | Current landlord's listings |
| POST | `/properties` | Approved landlord; multipart `property` JSON + required `image` file, saved together |
| PUT | `/properties/{id}` | Owner updates full editable listing |
| DELETE | `/properties/{id}` | Owner deletes listing and associated uploaded images |
| POST | `/properties/{id}/images` | Owner; multipart field `image`, max 8 MB |
| DELETE | `/properties/{id}/images/{image}` | Owner removes associated photo |
| PUT | `/properties/{id}/images/{image}/primary` | Owner selects cover |
| GET | `/roommates/preferences` | Authenticated user's saved questionnaire |
| PUT | `/roommates/preferences` | Student; all five preference fields |
| GET | `/roommates/matches` | Student with saved profile; computed scores and breakdown |
| GET | `/inquiries` | Applicant's outgoing or owner's incoming inquiries |
| POST | `/inquiries` | Authenticated; property_id, message; emails landlord |
| PUT | `/inquiries/{id}` | Property owner; status `pending`/`accepted`/`declined`; emails applicant |
| POST | `/chat/token` | Authenticated; Firebase custom token for MySQL user_id |
| GET | `/chat/contacts` | Authenticated; user IDs, names, roles (no emails/passwords) |
| POST | `/chat/conversation` | Authenticated; peer_id; validates contact and returns deterministic pair metadata |
| GET | `/admin/overview` | Admin counts and user review list |
| PUT | `/admin/users/{id}` | Admin only; `is_verified` landlord approval or `is_banned` flag |
| POST | `/contracts` | Approved landlord, accepted inquiry; `inquiry_id`, `start_date`, `end_date`; terms are generated automatically |
| GET | `/contracts/{id}` | Landlord or applicant; agreement and signatures |
| POST | `/contracts/{id}/sign` | Landlord or applicant; signature name comes from the authenticated account |
| GET | `/notifications` | Authenticated renter's expiry reminders |

`PUT /properties/{id}` JSON body; for `POST /properties`, send this object as the `property` multipart field **plus** an `image` file:

```json
{"title":"Student Home","description":"Near campus","monthly_rent":2500,"total_capacity":4,"available_beds":2,"latitude":13.41,"longitude":121.18,"amenities":["Wi-Fi","Kitchen"]}
```

Coordinates may be null only as a pair. `available_beds` must be between zero and total_capacity. Allowed amenities are defined consistently in frontend and backend. Ownership comes from the authenticated user; an arbitrary `owner_id` in input is ignored.

Preferences body:

```json
{"sleep_schedule":"night_owl","cleanliness":"very_tidy","noise_tolerance":"quiet","pet_friendly":1,"study_habits":"library"}
```

Chat messages do not pass through MySQL. After authenticated `/chat/token`, the browser signs in to Firebase and subscribes/writes to `direct/{lower_user_id}/{higher_user_id}/messages`; security rules enforce membership, sender, message length, and server timestamp. The conversation metadata endpoint does not grant Firebase access by itself.

Registration is separate from login. Cloudinary and SendGrid credentials are server-side. Missing provider configuration returns HTTP 503 for the required service; notifications instead return `not_configured` or `failed` alongside a successful database action. No fake success is returned for chat/upload or captcha verification.
