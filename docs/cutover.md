# Cutover from the Ruby app

How to replace the Sinatra app with this one on the production server, and how to go back.

Both apps use the same database schema and the same Redis keys for webmention status URLs and rate limits. So the switch is a web server change, not a data migration, and either app can serve the same data.


## Differences users may notice

* **Sessions don't carry over.** Signed-in users sign in again.
* **Sign-in uses the user's own IndieAuth server**, discovered from their website, instead of indieauth.com.
* **Pingback is gone.** `POST /{user}/xmlrpc` and `POST /webmention?forward=` return 404.
* **FedCM sign-in and the Munin `/stats` endpoints are gone.**
* **Bug fixes that change output**: see "Bugs in the Ruby app fixed by the rewrite" below. The ones API consumers could notice:
  * `.jf2` requests that used to fail with a 500 (links with no author photo, targets without a scheme) now return data.
  * Emoji in names and content are stored correctly instead of as `????` (issue 221). The Ruby app's database connection spoke three-byte `utf8`, so the server replaced each byte of a four-byte character with `?` on the way in. This app connects as `utf8mb4`. Rows damaged before the switch are not rewritten; re-sending the webmention refreshes one.
  * Relative author URLs with a fragment (`about#me`) keep the `#` instead of `%23`.
  * `sort-by=rsvp` sorts every result, not just one page.
  * Re-sending a webmention whose source now answers 410 Gone, or no longer links, deletes it. A timeout or other fetch error no longer does.
  * Sources, token endpoints and web hook URLs on private or loopback addresses are refused (`forbidden_address`).
* **Sign-in requires an https profile URL**, starts from the form on the home page (a `GET /auth/start?me=` link now lands on that form), and signing out is a button rather than a link.
* **Adding a site requires proof.** A new domain is accepted only if its home page already advertises this account's webmention endpoint. The site created automatically from the sign-in domain is exempt; existing sites are untouched.
* **Private webmentions are no longer returned by public target queries** (`/api/mentions?target=` and `/api/count`). They are still included when the owning account queries with its token.
* **New limits.** Source and target URLs longer than 512 bytes are refused (they never fit the database anyway). `per-page` is capped at 1000, `target[]` at 50 values. Webmentions are rate limited per client address and per source host as well as per (source, target) pair, `?debug` more tightly, and a full queue answers 503 with `Retry-After`. Sources on non-web ports, or that redirect to a blocked domain, are refused. Responses over 2 MB are not read.
* **Web hooks carry `X-Webmention-Signature: sha256=<HMAC of the body, keyed with the callback secret>`** in addition to the secret in the body. The payload is unchanged.
* **The API accepts `Authorization: Bearer <token>`** as an alternative to `?token=`.
* **Newly stored content has slightly different whitespace.** The bundled XRay (1.15) puts a newline between block elements in `content.html` and a blank line between paragraphs in `content.text`, where the hosted service (1.4.25) didn't. Everything else it extracts matched the hosted service on 142 recent real webmentions. Stored mentions are unchanged.


## Before the day

1. **Check the server has what the app needs.** It needs PHP 8.2+ with curl, mbstring, pdo_mysql, redis and xmlwriter, plus Composer.

   ```bash
   php -v && php -m | grep -E 'curl|mbstring|pdo_mysql|redis|xmlwriter'
   ```

2. **Deploy the code alongside the Ruby app**, e.g. `/web/sites/webmention.io-php`:

   ```bash
   composer install --no-dev --optimize-autoloader
   cp .env.example .env    # fill in DB, Redis, BASE_URL=https://webmention.io and the CA3DB settings from config.yml
   ```

   Use the same `REDIS_DB` the Ruby app uses (its config.yml has no db, so `0`). Otherwise status URLs handed out just before the switch won't resolve.

   Then check the settings and permissions, since the file holds the database and CA3DB credentials:

   ```bash
   chown deploy:www-data .env && chmod 0640 .env
   chown www-data:www-data logs && chmod 0750 logs   # LOG_DIR; the unit's ReadWritePaths must match
   grep -E '^(APP_DEBUG|TRUST_PROXY|ALLOW_PRIVATE_NETWORK|BASE_URL)=' .env
   ```

   `APP_DEBUG` and `ALLOW_PRIVATE_NETWORK` must be `0` or absent (the checked-out development `.env` has `APP_DEBUG=1` and a LAN `BASE_URL`), `BASE_URL` must be `https://webmention.io`. Behind a proxy, give nginx the proxy's address in `set_real_ip_from` (see `docs/nginx.conf`) and leave `TRUST_PROXY=0`; PHP then sees the visitor's address and both nginx's and the app's per-client rate limits work. Set `TRUST_PROXY=1` only if nginx itself cannot see the proxy headers, and never when the server is reachable directly, because `X-Forwarded-For` is then spoofable. The worker's environment must not carry `http_proxy`/`https_proxy` (the systemd unit clears them; check the shell you test from).

   The database user only needs `SELECT, INSERT, UPDATE, DELETE` on the application database; nothing runs DDL. Redis must listen on localhost only (`bind 127.0.0.1 ::1` in redis.conf), or set a password and put it in the Redis URL the app uses.

   Run `composer audit` to check the locked dependencies against published advisories.

