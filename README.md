# UP-Fix — Smart Maintenance Request System

Division of Buildings and Grounds, University of Phayao. See `SPEC.md` for the full specification.

## Requirements

- PHP **8.3 or newer** (this project was built and verified against PHP 8.5.4)
- `pdo_sqlsrv` and `sqlsrv` extensions — verified against **msphpsql 5.13.3** (matches the driver's
  own PHP 8.3+ floor) with **Microsoft ODBC Driver 18** for SQL Server (18.6.2.1)
- Microsoft SQL Server **2019+**
- Composer 2.x

> `ext-mbstring` is required by `composer.json`. If your distribution does not ship a PHP 8.3+
> mbstring package yet (a real gap encountered during development on a very recent Ubuntu release),
> `vendor/symfony/polyfill-mbstring` (a transitive dependency already installed by Composer) provides
> working `mb_*` functions for application code even without the native extension loaded — but
> **PHPUnit itself hard-requires the native extension to start**. If `vendor/bin/phpunit` refuses to
> run with "PHPUnit requires the ... mbstring ... extension", install the native `php-mbstring`
> package for your PHP version through your OS package manager before running the test suite.

## Local setup

```bash
composer install
cp .env.example .env
# edit .env: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS at minimum

php bin/migrate.php up
php bin/migrate.php status

php -S 127.0.0.1:8080 -t public
```

In another terminal:

```bash
curl -X POST -F 'text=แอร์ห้อง ICT1301 ไม่เย็น' -F 'channel=web' -F 'reporter_ref=dev' \
  http://127.0.0.1:8080/api/v1/tickets
```

Expect a `201` response body shaped like:

```json
{
  "ticket_no": "UPF-202608-00001",
  "id": "3F9A2C1E-...",
  "status": "triaging",
  "message_th": "รับเรื่องแล้ว กำลังวิเคราะห์ ระบบจะแจ้งผลภายใน 1 นาที",
  "poll_url": "/api/v1/tickets/3F9A2C1E-..."
}
```

## Running tests

```bash
vendor/bin/phpunit --testsuite Unit,Feature
```

Feature tests run against the real SQL Server instance configured in `.env` — there is no mock
database layer this phase.

## Migrations

- `php bin/migrate.php up` — applies every unapplied `database/migrations/NNN_*_up.sql` file in
  ascending numeric order, tracked in `dbo.schema_migrations`. Safe to re-run; already-applied
  filenames are skipped.
- `php bin/migrate.php status` — lists each migration as `applied` or `pending`.
- `php bin/migrate.php down --step=N` — reverts the N most recently applied migrations using their
  paired `_down.sql` files. This is a **dev-time recovery command only**, never run automatically
  from `up`, and not intended as a production rollback path (no production data exists yet).

## Production notes

- Set `display_errors = Off` in `php.ini` for production. `public/index.php` registers a global
  `set_exception_handler()` that converts every throwable into the SPEC.md 6.3 error envelope
  (code + request id only — no message or stack trace ever reaches the client); full detail is
  logged via Monolog to `{STORAGE_PATH}/logs/app.log`.
- `.env` must never be committed — `.gitignore` excludes it; only `.env.example` (empty secrets)
  is tracked.
