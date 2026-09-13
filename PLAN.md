# webmention.io → PHP rebuild

## Context

The live site (`/web/sites/webmention.io`) is a Sinatra/DataMapper app from ~2013. Its gems are long dead (dm-core, mysql2 0.4.2, sucker_punch 1.x, ratom). It also depends on hosted XRay and indieauth.com. We are rebuilding it in `/web/sites/webmention-io-php` as a small PHP app modeled on TinyLogin: no framework, a hand-rolled router, plain PHP templates, and very few dependencies.

Requirements:
- The external surface stays identical: per-account and per-domain webmention endpoints, `/api/count`, `/api/mentions|links[.json|.jf2|.atom|.html]`, the status URLs, and the webhook payloads.
- It runs against the existing production schema and data. No destructive migrations.
- Pingback is removed. So are FedCM, the Munin `/stats` endpoints and the `debugs` logging (per your answers).
- Aperture forwarding stays.
- Verification runs asynchronously through a **Redis queue plus a `bin/worker` daemon**.

Environment facts:
- PHP **8.2.32**. TinyLogin requires 8.3, so target `>=8.2`.
- Extensions available: ext-redis, pdo_mysql, curl, mbstring, xmlwriter.
- Database is MariaDB 11.8. Redis is local.
- `.env` already has DB_*, REDIS_*, BASE_URL and CA3DB_*.

## Data notes (from the imported DB, partial import)

- **Link types.** The `type` column holds more than the known set: like 656k, link 628k, reply 225k, repost 179k, NULL 27k, invite 8.8k, bookmark 7.7k, rsvp-yes/maybe/no/interested, `post` 617, bare `rsvp` 389. There is also junk like `rsvp-marty mcguire`, `rsvp-https://…`, `rsvp->yes` and `rsvp-true`.
  - Formatters must never crash on these. Unknown or NULL types become `mention-of` in jf2.
  - `/api/count` passes the raw type through, with `link` renamed to `mention` and NULL skipped (live output shows `"rsvp-marty mcguire":1`).
  - `activity.type` in JSON strips `rsvp-.*` to `rsvp`.
- **Protocol.** 288k links have `protocol=pingback` and 324k have NULL. They stay in API output; `wm-protocol` is output as stored (string or null). Only receiving pingbacks is removed.
- **Endpoint type.** Only 3 links have `endpoint_type=site`, but the `/d/{domain}/webmention` endpoint stays.
- **Accounts.**
  - 15 accounts have `username != domain` (e.g. `aaronpk` / `aaronparecki.com`). Endpoint lookup must match either one. Status URLs use `username`.
  - 77 accounts have no token and get one generated on dashboard/settings visit.
  - 4 accounts use Aperture.
  - 306 have `pingback_enabled`; the column is ignored.
- **Avatars.** Recent `author_photo` values are still `https://webmention.io/avatar/…`, so CA3DB archiving is active. Keep storing that form and rewrite to `avatars.webmention.io` on output.
- **Timestamps.** `published_offset` can be `0` (outputs `+00:00`) or NULL (no suffix). Dates are stored as UTC datetimes.
- **Import finished (checked 2026-09-13).** Row counts: links ~1.43M, pages 273k, sites 8.1k, accounts 5.5k, blocklists 6.3k, blocks 969. `new_accounts_per_month` is an unrelated view; ignore it.
- **Missing indexes.** `pages.href` has no index, so a target lookup (`WHERE href IN (…)`) scans all 273k pages (EXPLAIN: type ALL). `sites.domain` has no index either. **Decision:** add both indexes as an additive migration (step 2). Also `count_non_backfed_webmentions` exists and is unrelated; ignore it.
- **Ignored legacy tables and columns.** The `notifications` table and the `links.notification_id` column come from old, retired code. The new app never reads or writes them. Leave both out of `database/schema.sql`, the repositories, models and tests.

## Dependencies (composer.json)

- `require`: php >=8.2, ext-pdo, ext-redis, ext-curl, ext-mbstring, ext-json, ext-xmlwriter, `indieauth/client`, `p3k/xray`. XRay pulls in p3k/http, mf2/mf2 and htmlpurifier.
- `require-dev`: `phpunit/phpunit ^11`.
- No Predis (use ext-redis), no dotenv library (a 20-line `.env` parser), no JWT (CSRF tokens live in the session).

