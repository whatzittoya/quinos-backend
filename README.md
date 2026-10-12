# Quinos Reports

Multi-client web app that syncs POS data from quinosbackend.com day by day into its own
database and serves purchase and sales reports. Laravel 10 API (Sanctum cookie auth) + a
Vue 3 single-page app. Pinned to **PHP 8.1** (`composer.json` platform) because the target
cPanel host runs 8.1. Designed for shared hosting: MySQL, database queue, one cron line.

Laravel 10 and PHP 8.1 no longer receive security fixes. Keep `APP_DEBUG=false` in production,
and move the host to PHP 8.3 + Laravel 12/13 when possible (the app code is plain Laravel, so
the upgrade is mostly dependency bumps).

- Report rules and verified numbers: `docs/reports-spec.md`
- Build plan: `/Users/sein/.claude/plans/so-i-need-web-prancy-lark.md`

## Local setup

```sh
composer install && npm install
cp .env.example .env && php artisan key:generate
# set DB_CONNECTION=sqlite and DB_DATABASE=<absolute path>/database/database.sqlite in .env
touch database/database.sqlite && php artisan migrate
php artisan app:create-admin you@example.com      # prompts for the password
npm run build        # or `npm run dev` for hot reload
php artisan serve
```

## Tests

```sh
php artisan test
```

Tests use in-memory SQLite (`phpunit.xml`) and **wipe their database** on every run. `tests/CreatesApplication.php`
refuses to run against anything that is not in-memory SQLite or named `*_test`, so a misconfigured
`.env` cannot erase the development database.

## Roles

- **admin**: manages clients (including each client's encrypted Quinos login) and users, and
  picks which client to look at from the top bar.
- **client**: sees only their own client's data. The server pins them to their client; the
  `X-Client-Id` header only works for admins.

Tenant models use the `BelongsToClient` trait. Queue and console code is not bound to a
client, so jobs must call `CurrentClient::runAs($client, ...)` or filter by `client_id`.

## Important: APP_URL

Sanctum only starts a session for requests whose Referer/Origin host is in
`SANCTUM_STATEFUL_DOMAINS` or equals the `APP_URL` host. Locally keep
`APP_URL=http://localhost:8000` (with the port). In production use the real https URL.

## Quinos connection

Each client stores its own quinosbackend.com login (Admin → Clients). The app logs in like a
browser, keeps the session cookies encrypted in `quinos_sessions`, and logs in again by itself
when the session expires.

```sh
php artisan quinos:test-login 1          # by client id or exact name
php artisan quinos:test-login --all      # every active client that has a Quinos login
```

It prints the company the login belongs to and the sales types, so a wrong password or a
captcha/2FA wall shows up immediately. Code: `app/Services/Quinos/QuinosClient.php`.
Settings: `config/quinos.php` (`QUINOS_BASE_URL`, `QUINOS_DELAY_MS`).

Tests never call the real site: `tests/Support/FakeQuinos.php` is a pretend server with made-up data.
