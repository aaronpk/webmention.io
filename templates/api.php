<?php
/**
 * The API documentation. Static apart from the base URL; the demo section
 * runs the rendering script against the example feed.
 *
 * @var string $base_url
 */
?>
<section class="hero">
    <div>
        <h1>API documentation</h1>
        <p class="lede">Read the webmentions this service has collected for your pages, show them on your site, follow them as a feed, or take everything with you.</p>
        <ul class="toc">
            <li><a href="#basics">Basics</a></li>
            <li><a href="#render">Show mentions on your page</a></li>
            <li><a href="#mentions">List mentions</a></li>
            <li><a href="#count">Count mentions</a></li>
            <li><a href="#feeds">Feeds</a></li>
            <li><a href="#example">Example data</a></li>
            <li><a href="#export">Export</a></li>
            <li><a href="#deleted">Deleted mentions</a></li>
            <li><a href="#webhooks">Web hooks</a></li>
        </ul>
    </div>
</section>

<section class="doc" id="basics">
    <h2><a href="#basics">Basics</a></h2>
    <p>Every endpoint is under <code><?= $base_url ?>/api/</code> and answers <code>GET</code> requests. Responses are JSON unless you ask for another format by extension.
        The API sends <code>Access-Control-Allow-Origin: *</code>, so you can call it from a browser as well as from a server.</p>

    <h3 id="formats"><a href="#formats">Formats</a></h3>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Path</th><th>Format</th></tr></thead>
        <tbody>
            <tr><td><code>/api/mentions.jf2</code></td><td><a href="https://jf2.spec.indieweb.org/">jf2</a>. The recommended format, and the one used everywhere in these docs and in web hook payloads.</td></tr>
            <tr><td><code>/api/mentions</code> or <code>.json</code></td><td>The original JSON format, kept for existing clients. Same query parameters. <code>/api/links</code> is an alias.</td></tr>
            <tr><td><code>/api/mentions.atom</code></td><td>An Atom feed. See <a href="#feeds">Feeds</a>.</td></tr>
            <tr><td><code>/api/mentions.html</code></td><td>A Microformats h-feed you can follow in a reader.</td></tr>
        </tbody>
    </table></div>

    <h3 id="auth"><a href="#auth">Your token</a></h3>
    <p>Queries for a single page (by <code>target</code>) are public and need nothing else. Queries for a whole site or account need the token shown on your <a href="/settings">Settings</a> page.
        Send it as a query parameter or, to keep it out of URLs and access logs, as a header:</p>
    <pre><code>GET <?= $base_url ?>/api/mentions.jf2?domain=example.com&amp;token=xxxxx

GET <?= $base_url ?>/api/mentions.jf2?domain=example.com
Authorization: Bearer xxxxx</code></pre>
    <p>The token can only read the mentions sent to your sites. It cannot change anything on your account.
        <a href="https://indieweb.org/Private-Webmention">Private webmentions</a> are only ever included in token-authenticated listings; public queries by <code>target</code> never return them.</p>

    <h3 id="canonical"><a href="#canonical">Which URL a mention is filed under</a></h3>
    <p>A mention is filed under the target's canonical URL. When a target is first seen, the service fetches it, follows your site's redirects and honours its <code>rel="canonical"</code>,
        and files the mention under the URL it ends up at, as long as that URL is on one of your sites. A <code>#fragment</code> in the target is ignored.
        Every other form that led to the page is remembered as an alias, so <code>target=</code> queries for any of them return the same mentions, and <code>wm-target</code> is always the canonical URL.
        Trailing slashes and <code>http</code>/<code>https</code> are not treated as equivalent by rule; your site decides, by redirecting.
        If you move a page later, use "Moved a page?" on the <a href="/settings/sites">Sites</a> page to re-file its mentions once the old URL redirects.</p>
</section>

<section class="doc" id="render">
    <h2><a href="#render">Show mentions on your page</a></h2>
    <p>The quickest way to show what people have said about a page is the rendering script. It has no dependencies and works on any static site.
        Likes, reposts and bookmarks become a row of avatars; replies, mentions and RSVPs become a list with the author, what they wrote, and a link to the original.</p>
    <pre><code>&lt;div data-webmention-target="https://example.com/post/"&gt;&lt;/div&gt;
