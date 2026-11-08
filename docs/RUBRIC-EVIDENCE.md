# Progress-checking rubric mapping

| Criterion | Evidence in this project | Demonstration |
|---|---|---|
| DB connection and `.env` (10) | `app/config/database.php`, `.env.example`; existing `.env` stays on the user's machine | Run migrations and admin-only `/api/readiness`; inspect the MySQLi implementation inside the LavaLust connection manager |
| React frontend (10) | `frontend/src/`, `public/app.html`, `public/assets/` | Browse, login, filters, landlord CRUD, matches, inquiries, and Messages |
| LavaLust API class (10) | `app/libraries/Dorm_api.php` extends framework `Api`; `app/controllers/DormController.php`; `app/config/routes.php` | Exercise `/api/config`, `/api/health`, and authenticated endpoints |
| ORM/Query Builder (15) | `app/models/Dorm_model.php` uses `db->table()`, `where()`, `join()`, `get()`, `get_all()`, `insert()`, `update()`, `delete()`; bound SQL for locks/complex joins | Show filtered listings and model methods |
| CRUD (20) | Landlord property create/read/update/delete, image and amenity management, questionnaire upsert, inquiry create/read/status update, auth | Use two role-specific sessions and refresh to show persistence |
| Library organization (5) | `app/libraries/Dorm_api.php`, `Dorm_security.php`, `Dorm_services.php`; `app/models/Dorm_model.php` | Point out controllers remain focused on HTTP flow |
| Framework loading (10) | `$this->call->library()` and `$this->call->model()` in controller; no manual `require` for custom API dependencies | Inspect `DormController::run()` |
| Config (5) | `app/config/dorm_services.php` autoloaded by `app/config/autoload.php`; `config_item()` in service and public config endpoint | Demonstrate changing env-backed settings without hardcoded keys |
| Composer autoload (5, if applicable) | No PHP Composer packages are required by this implementation; LavaLust's native loader is used. Frontend JavaScript dependencies use `frontend/package.json`/`package-lock.json` | Explain N/A if examiner accepts applicability wording |
| Code quality (10) | Parameterized queries, allowlisted tables, validation, ownership checks, scoped mutations, CSRF/session controls, bounded external calls, README/tests | Review with examiner and run local checks |

The mapping is **implementation evidence, not a guaranteed 100/100 grade**. Local disposable-database integration checks passed; real Render/provider acceptance checks remain necessary. Third-party credentials, verified domains, Firebase rules, and network availability remain necessary. The supplied dump has destructive `DROP TABLE` lines; never import it over the already populated database.

The supplied rubric asks for LavaLust connection-manager and ORM/Query Builder usage. This project retains those entry points but adapts the underlying connection implementation to MySQLi at the user's request. It is not an unmodified stock database driver.
