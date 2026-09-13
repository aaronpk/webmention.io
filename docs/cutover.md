# Cutover from the Ruby app

How to replace the Sinatra app with this one on the production server, and how to go back.

Both apps use the same database schema and the same Redis keys for webmention status URLs and rate limits. So the switch is a web server change, not a data migration, and either app can serve the same data.


## Differences users may notice

* **Sessions don't carry over.** Signed-in users sign in again.
* **Sign-in uses the user's own IndieAuth server**, discovered from their website, instead of indieauth.com.
* **Pingback is gone.** `POST /{user}/xmlrpc` and `POST /webmention?forward=` return 404.
* **FedCM sign-in and the Munin `/stats` endpoints are gone.**
* **Bug fixes that change output**: see "Major bugs fixed" in PLAN.md. The ones API consumers could notice:
  * `.jf2` requests that used to fail with a 500 (links with no author photo, targets without a scheme) now return data.
  * Emoji in names and content are stored correctly instead of as `????`.
  * Relative author URLs with a fragment (`about#me`) keep the `#` instead of `%23`.
  * `sort-by=rsvp` sorts every result, not just one page.
  * Re-sending a webmention whose source now answers 410 Gone, or no longer links, deletes it. A timeout or other fetch error no longer does.
  * Sources, token endpoints and web hook URLs on private or loopback addresses are refused (`forbidden_address`).
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

3. **Add the indexes.** Both migrations are additive and can run while the Ruby app is live; `LOCK=NONE` keeps `links` writable. On a copy of production (2M links) each took under 15 seconds.

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
   tail -f logs/webmention.log               # web app errors
   journalctl -fu 'webmention-worker@*'      # webmention processing
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

* The `debugs` table, `links.notification_id` and the `accounts.pingback_enabled`, `tiktokbot_*` and `xmpp_*` columns are no longer used. They can be dropped whenever convenient; nothing needs them gone.
* `bin/worker` exits after 1000 jobs and systemd starts a fresh one, which keeps memory and connections from going stale.
