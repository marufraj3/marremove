# Production Deployment and Go-Live Runbook

**Status (2026-10-09): deployment preparation only — not deployed and not approved for live Facebook actions.** The dashboard now includes local email/password sign-in backed by Laravel web sessions, CSRF protection, rate limiting, and CLI-only administrator provisioning. It does not include public registration, password reset, external SSO, webhook subscription automation, or new moderation behavior.

## Release gates and known blockers

Do not call this release production-ready until all of these are resolved:

1. **There is no `backend/composer.lock`.** `composer.json` allows Laravel `^13.17`, but the resolved framework patch version and dependency graph are therefore not pinned. Generate and review the lockfile in a PHP 8.3+/Composer 2 build environment, run the backend tests against it, and commit it. Do not run an unreviewed `composer update` on the production server.
2. **This workspace has no PHP, Composer, or MySQL runtime.** Native PHP lint, Composer validation/install, PHPUnit, `php artisan migrate --pretend`, and migrations against fresh/existing MySQL databases were not run here. Staging verification is a go-live gate; the static schema review below is not a substitute.
3. **No production `.env`, allowlisted administrator account, Meta credentials/Page permissions, Gemini key, or public HTTPS hostname is available here.** No Facebook Page, webhook, Gemini request, or end-to-end production-style flow was exercised. Local email/password sign-in is implemented, but an operator must set `MODERATION_ADMIN_EMAILS` and create an allowlisted user from the CLI. External SSO is not included.

Never disable authentication/authorization to work around these gates. Keep `MODERATION_TEST_MODE=true` and all automatic execution disabled until the staged checks in this document pass.

## 1. Server and runtime requirements

| Component | Repository evidence | Deployment requirement |
|---|---|---|
| PHP | `backend/composer.json` requires `^8.3`; Laravel is declared as `^13.17`. | PHP 8.3 or newer but below 9.0 in both CLI and PHP-FPM/web runtime. Keep CLI and web versions/extensions aligned. No exact resolved Laravel patch can be named until `composer.lock` is added. |
| Laravel / Composer | Framework constraint `^13.17`; no lockfile is present. | Composer 2 is the project runbook baseline. Resolve dependencies and commit a reviewed lockfile in CI before production. |
| PHP extensions | Laravel 13.17's upstream Composer manifest declares `ctype`, `filter`, `hash`, `mbstring`, `openssl`, `session`, and `tokenizer`. The app selects MySQL and uses PDO. | Enable those extensions plus `pdo` and `pdo_mysql`. Verify with `php -m` and `composer check-platform-reqs` after the lockfile exists. Enable `intl` for the app's preferred Unicode normalization; the code has a fallback if it is absent. CI running PHPUnit also needs its XML/DOM extensions. |
| MySQL | `.env.example` selects `DB_CONNECTION=mysql`; migrations use transactions/foreign keys, `utf8mb4`, and JSON columns. The project does not pin a server version and none is installed in this workspace. | Use InnoDB and `utf8mb4`. MySQL 8.0/8.4 LTS is the production recommendation, not a version constraint encoded by this repository. Verify the exact managed MySQL version by running all migrations on it in staging. |
| Node.js | `frontend/package-lock.json` pins Vite 8.3.3 (Node `^20.19.0 || >=22.12.0`) and Vitest 5.0.3 (Node `^22.12.0 || ^24.0.0 || >=26.0.0`). | Node 22.12+ is the common supported line for build and test. The workspace was verified with Node 22.22.3. |
| npm | `frontend/package-lock.json` is lockfile version 3; `package.json` does not pin npm. | Use npm 10 (workspace verification: 10.9.8) and `npm ci`; do not use `npm install` to update production dependencies during deployment. |
| Database / queue / cache / sessions | The initial Laravel migrations create `users`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, and `failed_jobs`; app migrations add Page, comment, webhook, and moderation tables. | A durable MySQL database is required. The configured defaults use the database for session, cache, and queue. Keep queue retry-after greater than the longest job timeout. |
| Scheduler | `backend/routes/console.php` explicitly says there are no scheduled application tasks. | No cron entry is currently required. Revisit only if a scheduled task is added later. |
| Storage | No user-uploaded files are stored by the current application. Laravel still writes logs and compiled/cache data. | `backend/storage/` and `backend/bootstrap/cache/` must be writable by the PHP/deploy process as described below. `storage:link` is not currently required. |

The npm lockfile pins the frontend runtime packages to React/React DOM 19.3.0; build packages to Vite 8.3.3, `@vitejs/plugin-react` 6.1.2, and Tailwind 4.3.3; and test packages to Vitest 5.0.3, jsdom 30.1.2, and Testing Library. Keep `package-lock.json` as the source of exact transitive versions and deploy with `npm ci`.

`backend/composer.json` does not declare a MySQL server version, and `frontend/package.json` does not declare `engines`. Treat the table's MySQL and npm versions as explicit deployment recommendations, not hidden project constraints.

## 2. Environment variables and secrets

`backend/.env.example` is a **local-development** template (`APP_ENV=local`, `APP_DEBUG=true`). Do not copy it unchanged into production. Inject the production values from the host's secret manager or a private environment file outside Git. Do not place secrets in `frontend/.env`, `VITE_*`, a container image layer, a build argument, a ticket, a command transcript, or a frontend bundle.