&lt;link rel="stylesheet" href="<?= $base_url ?>/assets/webmention-render.css"&gt;
&lt;script src="<?= $base_url ?>/js/webmention-render.js" defer&gt;&lt;/script&gt;</code></pre>
    <p>Put the <code>div</code> where the responses should appear. Nothing is added to the page when there are no mentions.
        Everything is built from the API data with DOM calls, never from HTML strings, so nothing in a mention can add markup to your page.</p>

    <h3 id="render-options"><a href="#render-options">Options</a></h3>
    <p>All options are attributes on the <code>div</code>.</p>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Attribute</th><th>Meaning</th></tr></thead>
        <tbody>
            <tr><td><code>data-webmention-target</code></td><td>The page to show mentions of. Leave the value empty to use the current page's URL, which suits a template used on every post.</td></tr>
            <tr><td><code>data-webmention-api</code></td><td>The feed to read. Defaults to <code><?= $base_url ?>/api/mentions.jf2</code>. Point it at <code><?= $base_url ?>/api/example/mentions.jf2</code> while developing to see every kind of mention before your site has received any.</td></tr>
            <tr><td><code>data-webmention-html</code></td><td>Show each mention's <code>content.html</code> instead of its plain text. The HTML was sanitised by webmention.io, but it is still someone else's markup, so this is off unless you add the attribute.</td></tr>
            <tr><td><code>data-webmention-limit</code></td><td>How many mentions to fetch, oldest first. Default 100, at most 1000.</td></tr>
        </tbody>
    </table></div>

    <h3 id="render-demo"><a href="#render-demo">Live demo</a></h3>
    <p>This is the script running on this page against the <a href="#example">example feed</a>:</p>
    <pre><code>&lt;div data-webmention-target="https://example.com/post"
     data-webmention-api="<?= $base_url ?>/api/example/mentions.jf2"&gt;&lt;/div&gt;</code></pre>
    <div class="demo">
        <div data-webmention-target="https://example.com/post" data-webmention-api="/api/example/mentions.jf2?seed=7"></div>
    </div>

    <h3 id="render-style"><a href="#render-style">Styling</a></h3>
    <p>The stylesheet is small and everything in it is under <code>.webmentions</code>, so override what you like or leave the stylesheet out and write your own.
        The classes are <code>.wm-title</code>, <code>.wm-facepile</code> and <code>.wm-heading</code>, <code>.wm-faces</code>/<code>.wm-face</code>/<code>.wm-avatar</code> for the avatar rows,
        and <code>.wm-replies</code>/<code>.wm-reply</code> with <code>.wm-reply-head</code>, <code>.wm-author</code>, <code>.wm-verb</code>, <code>.wm-permalink</code>, <code>.wm-name</code> and <code>.wm-content</code> for the list.</p>

    <h3 id="render-fetch"><a href="#render-fetch">Or roll your own</a></h3>
    <p>If you would rather control the markup, the API is easy to call directly. To show a count:</p>
    <pre><code>fetch("<?= $base_url ?>/api/count?target=https://example.com/post/")
    .then(response =&gt; response.json())
    .then(data =&gt; console.log(data.count, data.type));</code></pre>
    <p>To list the mentions themselves, with the fields described under <a href="#mentions">List mentions</a>:</p>
    <pre><code>fetch("<?= $base_url ?>/api/mentions.jf2?target=https://example.com/post/&amp;sort-dir=up")
    .then(response =&gt; response.json())
    .then(feed =&gt; feed.children.forEach(mention =&gt; console.log(mention.author.name, mention["wm-property"])));</code></pre>
    <p>Set <code>textContent</code> rather than <code>innerHTML</code> when you put a mention's fields on your page; only <code>content.html</code> is meant to be inserted as markup, and only if you choose to.
        The older <code>jsonp</code> parameter is still supported for pages that cannot use <code>fetch</code>.</p>
</section>

