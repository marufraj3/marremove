# Marremove

Marremove is a monorepo with a Laravel API in `backend/` and a React + Vite + Tailwind CSS frontend in `frontend/`. For production requirements and the go-live/rollback procedure, see [docs/PRODUCTION_DEPLOYMENT.md](docs/PRODUCTION_DEPLOYMENT.md); that runbook records blockers and does not declare this checkout deployed.

## Project structure

```text
.
├── backend/                         # Laravel API
│   ├── app/
│   │   ├── Exceptions/
│   │   ├── Http/Controllers/Api/
│   │   ├── Http/Requests/
│   │   ├── Http/Resources/
│   │   ├── Jobs/
│   │   ├── Models/
│   │   └── Services/
│   ├── bootstrap/
│   ├── config/
│   ├── database/migrations/
│   ├── routes/
│   └── tests/
├── frontend/
│   ├── src/api/
│   ├── src/components/
│   └── src/pages/
└── README.md
```

## Requirements

- PHP 8.3+, Composer 2, and PHP's PDO MySQL extension
- A running MySQL server and an empty database created for this app
- Node.js 22.12+ and npm

## Run locally

Create a MySQL database, then configure and start Laravel:

```bash
mysql -u root -p -e "CREATE DATABASE marremove CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cd backend
cp .env.example .env
composer install
```

Set `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `backend/.env`. `FACEBOOK_GRAPH_VERSION` is configured there (the example is `v26.0`). Gemini settings are backend-only; AI recommendations remain disabled until `GEMINI_ENABLED=true` and a server-side `GEMINI_API_KEY` are configured. Never put the key in frontend environment variables. Then run:

```bash
# Set MODERATION_ADMIN_EMAILS=admin@example.com in backend/.env before proceeding.
php artisan key:generate
php artisan migrate
php artisan config:clear
php artisan marremove:admin-create admin@example.com --name="Local Admin"
php artisan serve --host=0.0.0.0 --port=8000
```

In another terminal, start React + Vite:

```bash
cd frontend
cp .env.example .env
npm install
npm run dev -- --host 0.0.0.0
```

Open `http://localhost:5173/` for the admin dashboard. The first page load presents the local email/password sign-in form. Only email addresses in `MODERATION_ADMIN_EMAILS` can sign in; create an account with the server-side Artisan command above. Public registration, password reset, and Facebook Login are not included. Connect a Page at `/facebook/pages/connect` after signing in. The frontend uses relative `/api` requests; Vite proxies them server-side to Laravel at `http://127.0.0.1:8000`. Set `LARAVEL_API_URL` in `frontend/.env` if Laravel runs elsewhere.

The connection endpoint is `POST /api/facebook-pages/connect` and requires an authenticated, allowlisted Laravel admin session. Login is handled by Laravel's web-session guard with CSRF protection, session-ID rotation, and a login rate limit. Do not remove the authentication middleware or the server-side admin allowlist.

For queued synchronization, moderation, and Facebook actions, keep a database queue worker running in another backend terminal:

```bash
cd backend
php artisan queue:work database --tries=3 --timeout=900 --sleep=1
```

The example uses Laravel's database queue. The existing jobs migration creates the queue table. Facebook action jobs carry their own bounded retry/backoff policy; do not use the `sync` queue driver in production.

## Page connection behavior

- `FacebookPageService` calls `https://graph.facebook.com/{FACEBOOK_GRAPH_VERSION}/me` using the configured Graph API version. The Page token is sent server-to-server in the `Authorization: Bearer` header.
- The backend asks Meta for Page ID, name, username, category, and picture. It translates Meta/network/rate-limit errors into safe messages and does not return raw provider errors.
- Only Page information is included in the API response. The Page token is never included in that response.
- The token is stored using Laravel's `encrypted` Eloquent cast; the database column holds ciphertext and needs a stable, private `APP_KEY`. `token_expires_at` remains `null` because this Page-info request does not return expiry metadata.
- Reconnecting the same Page as its owner updates that single row after re-validating the new token. Another account cannot take over an already-owned Page.
- `.env` files are ignored by Git. Never put Facebook or Gemini secrets in frontend environment variables or browser storage. The token input is cleared on submission and is not persisted or returned; however, browser DevTools can inspect any request body sent by the browser, so it is not technically possible to guarantee the submitted token is invisible in that browser's Network panel. Use the app over HTTPS outside local development.

### Bulk connection with explicit Page selection

The admin-only bulk flow is a two-step process; it does **not** automatically connect every Page:

1. Enter one Facebook **User Access Token** in `/facebook/pages/connect`. `POST /api/facebook-pages/discover-managed` calls Meta's `/me/accounts?fields=id,name,access_token` edge and returns Page names/IDs plus an opaque import ID. `pages_show_list` and access to those Pages are required to discover them.
2. Select the desired checkboxes and press **Connect selected Pages**. `POST /api/facebook-pages/import-managed` accepts only the import ID and selected Graph Page IDs. The server checks that the import belongs to the signed-in admin and that every selected ID was in the returned list; unselected Pages are not connected.

The submitted User Access Token is not persisted or returned. Per-Page tokens are encrypted with Laravel's `APP_KEY` in server-side cache for five minutes, then decrypted only to connect selected Pages. The cache entry is owner-bound and removed after the selection attempt; a new discovery is needed after expiry. Discovery is capped at 100 Pages and the endpoints are throttled. Page credentials that successfully connect are encrypted in the existing database model as usual.

This bulk flow only simplifies Page discovery and connection. It does **not** bypass Meta permission or App Review requirements: without `pages_show_list`, discovery fails; comment sync may still be blocked unless Meta has approved/granted the required read permissions, including `pages_read_user_content` and `pages_read_engagement` where applicable. A Page can connect successfully while comment reading remains unavailable.