### Required core configuration

```dotenv
APP_NAME=Marremove
APP_ENV=production
APP_KEY=<stable-private-Laravel-key>
APP_DEBUG=false
APP_URL=https://<public-host>
# Set to known reverse proxy IPs/CIDRs when behind a proxy; do not blindly use '*'.
TRUSTED_PROXIES=<trusted-proxy-ip-or-cidr>

DB_CONNECTION=mysql
DB_HOST=<private-mysql-host>
DB_PORT=3306
DB_DATABASE=<database-name>
DB_USERNAME=<least-privilege-database-user>
DB_PASSWORD=<secret>
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=960
QUEUE_FAILED_DRIVER=database-uuids

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
# Leave SESSION_DOMAIN unset for a single-host deployment unless sharing is required.

LOG_CHANNEL=daily
LOG_LEVEL=info
LOG_DAILY_DAYS=14

MODERATION_ADMIN_EMAILS=<comma-separated-verified-admin-emails>
FACEBOOK_GRAPH_VERSION=v26.0
FACEBOOK_APP_SECRET=<secret-if-webhooks-enabled>
FACEBOOK_WEBHOOK_VERIFY_TOKEN=<different-secret-if-webhooks-enabled>
# FACEBOOK_APP_ID is configured in Meta's dashboard; this code does not consume it.

GEMINI_ENABLED=false
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.8-flash
GEMINI_TIMEOUT=20
GEMINI_MAX_RETRIES=3

MODERATION_AUTO_REVIEW_THRESHOLD=0.70
MODERATION_AUTO_HIDE_THRESHOLD=0.90
MODERATION_AUTO_DELETE_THRESHOLD=0.98
MODERATION_AUTO_EXECUTE_ACTIONS=false
MODERATION_AUTO_HIDE_ENABLED=false
MODERATION_AUTO_DELETE_ENABLED=false
MODERATION_ALLOW_AI_HIDE=false
MODERATION_ALLOW_AI_DELETE=false
# Initial go-live value: keep actions simulated until every gate passes.
MODERATION_TEST_MODE=true

# Use database-backed workers for every per-service job connection by default.
FACEBOOK_SYNC_QUEUE_CONNECTION=database
FACEBOOK_WEBHOOK_QUEUE_CONNECTION=database
GEMINI_QUEUE_CONNECTION=database
MODERATION_ACTION_QUEUE_CONNECTION=database
```

### Provision the first administrator

Set `MODERATION_ADMIN_EMAILS` to the exact trusted admin email address(es). After updating the production `.env`, create the account from the backend directory:

```bash
php artisan config:clear
php artisan marremove:admin-create admin@example.com --name="Admin Name"
php artisan config:cache
```

The command prompts twice for a password (minimum 12 characters) without echoing or taking it as a command-line argument. It refuses addresses outside the configured allowlist; rerunning for an existing account requires confirmation and resets its password. There is no public registration or password-reset flow. Never place the password in chat, shell arguments, logs, or source control. External SSO is not implemented.

Also configure a stable `APP_KEY`; routine deployments must never regenerate it. Page Access Tokens are encrypted in the database with this key, so key loss/rotation can make stored tokens unreadable. Back up the key separately from the database, with stricter access controls. Laravel's `APP_PREVIOUS_KEYS` can support a planned key rotation, but test the migration/re-encryption procedure before rotating.

### Facebook and Gemini variables

| Variable | When required / safe value |
|---|---|
| `TRUSTED_PROXIES` | Optional comma-separated trusted proxy IPs/CIDRs. Set only to the actual proxy addresses in front of Laravel. `*` is supported only when the origin cannot be reached except through the trusted proxy network. |
| `FACEBOOK_GRAPH_VERSION` | Required for Graph requests; use `v26.0` as documented in this checkout and re-check Meta's current version before a later upgrade. |
| `FACEBOOK_APP_SECRET` | Required to verify webhook POST signatures. Backend-only secret. |
| `FACEBOOK_WEBHOOK_VERIFY_TOKEN` | Required to complete Meta's callback GET verification. Generate a long random value different from the App Secret. It is sent in the verification query string; redact query strings in proxy/access/tracing logs for this route. |
| `FACEBOOK_APP_ID` | **Not consumed by this application.** There is no Facebook Login/OAuth SDK flow. Configure the App ID in Meta's Developer Dashboard when setting up the app/webhook; do not add an unused runtime variable. |
| Page Access Token | Not an environment variable. An authenticated admin submits it to the existing HTTPS Page-connect endpoint; Laravel encrypts it at rest. Do not log request bodies or expose it in responses, jobs, or browser storage. |
| `GEMINI_ENABLED` | Keep `false` until the backend key/model are verified. To use AI, set `true` and enable AI for the intended Page(s). |
| `GEMINI_API_KEY` | Required only when Gemini is enabled. Backend secret only; never a `VITE_` variable. |
| `GEMINI_MODEL` | Default/documented model: `gemini-3.8-flash`. The model was checked against Google's current model reference on 2026-10-08; re-check that reference at deploy time. |
| `GEMINI_TIMEOUT` | Seconds per provider request, clamped by config to 2–120; default 20. |
| `GEMINI_MAX_RETRIES` | Internal provider retries, clamped to 0–5; default 3. Provider failures/invalid or incomplete responses resolve safely to `review`. |
| `GEMINI_QUEUE_CONNECTION` | Defaults to `database`; ensure a worker consumes that connection. |
| `MODERATION_TEST_MODE` | Must be `true` for the first production smoke test. In local/testing environments Meta actions are simulated regardless of this flag. |