<section class="doc" id="mentions">
    <h2><a href="#mentions">List mentions</a></h2>
    <pre><code>GET <?= $base_url ?>/api/mentions.jf2?target=https://example.com/post/</code></pre>
    <p>Returns the mentions of one page, of several pages, of a whole site, or of everything on your account, depending on the parameters.</p>

    <h3 id="mentions-params"><a href="#mentions-params">Parameters</a></h3>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Parameter</th><th>Meaning</th></tr></thead>
        <tbody>
            <tr><td><code>target</code></td><td>The page to list mentions of. Repeat as <code>target[]</code> to combine several pages, up to 50, which is useful when a post has had more than one URL. A target with no scheme, such as <code>//example.com/post</code>, matches both http and https.</td></tr>
            <tr><td><code>domain</code></td><td>Every mention of one of your sites. Needs your <a href="#auth">token</a>.</td></tr>
            <tr><td><code>token</code></td><td>Your token. With no <code>target</code> and no <code>domain</code>, lists everything on your account.</td></tr>
            <tr><td><code>wm-property</code></td><td>Only mentions of one kind: <code>in-reply-to</code>, <code>like-of</code>, <code>repost-of</code>, <code>bookmark-of</code>, <code>mention-of</code> or <code>rsvp</code>. Repeat as <code>wm-property[]</code> for several. <code>mention-of</code> matches everything that is not one of the other kinds, including older mentions stored without a type; <code>rsvp</code> matches every RSVP value.</td></tr>
            <tr><td><code>since</code></td><td>Only mentions received after this time, such as <code>2026-06-01T10:00:00-0700</code>. This is when webmention.io received the mention, not the date the post reports.</td></tr>
            <tr><td><code>since_id</code></td><td>Only mentions with a <code>wm-id</code> greater than this. The simplest way to poll for new mentions: remember the largest id you have seen.</td></tr>
            <tr><td><code>sort-by</code></td><td><code>created</code> (default, when it was received), <code>updated</code>, <code>published</code> (the date the linking page reports, falling back to created), or <code>rsvp</code> (no, interested, maybe, yes).</td></tr>
            <tr><td><code>sort-dir</code></td><td><code>down</code> (default, newest first) or <code>up</code> (oldest first).</td></tr>
            <tr><td><code>per-page</code></td><td>How many to return. Default 20, at most 1000.</td></tr>
            <tr><td><code>page</code></td><td>Which page of results, counting from 0.</td></tr>
            <tr><td><code>jsonp</code></td><td>Wrap the response in a call to this function.</td></tr>
        </tbody>
    </table></div>

    <h3 id="mentions-response"><a href="#mentions-response">Response</a></h3>
    <p>A jf2 feed. The mentions are in <code>children</code>, and <code>paging</code> says how many there are in all so you know whether to ask for another page.</p>
    <pre><code>{
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
        "html": "Another milestone: &lt;a href=\"https://twitter.com/eschnou\"&gt;@eschnou&lt;/a&gt; automatically shows #indieweb comments…",
        "text": "Another milestone: @eschnou automatically shows #indieweb comments…"
      },
      "mention-of": "https://indieweb.org/",
      "wm-property": "mention-of",
      "wm-private": false
    }
  ],
  "paging": {
    "per-page": 20,
    "page": 0,
    "total": 93,
    "total-pages": 5
  }
}</code></pre>

    <h3 id="mentions-fields"><a href="#mentions-fields">Fields on a mention</a></h3>
    <p>Fields starting with <code>wm-</code> come from webmention.io; the rest are the <a href="https://microformats.org/wiki/h-entry">h-entry</a> properties parsed from the linking page, so they are present only when the page provides them.</p>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Field</th><th>Meaning</th></tr></thead>
        <tbody>
            <tr><td><code>wm-id</code></td><td>A stable numeric id, increasing over time. Use it with <code>since_id</code>.</td></tr>
            <tr><td><code>wm-source</code></td><td>The URL the webmention was sent from: the page that links to yours.</td></tr>
            <tr><td><code>wm-target</code></td><td>The canonical URL of your page that was linked to.</td></tr>
            <tr><td><code>wm-property</code></td><td>What kind of mention this is: <code>in-reply-to</code>, <code>like-of</code>, <code>repost-of</code>, <code>bookmark-of</code>, <code>rsvp</code> or <code>mention-of</code>. The same name also appears as a property holding your URL, so an entry with <code>"like-of": "https://example.com/post/"</code> is a like of that post.</td></tr>
            <tr><td><code>wm-received</code></td><td>When webmention.io received it, in UTC.</td></tr>
            <tr><td><code>wm-private</code></td><td><code>true</code> for a private webmention. Only present in token-authenticated listings.</td></tr>
            <tr><td><code>wm-protocol</code></td><td><code>webmention</code> or <code>pingback</code>.</td></tr>
            <tr><td><code>author</code></td><td>An h-card with <code>name</code>, <code>url</code> and <code>photo</code>, any of which may be empty.</td></tr>
            <tr><td><code>url</code></td><td>The post's own permalink. Usually the same as <code>wm-source</code>, but differs for bridged posts, for example a reply sent on behalf of a social media post.</td></tr>
            <tr><td><code>published</code></td><td>The date the post reports, as written, with its own timezone.</td></tr>
            <tr><td><code>content</code></td><td>An object with <code>text</code> and, when the post had markup, sanitised <code>html</code>. Very long content is cut short when it is stored.</td></tr>
            <tr><td><code>name</code></td><td>The post's title, when it has one. Notes usually have none.</td></tr>
            <tr><td><code>summary</code></td><td>The post's summary as <code>{"content-type": "text/plain", "value": "…"}</code>, when it has one.</td></tr>
            <tr><td><code>rsvp</code></td><td><code>yes</code>, <code>no</code>, <code>maybe</code> or <code>interested</code>, on RSVPs.</td></tr>
            <tr><td><code>photo</code>, <code>video</code>, <code>audio</code></td><td>Media attached to the post, as lists of URLs.</td></tr>
            <tr><td><code>syndication</code></td><td>Other copies of the post, as a list of URLs.</td></tr>
        </tbody>
    </table></div>
    <p>To walk through everything, either step <code>page</code> from 0 to <code>total-pages - 1</code>, or fetch with <code>sort-dir=up</code> and repeat with <code>since_id</code> set to the last <code>wm-id</code> you saw. The second way is stable while new mentions arrive.
        The <a href="#example">example feed</a> returns one of every shape a mention can take, so you can test your code against all of them.</p>
