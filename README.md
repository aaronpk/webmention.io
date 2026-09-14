# Webmention.io

This project is an implementation of the [Webmention](https://webmention.net) protocol. It allows the webmention receiving service to be run separately from the blogging software or website environment, making it easier to manage and integrate with other services.

Say you have a statically-generated website using Jekyll or something similar, you can add the appropriate `<link>` tag pointing to this service, and now you have Webmentions enabled on your static site!

    <link rel="webmention" href="https://webmention.io/example.com/webmention" />

The Webmention protocol also supports specifying the endpoint in the headers,

    Link: <https://webmention.io/example.com/webmention>; rel="webmention"


## Features

* Accept Webmentions for any site by adding an html tag: `<link rel="webmention" href="https://webmention.io/example.com/webmention" />`
* API to get a list of pages linking to your site or a specific page
* Web hooks when a webmention is received or deleted
* [Private Webmentions](https://indieweb.org/Private-Webmention)
* A dashboard to delete webmentions. Deleting one also blocks its source URL for that site, so it is refused if sent again; blocked URLs and blocked domains are listed, and can be unblocked, under Settings › Blocklists.


## API

The API documentation is rendered at [webmention.io/api](https://webmention.io/api), with the rendering script running against the example feed. The same material follows.

### Find links to a specific page

This service provides an API for returning a list of pages that have linked to a given page. For example:

```
GET https://webmention.io/api/mentions.jf2?target=https://indieweb.org

{
  "type": "feed",
  "name": "Webmentions",
  "children": [
    {
      "type": "entry",
      "author": {
        "type": "card",
        "name": "Tantek Çelik",
        "url": "http://tantek.com/",
        "photo": "http://tantek.com/logo.jpg"
      },
      "url": "http://tantek.com/2013/112/t2/milestone-show-indieweb-comments-h-entry-pingback",
      "published": "2013-04-22T15:03:00-07:00",
      "wm-received": "2013-04-25T17:09:33Z",
      "wm-id": 900,
      "wm-source": "http://tantek.com/2013/112/t2/milestone-show-indieweb-comments-h-entry-pingback",
      "wm-target": "https://indieweb.org/",
      "wm-protocol": "webmention",
      "content": {
        "text": "Another milestone: @eschnou automatically shows #indieweb comments with h-entry sent via pingback http://eschnou.com/entry/testing-indieweb-federation-with-waterpigscouk-aaronpareckicom-and--62-24908.html",
        "html": "Another milestone: <a href=\"https://twitter.com/eschnou\">@eschnou</a> automatically shows #indieweb comments with h-entry sent via pingback <a href=\"http://eschnou.com/entry/testing-indieweb-federation-with-waterpigscouk-aaronpareckicom-and--62-24908.html\">http://eschnou.com/entry/testing-indieweb-federation-with-waterpigscouk-aaronpareckicom-and--62-24908.html</a>"
      },
      "mention-of": "https://indieweb.org/",
      "wm-property": "mention-of",
      "wm-private": false
    }
  ]
}
```

### Which URL a mention is filed under

A mention is filed under the target's canonical URL. When a target is first seen, the service fetches it, follows your site's redirects and honours its `rel="canonical"`, and files the mention under the URL it ends up at, as long as that URL is on one of your sites. A `#fragment` in the target is ignored. Every other form that led to the page (the URL as the sender gave it, an old URL that now redirects, a fragment URL) is remembered as an alias, so `target=` queries for any of them return the same mentions, and `wm-target` is the canonical URL. Trailing slashes and `http`/`https` are not treated as equivalent by rule; your site decides, by redirecting. If you change a page's URL later, use "Moved a page?" on the Sites page to re-file its mentions once the old URL redirects.

### Count mentions of a page

```
GET https://webmention.io/api/count?target=https://example.com/page/100

{
  "count": 6,
  "type": {
    "bookmark": 1,
    "mention": 2,
    "rsvp-maybe": 1,
    "rsvp-no": 1,
    "rsvp-yes": 1
  }
}
```

### Find links of a specific type to a specific page

You can include a parameter to limit the returned links to mentions of a specific type:

```
GET https://webmention.io/api/mentions.jf2?target=https://indieweb.org&wm-property=in-reply-to
```

or request multiple types by repeating the query parameter:

```
GET https://webmention.io/api/mentions.jf2?target=https://indieweb.org&wm-property[]=in-reply-to&wm-property[]=rsvp
```

The full list of recognized properties is below:

* in-reply-to
* like-of
* repost-of
* bookmark-of
* mention-of
* rsvp

`mention-of` matches every mention that is not one of the other kinds, including older mentions that were stored without a type. `rsvp` matches all RSVP values.


### Find links to multiple pages

This is useful for retrieving mentions from a post if you've changed the URL.

```
GET https://webmention.io/api/mentions.jf2?target[]=https://indieweb.org/a-blog-post&target[]=https://indieweb.org/a-different-post
```

### Find all links to your domain

You can also find all links to your domain:

```
GET https://webmention.io/api/mentions.jf2?domain=indiewebcamp.com&token=xxxxx
```

(You will see your account's token when you sign in.) The token can also be sent as a header, which keeps it out of URLs and access logs:

```
GET https://webmention.io/api/mentions.jf2?domain=indiewebcamp.com
Authorization: Bearer xxxxx
```

[Private Webmentions](https://indieweb.org/Private-Webmention) are only included in these token-authenticated listings. Queries by `target` are public and never return them.

You can optionally add a `since` parameter to return new webmentions as of a certain date. This is useful to poll for new webmentions you haven't seen yet.

```
GET https://webmention.io/api/mentions.jf2?domain=indiewebcamp.com&token=xxxxx&since=2017-06-01T10:00:00-0700
```


### Find all links to all sites in your account

With no parameters, the API will return all links to any site in your account:

```
GET https://webmention.io/api/mentions?token=xxxxxx
```

### Sorting

You can choose the sorting mechanism to return the list of mentions. The following options are supported:

* `sort-by=created` (default) - Sort by the date the mention was created in the webmention.io database.
* `sort-by=updated` - Sort by the updated date of the page, as seen by webmention.io (not the date the post reports in its microformats data).
* `sort-by=published` - Sort by the published date as reported by the linking page. Some pages don't include published date so this will fall back to created date if published is not present.
* `sort-by=rsvp` - Sort by RSVP value, in the following order: "no", "interested", "maybe", "yes".

By default, results are returned in descending order. You can control the ordering with the `sort-dir` parameter:

* `sort-dir=down` (default) - Newest first, RSVP "yes" first
* `sort-dir=up` - Oldest first, RSVP "no" first


### Paging

Basic paging is supported by using the `per-page` and `page` parameters. For example,

* `?per-page=20&page=0` first page of 20 results
* `?per-page=20&page=1` second page of 20 results

The default number of results per page is 20, and the most is 1000. A query may name up to 50 `target[]` URLs.

Every JSON and jf2 response says where it sits, so a client knows whether to fetch another page:

```json
"paging": {"per-page": 20, "page": 0, "total": 93, "total-pages": 5}
```

`total` is how many mentions match the query in all; `total-pages` follows from `per-page`.


### Finding New Mentions

You can use the `since` or `since_id` parameters to find new mentions retrieved by the service.

* `since=2017-06-01T10:00:00-0700` - pass a full timestamp to the `since` parameter to return links created after that date. This corresponds to the date the link was created in the webmention.io service, not the published date that the page reports.
* `since_id=1000` - pass an ID to return links with a greater ID


### Formats

* `/api/mentions` or `/api/mentions.json` - the original JSON format
* `/api/mentions.jf2` - [jf2](https://jf2.spec.indieweb.org/), the same format sent to web hooks
* `/api/mentions.atom` - an Atom feed
* `/api/mentions.html` - a Microformats h-feed you can subscribe to in a reader

`/api/links` is an alias of `/api/mentions`.

### Export everything

```
GET https://webmention.io/api/export.jf2?token=xxxxx
GET https://webmention.io/api/export.jf2?token=xxxxx&domain=example.com
```

Streams every published mention on your account (or one site) as a single jf2 feed, oldest first, private ones included, as a file download. It is meant for backups and for moving to another service, so it can only be started once every five minutes per account.

### Show mentions on your page

A small script renders a page's mentions with no dependencies: likes, reposts and bookmarks as a row of avatars, replies and mentions as a list. Everything is built from the API data with DOM calls, so nothing in a mention can add markup to your page.

```html
<div data-webmention-target="https://example.com/post/"></div>
<link rel="stylesheet" href="https://webmention.io/assets/webmention-render.css">
<script src="https://webmention.io/js/webmention-render.js" defer></script>
```

Leave out `data-webmention-target` to use the current page's URL. While developing, add `data-webmention-api="https://webmention.io/api/example/mentions.jf2"` to render the example feed. Add `data-webmention-html` if you would rather show each mention's sanitised `content.html` than its plain text. The styles are all under `.webmentions`, so replace or override them freely.


### Example data for testing

To try your code against every kind of mention before your site has received them, point it at the example feed. It returns made-up mentions on `.example` domains, one of each shape the real feed produces: replies, likes, reposts, bookmarks, plain mentions, all four RSVP values, an invite, photo, video and audio posts, a private webmention, a pingback, a legacy mention with no type or author, the pre-2018 `content-type`/`value` content shape, a check-in with `swarm-coins`, syndication links, `rels.canonical`, a bridged reply whose `url` differs from `wm-source`, and long HTML with non-Latin text.

```
GET https://webmention.io/api/example/mentions.jf2?target=https://example.com/post
GET https://webmention.io/api/example/count
```

`target` is echoed as `wm-target`. `wm-property`, `sort-dir`, `per-page`, `page` and `jsonp` work as on the real feed. Names are randomised on every request; add `seed=123` to get the same ones again. `wm-id` values 1001 to 1021 are stable, one per case.

### JSONP

The API also supports JSONP so you can use it to show webmentions on your own sites via JavaScript. Simply add a parameter `jsonp` to the API call, for example, https://webmention.io/api/mentions.jf2?jsonp=f&target=https%3A%2F%2Fwebmention.io


### Atom

You can change `/mentions` to `/mentions.atom` to receive your results in the [Atom] format. Each entry carries the author, a link to the mention, and its content (or summary or name) when there is any:

[Atom]: https://en.wikipedia.org/wiki/Atom_(Web_standard)

```
GET https://webmention.io/api/mentions.atom?token=xxxxxx

<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <id>https://webmention.io/api/mentions.atom</id>
  <title>Mentions</title>
  <updated>2013-04-25T17:09:33+00:00</updated>
  <link href="https://webmention.io/api/mentions.atom"/>
  <author>
    <name>webmention.io</name>
  </author>
  <entry>
    <title>tantek.com mentioned /webmention</title>
    <id>https://webmention.io/api/mention/8675309</id>
    <updated>2013-04-25T17:09:33+00:00</updated>
    <summary>http://tantek.com/2013/113/b1/first-federated-indieweb-comment-thread mentioned http://indiewebcamp.com/webmention</summary>
    <content type="xhtml" xml:lang="en"><div xmlns="http://www.w3.org/1999/xhtml"><p><a href="http://tantek.com/2013/113/b1/first-federated-indieweb-comment-thread">http://tantek.com/2013/113/b1/first-federated-indieweb-comment-thread</a> mentioned <a href="http://indiewebcamp.com/webmention">http://indiewebcamp.com/webmention</a></p></div></content>
  </entry>
</feed>
```


## Sending Webmentions

`POST` a `source` and `target` to your endpoint. The webmention is queued and verified in the background:

```
POST https://webmention.io/example.com/webmention
Content-Type: application/x-www-form-urlencoded

source=https://other.example/post&target=https://example.com/post

HTTP/1.1 201 Created
Location: https://webmention.io/example.com/webmention/9Hn2g4SNIVi1XDeWqj0Z

{
  "status": "queued",
  "summary": "Webmention was queued for processing",
  "location": "https://webmention.io/example.com/webmention/9Hn2g4SNIVi1XDeWqj0Z",
  "source": "https://other.example/post",
  "target": "https://example.com/post"
}
```

The `location` URL returns the processing status for three days. Include a `code` parameter to send a [Private Webmention](https://indieweb.org/Private-Webmention).

Source and target URLs may be at most 512 bytes. Requests are rate limited per (source, target) pair (one every 30 seconds), per client address and per source host; a `429` carries `Retry-After`. If the queue is full the endpoint answers `503` and the webmention should be re-sent later. Adding `debug=1` verifies the webmention synchronously and returns the result in the response; that path is limited to a few requests per minute per client.


## Moderation

Webmentions are published as soon as they verify unless you say otherwise.

* **Hold for review.** Each site has a setting on its page under Sites: publish at once (the default), hold webmentions from *first-time senders* until you have approved one from that source domain, or hold *everything*. Held webmentions wait on the dashboard, out of the API and your web hook, until you approve them (which also sends the web hook) or reject them (which deletes them and blocks the source URL, like the dashboard's delete). The sender is told the webmention succeeded either way.
* **Mute.** Under Settings › Blocklists you can mute a source (the pages that mention you) or an author (everything by someone, wherever it was relayed from) by domain or URL prefix. Muted webmentions are stored but hidden; unmuting brings them back, including ones that arrived while muted. Blocking, by contrast, deletes and refuses.
* **Deletions.** `GET /api/deleted?target=…` (or `?token=…` for everything on your account) lists webmentions that have been deleted, newest first, as `{"wm-id","wm-source","wm-target","wm-deleted"}`, with `since`, `since_id`, `per-page` and `page` as on `/api/mentions`, so a client can prune its cache. This includes webmentions removed because their source stopped linking to you.

In the original JSON format, `verified: true` now also means approved; held and hidden webmentions are never returned.

## Web Hooks

Each site's page under Sites has a callback URL and secret. If a site has a callback URL, every verified webmention is POSTed to it as JSON. When the site has a callback secret, the request also carries `X-Webmention-Signature: sha256=<hex>`, the HMAC-SHA256 of the request body keyed with that secret, so the receiver can verify the delivery without comparing the secret in the body:

```
{
  "secret": "1234abcd",
  "source": "http://rhiaro.co.uk/2015/11/1446953889",
  "target": "http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
  "private": false,
  "post": { ...the jf2 entry, as returned by /api/mentions.jf2... }
}
```

When a webmention is deleted, because the source stopped linking to the target or because you deleted it from the dashboard, the callback receives:

```
{
  "secret": "1234abcd",
  "source": "http://rhiaro.co.uk/2015/11/1446953889",
  "target": "http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
  "private": false,
  "deleted": true
}
```


## FAQ

### Is there a way to replay webhooks

Q: Is there a way to replay webhooks from webmention.io?

A: In short no, however you should be able to get the same data from the API, and make sure you use the .jf2 URLs instead of .json since that's the format it uses for the webhook.


## Development

Written in PHP (8.2+) with no framework: a hand-rolled router, plain PHP templates, PDO for MySQL/MariaDB and ext-redis. The only dependencies are [XRay](https://github.com/aaronpk/XRay), which fetches and parses the source of each webmention, and [indieauth/client](https://github.com/indieweb/indieauth-client-php) for signing in.

Requirements: PHP 8.2 with the curl, mbstring, pdo_mysql, redis and xmlwriter extensions; MySQL or MariaDB; Redis.

```bash
composer install
cp .env.example .env        # then fill it in
mysql webmention < database/schema.sql
php -S 127.0.0.1:8080 -t public public/index.php
bin/worker                  # in another terminal
```

Webmentions are verified by `bin/worker`, which pops jobs from a Redis list. Run as many workers as you need; see `docs/webmention-worker@.service` for a systemd unit and `docs/nginx.conf` for an nginx site.

### Layout

* `public/index.php` - front controller
* `src/Bootstrap.php` - every service and route, wired explicitly
* `src/Controllers/` - HTTP handlers
* `src/Webmention/` - the queue, the worker's `Processor`, XRay fetching, web hooks
* `src/Format/` - the JSON, jf2 and Atom API formats
* `src/Storage/` - database access
* `templates/` - plain PHP templates; values are HTML-escaped before a template sees them

### Tests

The integration tests use a separate database and Redis DB 15, both wiped on every test. Outgoing HTTP is faked, serving the pages in `tests/fixtures`.

```bash
sudo mysql -e "CREATE DATABASE webmention_test; CREATE USER webmention_test@127.0.0.1 IDENTIFIED BY 'secret'; GRANT ALL ON webmention_test.* TO webmention_test@127.0.0.1;"
sudo mysql webmention_test < database/schema.sql
cat > .env.testing <<EOF
DB_HOST=127.0.0.1
DB_NAME=webmention_test
DB_USER=webmention_test
DB_PASS=secret
REDIS_HOST=127.0.0.1
REDIS_DB=15
BASE_URL=https://webmention.io
EOF
composer test
```

`bin/parity-check TARGET_URL...` compares the API of two deployments (by default the live site and a local server) and reports any differences.

`tools/replay-check LINK_ID...` re-verifies stored webmentions with the current processor, without writing anything, and reports which stored fields would come out differently. Add `--compare-xray=https://xray.p3k.io/parse` to separate parser differences from pages that changed since they were received.

### Outgoing requests

Every outgoing request (fetching sources, private webmention tokens, web hooks, Aperture, avatar archiving, IndieAuth discovery, site verification) goes through `SafeTransport`. It only allows http and https, on web ports, to public IP addresses that are not this machine's own; it pins the address it checked for the connection, re-checks every redirect, never follows a redirect for a POST, drops `Authorization` and `Cookie` when a redirect leaves the origin, reads at most 2 MB of any response, and gives the whole redirect chain one time budget. To send webmentions from a local test site, set `ALLOW_PRIVATE_NETWORK=1` in `.env`; never in production.

### Accounts and sites

Signing in requires an `https://` profile URL. The domain signed in with becomes the account's first site. Any further domain must advertise one of the account's endpoints (`/{username}/webmention` or `/d/{domain}/webmention`) on its home page, in a `Link` header or a `<link rel="webmention">`, before it can be added; that is what stops anyone from adding someone else's domain and feeding mentions into its public results. The `sites.public_access` column from the old schema is not enforced (it never was).

Each site records whether it has proved this (`sites.verified_at`). Sites added before the check existed are re-checked by `tools/verify-sites` (run it from cron with `--apply`; it tries the home page and the site's recently mentioned pages) and by the "Check now" button on the Sites page. The proof must come from the domain itself: redirects are followed only while they stay on that host, so a link shortener cannot be claimed by someone whose short link leads to their own site. An unverified site still receives its mentions, but when another account holds a verified site for the same domain, the unverified site's mentions are left out of public `target=` queries, and the `/d/{domain}/webmention` endpoint goes to the verified holder.

### Security notes for the spec

`docs/spec-feedback.md` collects the points from the security review that concern the Webmention spec itself rather than this implementation.

### Migrations

Schema changes live in `database/migrations/` and are applied by hand, in date order. Most are `.sql` files for `mysql`; a `.php` file is a data migration run with `php`, and its header says whether something must run before or after it. `database/schema.sql` is the full current schema.


## License

Copyright 2018-2026 by Aaron Parecki.

Available under the BSD License. See LICENSE.txt