## Page post and comment synchronization (Step 3)

After connecting an owned Page, visit `/facebook/comments`, choose the Page, and press **Sync Now**. The request enqueues a `SyncFacebookPageJob`; it does not wait for Meta's paginated requests. Keep the database queue worker above running. The UI polls the safe Page status until it is `completed` or `failed`. A failed sync exposes only a sanitized message. Retry a failed sync with **Sync Now**.

Authenticated API endpoints:

- `GET /api/facebook/pages` — safe list of the signed-in user's Pages and sync status; tokens are excluded.
- `POST /api/facebook/pages/{page}/sync` — `{page}` is the local `facebook_pages.id`, not the Graph Page ID. The authenticated owner is checked before the job is dispatched. Returns `202 Accepted` with status/count summary only.
- `GET /api/facebook/comments?page_id=1&search=text&from=2026-01-01&to=2026-10-08&per_page=50&page=1` — comments are always scoped to Pages owned by the signed-in user. `page_id` is the local `facebook_pages.id`; `search`, date bounds, and `per_page` are optional. Server-side page size is limited to 100.

The Graph API token is decrypted from `facebook_pages` only in Laravel services. The queued job contains only the internal Page row ID. Neither API responses nor logs include the token or Meta's raw error message.

### Pagination, caps, and persistence

- `FACEBOOK_GRAPH_VERSION` controls every Graph request (example: `v26.0`). The implementation calls the Page Feed edge `/{page-id}/feed`—not `/{page-id}/posts`—with `id,message,created_time,status_type`, the fields documented on the Page Feed reference. Meta's Page reference lists its own `posts` edge as derived from `/feed`; that documentation does not change the path this app actually requests. `post_type` stores the documented `status_type` value, not an undocumented generic `type` field.
- Page Feed requests use a per-request `limit` no greater than the Page Feed reference's documented maximum of 100. Cursor pagination follows only the opaque `after` cursor and stops at `FACEBOOK_MAX_POSTS_PER_SYNC` (default `50`).
- Each post's comments use cursor pagination with `filter=stream` to include replies, up to `FACEBOOK_MAX_COMMENTS_PER_POST` (default `100`). `FACEBOOK_MAX_PAGES_PER_SYNC` (default `100`) is an additional loop/safety bound. Set these variables in `backend/.env` and restart Laravel workers after changing them.
- Post and comment Graph IDs have unique database indexes. Synchronization uses database upserts, so later runs update existing rows rather than creating duplicates. `facebook_comments.parent_comment_id` preserves reply linkage when Meta returns it.
- Additive migrations: `2026_10_08_000001_create_facebook_posts_and_comments_tables.php` creates `facebook_posts` and `facebook_comments`; `2026_10_08_000002_add_sync_tracking_to_facebook_pages_table.php` adds safe queue/status/error/count metadata to `facebook_pages`.

### Meta v26.0 fields and limitations

Implementation was checked against Meta's current v26.0 references:

- The [v26.0 Page reference](https://developers.facebook.com/docs/graph-api/reference/v26.0/page) lists a `posts` edge for the Page's own posts as derived from `/feed`. This code calls `/{page-id}/feed`. The [Page Feed reference](https://developers.facebook.com/docs/graph-api/reference/v26.0/page/feed) documents `id`, `message`, `created_time`, and `status_type`; it requires `pages_read_engagement`, `pages_read_user_content`, and an appropriate Page task (such as `CREATE_CONTENT`, `MANAGE`, or `MODERATE`). The Feed documentation caps a request at 100 and notes approximately 600 ranked published posts per year. A sync is intentionally capped by the lower configured app maximum.
- [Page Post Comments](https://developers.facebook.com/docs/graph-api/reference/v26.0/pagepost/comments) provides the comments edge and cursor pagination. Its current reference lists `pages_read_engagement` and/or `pages_read_user_content` among the permissions that may trigger error 283; Page Public Content Access may also be required in some access scenarios. A Page token holder must have the `MODERATE` task to receive comment IDs (public-content-access reads without that task may omit IDs; items without IDs are skipped because they cannot be safely upserted). The sync requests `filter=stream` and the fields `id,message,created_time,from{id,name},parent{id}`. If Meta rejects fields (Graph error 100), it retries with reduced fields (`id,message,created_time`, then `id`) so available comments can still be stored.
- Author ID/name and reply parent are nullable because Meta may withhold them for privacy, permissions, or individual comments. Meta's Page Feed reference notes that user information is only included when using a Page Access Token; it may still be absent for a particular commenter. `message` and `comment_created_at` are also nullable for partial responses. The [v26.0 Comment reference](https://developers.facebook.com/docs/graph-api/reference/v26.0/comment) lists `parent` but does not document `updated_time`; the app does not request it, and `comment_updated_at` therefore remains nullable. These reads have not been verified with live Page credentials.

## Page comment webhooks (Step 4)

The webhook request handler verifies the `X-Hub-Signature-256` HMAC against the exact raw POST body, validates the Page feed notification shape, and queues supported comment events without making Graph API calls in the request. A worker fetches the latest comment details through the existing versioned Graph API client and upserts by the unique Facebook comment ID. New comments (`verb=add`) and comment edits (`edit`, `edited`, or `update`) are handled; deletions, unrelated feed items, unknown Page IDs, and unknown shapes are safely ignored. Event idempotency is stored using a SHA-256 key for the Page/change, and the unique comment ID protects storage from duplicate processing. Page tokens are loaded from encrypted backend storage and are not included in webhook jobs or responses.

### API routes and React status page

- `GET /api/facebook/webhook` — public Meta verification endpoint. It accepts `hub.mode=subscribe`, `hub.verify_token`, and `hub.challenge`; on a match it records a token fingerprint and returns the challenge as plain text.
- `POST /api/facebook/webhook` — public signed event endpoint. Valid deliveries are acknowledged with `200 EVENT_RECEIVED`; invalid signatures return `401`, malformed JSON returns `400`, and queue dispatch failures return `503`. Valid but unsupported events/shapes are acknowledged and ignored.
- `GET /api/facebook/webhook/status` — authenticated, moderation-admin-only status for the signed-in user's Pages; no secrets or access tokens are returned.
- `/facebook/webhooks` — admin-only React webhook status page showing the callback URL, Meta verification status, last received event, and last processed event.

### Configure Meta and Laravel

1. In `backend/.env`, set `FACEBOOK_APP_SECRET` to the App Secret from **Meta App Dashboard → App settings → Basic**. Set `FACEBOOK_WEBHOOK_VERIFY_TOKEN` to a long, random value you generate; it is a separate callback-verification string, not the App Secret. Keep both backend-only; never add them to Vite/frontend environment variables or commit them. Clear/rebuild Laravel configuration cache after changing environment values.
2. Run the database migrations, including `2026_10_08_000003_create_facebook_webhook_tables.php`, and run the configured database/Redis queue worker. Do not use the `sync` queue driver in production: it would process Graph API requests inline instead of returning quickly.
3. Set `APP_URL` to the public HTTPS origin so the status page displays the right callback URL. In the Meta App Dashboard, open **Webhooks**, add the **Page** object, set the callback URL to `https://<your-public-host>/api/facebook/webhook`, enter the same verify token, and verify/subscribe. Enable the `feed` field and include webhook values so comment IDs are delivered. Meta requires a publicly reachable HTTPS callback outside local development.
4. Make sure the app has the Page webhook permissions (`pages_manage_metadata`, `pages_show_list`) and the Graph read permissions needed to fetch comment details (`pages_read_engagement` and `pages_read_user_content`, subject to Meta app review/access requirements). The Facebook user must have a Page task that permits the operation, such as `CREATE_CONTENT`, `MANAGE`, or `MODERATE`.
5. Subscribe each connected Page to the app's `feed` field using Meta's Page `/{page-id}/subscribed_apps` endpoint and a suitable Page Access Token. Keep that token server-side; do not put it in React or log it. The callback status page reports whether verification and server-secret configuration are present but does not expose their values.

### Local webhook testing

Laravel feature tests use a local HMAC secret, fake Meta Graph responses, and a fake queue; they do not require a real Meta App, Page token, or public callback. Run the new webhook tests with `php artisan test --filter=FacebookWebhookTest`. For a real end-to-end delivery, a local server must be exposed through a trusted HTTPS tunnel and that public URL configured in the Meta dashboard; keep a queue worker running. The authenticated status page is available at `/facebook/webhooks` after signing in to the Laravel app. The endpoint logs event identifiers, durations, and error categories only; never log the raw payload, signature, app secret, Page token, or Gemini key.

## Manual moderation rules (Step 5)

Manual rules run after a comment is stored by either the Step 3 Page sync or the Step 4 webhook refresh. Migration `2026_10_08_000004_create_moderation_rules_and_add_manual_comment_fields.php` creates `moderation_rules` and adds manual-result columns to `facebook_comments`. `ManualModerationService` saves the matched rule, recommended action, category, severity, reason, and check time on the comment. If no active rule matches, it saves `manual_moderation_status=no_manual_match` and `manual_action=none`. Rule changes affect the next sync or webhook update; existing comments are not automatically backfilled.

The Step 5 rule evaluation remains deterministic and unchanged. Its `keep`, `review`, `hide`, and `delete` values are recommendations stored in the database; Step 5 itself does not call Facebook. Step 6 adds Gemini as a separate asynchronous classification. Step 7 combines the audit results into a final recommendation, with any manual match—including `review`—taking precedence and stopping AI processing. Step 8's separate queued action pipeline decides whether an eligible final decision is actually executed.

### Admin security and routes

Set `MODERATION_ADMIN_EMAILS` in `backend/.env` to a comma-separated allowlist of administrator email addresses. An empty allowlist denies sign-in and every moderation-admin endpoint. After configuring the email, run `php artisan config:clear`, then `php artisan marremove:admin-create admin@example.com --name="Admin Name"`; the command prompts for a minimum 12-character password without echoing it. The same command can reset an existing allowlisted account after confirmation. There is no public registration or password-reset route. All moderation endpoints require both an authenticated session and the server-side email allowlist.

- `GET /api/moderation/pages` — active connected Pages available for rule scope; returns Page names and Graph IDs only.
- `GET /api/moderation/rules` — list and search rules. Optional filters: `search`, `facebook_page_id` (Graph Page ID or `global`), `action`, `rule_type`, and `sort_priority=asc|desc`.
- `POST /api/moderation/rules` — create a rule.
- `GET /api/moderation/rules/{rule}` — read a rule.
- `PUT|PATCH /api/moderation/rules/{rule}` — edit a rule or toggle `is_active`.
- `DELETE /api/moderation/rules/{rule}` — remove a rule; previously stored comment decisions remain as history.
- `POST /api/moderation/rules/test` — `{ "comment": "...", "facebook_page_id": "..." }`; evaluates active global rules and, when a Page ID is supplied, that Page's rules. It is read-only and never modifies a Facebook comment.
- `/moderation/rules` — React rule-management page and built-in tester.

A null `moderation_rules.facebook_page_id` is a global rule. A specific value is Meta's Graph Page ID (not the local database row ID). Page-scoped rules apply only to that connected Page; global rules apply to all Pages.

### Decision hierarchy

All active global and matching Page-specific rules are considered. **Higher priority wins first**. When two or more matching rules have equal priority, the action order is **delete > hide > review > keep**. If priority and action are also equal, the rule with the lower database ID wins for a stable result. This only chooses the stored recommendation—`delete` and `hide` never trigger Facebook actions. A match returns `method=manual`, `confidence=1.0` as a deterministic rule-match indicator (not AI confidence), the rule ID/name, category (explicit category or rule type), severity, and reason. No match returns `action=none`.

### Matching and normalization

- Text is Unicode NFKC-normalized when PHP's `intl` extension is available, lowercased, stripped of zero-width format characters, and whitespace/punctuation is normalized. Bengali marks are kept safely; one narrow Bengali elongation normalization handles `চোওর` as `চোর`.
- Keyword matching is case-insensitive and uses Unicode-aware whole-word boundaries, so `ass` does not match `class`. Punctuation around words is ignored. A limited secondary comparison handles punctuation inserted inside a word (`চো-র`) and runs of three or more spaced single-letter tokens (`c h o r`); it does not concatenate ordinary words.
- Phrase matching normalizes repeated spaces and punctuation separators, so `ফালতু মাল` matches `এই ফালতু,   মাল` while preserving word boundaries.
- Regex rules use delimited PHP/PCRE syntax, for example `~\b(?:spam|scam)\b~iu`, against normalized text. Patterns are validated before saving, capped at 512 bytes, recursion/subroutine calls and backreferences are rejected, and evaluation applies PCRE backtrack/recursion limits. Runtime errors are caught and logged with rule ID/error category only—never with the pattern or comment.
- URL rules use a built-in detector for `http://`, `https://`, `www.` and common domain suffixes. Phone rules detect Bangladesh local numbers such as `01712345678`, Bangladesh international numbers such as `+880 17-1234-5678`, and common international formats. A URL or phone is only a match condition; the selected rule action determines the recommendation.
- Repeated-text rules detect a token or short phrase repeated consecutively. `pattern` sets the minimum repeat count (2–10, default 3).

Example rule: **Bangla abusive words**, type `keyword`, pattern `চোর`, category `profanity`, action `hide`, severity `high`, priority `100`, Page `All Pages`. For `আপনারা চোর`, the rule tester shows the matched rule, `HIDE`, and `Matched keyword`; the stored result is only a manual recommendation.

## Gemini AI moderation and final decisions (Steps 6–7)

Step 6 queues `ModerateCommentWithGeminiJob` after a comment has been stored. It never calls Gemini inside a Facebook webhook request. Jobs contain only the local comment row ID; the worker loads the comment, Page name, post text, and minimal manual-result context from the backend. Author IDs/names, Facebook IDs, Page tokens, App Secrets, and the Gemini key are not sent to Google.

Step 7 adds `CommentModerationService` as the final-decision engine. A manual rule match (`keep`, `review`, `hide`, or `delete`) is final and prevents a Gemini job. Without a manual match, the engine applies the AI category/confidence, per-Page thresholds, and permissions. Step 8 can execute only eligible final `hide`/`delete` decisions when that Page's automatic-action switches are enabled; `keep` and `review` never dispatch a Facebook mutation. Admin overrides remain auditable, and a final `keep` always cancels a still-pending automatic hide/delete.

### Gemini endpoint and backend-only configuration

The implementation uses Google's Interactions API (`POST https://generativelanguage.googleapis.com/v1beta/interactions`) with the API key in the server-side `x-goog-api-key` header and JSON Schema output. Requests are stateless (`store=false`). The default model is `gemini-3.8-flash`; the endpoint/model are configurable and the key is never hardcoded or returned to the frontend.

Configure `backend/.env` and restart Laravel workers after changes:

```dotenv
GEMINI_ENABLED=false
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.8-flash
GEMINI_TIMEOUT=20
GEMINI_MAX_RETRIES=3
GEMINI_QUEUE_CONNECTION=database
GEMINI_RETRY_BASE_DELAY_MS=250
MODERATION_ADMIN_EMAILS=
MODERATION_AUTO_REVIEW_THRESHOLD=0.70
MODERATION_AUTO_HIDE_THRESHOLD=0.90
MODERATION_AUTO_DELETE_THRESHOLD=0.98
MODERATION_ALLOW_AI_HIDE=true
MODERATION_ALLOW_AI_DELETE=false
```

Both the global `GEMINI_ENABLED` feature flag and the per-Page `ai_enabled` switch must be on for AI moderation. A Page can also disable manual rules independently. The `MODERATION_ADMIN_EMAILS` allowlist is server-side; an empty value denies all moderation-admin APIs. The key and Page tokens must not be put in frontend variables, browser storage, prompts, or logs.

### Final-decision hierarchy and thresholds

- **Manual match:** use the stored manual action and stop. Manual results remain unchanged when writing the final fields.
- **AI unavailable, disabled, or failed:** final recommendation is `review`, method/source `fallback`; AI failure can never become `delete`.
- **Protected/safe AI categories:** `customer_complaint`, `negative_feedback`, `clean`, `irrelevant`, `suspicious`, and `other` cannot be automatically hidden or deleted from an AI category/confidence match. A sufficiently confident Gemini `keep` recommendation remains `keep`; Gemini's `review` recommendation remains `review`. The response validator also downgrades `hide`/`delete` recommendations for protected feedback categories to `review`.
- **High-risk AI categories:** profanity, insult, harassment, sexual content, hate, threat, spam, scam, and competitor spam may reach `hide` or `delete` only if Gemini explicitly recommends that action (or a stricter `delete` recommendation is safely downgraded to `hide`), the confidence meets the corresponding threshold, and that Page has enabled the action. A `review` recommendation is never escalated based on category/confidence alone, and a `hide` recommendation is never escalated to `delete`. If no enabled threshold is met, the result remains `review`.
- Threshold comparisons are inclusive (`confidence >= threshold`). Defaults are **review 0.70**, **hide 0.90**, and **delete 0.98**. Per-Page settings govern actual decisions; values must be numeric in `[0,1]` and ordered `review ≤ hide ≤ delete`. AI hide is enabled by default; AI delete is disabled by default.

The backend validates the exact AI response schema and safe categories before storing the AI result. It uses separate manual, AI, and final fields, so finalization does not overwrite the existing manual/AI audit results. The final fields retain action, method, source, category, confidence, severity, reason, threshold, and decision time. AI timeout/network/429/5xx/invalid-response failures are safely recorded and sent to `review`; logs omit comment text, provider response bodies, secrets, and tokens.

### Persistence, admin UI, and routes

Migration `2026_10_08_000006_create_final_moderation_pipeline.php` adds `page_moderation_settings`, final/override fields on `facebook_comments`, and `moderation_logs`. Logs contain a comment ID, manual/AI/final snapshots, source, processing time, and the override history through audit entries. They do not contain a copy of the comment text. A keyed HMAC over the moderation input/settings supports idempotency without storing content in the hash.

- `/moderation/ai` — admin-only per-Page manual/AI switches, thresholds, AI recommendation permissions, automatic hide/delete settings, an explicit automatic-execution switch, and a read-only Gemini tester. It shows whether `MODERATION_TEST_MODE` is enabled; the API returns only whether the Gemini key is configured, never the key.
- `/facebook/comments` — shows Manual, AI, and Final outcomes. **Decision details** displays the evidence, threshold, reason, source, and final recommendation.
- Admins can override a comment to `keep`, `review`, `hide`, or `delete`. The final record and `manual_override` log entry include actor/time. A final `keep` prevents any pending automatic hide/delete; hide/delete overrides are eligible for automatic execution only when the Page settings explicitly allow it. Complaints and negative-feedback categories still cannot be automatically deleted.
- `/moderation/actions` — admin-only paginated action-log UI with comment, final decision, Facebook action/state, status, attempts, last error, response, and processing time. Its detail view has confirmation-protected hide/unhide/delete controls that queue through the same service as automatic actions.

Admin-only API routes (all require authenticated email allowlisting):

- `GET /api/moderation/ai/settings` — safe global metadata and per-Page settings.
- `PATCH /api/moderation/ai/pages/{page}/settings` — update a Page's policy; `{page}` is the local database ID.
- `POST /api/moderation/ai/test` — stateless test classification; does not modify a comment or write a moderation log.
- `PATCH /api/moderation/comments/{comment}/override` — save an admin final recommendation and audit entry.
- `GET /api/moderation/actions?status=pending&action=hide&per_page=50&page=1` — filter the action audit history; status also accepts `completed`, `failed`, `hidden`, and `deleted`.
- `POST /api/moderation/comments/{comment}/actions` with `{ "action": "hide|unhide|delete" }` — enqueue an explicit administrator action; `{comment}` is the local comment row ID.

All these routes require an authenticated user whose email is on `MODERATION_ADMIN_EMAILS`. They never accept a Page ID or token from the action request: the backend selects the comment's stored Page relation and decrypts that Page's token.

The authenticated `GET /api/facebook/comments` response exposes separate `manual_moderation`, `ai_moderation`, `final_moderation`, and `decision_explanation` fields. Ordinary users see only comments on Pages they own; only moderation admins receive override capability.

## Facebook comment actions and moderation execution (Step 8)

Step 8 is a server-side action pipeline, not a webhook or controller-side API call. It uses only the documented Page-comment mutations in the [Graph API Comment reference v26.0](https://developers.facebook.com/docs/graph-api/reference/v26.0/comment/): `POST /{comment_id}` with `is_hidden=true` to hide and `is_hidden=false` to unhide (the field applies only to Page comments), and `DELETE /{comment_id}` to delete. Requests use the Graph version from `FACEBOOK_GRAPH_VERSION` and a bearer token loaded from that comment's encrypted `facebook_pages.page_access_token` relation. Controllers and webhook handlers do not make Meta requests.

Before queueing, at worker start, and immediately before a mutation, the service verifies that the comment's local `page_id` resolves to an active Page and that its stored `facebook_page_id` matches that Page's Meta ID. Callers supply only a local comment row and an action—never a Page ID or token. A mismatch is rejected before dispatch, preventing a Page A token from being selected for a Page B comment. Every job also performs a documented `GET /{comment_id}?fields=id` with that same Page token and checks the returned ID before mutating; this confirms the token can read that comment. For DELETE, if Meta reports that the comment is no longer available, deletion is recorded complete without sending another DELETE. Missing comments skip hide/unhide.

### Queue, retries, settings, and safeguards

- Final-decision changes are saved first. `HideFacebookCommentJob`, `UnhideFacebookCommentJob`, and `DeleteFacebookCommentJob` are dispatched after commit to the configured action queue. The job contains local row/log IDs only. Production must run a Laravel queue worker; `MODERATION_ACTION_QUEUE_CONNECTION` defaults to `database`, and an empty or `sync` connection is deliberately replaced with `database` so no request can execute a Graph mutation inline.
- Retryable network failures, Meta transient/service errors, and rate limits use finite exponential-style backoff `[10, 60, 180]` seconds and a clamped `MODERATION_ACTION_MAX_ATTEMPTS` (default `3`, maximum `5`). HTTP 408/425/429/5xx and documented transient/rate-limit Meta errors can retry, following Meta's [Graph API error-handling guidance](https://developers.facebook.com/docs/graph-api/guides/error-handling). Invalid/expired token code 190, permission errors (including 10/200–299), and other permanent rejections fail without retry. Inspect the safe error category and reconnect or fix Page permissions rather than retrying invalid credentials.
- Each Page has `auto_hide_enabled` (default `true`), `auto_delete_enabled` (default `false`), and `auto_execute_actions` (default `false`). A final AI/manual hide/delete is only queued automatically when both execution and the relevant action switch are enabled. Disabled execution still stores a `skipped` action decision/log; there is no Facebook request. `keep` and `review` never execute a mutation. If a final `keep` override replaces an action that is still pending, the old queued log is cancelled. Workers also recheck the latest final decision after the Graph read preflight and before the mutation, so a keep change made before the mutation stops the Meta call.
- Automatic deletion is blocked for `customer_complaint`, `negative_feedback`, `clean`, and `other`, even if delete settings are enabled. Such comments remain for review; an administrator may still choose an explicit manual Facebook action from an Action Log detail. This explicit admin path is distinct from an automatic AI/rule deletion.
- Duplicate queued work is detected under a database row lock; identical pending/processing work reuses its log, terminal duplicate desired states are skipped (`hidden`, `visible`, `deleted`), and a job that already has a terminal log exits. Local state is best-effort: Meta's current Comment read fields do **not** document `is_hidden` as readable, so the app does not claim it can inspect external hide state or reconcile changes made by another moderator. A network timeout after Meta accepted a hide/unhide may remain ambiguous; these mutations set an explicit boolean and are semantically repeatable, but the local audit is the source of the app's recorded state.
- `MODERATION_TEST_MODE=true` simulates a successful queued job, records `is_test=true`, and never sends an HTTP request to Meta (including the comment-identity preflight). Keep it enabled for safe pipeline checks; turn it off only when production queue/token/permissions are ready.

### Permissions and production setup

Meta's current [Pages API](https://developers.facebook.com/documentation/pages-api) maps Page comment moderation to `pages_manage_engagement`; its [Manage a Page](https://developers.facebook.com/documentation/pages-api/manage-pages) guide requires a Page Access Token and says the app user must hold an applicable Page task such as `MODERATE` (also lists `CREATE_CONTENT`/`MANAGE` for operations covered by that broad guide). Meta's Comment reference notes that read requests can require additional Page read permissions and that Page Public Content Access may apply. For this implementation, grant only the review-approved permissions your endpoints actually require: `pages_manage_engagement` for hide/unhide/delete and any read permission Meta requests for the `fields=id` comment-identity preflight used before each mutation (commonly `pages_read_engagement` and/or `pages_read_user_content`). Complete App Review/Advanced Access where Meta requires it and ensure the person who granted the Page token has the `MODERATE` task. A valid token alone is not sufficient if its Page, permissions, task, or comment access do not match.

For production, configure the stable encrypted-token `APP_KEY`, `FACEBOOK_GRAPH_VERSION=v26.0`, `MODERATION_ACTION_QUEUE_CONNECTION=database`, `MODERATION_ACTION_MAX_ATTEMPTS=3`, `MODERATION_TEST_MODE=false`, and `MODERATION_ADMIN_EMAILS`. Run migrations, verify each Page's encrypted token by reconnecting through the existing server-side flow when required, and keep a database queue worker supervised:

```bash
cd backend
php artisan migrate
php artisan queue:work database --sleep=1 --tries=3 --timeout=900
```

Use HTTPS, protect the admin allowlist, keep queue/database backups and worker logs private, and never log or expose a Page token, Meta App Secret, or Gemini key. The Actions UI and endpoints return only sanitized categories/messages and action audit metadata. The migration `2026_10_08_000007_add_moderation_action_pipeline.php` adds per-Page switches, local state/timestamps/errors, and `moderation_action_logs` with action, status, HTTP/Meta response codes and safe message, elapsed time, and attempt count.

## Admin dashboard (Step 9)

The responsive React admin console is available at these browser routes; every route is protected by the existing Laravel authentication session and moderation-admin allowlist:

- `/dashboard` — server-calculated moderation metrics, 30-day decision/method/category charts, and recent activity; `?page_id={local Page row ID}` scopes it to a Page.
- `/facebook/pages`, `/facebook/pages/connect`, `/facebook/pages/{id}` — owned Page list, existing secure connect form, Page-scoped dashboard, queued sync, settings shortcut, and confirmation-protected disconnect. Disconnect clears the encrypted Page credential, deactivates the Page, and retains comments and audit history.
- `/facebook/comments`, `/facebook/comments/{id}` — searchable/filterable server-paginated comments and the decision-explanation timeline; overrides require explicit confirmation.
- `/moderation/review` — server-filtered review queue and human-confirmed decisions.
- `/moderation/rules` — existing rule CRUD and read-only tester.
- `/settings/ai` — safe Gemini readiness metadata, Page AI switches, and stateless classifier tester.
- `/settings/moderation` — Page-level moderation switches, confidence thresholds, dangerous-setting warnings, and test-mode notice.
- `/logs/actions` — paginated action logs, filters, detail view, and confirmation-protected manual-action requests through Laravel.
- `/facebook/webhook` — callback readiness and event totals/queue/failure counts.
- `/settings/system` — read-only, non-secret service, queue, moderation, and webhook status.

The frontend uses a lightweight pathname route map (no router dependency), one responsive `AppShell`, reusable loading/empty/error states, confirmation dialogs, and toast notifications. API calls are centralized in `frontend/src/api/`; the browser uses same-origin `/api` requests and does not call Meta directly. Dashboard aggregates and all comment/action-log filters run in Laravel; comment results are capped and paginated rather than downloading a large collection. When hosting the Vite build in production, route frontend paths to `index.html` while preserving the `/api/*` proxy to Laravel.

New or extended API endpoints used by Step 9:

- `GET /api/auth/csrf` — starts a same-origin web session and issues Laravel's XSRF cookie; it does not return the token in JSON.
- `POST /api/auth/login` — accepts email/password only for a `MODERATION_ADMIN_EMAILS` address, rotates the session ID, and returns a safe user summary. Protected by CSRF and a per-email/IP rate limit.
- `POST /api/auth/logout` — authenticated session logout; invalidates the session and rotates the CSRF token.
- `GET /api/moderation/access` — authenticated administrator access check and safe user summary; the admin middleware denies non-admins before dashboard data loads.
- `GET /api/moderation/dashboard?page_id={local Page row ID}` — optimized aggregate statistics, chart buckets, and recent moderation/action activity. The optional local Page ID must belong to the signed-in user.
- `GET /api/facebook/comments/{comment row ID}` — safe comment and decision evidence, scoped to an owned Page.
- `GET /api/facebook/comments` — existing paginated list extended with `decision`, `category`, `method`, `action_status`, `min_confidence`, and `max_confidence` filters. Page IDs are ownership-checked; `decision=review` includes pending decisions.
- `DELETE /api/facebook/pages/{page row ID}` — admin-only, owner-scoped disconnect that clears the stored credential without deleting moderation history.
- `GET /api/moderation/actions` — existing paginated log list now supports a local `page_id` filter and only returns logs for the authenticated user's Pages.
- `GET /api/facebook/webhook/status` — existing safe status payload extended with event totals, processed/queued/failed counts, and a safe error category; requires an authenticated moderation administrator.

The dashboard also reuses the existing Page list/connect/sync, moderation-rule CRUD/test, AI settings/test, moderation override/action, health, and webhook APIs. Backend ownership checks validate each Page or comment; frontend-supplied IDs are never treated as authorization. Page tokens, Meta App Secrets, and Gemini keys are not returned or logged. Automatic moderation and Facebook mutations remain in the existing Laravel services/queue; this dashboard does not replace or duplicate that engine.

Frontend tests are run with `cd frontend && npm test`; they cover dashboard aggregation/filtering, Page sync/disconnect, single-Page token handling, the explicit bulk Page discovery/selection flow, paginated comment filters and confirmed overrides, review-queue decisions, rule CRUD/tester behavior, AI/moderation settings, action-log detail and confirmed manual actions, webhook/system status, admin denial, and transient Page-token handling.

## STEP 10: production deployment and audit checklist

This section records production requirements for the existing system. Local email/password session sign-in is implemented, with CLI-only account provisioning; there is no public registration, password-reset page, or external SSO provider. `.env.example` remains a local-development template. Create a separate production environment file or inject values from a secrets manager—never commit production values.

### Required production configuration

- Set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL=https://<public-host>`. Use HTTPS end to end (including the Meta callback), enable HSTS and appropriate security headers at the TLS/reverse-proxy layer, and set `TRUSTED_PROXIES` to only known proxy IPs/CIDRs (or leave it empty for direct connections); never trust arbitrary client-supplied forwarded headers. Do not expose Laravel's development server in production.
- Provision a strong, private `APP_KEY` once and keep it stable. The Page Access Token is stored through Laravel's encrypted model cast; rotating `APP_KEY` without a planned token re-encryption/reconnect process makes existing credentials unreadable. Do not run `php artisan key:generate` during routine deploys.
- Configure production MySQL/PDO credentials (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), durable backups, and a real shared session/cache/queue store appropriate to the deployment. The initial migration `0001_01_01_000000_create_users_table.php` also creates the `sessions` table required by `SESSION_DRIVER=database`. Explicitly set `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`, `SESSION_DRIVER=database`, and preferably `SESSION_ENCRYPT=true`; scope `SESSION_DOMAIN` narrowly or leave it unset for a single host.
- Set `MODERATION_ADMIN_EMAILS` to the exact email addresses of trusted administrators, then create each password-authenticated account from the backend terminal with `php artisan marremove:admin-create <email> --name="<display name>"`. The command prompts for the password privately; there is no public registration or password-reset flow. Use strong unique passwords, keep the email allowlist private, and do not bypass `auth` or `EnsureModerationAdmin`. External SSO is not implemented.
- Set `FACEBOOK_GRAPH_VERSION=v26.0`. For webhooks, set backend-only `FACEBOOK_APP_SECRET` and a separate high-entropy `FACEBOOK_WEBHOOK_VERIFY_TOKEN`; register the public HTTPS callback, Page `feed` subscription, required reviewed permissions, and Page task in Meta. Obtain Page tokens only through the existing authenticated HTTPS flow. The Graph reads/mutations and permission requirements documented above may require App Review/Advanced Access; a source-code audit cannot grant or live-verify them.
- Gemini is optional and remains disabled unless both `GEMINI_ENABLED=true` and the Page AI switch are enabled. When used, set `GEMINI_API_KEY` only on the backend, choose the documented model, and restart workers after configuration changes. The stateless tester uses the provider; it does not change stored comments.

Minimum production environment template (replace placeholders in a secrets manager; the webhook/Gemini values are conditional on enabling those integrations):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<public-host>
APP_KEY=<stable-private-Laravel-key>
TRUSTED_PROXIES=<trusted-proxy-ip-or-cidr>
DB_CONNECTION=mysql
DB_HOST=<private-database-host>
DB_PORT=3306
DB_DATABASE=<database-name>
DB_USERNAME=<database-user>
DB_PASSWORD=<database-password>
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=960
FACEBOOK_GRAPH_VERSION=v26.0
MODERATION_ADMIN_EMAILS=<verified-admin-emails>
MODERATION_TEST_MODE=true
MODERATION_AUTO_EXECUTE_ACTIONS=false
MODERATION_AUTO_DELETE_ENABLED=false
MODERATION_ALLOW_AI_DELETE=false
FACEBOOK_APP_SECRET=<server-secret-if-webhooks-enabled>
FACEBOOK_WEBHOOK_VERIFY_TOKEN=<different-server-secret-if-webhooks-enabled>
GEMINI_ENABLED=false
GEMINI_API_KEY=
```

`MODERATION_TEST_MODE=true` is intentionally shown for the initial production smoke test; after verifying the queue, Page credentials/permissions, and owner approval, set it to `false` only if live actions are required. Keep deletion and autonomous execution disabled unless separately approved. Never use these placeholders as literal credentials.

### Queue and destructive-action safety

Use a persistent asynchronous queue and supervised workers. The default database queue has `DB_QUEUE_RETRY_AFTER=960`; Redis and Beanstalkd defaults are also set to 960 seconds so they exceed the longest built-in job timeout (Page sync: 900 seconds). If using SQS or overriding any queue timeout/visibility value, make its visibility/retry interval longer than the longest job timeout, with a safety margin; keep it longer than the webhook processing lease. A shorter interval can cause concurrent duplicate job attempts. Set `FACEBOOK_WEBHOOK_JOB_TIMEOUT` and `FACEBOOK_WEBHOOK_PROCESSING_LEASE_SECONDS` consistently if customizing webhook retry behavior.

Keep `MODERATION_AUTO_EXECUTE_ACTIONS=false`, `MODERATION_AUTO_DELETE_ENABLED=false`, and `MODERATION_ALLOW_AI_DELETE=false` until the relevant Page token, permissions, worker, audit path, and owner approval are verified. Page-level switches also default to disabling automatic execution/deletion. In non-production (`local`, `testing`, etc.) Meta mutations are always simulated regardless of `MODERATION_TEST_MODE`; in production, leave `MODERATION_TEST_MODE=true` during a controlled queue smoke test and set it to `false` only after approval. A confirmed administrator-requested action in the Action Log is distinct from autonomous moderation and can perform a real action in production, so tightly restrict admin access and require the UI confirmation. Gemini `review` recommendations cannot be promoted to hide/delete by confidence alone; high-risk category, explicit AI recommendation, confidence threshold, and Page policy are all checked.

Run the backend behind a same-origin reverse proxy: serve the built React SPA with a history fallback, but route `/api/*` to Laravel without stripping the prefix. The browser client deliberately uses same-origin cookies and XSRF headers; do not deploy the admin UI on a separate origin without redesigning and explicitly reviewing the session/CORS/XSRF model. `VITE_API_BASE_URL` is a build-time path, not a place for credentials; use `/api` for this deployment layout.

### Deployment commands

Provision the stable `APP_KEY` and secrets outside the application checkout. For a first installation only, generate the key before storing encrypted Page tokens. Example release commands:

```bash
cd backend
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
# Run under Supervisor/systemd or an equivalent process manager:
php artisan queue:work database --sleep=1 --tries=3 --timeout=900
```

Build and publish the SPA as a separate release artifact (never copy frontend secrets into it):

```bash
cd frontend
npm ci
npm run build
# Serve frontend/dist with SPA history fallback; keep /api/* on the Laravel proxy.
```

After a deployment/configuration change, verify `/up` and `/api/health`, inspect queue/failed-job and sanitized application logs, verify the webhook callback, and review one test-mode action log before enabling live execution. Keep logs, session data, databases, backups, and queue payloads private; rotate exposed secrets immediately and treat an `APP_KEY` rotation as a data migration, not a routine secret refresh.

### Audit verification and limitations

Automated feature tests are written to fake Meta and Gemini HTTP calls and fake/inspect queued jobs; they must never use real Page tokens or execute a real destructive Facebook action. Page Feed and Gemini Interactions API behavior has been checked against the current official versioned documentation linked above, but no live provider credentials or Meta Page permissions were available for end-to-end verification. This checkout has no PHP/Composer runtime, and no `backend/composer.lock` is present, so the Laravel suite and resolved framework/dependency versions are not verified. Generate and review a lockfile in a PHP 8.3+/Composer 2 build environment, commit it, then run the Laravel suite before release. The frontend suite passes 27 tests across 7 files and the production Vite build succeeds. `php-parser` 3.7.0 parsed all 104 backend PHP files without syntax errors, but this is not a substitute for native `php -l` or runtime tests. Local email/password sign-in is implemented, but an operator must configure `MODERATION_ADMIN_EMAILS` and provision the first admin through the hidden-password Artisan command; public registration, password reset, and external SSO are not included.

## Tests and verification

The mocked Laravel tests cover local session sign-in/logout, allowlist enforcement, single-Page connection, selected bulk Page discovery/import, encrypted short-lived credentials and token-safe responses, sync/webhook/manual/AI flows, Step 7 precedence/thresholds/overrides, Step 8 hide/delete/unhide, permission denial, bounded retries, safe logs, Page ownership, complaint protection, and admin-only routes. No real Meta or Gemini credential/network call is required for feature tests.

```bash
cd backend
php artisan test --filter=FacebookManagedPageImportTest
php artisan test --filter=FacebookPageConnectionTest
php artisan test --filter=FacebookSyncTest
php artisan test --filter=FacebookWebhookTest
php artisan test --filter=ManualModerationServiceTest
php artisan test --filter=GeminiModerationServiceTest
php artisan test --filter=CommentModerationServiceTest
php artisan test --filter=FacebookCommentActionServiceTest
php artisan test
```

Build the frontend:

```bash
cd frontend
npm run build
```

## Scope

Step 2 provides backend single-Page connection plus a User-token discovery flow with explicit selection, short-lived encrypted Page-token handoff, ownership checks, encrypted persistence, and token-safe Page details. Step 3 adds queued post/comment synchronization. Step 4 adds signed webhook intake and queued comment refreshes. Step 5 adds manual rule-based recommendations. Step 6 adds queued Gemini classifications. Step 7 combines the results into a saved final recommendation, per-Page policy/settings, a decision explainer, and admin overrides. Step 8 adds queued, audited, safeguarded Facebook Page comment hide/unhide/delete actions. No later step is included.