The app currently uses Google's Interactions API at `https://generativelanguage.googleapis.com/v1beta/interactions`, with the key in the server-side `x-goog-api-key` header and stateless structured output. References: [Gemini Interactions changes](https://ai.google.dev/gemini-api/docs/interactions-breaking-changes-may-2026), [quickstart](https://ai.google.dev/gemini-api/docs/quickstart), and [Gemini 3.8 Flash](https://ai.google.dev/gemini-api/docs/models/gemini-3.8-flash). No provider key or live request was available during this workspace audit.

### Values in `.env.example` that need review

The template already contains the app, DB, session, cache, queue, trusted-proxy, Facebook Graph/webhook, admin, moderation, Gemini, and timeout/retry settings. `APP_DEBUG=true`, `LOG_LEVEL=debug`, `SESSION_ENCRYPT=false`, and `MODERATION_TEST_MODE=false` are local-template values; production must override them as above. `FACEBOOK_APP_ID` appears only as a commented dashboard-metadata note because application code does not read it. Review `.env.example` and this table together after changing config.

## 3. Laravel configuration and release preparation

- `config/app.php` reads `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY`, and key-rotation values. The framework default for debug is false, but production must explicitly set `APP_DEBUG=false`.
- `config/database.php` configures MySQL with strict mode, `utf8mb4`, and optional CA configuration via `MYSQL_ATTR_SSL_CA`; install `pdo_mysql`. If the managed database requires TLS, set `MYSQL_ATTR_SSL_CA` to the trusted CA path supported by the PHP runtime. Use the platform's encrypted DB connection/secrets and TLS settings where supported.
- `config/cache.php` defaults to the database store and disables cached PHP object deserialization. The initial migration includes `cache` and `cache_locks`.
- `config/queue.php` defaults to database jobs and `database-uuids` failed-job storage. Database, Redis, and Beanstalkd retry-after defaults are 960 seconds; SQS visibility is configured in AWS and must be set separately.
- `config/session.php` defaults to database sessions. Production must set secure, HTTP-only, SameSite cookies and preferably encrypted session payloads; the initial migration includes `sessions`.
- `config/logging.php` defaults to a stack/single file with debug-level logging in the local template. Production should use a private daily/centralized channel at `info` or stricter; never enable request-body/header capture for credentials.
- `config/trustedproxy.php` supplies Laravel's built-in `TrustProxies` middleware with the `TRUSTED_PROXIES` values after configuration is loaded. Without it, Laravel will not trust forwarded host/protocol/client-IP headers from a generic reverse proxy. Configure known proxy IPs/CIDRs; do not trust arbitrary internet clients. A wildcard is safe only when the origin is network-private and the proxy is the only caller.
- `config/services.php`, `config/ai_moderation.php`, and `config/moderation.php` read integration/secrets into Laravel config. A source search found no direct `env()` calls in `app/`, routes, bootstrap, or frontend runtime code; application logic reads `config()`.
- External Meta and Gemini requests use HTTPS. No TLS-verification-disable setting was found. Keep certificate verification enabled.

Because the lockfile is missing, generate it once in a controlled PHP 8.3+/Composer 2 development or CI environment—not on production—then review/test and commit it:

```bash
cd backend
composer update --prefer-dist --no-interaction
composer validate --strict
composer check-platform-reqs
php artisan test
# Review and commit backend/composer.lock only after the tests pass.
```

Subsequent builds/releases must use the committed lockfile:

```bash
cd backend
composer validate --strict
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
```

For a release, deploy the exact reviewed lockfile and secrets first, then run the database migration instructions, followed by:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan route:list --except-vendor
php artisan queue:restart
```

`optimize:clear` clears cached app artifacts; run it as a controlled release step, then rebuild caches only after checking the `.env`/secret-manager values. The app routes use controller actions (no route-action closures were found), so `route:cache` is appropriate. Do not cache a developer `.env` or build the release with `APP_DEBUG=true`.

## 4. Database migration and data-safety procedure

Static inspection found the initial framework migration creates `users`, password-reset tokens, and `sessions`; the next initial migration creates `cache`, `cache_locks`; the jobs migration creates `jobs`, `job_batches`, and `failed_jobs`. The app migrations create Pages/posts/comments, sync fields, webhook tables, rules, Page settings, AI/final/action audit tables. Foreign keys point to matching local `id` columns and declare cascade/null behavior. Important unique keys cover Meta Page/post/comment IDs; composite timestamp indexes are used by date filters. No duplicate-column conflict was apparent from the ordered migration definitions.

**This was not executed against MySQL**: PHP, Composer, and MySQL are absent, and the schema review cannot prove the SQL works on your chosen server/version.

Provision an empty database on the chosen MySQL 8.0/8.4 service (or create it through the managed provider), using InnoDB and `utf8mb4`; use a least-privilege runtime account and a controlled migration/deployment account. Example database creation, run only through your approved DB administration channel:

```sql
CREATE DATABASE marremove CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

On a disposable staging database matching production, and on an existing staging copy after backup:

```bash
cd backend
php artisan migrate:status
php artisan migrate --pretend
# Review the SQL/output and confirm DB target before applying.
php artisan migrate --force
php artisan migrate:status
```

Before production, take and verify a recoverable backup, confirm the target database/credentials, review pending migrations, then run `php artisan migrate --force` during the release window. Do not run `migrate:fresh`, `db:wipe`, or `migrate:rollback` as a routine deployment. If an existing database has tables created outside Laravel's `migrations` table, stop and reconcile/baseline that schema rather than rerunning table-creation migrations. For rollback, restore from backup or make a forward corrective migration; some `down()` methods drop data/tables/columns.

## 5. Queue workers, retries, and failed jobs

All jobs use the `default` queue unless a per-service connection is explicitly changed. With the documented database defaults, run a persistent worker from `backend/`:

```bash
php artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=900 --max-time=3600
```

The job classes set their own attempt/timeout limits; the worker options are the fallback and maximum worker safety boundary. Keep `DB_QUEUE_RETRY_AFTER=960` (or a higher value) so the message is not reserved by a second worker before the 900-second sync job can finish. For Redis/Beanstalkd use matching service queue-connection settings and keep their `retry_after` above the longest job timeout; for SQS set visibility timeout above 900 seconds with margin. Do not use the `sync` driver for production processing. If any of `FACEBOOK_SYNC_QUEUE_CONNECTION`, `FACEBOOK_WEBHOOK_QUEUE_CONNECTION`, `GEMINI_QUEUE_CONNECTION`, or `MODERATION_ACTION_QUEUE_CONNECTION` is changed, run workers for that connection too.

| Job | Timeout / attempts | Retry behavior |
|---|---|---|
| Page sync | 900 s; default 3 attempts, config bounded 1–5 | Backoff 15, 60, 180, 300 s. |
| Webhook processing | Default 60 s (config bounded 30–300); default 5 attempts, bounded 1–10 | Backoff 10, 30, 60, 120 s; default processing lease 75 s, always longer than the job timeout. |
| Gemini moderation | Default request timeout 20 s (bounded 2–120), internal retries default 3 (bounded 0–5); job attempts 2 | Job timeout includes all provider-call timeouts and capped backoff (default about 115 s; maximum configured budget 765 s), then job backoff 10, 30 s. Failures must remain `review`. |
| Facebook action | 45 s; default 3 attempts, config bounded 1–5 | Backoff 10, 60, 180 s. Test mode prevents real Graph requests. |

The initial jobs migration includes `failed_jobs` and the default `database-uuids` driver. Operate failures with:

```bash
php artisan queue:failed
php artisan queue:retry <failed-job-uuid>
php artisan queue:forget <failed-job-uuid>
```

Only retry a failed job after reviewing its error and effect. Do not mass-retry failed delete/hide jobs without checking the Action Log and current Facebook state. Restrict access to queue payloads and failed-job exception details. Restart supervised workers after deploy/config change with `php artisan queue:restart`.

### Supervisor example (VPS only)

Use Supervisor only if the hosting provider gives you a persistent process manager and the required permissions. Replace paths, Unix user/group, PHP binary, and log location. Keep `stopwaitsecs` longer than the 900-second maximum job timeout.

```ini
[program:marremove-queue]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /srv/marremove/backend/artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=900 --max-time=3600
directory=/srv/marremove/backend
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=marremove
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/marremove/queue-worker.log
stopwaitsecs=1200
```

Do not assume root/SSH access. On a managed Laravel/PaaS host, configure a **persistent background worker process** using the same command and environment values; on a container platform, run a separately supervised worker service from the same immutable release. If the host cannot keep a worker alive, it is not suitable for this queued application. Do not substitute a web request, cron-triggered HTTP endpoint, or `sync` queue.

## 6. Scheduler

No application tasks are scheduled: `backend/routes/console.php` contains only a note that no scheduled tasks are registered. **Do not add a cron job for `schedule:run` at this time.** If a scheduled task is introduced in a future release, add it to Laravel's scheduler, then configure the host's documented once-per-minute scheduler command.

## 7. Storage and permissions

The app currently stores operational data in MySQL; no public user-upload feature is present. Laravel still needs write access for logs, compiled views, any configured file cache/session fallback, and generated `bootstrap/cache` files. Keep release source read-only to PHP where possible. Give the deploy user and PHP-FPM/worker group ownership or a narrow ACL only on these writable paths:

```bash
cd /srv/marremove/backend
install -d -m 2775 storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
# Example only—replace owners/groups with the hosting account and PHP group.
chown -R <deploy-user>:<php-group> storage bootstrap/cache
chmod -R u+rwX,g+rwX storage bootstrap/cache
```

Never use `chmod 777`. Ensure directories are not writable by unrelated users. `php artisan storage:link` is unnecessary unless a future feature adds public-disk files. Serve only `backend/public` for the Laravel runtime; never publish `.env`, source code, logs, `storage/`, or `bootstrap/cache/` as web roots.

## 8. React build and same-origin hosting

The frontend is a static Vite SPA built to `frontend/dist`. The client defaults to relative `/api`; `frontend/.env.example` sets `VITE_API_BASE_URL=/api`. `LARAVEL_API_URL` is used only by the Vite **development server proxy** and must not appear in the production bundle. Production API calls must go over the same HTTPS origin so the Laravel session cookie and XSRF header work. No CORS configuration is needed in this same-origin design; serving the dashboard and API on separate origins is not supported by the current cookie/XSRF setup.