</section>

<section class="doc" id="count">
    <h2><a href="#count">Count mentions</a></h2>
    <pre><code>GET <?= $base_url ?>/api/count?target=https://example.com/post/

{
  "count": 6,
  "type": {
    "like": 2,
    "mention": 2,
    "reply": 1,
    "rsvp-yes": 1
  }
}</code></pre>
    <p>The total number of mentions of a page, and the count of each kind. The keys are the short type names: <code>like</code>, <code>repost</code>, <code>bookmark</code>, <code>reply</code>, <code>mention</code> and <code>rsvp-yes</code>, <code>rsvp-no</code>, <code>rsvp-maybe</code> and <code>rsvp-interested</code>.
        <code>target</code> and <code>target[]</code> work as on <a href="#mentions">List mentions</a>. Private webmentions are not counted.</p>
</section>

<section class="doc" id="feeds">
    <h2><a href="#feeds">Feeds</a></h2>
    <p>Everything on your account is also available as a feed you can subscribe to, so new mentions arrive in your reader. Both URLs, with your token filled in, are on the <a href="/settings">Settings</a> page.</p>
    <pre><code>GET <?= $base_url ?>/api/mentions.atom?token=xxxxx
GET <?= $base_url ?>/api/mentions.html?token=xxxxx</code></pre>
    <p>Each Atom entry carries the author's name and URL, a link to the mention, its published date, and the mention's content as HTML, falling back to its summary or title.
        The h-feed version shows the same in a page marked up with Microformats, for readers such as <a href="https://aperture.p3k.io">Aperture</a>.
        Every parameter from <a href="#mentions">List mentions</a> works on both, so a feed of just the replies to one post is <code>/api/mentions.atom?target=…&amp;wm-property=in-reply-to</code>.</p>
</section>

<section class="doc" id="example">
    <h2><a href="#example">Example data</a></h2>
    <pre><code>GET <?= $base_url ?>/api/example/mentions.jf2?target=https://example.com/post
GET <?= $base_url ?>/api/example/count</code></pre>
    <p>Made-up mentions on <code>.example</code> domains, one of each shape the real feed produces: replies, likes, reposts, bookmarks, plain mentions, all four RSVP values, an invite, photo, video and audio posts,
        a private webmention, a pingback, a legacy mention with no type or author, the pre-2018 <code>content-type</code>/<code>value</code> content shape, a check-in, syndication links, a bridged reply whose <code>url</code> differs from <code>wm-source</code>, and long HTML with non-Latin text.
        Use it to build and test before your site has received anything.</p>
    <p><code>target</code> is echoed as <code>wm-target</code>. <code>wm-property</code>, <code>sort-dir</code>, <code>per-page</code>, <code>page</code> and <code>jsonp</code> work as on the real feed, and the response carries <code>paging</code>.
        Names are randomised on every request; add <code>seed=123</code> to get the same ones again. The <code>wm-id</code> values 1001 to 1021 are stable, one per case.</p>
</section>

<section class="doc" id="export">
    <h2><a href="#export">Export</a></h2>
    <pre><code>GET <?= $base_url ?>/api/export.jf2?token=xxxxx
GET <?= $base_url ?>/api/export.jf2?token=xxxxx&amp;domain=example.com</code></pre>
    <p>Every published mention on your account, or on one site, as a single jf2 feed in the same shape as <a href="#mentions">List mentions</a>: oldest first, private mentions included, mentions you have deleted or that are awaiting moderation left out.
        The response is streamed as a file download named <code>webmentions-example.com-2026-09-14.jf2.json</code>, with one mention per line inside the <code>children</code> array so the file can be read line by line as well as parsed whole.</p>
    <p>An export reads everything on the account, and for a long-lived site that can be hundreds of megabytes, so it can be started once every five minutes per account. A second attempt inside that window gets a <code>429</code> with a <code>Retry-After</code> header.
        It is meant for backups and for moving to another service; to keep a copy up to date, poll <a href="#mentions">List mentions</a> with <code>since_id</code> instead.</p>