## Layout

```
public/index.php              front controller (php -S friendly, like TinyLogin)
public/assets/app.css, app.js new modern CSS (light/dark tokens), small vanilla JS
public/js/mentions.js         copied verbatim (third-party sites embed this URL)
public/img/…, favicon.ico, robots.txt, manifest.json (icons kept, screenshots dropped)
bin/worker                    queue consumer (BRPOP loop; run N copies under systemd)
bin/parity-check              diff new app vs live webmention.io API for a list of URLs
src/Bootstrap.php Kernel.php Router.php RouteMatch.php Container.php   (adapted from TinyLogin)
src/Config.php                .env loader → typed config (BASE_URL rtrimmed)
src/Http/Request.php Response.php HttpException.php Session.php Csrf.php
src/View/Template.php Raw.php (copied from TinyLogin)
src/Storage/Database.php      PDO: utf8mb4, sql_mode='', time_zone '+00:00', exceptions
src/Storage/{Account,Site,Page,Link,Block,Blocklist}Repository.php
src/Model/{Account,Site,Page,Link}.php  readonly row objects; Link has absoluteUrl(),
                              publishedDate(), syndications(), hasAuthorInfo(), mf2RelationClass()
src/Webmention/Queue.php StatusStore.php RateLimiter.php
src/Webmention/Processor.php  port of helpers/webmention_processor.rb
src/Webmention/SourceFetcher.php  wraps p3k\XRay + private-webmention token exchange
src/Webmention/WebHooks.php AvatarArchiver.php ApertureForwarder.php
src/Format/{JsonFormat,Jf2Format,AtomFormat}.php  Url.php (AbsoluteUri port)
src/Controllers/{Home,Webmention,Api,Auth,Dashboard,Settings,Delete}Controller.php
templates/layout.php home.php endpoint.php status.php mentions.php dashboard.php
          settings.php sites.php webhooks.php blocks.php delete.php error.php _row.php _nav.php
database/schema.sql           regenerated from the imported DB (SHOW CREATE TABLE)
docs/nginx.conf, docs/webmention-worker.service
tests/Unit, tests/Integration, tests/fixtures (copy Ruby test/data HTML)
```

What to reuse from TinyLogin (`/web/sites/TinyLogin/src`):
- **Copy nearly as-is:** `Router.php`, `Container.php`, `View/Template.php`, `View/Raw.php`, `Http/Response.php`, `Http/HttpException.php`, and the Kernel's error handling.
- **`Http/Request.php` needs one change.** Its `flatten()` drops array params, but we need `target[]=`, `wm-property[]=` and `wm-property[0]=`. Keep the raw `$_GET` values and add `queryList(name): list<string>`.
- **Security headers become per-response:**
  - `/api/mentions.html` is iframed and shows third-party images, so it gets no X-Frame-Options (the Ruby app disabled frame protection) and `img-src *`.
  - Logged-in pages get `frame-ancestors 'none'`.

## Routes (all other paths → 404)

| Route | Notes |
|---|---|
| `GET /` | Home page. Same content sections as now, new design, IndieAuth sign-in form. |
| `GET /id` | Client metadata JSON (exact keys as live). |
| `GET /auth/start?me=`, `GET /auth/callback` | `IndieAuth\Client::begin/complete`. clientID `BASE/id`, redirect `BASE/auth/callback`. |
| `GET /logout` | Clears session and redirects to `/`. |
| `GET /{username}/webmention` | HTML page with the send-webmention form. |
| `GET /{username}/webmention/{token}` | Status JSON from Redis `webmention:status:{token}`, or 404 `{"error":"not_found"}`. |
| `POST /{username}/webmention` | Account looked up by `domain = ? OR username = ?`. |
| `POST /d/{domain}/webmention` | Per-site endpoint. |
| `GET /api/count[.json]` | |
| `GET /api/{links,mentions}[.json,.jf2,.atom,.html]` | Registered as explicit literal routes. |
| `GET /dashboard`, `/settings`, `/settings/sites`, `/settings/webhooks`, `/settings/blocks`, `/delete` | Login required. |
| `POST /settings/change_token`, `/settings/sites/new`, `/webhook/configure`, `/delete`, `/unblock` | Login and CSRF required. |