3. **Add the indexes.** Done on production on 2026-09-13. (Both migrations are additive and ran while the Ruby app was live; `LOCK=NONE` keeps `links` writable.)

   ```bash
   mysql webmention < database/migrations/2026-09-13-indexes.sql
   mysql webmention < database/migrations/2026-09-14-links-by-page.sql
   ```

4. **Point a spare hostname at the new app** through nginx (see `docs/nginx.conf`), start one worker, and check it against production:

   ```bash
   bin/parity-check --new=https://new.webmention.io --delay=300 https://aaronparecki.com/ https://indieweb.org/
   ```

   "same" and "fixed" are expected. A "DIFF" on a busy target usually means a mention arrived between the two requests. Rerun it before investigating.

5. **Send a real webmention through the spare hostname.** Use a site with no web hook, and watch the status URL flip to `success`.

6. **Check the avatar URLs.** Stored photos are `https://webmention.io/avatar/…`, and the API rewrites them to `https://avatars.webmention.io/…`. The new app serves nothing under `/avatar/`. If nginx or the CDN on the production host currently handles `/avatar/`, keep that `location` block in the new server config.

   A test deployment on another hostname (say `v2.webmention.io`) that shares the production database must set `CA3DB_AVATAR_URL=https://webmention.io/avatar`. Otherwise the avatars it archives are stored under its own hostname, which the API never rewrites and which stops resolving when that hostname goes away.


## The switch

1. **Start the workers.** At least two, so one slow source doesn't hold up the queue:

   ```bash
   sudo cp docs/webmention-worker@.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now webmention-worker@1 webmention-worker@2 webmention-worker@3
   ```

2. **Swap the nginx site** for webmention.io to the new `root` and PHP-FPM, keeping the TLS config, then reload nginx:

   ```bash
   sudo nginx -t && sudo systemctl reload nginx
   ```

   The Ruby app processed webmentions in threads inside its own process. Any it was still verifying are lost when it stops, and their status URLs stay `pending`. That's a handful at most; senders can resend.

3. **Stop the Ruby app**, i.e. Passenger for that site.

4. **Watch it:**

   ```bash
   tail -f logs/web.log                      # web app: accepted webmentions, rate limits, errors
   tail -f logs/worker-*.log                 # one file per worker: verification results
   journalctl -fu 'webmention-worker@*'      # only start-up failures land here
   redis-cli llen webmention:queue           # should hover near 0
   ```

   Then check that:
   * `curl -s https://webmention.io/api/count?target=https://aaronparecki.com/` returns a count
   * signing in works
   * the dashboard lists recent mentions


## Rolling back

Nothing in the switch changes data in a way the Ruby app can't read, so rolling back is the switch in reverse:

1. Point nginx back at the Ruby app and start Passenger again.
2. Stop the workers:

   ```bash
   sudo systemctl stop 'webmention-worker@*'
   ```

   Any jobs still in `webmention:queue` are not processed by the Ruby app. Their status URLs stay `pending`.

The indexes can stay; the Ruby app's queries benefit from them too.


## Afterwards

* **Fold duplicate sites and add the unique index.** Production has hundreds of `sites` rows that repeat a domain on the same account (the Ruby add-site form never checked). Dry-run, review, apply, then add the index; the index migration fails harmlessly if any duplicates remain.

  ```bash
  php database/migrations/2026-09-14-dedupe-sites.php            # prints what would change
  php database/migrations/2026-09-14-dedupe-sites.php --apply
  mysql webmention < database/migrations/2026-09-14-sites-unique-domain.sql
  ```

  Both are safe with either app live. Afterwards the Ruby app's add-site form errors on a duplicate instead of creating one.
