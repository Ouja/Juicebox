# Juicebox API

Laravel 13 API with Sanctum authentication, posts, user profiles, Perth weather, and queued welcome emails.

## Requirements

- PHP 8.5 and Composer 2.
- MySQL 8.4, running locally or on an accessible server.
- Laravel's required PHP extensions, including PDO MySQL, mbstring, OpenSSL, XML and DOM. Enable PDO SQLite for the default test suite.

No frontend build is required to use the API. Run `php -v` to confirm your terminal uses the correct PHP runtime.

## Setup

### 1. Install dependencies

From the project directory:

```sh
composer install
```

Copy `.env.example` to `.env` if you do not already have a local environment file:

```sh
cp .env.example .env
```

On Windows PowerShell, use `Copy-Item .env.example .env`. Keep existing credentials if `.env` already exists.

For a fresh installation, generate the application key:

```sh
php artisan key:generate
```

### 2. Configure the database

Create the database in MySQL using DBeaver or the MySQL client:

```sql
CREATE DATABASE juicebox CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

Update `.env` with your database credentials:

```dotenv
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=juicebox
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_0900_ai_ci

QUEUE_CONNECTION=database
CACHE_STORE=database
```

Create the application, queue, failed-job, session and cache tables:

```sh
php artisan config:clear
php artisan migrate
```

Create the database before running migrations. Migrations create the tables, including `posts.deleted_at` for soft deletion.

### 3. Configure email

The environment template includes Gmail SMTP placeholders. Choose one of these options before processing email jobs.

**Local testing without sending email:**

```dotenv
MAIL_MAILER=log
MAIL_FROM_ADDRESS=hello@example.com
MAIL_FROM_NAME="Juicebox"
```

Processed email content is written to `storage/logs/laravel.log`.

**Send real email through Gmail:**

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-google-app-password
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="Juicebox"
MAIL_TIMEOUT=10
```

Use a [Google App Password](https://support.google.com/mail/answer/185833), not your regular Google password. Keep credentials in `.env`; never commit them.

After changing environment settings, clear cached configuration and restart any running queue workers:

```sh
php artisan config:clear
php artisan queue:restart
```

A manually started worker exits after its current job; start it again with the command below.

### 4. Start the application

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

Base URL: `http://localhost:8000/api`. Health check: `http://localhost:8000/up`.

If using an existing local web server, point its document root to `public/` and update `APP_URL` and the Postman `base_url` accordingly.

## Queue worker

With `QUEUE_CONNECTION=database` and migrations applied, open a separate terminal in the project directory:

```sh
php artisan queue:work database --queue=default --tries=3 --timeout=60
```

Keep this process running to send welcome emails and process weather refresh jobs. New users receive a welcome email after registration. Jobs remain pending while the worker is stopped.

Failed email jobs are retried up to three times.

Inspect failed jobs and retry a specific job after fixing the cause:

```sh
php artisan queue:failed
php artisan queue:retry <failed-job-id>
```

In production, use a process manager to keep the worker running and restart it after deployments with `php artisan queue:restart`.

## Manually dispatch a welcome email

Register a user through `POST /api/register`, then use their `data.user.id`:

```sh
php artisan welcome:send 1
```

Replace `1` with an existing user's ID. A successful dispatch prints:

```text
Welcome email queued.
```

This queues the job; the queue worker must be running to process it. The email is sent to that user's registered email address. A missing or invalid user ID returns an error without queuing a job. Running this after registration queues an additional welcome email.

With the log mailer, check `storage/logs/laravel.log`. With SMTP, check the recipient's inbox and spam folder.

## Scheduler and weather cache

Run the scheduler in another terminal for local development:

```sh
php artisan schedule:work
```

It queues an hourly Perth weather refresh and prunes expired authentication tokens daily. The queue worker must also be running. In production, schedule `php artisan schedule:run` once per minute.

Weather comes from Open-Meteo and is cached for 15 minutes. These optional `.env` settings override the defaults:

```dotenv
WEATHER_API_URL=https://api.open-meteo.com/v1/forecast
WEATHER_CACHE_TTL=900
```

If weather data is unavailable, the API returns HTTP 503.

## API usage

Import [the Postman collection](Juicebox.postman_collection.json) and [local environment](Juicebox.local.postman_environment.json). Select **Juicebox Local**, set `base_url`, and enter a test email and password. Register or log in first; the collection saves the token automatically.

Send `Accept: application/json` and `Content-Type: application/json`. Protected routes require `Authorization: Bearer <token>`.

| Method | Path | Authentication |
| --- | --- | --- |
| POST | `/api/register` | Public |
| POST | `/api/login` | Public |
| POST | `/api/logout` | Required |
| GET | `/api/posts` | Required |
| POST | `/api/posts` | Required |
| GET | `/api/posts/{id}` | Required |
| PATCH | `/api/posts/{id}` | Post owner |
| DELETE | `/api/posts/{id}` | Post owner |
| GET | `/api/users` | Required |
| GET | `/api/users/{id}` | Required |
| GET | `/api/weather` | Required |

Register with `name`, `email`, `password` and `password_confirmation`. Log in with `email` and `password`. Create posts with `title` and `body`; PATCH accepts either or both fields. List endpoints support `page` and `per_page` (default 15, maximum 100).

All JSON responses use a single `data` wrapper. Lists contain `data.items`, `data.links` and `data.meta`; errors contain `data.message` and optional `data.errors`. Logout and successful deletion return HTTP 204 with no body. Posts are soft deleted and excluded from subsequent API reads.

Common errors: 401 for failed authentication, 403 for ownership failures, 404 for missing resources, 422 for validation, 429 for rate limits, and 503 for unavailable weather.

## Tests

```sh
php artisan test --compact
```

Tests use an in-memory SQLite database and do not send real email or call the weather provider.
