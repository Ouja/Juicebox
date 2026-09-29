# Juicebox API

Laravel 13 REST API implementing the Laravel Developer Code Test (2024), with Sanctum bearer tokens, posts, users, Perth weather, and queued welcome emails.

## Evaluation guide

| Criterion | Implementation and evidence |
| --- | --- |
| 1. Code quality and organisation | Thin [controllers](app/Http/Controllers/Api), [services](app/Services), [repositories](app/Repositories) and [integration libraries](app/Libraries), with dependency injection and Pint formatting. |
| 2. Laravel features and practices | Sanctum, Form Requests, API Resources, middleware, Eloquent relationships, migrations, queued jobs, scheduler and cache locks. |
| 3. API design and REST | Explicit GET/POST/PATCH/DELETE routes, appropriate status codes, pagination, ownership rules and a consistent `data` envelope; see [routes](routes/api.php) and the endpoint reference below. |
| 4. Database design | MySQL migrations, unique email/token constraints, indexed foreign keys, user-to-post relationship and additive soft-delete migration; see [migrations](database/migrations). |
| 5. Errors and validation | Bounded input and pagination, duplicate-email handling, JSON 401/403/404/405/422/429/503 responses; see [requests](app/Http/Requests) and [response tests](tests/Feature/ApiResponseTest.php). |
| 6. Security | Hashed passwords/tokens, seven-day token expiry, current-token logout, authentication throttling, owner-only mutations, allowlisted resources, protected ownership fields and escaped email HTML. Credentials are excluded from Git. |
| 7. Performance | Eager-loaded post authors, bounded pagination, indexed post ordering, shared weather cache and async email. [Post tests](tests/Feature/PostApiTest.php) assert the list uses three queries rather than one author query per post. |
| 8. Testing | PHPUnit feature tests cover successful requests, validation, authorization, token lifecycle, soft deletion, response envelopes, queues, provider failures and cache expiry. GitHub Actions runs the suite on SQLite and MySQL 8.4. |
| 9. Documentation | This README includes setup, every endpoint, response examples, environment settings, queue/scheduler operations and Postman instructions. |
| 10. Queues | Welcome email dispatched after commit, manual `welcome:send` command, three attempts with backoff, failed-job handling and a real database queue-worker test; see [queue tests](tests/Feature/WelcomeEmailTest.php). |
| 11. External API | [OpenMeteoLibrary](app/Libraries/OpenMeteoLibrary.php) fetches current Perth weather with bounded timeouts and validates the provider response. HTTP calls are faked in tests. |
| 12. Caching | Fifteen-minute TTL, hourly forced refresh, shared refresh lock, no caching of failures, preservation of valid cached data on a failed refresh, and recovery on retry; see [weather tests](tests/Feature/WeatherApiTest.php). |

## Quick evaluation with Postman

1. Complete setup below, then start the web server, queue worker and scheduler.
2. Import [the collection](Juicebox.postman_collection.json) and [the environment](Juicebox.local.postman_environment.json). Select **Juicebox Local**.
3. Set `base_url` to your server address and enter a new test email/password. Run **Register** once, or **Login** for an existing account. The response script saves `token` and `user_id` automatically.
4. Run **Create Post**, then list/get/update/delete it. `post_id` is saved automatically. Deletion preserves the database row with `deleted_at` set.
5. Exercise the user and weather endpoints, then run **Logout** last. Subsequent protected requests return 401 until logging in again.
6. To inspect the welcome email without external credentials, leave `MAIL_MAILER=log`, process the queue, and open `storage/logs/laravel.log`.

The exported environment contains examples only. Do not commit exports containing real passwords or tokens. A complete collection run creates an account and post, deletes the post, and logs out; use a unique email each run. The five-per-minute authentication limit applies during manual testing.

## Requirements and setup

- PHP 8.5 recommended for the committed lockfile (developed with PHP 8.5.0).
- Composer 2.
- MySQL 8.4 (or a supported MySQL 8 release).
- PHP extensions required by Laravel, including PDO MySQL, mbstring, OpenSSL, ctype, fileinfo, tokenizer, XML and DOM. SQLite is needed for the default test suite.
- No frontend build is required to use the API.