</section>

<section class="doc" id="deleted">
    <h2><a href="#deleted">Deleted mentions</a></h2>
    <pre><code>GET <?= $base_url ?>/api/deleted.jf2?target=https://example.com/post/
GET <?= $base_url ?>/api/deleted.jf2?token=xxxxx</code></pre>
    <p>Mentions that have been removed, newest first, whether because the linking page stopped linking to you, because you deleted or rejected them on the dashboard, or because you blocked their source.
        Each item is <code>{"wm-id", "wm-source", "wm-target", "wm-deleted"}</code>. <code>since</code>, <code>since_id</code>, <code>per-page</code> and <code>page</code> work as on <a href="#mentions">List mentions</a>.
        A client that keeps its own copy of its mentions can poll this to prune them.</p>
</section>

<section class="doc" id="webhooks">
    <h2><a href="#webhooks">Web hooks</a></h2>
    <p>Instead of polling, give a site a callback URL on its settings page under <a href="/settings/sites">Sites</a>, and every webmention that verifies is POSTed to it as JSON as it arrives. Webmentions that fail verification, and ones held for moderation until you approve them, are not sent.
        The <code>post</code> is the same jf2 entry the API returns, so one parser handles both.</p>
    <pre><code>POST https://example.com/webmention/hook
Content-Type: application/json
X-Webmention-Signature: sha256=2f7e…

{
  "secret": "1234abcd",
  "source": "http://rhiaro.co.uk/2015/11/1446953889",
  "target": "http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
  "private": false,
  "post": {
    "type": "entry",
    "author": {
      "type": "card",
      "name": "Amy Guy",
      "photo": "https://avatars.webmention.io/rhiaro.co.uk/829d3f6e7083d7ee8bd7b20363da84d88ce5b4ce094f78fd1b27d8d3dc42560e.png",
      "url": "http://rhiaro.co.uk/about#me"
    },
    "url": "http://rhiaro.co.uk/2015/11/1446953889",
    "published": "2015-11-08T03:38:09+00:00",
    "wm-received": "2015-11-08T03:40:12Z",
    "wm-id": 900,
    "wm-source": "http://rhiaro.co.uk/2015/11/1446953889",
    "wm-target": "http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
    "wm-protocol": "webmention",
    "name": "repost of http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
    "repost-of": "http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
    "wm-property": "repost-of",
    "wm-private": false
  }
}</code></pre>
    <p><code>wm-property</code>, and the matching property inside <code>post</code>, says what kind of post it is: <code>in-reply-to</code>, <code>like-of</code>, <code>repost-of</code>, <code>bookmark-of</code>, <code>mention-of</code> or <code>rsvp</code>, as described under <a href="#mentions-fields">Fields on a mention</a>.
        <code>private</code> is <code>true</code> for a private webmention.</p>

    <h3 id="webhooks-secret"><a href="#webhooks-secret">Checking it came from webmention.io</a></h3>
    <p>If the site has a callback secret, it is sent as <code>secret</code> in the body and the request also carries <code>X-Webmention-Signature: sha256=&lt;hex&gt;</code>, the HMAC-SHA256 of the raw request body keyed with that secret.
        Compare the signature rather than the secret, and your endpoint never has to read the body before trusting it.</p>

    <h3 id="webhooks-deleted"><a href="#webhooks-deleted">Deletions</a></h3>
    <p>When a webmention is later deleted, because the linking page was removed or stopped linking to you, or because you deleted or rejected it on the dashboard, the callback receives the same <code>source</code> and <code>target</code> with <code>deleted</code> and no <code>post</code>:</p>
    <pre><code>{
  "secret": "1234abcd",
  "source": "http://rhiaro.co.uk/2015/11/1446953889",
  "target": "http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
  "private": false,
  "deleted": true
}</code></pre>
    <p>Deliveries are not retried on their own. Each site's settings page lists its last 50 deliveries with the status or error, the time taken, and the request and response bodies, and can re-send any of them or send the newest webmention as a test.
        If your endpoint was down for longer, fetch what it missed from <a href="#mentions">List mentions</a> with <code>since_id</code> and from <a href="#deleted">Deleted mentions</a>; both return the same data the web hook carries.</p>
</section>

<link rel="stylesheet" href="/assets/webmention-render.css">
<script src="/js/webmention-render.js" defer></script>