Removed:
- `/{username}/xmlrpc` (GET and POST)
- `POST /webmention?forward=`
- `/settings/enable_pingback`
- `/auth/fedcm-*`
- `/stats/*`
- `/reset`
- `/auth/failure`

Database columns and tables are left untouched.

## Behaviour to reproduce exactly

**JSON response helper.** Port of `json_response` in `controllers/controller.rb`:
- Headers: `Content-Type: application/json;charset=UTF-8`, `Cache-Control: no-store`, `Access-Control-Allow-Origin: *`.
- A `jsonp` param returns `text/javascript` wrapping the JSON.
- An `Accept: text/html` header renders the status/debug HTML page instead (`views/html_response.erb`).
- Encoding uses `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE`.
- Empty hashes must encode as `{}` (stdClass). This applies to `type` in count and `data` in pending status.

**Webmention POST.** Port of `controllers/webmention.rb`, in the same order:
1. Validate params: missing → 400 `invalid_request`; non-http(s) or missing host → 400 with `error_details`.
2. Look up the account (404 `not_found`), then the site (404 `invalid_target`). For `/d/`: site 404, then a domain mismatch → 400.
3. Rate limit: Redis `webmention:ratelimit:{md5("s=…;t=…")}`, 30s TTL → 429.
4. Create the token: 15 random bytes, urlsafe base64 (20 chars, fits `links.token`).
5. Rewrite Twitter URLs to brid.gy.
6. Write the pending status with a 3-day TTL.
7. Enqueue JSON `{username, source, target, protocol:'webmention', token, code, endpoint_type}` on `webmention:queue`.
8. Respond 201 (303 for HTML Accept) with a `Location` header and the queued JSON.

The `debug` param processes synchronously and maps results to the 200/400 bodies shown in the Ruby file. Redis key names are unchanged so status URLs issued by the old app keep working through cutover.

**Processor.** `bin/worker` calls `Processor::process()`, a port of `process_mention`. The steps below run in order:
1. **Early checks**, each setting an error status: account missing, source == target, bad target/source URL, blocked source domain (`blocks`), site not on the account, source in `blocklists`.
2. **Fetch the source with XRay:** `(new p3k\XRay)->parse($source, ['target'=>$target, 'timeout'=>6, 'accept'=>'html', 'token'=>…])`. The hosted service halved its 12s timeout.
   - Library `error` becomes the status error, with `unknown` mapped to `error`. A null or exception result becomes `parse_error`/`invalid_source`.
   - The user agent is XRay's default with ` webmention.io (+BASE_URL)` appended.
3. **Private webmentions:** port XRay's `controllers/Token.php`. HEAD the source, take the `token_endpoint`/`oauth2-token` rel, POST `grant_type=authorization_code&code`, and use the resulting token for the fetch. Errors map to `access_token_error` / pass-through.
4. **Existing-link cleanup on an XRay error:** if a link already exists for page+source, hard-delete it, set status `deleted`, and send the `WebHooks::deleted` payload (see bug fixes for which errors qualify).
5. **Create the page if needed** (`create_page_in_site`). XRay parses the target and sets type to entry/photo/video/audio/event, plus `name`.
6. **Upsert the link** by (page_id, href) with site_id, account_id, `domain` = source host, protocol and endpoint_type. Insert it before the webhook, because jf2 needs its `id` and `created_at`.
7. **Author:** port `add_author_to_link`. Invitee is used as author_url. With archive_avatars, POST JSON to CA3DB and replace `CA3DB_S3_URL` with `BASE/avatar`.
8. **Microformats data:** port `add_mf2_data_to_link`. Store name, summary, content html/text, and url absolutized against href. Store photo/video/audio/syndication as JSON. Store `published` as a UTC datetime, with `published_offset` only when the string carries a timezone, and `published_ts`. Also store swarm-coins and rel canonical.
9. **Type:** port `set_type`: rsvp-*, invite, repost, like, bookmark, reply, else link, with `is_direct`.
10. **Webhook and Aperture:** send the webhook (`secret, source, target, private, post: jf2` in that key order). If `aperture_uri` is set, POST to Aperture with the jf2 fallbacks for url, published and in-reply-to.
11. **Finish:** set token, `verified=1` and `is_private`, and save. Write status `success` with `data: jf2`.

**Timestamps.** DataMapper filled these automatically, so repositories must too: `created_at` on insert and `updated_at` on every update, in UTC.