The resolved framework version is **13.33.0**, the latest stable release available when dependencies were installed. Commit `composer.lock` for reproducible installs. `composer install` preserves these versions.

```sh
composer install
cp .env.example .env
php artisan key:generate
```

On Windows PowerShell, use `Copy-Item .env.example .env` instead of `cp`. If `.env` already exists, update it instead of overwriting it. Restart your terminal after installing PHP and confirm `php -v` selects the new runtime.

Create a database using your MySQL administrator account:

```sql
CREATE DATABASE juicebox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Set your database credentials in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=juicebox
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
```

Then run:

```sh
php artisan migrate
php artisan serve
```

The API is available at `http://localhost:8000/api`. The health endpoint is `/up`. Optional local sample posts can be generated with `php artisan db:seed --class=PostSeeder`; do not seed sample users in production.

## Architecture

- **Controllers** accept validated input, call a service, and format HTTP responses.
- **Middleware** enforces access: `auth:sanctum` authenticates protected routes and `post.owner` authorizes post updates and deletions before request validation. Failures return JSON 401 or 403 respectively.
- **Services** own business rules: registration, credential verification, weather freshness, and welcome-email orchestration.
- **Repositories** own application database queries, transactions, token persistence, and weather cache/lock storage.
- **Libraries** wrap third-party boundaries: `OpenMeteoLibrary` for HTTP and `MailLibrary` for the configured mail transport.
- Form Requests validate input without querying the database. Duplicate-email checks run through the service and repository; a unique database constraint handles races.
- API Resources allowlist response fields. Repositories eager-load post authors. Route IDs are passed to services rather than implicit model binding.
- Jobs and console commands delegate to services. Models declare attributes, casts and relationships. Laravel's authentication, queue and cache internals manage their own framework storage.

Posts belong to users; deleting a user cascades to their posts. An authenticated user can read all posts and public user profiles. Only the post's author can update or delete it.

The post DELETE endpoint uses soft deletion: it sets `posts.deleted_at` and preserves the record. Deleted posts are excluded from lists and return 404 when read, updated or deleted again. Run `php artisan migrate` to apply the additive `deleted_at` migration to an existing database. No restore or permanent-delete endpoint is exposed.

## Authentication

Send JSON with `Content-Type: application/json` and `Accept: application/json`. All API errors render JSON even without the Accept header.

Every JSON API response has exactly one top-level key: `data`. Authentication returns `data.user`, `data.token` and `data.token_type`; lists return `data.items`, `data.links` and `data.meta`; errors return `data.message` and, for validation failures, `data.errors`. Single resources and weather remain directly under `data`. HTTP 204 responses have no body. The HTML home and health routes are unchanged.

Only registration and login are public. Every other endpoint requires:

```http
Authorization: Bearer <token>
```

Registration and login each issue a token that expires after seven days. Logout revokes only the token used for that request; other devices stay logged in. Expired tokens are pruned daily by the scheduler.

Public authentication requests share a limit of 5 per minute per IP. Authenticated API requests share a limit of 60 per minute per user. Exceeding a limit returns `429` with `Retry-After`.

### Register

`POST /api/register`

```json
{
  "name": "Taylor",
  "email": "taylor@example.com",
  "password": "Securepass123",
  "password_confirmation": "Securepass123"
}
```

Name and email: required, up to 255 characters; email must be valid and unique. Emails are normalized to lowercase. Passwords require at least 8 characters, a letter, a number, matching confirmation, no null bytes, and at most 72 UTF-8 bytes. Passwords are hashed; clients must send the original password over HTTPS.

Returns `201`:

```json
{
  "data": {
    "user": {"id": 1, "name": "Taylor", "created_at": "2026-09-29T10:00:00.000000Z"},
    "token": "<opaque-token>",
    "token_type": "Bearer"
  }
}
```

A welcome-email job is queued after the user/token transaction commits.

### Login

`POST /api/login`

```json
{"email": "taylor@example.com", "password": "Securepass123"}
```

Returns `200` with the same response shape as registration. Invalid credentials return `401` with a generic message.

### Logout

`POST /api/logout` (no body) returns `204` with an empty response.

## Posts and users