* **Site verification.** Add the columns (the migration also marks every account's sign-in domain as verified, since IndieAuth proved it), then let the checker work through the ~4,000 legacy sites:

  ```bash
  mysql webmention < database/migrations/2026-09-15-site-verification.sql
  tools/verify-sites --limit=20                 # dry run, read the lines
  tools/verify-sites --limit=500 --apply
  ```

  and add a nightly cron entry, e.g. `15 4 * * * cd /web/sites/webmention.io && tools/verify-sites --limit=500 --apply >> logs/verify-sites.log 2>&1`. The backlog clears in about a week; afterwards it only re-tries sites that failed. Domains held by several accounts (376 of them: `t.co`, `bit.ly`, `web.archive.org`, …) show only their verified holders' mentions in public results from the moment the migration runs.

* **Moderation.** Adds `links.status`, `sites.moderation` and the `mutes` table; nothing changes for existing rows.

  ```bash
  mysql webmention < database/migrations/2026-09-15-moderation.sql
  ```

* **Page aliases and fragment folding.** New mentions are filed under the target's canonical URL (redirects, `rel=canonical`, no fragment) with the other forms kept as aliases. Create the table, then fold the ~9,800 pages the Ruby app filed under `#fragment` URLs into their pages (dry run first):

  ```bash
  mysql webmention < database/migrations/2026-09-15-page-aliases.sql
  php database/migrations/2026-09-15-fold-fragment-pages.php
  php database/migrations/2026-09-15-fold-fragment-pages.php --apply
  ```

* **Web hook deliveries.** Adds the `webhook_deliveries` table, the per-site delivery log shown on each site's settings page.

  ```bash
  mysql webmention < database/migrations/2026-09-16-webhook-deliveries.sql
  ```

* **Re-sanitise old GitHub-sourced content.** Before XRay v2.0.1 (fixed and bundled here on 2026-09-13), XRay's GitHub format stored issue and comment bodies as raw HTML in `links.content`. Rows received from `github.com` sources through the hosted XRay may still carry markup that the current parser would strip, and every API format serves `content` as stored. Once traffic is on the new app, run a one-off pass over `links WHERE domain = 'github.com'` that passes `content` through `p3k\XRay\Formats\Format::sanitizeHTML()` and writes back only the rows that change.
* The `debugs` table, `links.notification_id` and the `accounts.pingback_enabled`, `tiktokbot_*` and `xmpp_*` columns are no longer used. They can be dropped whenever convenient; nothing needs them gone.
* `bin/worker` exits after 1000 jobs and systemd starts a fresh one, which keeps memory and connections from going stale.


## Bugs in the Ruby app fixed by the rewrite

Found while porting and in the security review, and behaving differently here on purpose.

1. **`POST /webhook/configure` has no ownership check.** Any logged-in user can set any site's callback URL and receive its webhooks. This app scopes it to the user's sites.
2. **`POST /delete` by id always passes its ownership check**, because it uses `=` instead of `==`. Any user can delete any link. This app adds a real check.
3. **Several POST forms lack CSRF.** `change_token`, `sites/new` and `webhook/configure` have none. This app adds a session CSRF token to every POST.
4. **The `jsonp` callback is echoed raw.** This app restricts it to `[A-Za-z0-9_.$]`.
5. **Any XRay error on re-send hard-deletes an existing mention**, including timeouts, DNS or SSL errors, and 5xx. This app deletes only on `no_link_found`, `not_found` and HTTP 410, and leaves other errors alone. 
6. **Some responses crash:**
   - jf2 on links with a NULL `author_photo`
   - atom/html error responses (Ruby handed a hash to the XML writer)
   - `/delete` with a bad `source`
   - negative `page` values

   This app makes these graceful: error responses for atom/html are JSON, and `page` is clamped to 0.
7. **`sort-by=rsvp` is broken.** Ruby sorts only one page, and only in target mode. This app does it in SQL: `FIELD(type,'rsvp-no','rsvp-interested','rsvp-maybe','rsvp-yes')` followed by created_at.
8. **RSVP values are stored unvalidated.** Live data contains `rsvp-marty mcguire`. This app only accepts yes/no/maybe/interested and otherwise treats the post as a reply/mention. Existing rows are untouched.
9. **`/settings/sites/new` accepts garbage.** This app normalizes the domain (lowercase, strip scheme and path) and rejects empty or duplicate entries.

Found during implementation and the overnight review (2026-09-14):

10. **Server-side request forgery.** Fetches used to happen on the hosted XRay service; now they run next to Redis and the database. A private webmention's token endpoint comes from the source's headers, so a `gopher://` URL could send commands to Redis, where sessions live. Sources could also point at internal addresses. This app sends every outgoing request through `SafeTransport`: http/https only, public addresses only, each redirect checked, resolved address pinned.
11. **Accounts whose username isn't their domain lost webmentions.** The controller accepted `/{domain}/webmention`, but the worker looked the account up again by username only (`target_not_found`). Jobs now carry the account id.
12. **A private webmention's web hook said `post.wm-private: false`**, because Ruby set `is_private` after sending the hook.
13. **A deleted post (HTTP 410) didn't remove the mention.** XRay treats 410 as a success and throws on an empty body. The status is now checked directly.
14. **Emoji were stored as `????`** in names, content and author names. The new connection stores them intact.
15. **A huge `page` value overflowed the offset and returned a 500.**
16. **Listing or counting a very busy page's mentions took ~9s** (http://tantek.com/ has 172k). Covering indexes on `links` bring it under 40ms (`database/migrations/2026-09-14-links-by-page.sql`).