**Formats.** Port of `helpers/formats.rb`:
- **jf2:**
  - `published` is local time using `published_offset`, with a `±HH:MM` suffix only if the offset is non-null.
  - `wm-received` is `created_at` formatted `Y-m-d\TH:i:s\Z`.
  - Includes `wm-id/source/target/protocol`, plus `name`, `syndication` and `summary{content-type,value}` when present.
  - Content shape depends on site age: sites created after 2018-02-26T17:00Z get `{html,text}`. Older sites get legacy `content-type/value` too.
  - Relation property plus `wm-property`, `wm-private`, and `rels.canonical`.
  - `https://webmention.io/avatar/` is rewritten to `https://avatars.webmention.io/`.
- **json (`links_to_json`):**
  - `verified_date` = `updated_at` as ISO-8601 `+00:00`.
  - `data.author` with nulls, `data.published` with offset, `data.rsvp`, `activity.type`, `rels`, `target`.
- **atom:** XMLWriter output matching the README sample: feed id/title/updated/link/author; entry title `"{source host} {verb} {target path}"`, id `BASE/api/mention/{id}`, summary, and xhtml content.
- **html:** `templates/mentions.php`, a standalone h-feed matching `views/mentions.erb` markup. It shows the account username when the request uses a token.
- **`Url::absolutize`:** port of `helpers/absolute_uri.rb`, built on `mf2\resolveUrl` plus non-ASCII percent-encoding (emoji test case).

**`/api/mentions`.** Port of `controllers/api.rb`:
- `token` is an alias for `access_token`. `per-page`/`perPage` default to 20. `page` sets the offset.
- `sort-dir=down` → DESC; any other value → ASC; absent → DESC.
- `sort-by`: `published` → published, created_at; `updated` → updated_at; otherwise created_at.
- `wm-property` mapping (string, list or indexed hash): rsvp → the 4 rsvp types, mention-of → `link`, otherwise strip `in-` and `-of/-to`.
- `since` (parsed with its timezone, converted to UTC) and `since_id`.
- **Target mode:** a target without a scheme also tries `https:` and `http:` prefixes. Pages are matched by `href IN (…)`, then links by page_id.
- **Token mode:** the account is found by token. An invalid token → 401 `forbidden`. An optional `domain` restricts to that site, and an unknown domain returns an empty list.
- All queries filter `verified=1 AND deleted=0`.
- Before querying, the controller sets `collation_connection=utf8mb4_general_ci`, as the Ruby code does, for emoji URLs.

**`/api/count`.**
- A missing target → 400 `invalid_input`.
- Otherwise returns the count plus a GROUP BY on type, with `link` renamed to `mention` and null types skipped.
- The response is always JSON (jsonp supported).

## Major bugs fixed along the way (called out in commit/README)

1. **`POST /webhook/configure` has no ownership check.** Any logged-in user can set any site's callback URL and receive its webhooks. The fix scopes it to the user's sites.
2. **`POST /delete` by id always passes its ownership check**, because it uses `=` instead of `==`. Any user can delete any link. The fix adds a real check.
3. **Several POST forms lack CSRF.** `change_token`, `sites/new` and `webhook/configure` have none. The fix adds a session CSRF token to every POST.
4. **The `jsonp` callback is echoed raw.** The fix restricts it to `[A-Za-z0-9_.$]`.
5. **Any XRay error on re-send hard-deletes an existing mention**, including timeouts, DNS or SSL errors, and 5xx. The fix deletes only on `no_link_found`, `not_found` and HTTP 410, and leaves other errors alone. *(Confirmed by Aaron.)*
6. **Some responses crash:**
   - jf2 on links with a NULL `author_photo`
   - atom/html error responses (Ruby handed a hash to the XML writer)
   - `/delete` with a bad `source`
   - negative `page` values

   The fix makes these graceful: error responses for atom/html are JSON, and `page` is clamped to 0.
7. **`sort-by=rsvp` is broken.** Ruby sorts only one page, and only in target mode. The fix does it in SQL: `FIELD(type,'rsvp-no','rsvp-interested','rsvp-maybe','rsvp-yes')` followed by created_at.
8. **RSVP values are stored unvalidated.** Live data contains `rsvp-marty mcguire`. The fix only accepts yes/no/maybe/interested and otherwise treats the post as a reply/mention. Existing rows are untouched.
9. **`/settings/sites/new` accepts garbage.** The fix normalizes the domain (lowercase, strip scheme and path) and rejects empty or duplicate entries.