| Method | Endpoint | Result |
| --- | --- | --- |
| GET | `/api/posts` | Paginated posts, newest first (ID breaks timestamp ties) |
| GET | `/api/posts/{id}` | One post and its author |
| POST | `/api/posts` | Create a post as the authenticated user; returns 201 |
| PATCH | `/api/posts/{id}` | Update the author's own post; returns 200 |
| DELETE | `/api/posts/{id}` | Delete the author's own post; returns 204 |
| GET | `/api/users` | Paginated public user profiles, ascending ID |
| GET | `/api/users/{id}` | One user profile |

`GET /api/users` supplements the PDF's endpoint list to satisfy its explicit requirement for user pagination.

Create a post:

```json
{"title": "Hello Perth", "body": "A first post."}
```

Title is required and up to 255 characters. Body is required and up to 10,000 characters. PATCH accepts either or both fields; omitted fields remain unchanged. Empty updates and null/blank values fail validation. Supplying `user_id` is prohibited. Other unrecognized fields are ignored and never mass-assigned.

Single-post response:

```json
{
  "data": {
    "id": 1,
    "title": "Hello Perth",
    "body": "A first post.",
    "user_id": 1,
    "user": {"id": 1, "name": "Taylor", "created_at": "2026-09-29T10:00:00.000000Z"},
    "created_at": "2026-09-29T10:00:00.000000Z",
    "updated_at": "2026-09-29T10:00:00.000000Z"
  }
}
```

A user profile contains `id`, `name`, and `created_at`. The authenticated user's own profile also includes `email`; other users' emails, passwords and remember tokens are never exposed.

Both list endpoints accept `?page=1&per_page=15`. Page must be an integer between 1 and 1,000,000; per_page must be between 1 and 100 (default 15). Responses contain `data.items`, `data.links` (first/last/prev/next) and `data.meta` (current_page, last_page, per_page, total, etc.). Pages beyond the last return an empty `data.items` array.

## Perth weather

