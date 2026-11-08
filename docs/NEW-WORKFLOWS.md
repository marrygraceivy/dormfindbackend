> Current deployment and security setup: see [DEPLOY-RENDER.md](DEPLOY-RENDER.md) and [SECURITY-CHANGES.md](SECURITY-CHANGES.md). Migration 008 is required for the updated API.

# DormFind role and rental workflows

Keep a private backup of your database configuration, rotate the exposed secrets, and use the new private values. Back up your database, then apply **only the additive migration** with `php lava migration run`. Do not import `database/dormfind_db.sql` over your existing database (that dump contains `DROP TABLE`). The migrations add the rental workflow, email registration OTP, and password-reset tables without renaming existing fields.

## First administrator

Public registration deliberately cannot create an admin. Register a normal account first, then in your trusted database admin client promote **that exact email** after confirming its identity:

```sql
UPDATE users SET role='admin', is_verified=1
WHERE email='YOUR-EXISTING-ADMIN-EMAIL@example.com' AND is_banned=0;
```

Log out and log in again. The admin dashboard then shows users, property approvals, counts, and ban/unban controls. Newly registered landlords start unapproved. New properties also start pending and appear in public housing only after admin approval. Approved properties show a disabled Approved button and an enabled Delete button in the admin dashboard. The existing DB connection settings are unchanged.

## Listings and renters

A new listing is sent as one multipart request containing details and the required first image. The backend uploads the image to Cloudinary and commits its DB record together with the listing; on failure it rolls back the DB transaction and attempts cloud cleanup. The owner must already be admin-approved. The landlord pins the property on Mapbox; latitude and longitude are filled automatically and manual address entry is removed. Public search hides pending, image-less, occupied, and unapproved-owner listings.

Students save their questionnaire in `roommate_preferences` (unique `user_id` upsert) and see compatibility scores for other non-banned students who saved theirs. Landlords can view their own properties, map locations, inquiries, and open Firebase messages to students. The Mapbox token and Firebase service account still need to be configured to demonstrate those live APIs.

Accepting an inquiry marks the whole property occupied, sets its available beds to zero, and rejects its other pending inquiries. Accepted or rejected inquiry responses are final and their status control becomes disabled. The landlord enters only the start and end dates; the server creates the rental agreement text from the property, rent, landlord, tenant, and dates. Each signature automatically uses the signed-in account name and cannot be edited in the form.

Registration requires a six-digit OTP sent to the entered email through SendGrid. Forgot password also sends a one-hour reset link through SendGrid. Keep valid `SENDGRID_API_KEY`, `SENDGRID_FROM_EMAIL`, and `SENDGRID_FROM_NAME` values in the existing `.env`.

## End-of-rent notifications

Run `php lava reminders` **daily** using Windows Task Scheduler (or server cron). It checks fully signed, accepted contracts with rental end dates 7, 3, 1, or 0 days away (Philippine calendar date), creates an in-app student notification and emails through SendGrid if configured. The unique `(inquiry_id,recipient_id,kind)` key prevents duplicate notices for each milestone; failed/not-configured emails remain unmarked for a later daily retry. In-app notifications are visible on the Notifications page. Without a daily scheduled command, reminders do not run automatically while nobody is using the site.

The old backend PHP page views were removed. `app/views/errors/` remains because the LavaLust framework requires its error templates. Frontend production assets are in `public/`. CLI migrations and API workflows were checked against a disposable local database; perform the provider checks on Render too.

## Verification on your Laragon PC

1. Keep your existing `LavaLust/.env` and `LavaLust/private/firebase-service-account.json` outside the ZIP. Preserve `app/config/database.php` and all service credentials when updating an existing installation.
2. Back up `dormfind_db`; run `php lava migration run` from the `LavaLust` directory. Use admin-only `/api/readiness` to verify the database. `/api/health` is now a dependency-free liveness endpoint. Never re-import the bundled SQL into a populated database.
3. Test with an admin, an approved landlord, and two students: approve the landlord; create a listing with an image; save two questionnaires and compare matches; accept an inquiry; create a contract and sign with both accounts. Verify the corresponding rows in `roommate_preferences`, `property_images`, `rental_contracts`, and `inquiries_applications`.
4. Set a test rental end date within seven days, then run `php lava reminders` once from the command line. Check the student's Notifications tab and `rental_notifications` table. Run it again to verify no duplicate in-app notification. Schedule this CLI command once a day for ongoing reminders.
5. Test Mapbox pins, Firebase messaging with two accounts, Cloudinary upload, reCAPTCHA, and SendGrid on Laragon using your existing keys. These external service calls need valid credentials and network access.

There is no PHP runtime or access to your MySQL server in the build environment, so PHP syntax was parsed and the React production build was checked, but live DB/API execution needs the Laragon test above.