Build from the pinned npm lockfile:

```bash
cd frontend
npm ci
npm test
npm run build
npm audit --omit=dev
```

Publish `frontend/dist` as static files on the same origin as Laravel. The React route map uses browser paths, so configure a history fallback to `index.html`; route `/api/*` and `/up` to Laravel **before** the SPA fallback. An illustrative Nginx edge (replace the private Laravel upstream and TLS configuration) is:

```nginx
# In the enclosing http{} block, omit query args from logs so Meta's
# hub.verify_token callback query can never be recorded in the access log.
log_format marremove_safe '$remote_addr [$time_local] $request_method $uri $server_protocol $status $body_bytes_sent';

# Point this to the private Laravel/PHP web origin managed by the host.
upstream laravel_upstream {
    server 127.0.0.1:8080;
}

server {
    listen 443 ssl;
    server_name app.example.com;
    root /srv/marremove/frontend/dist;
    index index.html;
    access_log /var/log/nginx/marremove_access.log marremove_safe;

    location ^~ /api/ {
        proxy_pass http://laravel_upstream;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location = /up {
        proxy_pass http://laravel_upstream/up;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

This is a routing illustration, not a complete TLS/PHP-FPM configuration. The private Laravel upstream must serve the `backend/public` front controller through the host's supported PHP runtime. Configure trusted proxies narrowly and preserve HTTPS forwarding. If the host cannot proxy both paths on one origin, do not deploy the UI cross-origin without a separate reviewed authentication/CORS/CSRF design.

Use hashed Vite assets with long cache lifetimes and keep `index.html` short/no-cache. The build verified in this workspace has a root-relative asset base. Scan published build files before release; do not put secrets in any variable prefixed `VITE_`.

## 9. HTTPS and request logging

- Serve the dashboard, Laravel API, `/up`, and webhook callback only over HTTPS. Set `APP_URL=https://<public-host>` and register `https://<public-host>/api/facebook/webhook` with Meta.
- At a TLS-terminating proxy, pass the correct forwarded protocol/host and trust only known proxy addresses. Do not trust client-supplied forwarded headers from arbitrary internet clients.
- The Meta verification protocol sends `hub.verify_token` in a GET query string. Configure web-server, CDN, WAF, APM, and access logs to redact or omit query strings on `/api/facebook/webhook`; a default `$request`/full-URL access log could record the verification token. Disable request-body logging on the Page-connect endpoint (the submitted token is in the POST body) and header capture for `Authorization` / `x-goog-api-key`.
- Source code uses HTTPS for Graph and Gemini and did not contain a TLS-verification bypass. Do not disable outbound certificate verification.
- `/api/health` and `/up` are public liveness routes and must not be treated as authenticated admin data endpoints.

## 10. Meta Page and webhook go-live

The backend callback is `GET|POST https://<APP_URL-host>/api/facebook/webhook`. It handles Meta's verification challenge, checks POST `X-Hub-Signature-256` against the raw body using `FACEBOOK_APP_SECRET`, stores supported Page feed comment events, and queues processing. It does not automatically subscribe Pages from the dashboard.