`GET /api/weather` returns current conditions for Perth, Australia, from [Open-Meteo](https://open-meteo.com/en/docs). The free non-commercial endpoint needs no API key. Check the provider's [terms and pricing](https://open-meteo.com/en/pricing) before commercial deployment; this adapter is configured for the public endpoint.

```dotenv
WEATHER_API_URL=https://api.open-meteo.com/v1/forecast
WEATHER_CACHE_TTL=900
```

The server uses fixed Perth coordinates (-31.9523, 115.8613) and timezone `Australia/Perth`, configured in `config/weather.php`. Clients cannot supply an upstream URL or a different location. Outbound requests have a 3-second connection timeout and a 5-second overall timeout.

Example response (values are illustrative):

```json
{
  "data": {
    "location": "Perth, Australia",
    "latitude": -31.9523,
    "longitude": 115.8613,
    "timezone": "Australia/Perth",
    "observed_at": "2026-09-29T16:00",
    "temperature_c": 23.5,
    "feels_like_c": 22.1,
    "humidity_percent": 55,
    "is_day": true,
    "precipitation_mm": 0,
    "weather_code": 2,
    "wind_speed_kmh": 12.4,
    "fetched_at": "2026-09-29T08:01:00.000000Z",
    "source": "Open-Meteo",
    "attribution_url": "https://open-meteo.com/"
  }
}
```

`observed_at` is the provider's current-condition time in Australia/Perth; `fetched_at` is UTC. `weather_code` uses the provider's WMO codes. Preserve provider attribution when displaying the data.

Successful results are cached for 900 seconds (15 minutes). Cache misses fetch fresh data; an hourly queued job refreshes it independently of requests. A shared cache lock prevents simultaneous refreshes. Upstream HTTP failures, timeouts and malformed payloads return a safe `503`; failed responses are not cached. Expired weather is not silently served as current. A failed scheduled refresh leaves any still-valid cached result intact.

## Queue worker and scheduler

Run these in separate terminals alongside the web server:

```sh
php artisan queue:work --tries=3 --timeout=60
php artisan schedule:work
```

The default database queue and cache tables are included in the migrations. Keep the queue's retry_after greater than the worker timeout (default 90 seconds versus 60 seconds). Welcome emails and weather jobs each allow three attempts with backoffs of 10, 60 and 120 seconds. Weather jobs are unique for up to five minutes to avoid duplicate queued refreshes. Laravel's normal queue delivery is at-least-once; an SMTP acknowledgement failure may cause a duplicate welcome email on retry.

To manually queue a welcome email for a registered user:

```sh
php artisan welcome:send 1
```

The command validates the ID and reports a missing user without queuing mail. A job skips a user deleted before processing.

`MAIL_MAILER=log` is the development default: processed emails appear in `storage/logs/laravel.log`. For real delivery, configure `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` for your provider.

For Gmail, enable 2-Step Verification and generate an app password in your Google account, then set these values only in your local `.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-address@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_FROM_ADDRESS=your-address@gmail.com
MAIL_FROM_NAME="Juicebox"
MAIL_TIMEOUT=10
```

Use your generated app password, not your normal Google password. Port 587 upgrades with STARTTLS. See [Google's app-password instructions](https://support.google.com/mail/answer/185833). SMTP has a configurable ten-second socket timeout, below the job timeout, so an unreachable mail server does not block a worker indefinitely. After changing `.env`, run `php artisan config:clear` and restart your worker. `queue:restart` gracefully stops existing workers; a process supervisor must relaunch them, or you must rerun `queue:work` manually.

Useful operations:

```sh
php artisan schedule:list
php artisan queue:failed
php artisan queue:retry <failed-job-id>
php artisan queue:restart
```

In production, supervise the queue worker and invoke `php artisan schedule:run` once a minute (cron on Linux, Task Scheduler on Windows). Use `APP_DEBUG=false`, HTTPS, private credentials, and a shared database/cache for multiple application instances. Run `php artisan config:cache` after configuring the environment.

## Errors

| Status | Meaning |
| --- | --- |
| 401 | Missing, invalid or expired token; incorrect login credentials |
| 403 | Attempt to modify another author's post |
| 404 | Missing resource or invalid route ID |
| 422 | Invalid input or duplicate registration email |
| 429 | Request limit exceeded |
| 503 | Weather provider unavailable or refresh lock busy |

Validation example:

```json
{
  "data": {
    "message": "The title field is required.",
    "errors": {"title": ["The title field is required."]}
  }
}
```

Other errors contain a `data.message` field. Weather failures always return:

```json
{"data": {"message": "Weather data is temporarily unavailable. Please try again later."}}
```

## Tests and formatting

```sh
php artisan test --compact
vendor/bin/pint --format agent
```

On Windows, `php vendor/bin/pint --format agent` is also supported. The default test suite uses an in-memory SQLite database, array cache and faked HTTP/mail. It covers authentication and token lifecycle, validation, ownership, data privacy, pagination, weather normalization/failures/cache expiry, scheduling, database cache locks, the manual email command, database queue processing and escaped mail templates. No test sends actual mail or calls the weather provider.

To run the same suite against MySQL, create a **dedicated disposable** database such as `juicebox_test`. Tests reset that database. PowerShell example:

```powershell
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_DATABASE = 'juicebox_test'
$env:DB_USERNAME = 'your_test_user'
$env:DB_PASSWORD = 'your_test_password'
php artisan test --compact
```

Run this in a dedicated terminal and close it afterward so test connection overrides do not affect normal Artisan commands. Never point the tests at a development or production database containing data you need.

Laravel Boost is installed as a development dependency. Regenerate its guidelines with `php artisan boost:update` after dependency changes.

## Submission and operational notes

- Submit the GitHub repository URL and grant the evaluator access if it is private. The complete source, migrations, tests, dependency lockfile, Postman files and setup template are included.
- The [GitHub Actions workflow](.github/workflows/tests.yml) validates Composer metadata, formatting and dependencies, then tests with SQLite and MySQL. It does not use your local SMTP credentials or send mail.
- `.env`, database files, logs, temporary files, generated caches and installed dependencies are excluded. Install dependencies using `composer install` after cloning.
- The suite exercises behavior and failure cases; no line-coverage percentage is claimed. An SMTP job completing confirms acceptance by the configured transport, not guaranteed inbox placement.
- Soft deletion applies to the post API. A direct hard deletion of a user still cascades to their posts at the database level. There is no account-deletion or post-restore API.
- Welcome-email dispatch is after commit. For a production system requiring guaranteed dispatch during queue outages, use a transactional outbox; this code test uses Laravel's database queue and standard at-least-once delivery.