## UI

- Single modern stylesheet: CSS custom properties, dark mode, responsive nav, no jQuery.
- Pages are the same as today: Dashboard (40 most recent), Sites (auto-creates the login domain and always shows the setup snippet), Webhooks (per-site forms and payload docs), Blocklists, Settings (feed URLs, token, regenerate), Delete preview/confirm.
- The endpoint page and the webmention status/debug page are restyled.
- The inline script that adds `https://` to URL inputs moves to `app.js` so CSP stays `script-src 'self'`.

## Sessions and auth

- Native PHP sessions, because indieauth/client uses `$_SESSION`. They are stored in Redis via ext-redis `session.save_handler=redis` with a `webmention:session:` prefix.
- Cookie is HttpOnly, SameSite=Lax, Secure on https, with a 30-day lifetime. The session ID is regenerated on login.
- Account creation matches `create_user_and_log_in`:
  - Strip a `/` path, lowercase, drop the scheme, and replace `/` with `_` to get the domain.
  - Find the account or create it with username = domain, and set `last_login`.
  - A `me` URL containing a query string gets the error page.
  - After login, redirect to `/settings` if the account has no verified links, otherwise `/dashboard`.
- Existing Ruby cookie sessions are not carried over, so users sign in again.

## Implementation order

0. ~~Wait for the production DB import to finish.~~ **Done.** Checked 2026-09-13: no import running, and all tables are present.
1. Skeleton: composer and autoload, Config, Kernel, Router, Request/Response, Template, Database, ErrorLog, layout and CSS, home page.
2. Repositories and models, plus `database/schema.sql` from the imported DB once the import finishes. Add `database/migrations/2026-09-13-indexes.sql` with `ALTER TABLE pages ADD INDEX href (href)` and `ALTER TABLE sites ADD INDEX domain (domain)`. Apply it to the local DB, include it in `schema.sql`, and confirm with EXPLAIN that the target lookup uses the index. Aaron runs the migration on production at cutover.
3. Formats (jf2/json/atom/html) and `Url`, plus the API controller, then parity-check against live.
4. Webmention endpoints, Queue, StatusStore, RateLimiter, Processor, SourceFetcher, WebHooks, avatar archiving, Aperture, `bin/worker`.
5. Auth (IndieAuth) and dashboard/settings/delete/blocks/webhooks pages with CSRF.
6. README (API docs carried over without the pingback sections, plus dev/deploy), nginx and systemd examples.

## Verification

**Test setup.**
- `sudo mysql` creates a `webmention_test` database and user and loads `database/schema.sql`.
- Tests use Redis DB 15, flushed between tests.
- XRay and outgoing HTTP go through a fake `p3k\HTTP` transport that serves `tests/fixtures/{host}/{path}` (Ruby's `test/data` HTML) and records POSTs (webhook, Aperture, CA3DB).

**Unit tests:**
- Port of `test/helpers/jf2_spec.rb`
- Port of `test/models/link_spec.rb`, including emoji URLs and emoji content round-trip
- Port of `test/helpers/webmention_processor_spec.rb`
- Router tests
- Url tests

**Integration tests:**
- API: every format, filters, sorting, paging, `since`/`since_id`, token/domain, jsonp, Accept: html, error codes
- Webmention flow: validation, 404s, 429, then 201+Location, worker run, status JSON, stored row
- Webhook payloads for new and deleted mentions
- Delete, block and unblock
- Ownership and CSRF rejection

**Other checks:**
- **Parity:** `bin/parity-check` runs the same query matrix against `https://webmention.io` and `BASE_URL` using real targets from the imported data. It compares decoded JSON, allowing mentions newer than the dump. Run it before sign-off.
- **Manual:**
  - Run `php -S 127.0.0.1:8080 -t public public/index.php` and `bin/worker`.
  - Sign in via IndieAuth.
  - Add a site, send a real webmention with curl to `/{domain}/webmention`, and watch the status URL flip to success.
  - Confirm the webhook arrives at a request-bin style local listener.
  - Check `/api/mentions.html` and `.atom` in a browser and a feed validator.
  - Check all pages at phone width and in dark mode.