1. In Meta's current Developer Dashboard, use the correct app and add the **Page** webhook object. Set the callback URL to `https://<public-host>/api/facebook/webhook`; enter the distinct `FACEBOOK_WEBHOOK_VERIFY_TOKEN`; verify the callback; enable the `feed` field and delivery values needed for comment changes. Keep the URL public HTTPS and confirm CDN/WAF allows Meta POSTs without stripping `X-Hub-Signature-256` or changing the raw body.
2. Subscribe each connected Page to the app's `feed` field using the documented Page `/{page-id}/subscribed_apps` edge and a suitable Page Access Token. The current app does not perform this subscription call for you. Keep the token server-side. Follow Meta's current [Page Feed](https://developers.facebook.com/docs/graph-api/reference/v26.0/page/feed), [Comment](https://developers.facebook.com/docs/graph-api/reference/v26.0/comment), and Webhooks/Page subscription documentation; review the required permissions/tasks in the current Meta app dashboard before requesting access.
3. Grant only reviewed, necessary Page permissions and ensure the token-granting user has the required Page task. Existing implementation notes call out `pages_manage_metadata` / `pages_show_list` for webhook subscription and Page visibility, read permissions for sync/comment refresh, and `pages_manage_engagement` for comment actions. Meta App Review/Advanced Access may be required. A valid token alone does not prove these permissions.
4. Verify GET challenge succeeds, then use Meta's dashboard/test delivery to confirm a signed `feed` comment event reaches the public callback, returns `200 EVENT_RECEIVED`, is queued once, and appears in the authenticated webhook status page. Test duplicate deliveries and an invalid signature in staging. The app's webhook feature tests fake the HMAC and Graph responses; they do not prove Meta account permissions or internet reachability.
5. Monitor `facebook_webhook_events` statuses and the safe webhook status page. Do not log raw event bodies, verify tokens, app secrets, or Page tokens.

The Facebook App ID is configured in the Meta Developer Dashboard and is not consumed by this backend. No Page token, app secret, verify token, or login credential was available for a live connect/sync/webhook test in this workspace.

## 11. Gemini production verification

The model documented in the code is `gemini-3.8-flash`; the official current model/Interactions references were checked during the prior API compatibility audit. Re-check them at release time and use a model enabled for the deployed project/key. Keep the API key backend-only.

In staging, enable `GEMINI_ENABLED=true`, set `GEMINI_API_KEY`, keep Page automatic actions disabled/test mode enabled, and test a benign sample using the stateless admin endpoint/UI (`POST /api/moderation/ai/test`). Then exercise an eligible stored comment through the queued moderation path. Verify the raw provider result is schema-validated, completion status is `completed`, the audit fields are safe, and no secret appears in responses/logs. Exercise the automated fake-HTTP cases for successful response, timeout, invalid/incomplete output, and provider error:

```bash
cd backend
php artisan test --filter=GeminiModerationServiceTest
php artisan test --filter=CommentModerationServiceTest
```

The error/timeout/invalid-response cases must leave the final action at `review`, never `delete`. Do not intentionally disrupt the production key/service to test failure behavior; use the automated fakes or staging. This workspace had no PHP runtime or Gemini key, so those backend tests and the live provider check remain pending.

## 12. Test mode and cautious action activation

### Initial production smoke test

1. Deploy with `MODERATION_TEST_MODE=true`, `MODERATION_AUTO_EXECUTE_ACTIONS=false`, `MODERATION_AUTO_HIDE_ENABLED=false`, `MODERATION_AUTO_DELETE_ENABLED=false`, and `MODERATION_ALLOW_AI_DELETE=false`. Keep the per-Page `auto_execute_actions`, `auto_hide_enabled`, and `auto_delete_enabled` switches off too. Test mode also blocks Meta requests in local/testing environments regardless of environment flags.
2. In a staging environment, use a controlled Page/account to verify authenticated dashboard access, Page connection, safe Page response (no token), queued sync, comments, webhook delivery, rule decisions, AI decisions, action queue/audit records, and dashboard statistics. Automated tests must use fake Meta/Gemini HTTP clients.
3. To test the action job in production test mode, use only a designated test Page/comment and deliberately enable its per-Page execution/action switch while `MODERATION_TEST_MODE=true`. Confirm the action log is marked `is_test=true` and the action worker makes no Meta request. Disable those switches after the smoke test. Do not infer safety from a UI label alone—inspect server configuration and the Action Log.
4. Confirm provider outages, invalid Gemini JSON/status, and timeouts yield `review`; verify duplicate webhooks/actions do not repeat work; verify the failed-jobs table and retry process.

### Real-action activation (explicit owner approval required)

Start conservatively:

- First keep automatic deletion off globally and per Page: `MODERATION_AUTO_DELETE_ENABLED=false`, `MODERATION_ALLOW_AI_DELETE=false`, `auto_delete_enabled=false`, `allow_ai_delete=false`. Do not configure broad destructive manual rules.
- Keep `MODERATION_AUTO_EXECUTE_ACTIONS=false` and per-Page `auto_execute_actions=false` while observing AI/manual recommendations. Enable AI and manual rules only after representative comments are reviewed; leave uncertain or complaint/negative-feedback cases at `review`/`keep`.
- After sign-off, disable test mode only during a monitored change window. This makes a separately confirmed administrator-requested action capable of reaching Meta even if autonomous switches remain off. Keep the admin allowlist minimal and protect the action-log UI.
- If approved, enable automatic **hide** for one Page first, with explicit Page-level execution and hide switches, conservative thresholds, and a short observation period. Keep delete off. Monitor every decision, skip/error category, worker backlog, and Meta response.
- Consider automatic deletion only as a separate later approval after evidence and policy review; it is irreversible. This runbook does not recommend enabling it at initial launch.

A final admin override/action is intentionally distinct from automatic moderation and can perform a real Facebook action when test mode is off. Treat admin membership and action confirmation as production controls.

## 13. Health checks, monitoring, and incident response

Existing safe endpoints:

```bash
curl --fail --silent --show-error https://<public-host>/up
curl --fail --silent --show-error https://<public-host>/api/health
```

`/up` is Laravel's liveness route. `/api/health` returns only `status` and the application name. **It currently does not probe the database, cache, queue backend, or worker liveness.** Do not treat a `200` from it as proof that jobs are running or integrations are healthy; monitor those dependencies separately. No health route exposes credentials or environment values.

Practical checks using the existing app/host tools (no new monitoring vendor required):

- **Laravel/PHP:** monitor 5xx rate, PHP-FPM health, `storage/logs/laravel*.log` or the configured private log channel, disk space, and PHP memory/timeouts. Keep `APP_DEBUG=false`; never collect request bodies, auth cookies, XSRF values, API keys, or authorization headers.
- **Database:** monitor reachability, connection exhaustion, latency, free disk, backups, and migration status. Alert on DB errors in app logs and queue tables.
- **Queue:** supervise worker process count/restarts and backlog age/count in `jobs`; inspect `failed_jobs` with `php artisan queue:failed`. Alert if no worker is alive or pending work ages past the expected processing window.
- **Facebook Graph/actions:** inspect sync status, safe error categories, webhook event failures, action-log attempts/results/latency, 190/permission/rate-limit/transient errors, and Page token expiry/revocation. Reconnect or fix Meta permissions rather than retrying permanent errors blindly.
- **Gemini:** track queue age, request duration/timeout/error categories, invalid/incomplete response counts, and `review` fallback rate. Provider errors should increase safe review decisions, not destructive actions.
- **Webhook:** monitor HTTPS callback availability, verification state, received/processed/failed counts, queue dispatch 503s, and signature rejections. Check CDN/WAF body/header preservation and query-log redaction.
- **Product health:** review the review queue and action logs daily during ramp-up; reconcile a sample against real Page comments. Check `APP_KEY`/secret-manager availability after a restore.

Keep the existing authenticated `/facebook/webhook` status and moderation/action dashboard for operational review. Do not expose internal exceptions or secret values to unauthenticated health routes.

## 14. Backup and recovery

- **MySQL:** take an encrypted daily full backup at minimum; use managed point-in-time recovery/binlogs with a target RPO (15 minutes or better where available) during active moderation. Retain encrypted daily backups for at least 30 days or the organization's policy, and perform a test restore monthly and before risky migrations. Include moderation/audit tables and queue/failed-job tables; do not take a backup while a migration is partially applied.
- **Secrets:** back up the stable `APP_KEY`, Meta App Secret, webhook verify token, DB credentials, and Gemini key in the secret manager with restricted break-glass access. Never place them in backup job logs or the same unrestricted bucket as application logs. The DB backup alone cannot decrypt Page tokens without the matching `APP_KEY`.
- **Application/frontend:** keep immutable release artifacts (Laravel source/vendor lockfile and the corresponding `frontend/dist`) in the build/deployment system. Source is recoverable from Git; deployment artifacts and environment-specific secrets are not. There are no current user-uploaded files to back up. Keep `storage/` logs according to the log-retention policy, not as a substitute for DB backups.
- Test restoring DB + matching key + previous release in an isolated environment. Verify that encrypted Page tokens can be read, sessions are handled safely, and queue processing is disabled or isolated during the restore test.

A secure MySQL dump example for a self-managed server (the defaults file must be outside the web root and mode `0600`; prefer managed encrypted snapshots when available):

```bash
mysqldump --defaults-extra-file=/run/secrets/marremove-db.cnf \
  --single-transaction --routines --triggers --hex-blob <database-name> \
  > /secure-backups/marremove-$(date -u +%F-%H%M).sql
```

Encrypt the dump at rest, restrict backup-reader permissions, and suppress shell tracing so credentials are never printed.

## 15. Troubleshooting

| Symptom | First checks |
|---|---|
| Dashboard redirects to sign-in / API returns 401 | Sign in with the allowlisted local admin account. If no account exists, set `MODERATION_ADMIN_EMAILS`, run `config:clear`, then `php artisan marremove:admin-create <email>` from `backend/`; verify `SESSION_SECURE_COOKIE=true` on HTTPS and that the `sessions` table/session driver work. |
| Sign-in returns 422 | Confirm the email exactly matches `MODERATION_ADMIN_EMAILS`, the account was created/reset through the CLI, and `config:clear` ran after `.env` changes. The response intentionally does not disclose whether the email is unregistered, non-admin, or has a wrong password. |
| Sign-in returns 419 / CSRF mismatch | Confirm same-origin HTTPS `/api/auth/csrf` and `/api/auth/login`, `XSRF-TOKEN` and session cookies are accepted for `shop.aveen.xyz`, PHP can write sessions, and the app is behind a correctly configured trusted proxy. Do not disable CSRF middleware. |
| Admin endpoint returns 403 | Exact lowercased user email is in `MODERATION_ADMIN_EMAILS`; config cache was rebuilt; do not weaken middleware. |
| CSRF/session failure on POST | Frontend and API are same HTTPS origin; XSRF cookie/header and trusted proxy scheme are preserved; do not move the frontend to another origin without a reviewed design. |
| `/api/health` works but sync/actions never complete | Health is liveness-only. Check supervisor/PaaS worker, `QUEUE_CONNECTION` and per-service connections, database `jobs`/`failed_jobs`, retry-after, and worker logs. |
| `migrate` reports duplicate table/column | Stop. Compare `php artisan migrate:status` and actual schema; check if tables were manually created or migrations partially applied. Back up and make a reviewed forward migration; never use `migrate:fresh` on existing data. |
| Facebook webhook verification fails | HTTPS callback path, Meta Page object/`feed`, exact separate verify token, app secret config, forwarded HTTPS, WAF allowlist/header preservation, and token-query log redaction. |
| Webhook returns 401/400/503 | 401: signature/raw-body mismatch or wrong App Secret. 400: payload format/size. 503: DB/queue dispatch unavailable; Meta should retry. Inspect only sanitized categories, not raw payload/secrets. |
| Page sync Graph error / no comments | Page token still valid, connected Page ID, Page task, app review/permission access, supported Graph version/fields, queue worker, and Meta rate limit. Do not paste tokens into logs/support tickets. |
| Gemini returns unavailable/review | Confirm `GEMINI_ENABLED`, key/model/quota and worker config; inspect safe status/error category. Review is the expected safe fallback. Use test fakes for failure paths. |
| Action log shows skipped/test/failure | Test mode, both per-Page execution/action switches, protected category, final decision, active Page/token, Page task/permissions, worker, and current Meta state. Test mode must stay on until sign-off. |
| Wrong generated webhook URL | Set `APP_URL` to public HTTPS origin and fix trusted-proxy forwarded host/proto; rebuild config cache and restart workers. |

## 16. Rollback procedure

1. Pause automatic moderation (`MODERATION_AUTO_EXECUTE_ACTIONS=false`, Page execution switches off) and leave `MODERATION_TEST_MODE=true` where possible. Do not issue new manual actions during rollback. A Meta delete already completed cannot be reversed; a hidden comment may be unhidden only through the approved action flow and permissions.
2. Preserve current logs, queue/failed-job state, action log, and a database backup. Stop/restart workers through the process manager after deploying a prior code release; do not delete queue tables or flush failed jobs.
3. Atomically repoint the web/PHP runtime to the previous immutable Laravel release and republish the matching previous `frontend/dist`. Keep the current secrets/environment unless the change itself was the cause; never roll back to a leaked key. Run `php artisan optimize:clear`, rebuild caches from the chosen environment, then `php artisan queue:restart` and verify health/routes.
4. Database changes are not automatically reversible. Do not run `migrate:rollback` blindly—the `down()` methods can drop logs, Page data, foreign keys, and columns. Prefer a forward-compatible code rollback that tolerates additive columns. Restore a pre-migration backup only under an approved recovery plan with writes/queue stopped, accounting for data loss after the backup; otherwise create a reviewed forward-fix migration.
5. Roll back environment changes as a versioned secret-manager change, validate `APP_KEY`/Page-token decryption, and keep test mode enabled until the prior release is confirmed. Restart workers after every config change.

## 17. Staging go-live checklist

Run the following only after the lockfile/build/runtime prerequisites are resolved, in staging with a real public HTTPS callback or the provider's appropriate safe test setup. Do not use production credentials in local feature tests.

- [ ] Local sign-in accepts only a CLI-provisioned, allowlisted admin; session rotates at login, CSRF is enforced, logout invalidates the session, and a valid non-admin is denied.
- [ ] `/up` and `/api/health` return safe liveness responses; database and worker health are independently verified.
- [ ] Connect an approved test Page through the existing server-side endpoint; Page name/id display correctly and token never appears in UI, response, logs, or bundle.
- [ ] Queue Page feed/post/comment sync; verify counts, pagination/caps, ownership scoping, and retry/failure reporting.
- [ ] Meta verifies the exact HTTPS callback and `feed` field is subscribed for the Page; a new signed comment event is queued/processed once, and a duplicate is idempotent.
- [ ] Manual rule result, Gemini result, combined final decision, review queue, action audit, and dashboard statistics agree for representative clean, complaint, negative-feedback, spam, and uncertain samples.
- [ ] With Gemini live in staging, verify valid completed structured output; use automated fakes for timeout, provider error, invalid JSON/schema/status. Every failure stays at `review`.
- [ ] With test mode on and the test Page's per-Page action switch deliberately enabled, an action job completes with `is_test=true`; no real Meta mutation is sent.
- [ ] Verify invalid webhook signatures are rejected, queue failure is visible, action retry is bounded, and app/page tokens/secrets remain absent from logs and API responses.
- [ ] Backups restore in isolation; logs and worker supervision are observable; rollback procedure is rehearsed.

This workspace could not perform this end-to-end checklist: it has no PHP/Composer/MySQL runtime, production `.env` or provisioned administrator session, Meta credentials/Page permissions/public callback, or Gemini key. The login unit/feature tests are present but have not run here; do not mark this checklist complete based on frontend tests alone.

## 18. Audit and test results from this workspace

- `npm ci`: passed using `frontend/package-lock.json`; Node 22.22.3 / npm 10.9.8.
- `npm test`: **26 tests passed across 7 files**, including the local sign-in UI path.
- `npm run build`: passed; Vite produced `frontend/dist` (43 modules transformed).
- `npm audit --omit=dev`: **0 vulnerabilities**.
- Production build scan: no credential-pattern matches or `LARAVEL_API_URL` / `127.0.0.1:8000` dev proxy setting; `VITE_API_BASE_URL` is relative `/api`.
- `.gitignore` ignores root, backend, and frontend `.env` files while allowing `.env.example`; no actual `.env` file was present in this workspace.
- A JavaScript PHP parser parsed all 101 backend PHP files with no syntax errors, but this does not replace native `php -l` or runtime tests. PHP, Composer, MySQL, and PHPUnit are unavailable here; backend feature tests, migrations, dependency platform checks, live Meta/Gemini calls, and end-to-end deployment remain **not verified**. The Composer lockfile is absent.

## Official references

- Meta Page Feed v26.0 and permissions/caps: [Page Feed](https://developers.facebook.com/docs/graph-api/reference/v26.0/page/feed), [Comment](https://developers.facebook.com/docs/graph-api/reference/v26.0/comment), [Graph API error handling](https://developers.facebook.com/docs/graph-api/guides/error-handling). Use the current Meta Developer Dashboard/Webhooks and Page subscription reference at deployment; permissions and access requirements can change.
- Google Gemini current Interactions/model docs: [Interactions changes](https://ai.google.dev/gemini-api/docs/interactions-breaking-changes-may-2026), [Quickstart](https://ai.google.dev/gemini-api/docs/quickstart), [Gemini 3.8 Flash](https://ai.google.dev/gemini-api/docs/models/gemini-3.8-flash).
