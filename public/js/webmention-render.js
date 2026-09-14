/*
 * Show the webmentions a page has received, from the webmention.io API.
 *
 *   <div data-webmention-target="https://example.com/post/"></div>
 *   <link rel="stylesheet" href="https://webmention.io/assets/webmention-render.css">
 *   <script src="https://webmention.io/js/webmention-render.js" defer></script>
 *
 * Optional attributes on the element:
 *   data-webmention-api   the feed to read (default https://webmention.io/api/mentions.jf2;
 *                         use https://webmention.io/api/example/mentions.jf2 while developing)
 *   data-webmention-html  render content.html instead of the plain text (only if you trust it)
 *   data-webmention-limit how many to fetch (default 100)
 *
 * Likes, reposts and bookmarks become a row of avatars; replies, mentions and
 * RSVPs become a list with the author, what they wrote, and a link to it.
 * Everything is built with DOM APIs, never HTML strings, so nothing in a
 * mention can inject markup into your page. No dependencies.
 */
(function () {
  'use strict';

  var DEFAULT_API = 'https://webmention.io/api/mentions.jf2';
  var FACEPILE = { 'like-of': 'Likes', 'repost-of': 'Reposts', 'bookmark-of': 'Bookmarks' };
  var VERBS = { 'in-reply-to': 'replied', 'mention-of': 'mentioned this', 'rsvp': 'RSVPed' };

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function isHttp(url) {
    return typeof url === 'string' && /^https?:\/\//i.test(url);
  }

  function link(href, className, text) {
    if (!isHttp(href)) return el('span', className, text);
    var a = el('a', className, text);
    a.href = href;
    a.rel = 'nofollow noopener ugc';
    return a;
  }

  function avatar(author) {
    var img = el('img', 'wm-avatar');
    img.alt = '';
    img.loading = 'lazy';
    img.width = 48;
    img.height = 48;
    if (isHttp(author.photo)) img.src = author.photo;
    else img.className += ' wm-avatar-empty';
    return img;
  }

  function authorName(author) {
    return (author && author.name) || (author && author.url && author.url.replace(/^https?:\/\//, '').replace(/\/$/, '')) || 'Someone';
  }

  function when(entry) {
    var iso = entry.published || entry['wm-received'];
    if (!iso) return null;
    var date = new Date(iso);
    if (isNaN(date.getTime())) return null;
    var time = el('time', 'wm-date', date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }));
    time.dateTime = iso;
    return time;
  }

  function facepile(label, entries) {
    var section = el('section', 'wm-facepile');
    section.appendChild(el('h3', 'wm-heading', label + ' (' + entries.length + ')'));
    var list = el('ul', 'wm-faces');
    entries.forEach(function (entry) {
      var item = el('li');
      var a = link(entry.url || entry['wm-source'], 'wm-face');
      a.title = authorName(entry.author);
      a.appendChild(avatar(entry.author || {}));
      item.appendChild(a);
      list.appendChild(item);
    });
    section.appendChild(list);
    return section;
  }

  function reply(entry, allowHtml) {
    var item = el('li', 'wm-reply');
    var author = entry.author || {};

    var head = el('div', 'wm-reply-head');
    var who = link(author.url, 'wm-author');
    who.appendChild(avatar(author));
    who.appendChild(el('span', 'wm-author-name', authorName(author)));
    head.appendChild(who);

    var verb = VERBS[entry['wm-property']] || 'mentioned this';
    if (entry['wm-property'] === 'rsvp' && entry.rsvp) verb = 'RSVPed ' + entry.rsvp;
    head.appendChild(el('span', 'wm-verb', verb));

    var date = when(entry);
    if (date) {
      var dateLink = link(entry.url || entry['wm-source'], 'wm-permalink');
      dateLink.appendChild(date);
      head.appendChild(dateLink);
    }
    item.appendChild(head);

    if (entry.name && entry['wm-property'] !== 'in-reply-to') {
      item.appendChild(el('p', 'wm-name', entry.name));
    }

    var content = entry.content || {};
    if (allowHtml && content.html) {
      // Only when the page owner opted in: the HTML was sanitised by
      // webmention.io, but it is still someone else's markup.
      var body = el('div', 'wm-content');
      body.innerHTML = content.html;
      item.appendChild(body);
    } else if (content.text) {
      var text = content.text.length > 600 ? content.text.slice(0, 600).replace(/\s+\S*$/, '') + '…' : content.text;
      item.appendChild(el('p', 'wm-content', text));
    } else if (entry.summary && entry.summary.value) {
      item.appendChild(el('p', 'wm-content', entry.summary.value));
    }

    return item;
  }

  function render(container, feed) {
    var entries = (feed && feed.children) || [];
    if (!entries.length) return;

    var byProperty = {};
    entries.forEach(function (entry) {
      var property = entry['wm-property'] || 'mention-of';
      (byProperty[property] = byProperty[property] || []).push(entry);
    });

    var root = el('div', 'webmentions');
    root.appendChild(el('h2', 'wm-title', 'Responses'));

    Object.keys(FACEPILE).forEach(function (property) {
      if (byProperty[property]) root.appendChild(facepile(FACEPILE[property], byProperty[property]));
    });

    var conversation = entries.filter(function (entry) { return !FACEPILE[entry['wm-property']]; });
    if (conversation.length) {
      var list = el('ol', 'wm-replies');
      var allowHtml = container.hasAttribute('data-webmention-html');
      conversation.forEach(function (entry) { list.appendChild(reply(entry, allowHtml)); });
      root.appendChild(list);
    }

    container.textContent = '';
    container.appendChild(root);
  }

  function load(container) {
    var target = container.getAttribute('data-webmention-target') || window.location.href.split('#')[0];
    var api = container.getAttribute('data-webmention-api') || DEFAULT_API;
    var limit = parseInt(container.getAttribute('data-webmention-limit') || '100', 10) || 100;
    var url = api + (api.indexOf('?') === -1 ? '?' : '&') + 'target=' + encodeURIComponent(target) + '&per-page=' + limit + '&sort-dir=up';

    fetch(url, { headers: { Accept: 'application/json' } })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (feed) { if (feed) render(container, feed); })
      .catch(function () { /* leave the page as it was */ });
  }

  function start() {
    var containers = document.querySelectorAll('[data-webmention-target], [data-webmention]');
    Array.prototype.forEach.call(containers, load);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
