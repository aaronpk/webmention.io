# Notes for the Webmention spec

Things the security review of this receiver turned up that the
[Webmention Recommendation](https://www.w3.org/TR/webmention/) is silent on
or could say more clearly. Each entry names the spec section, the behaviour
seen in this implementation (and mostly in the Ruby app before it), and a
suggestion. Kept here so they can be raised with the spec's maintainers
later; the app-side fixes are described in the git history.

## 1. Receiver-side SSRF is not covered

**Section 4.3, "Avoid sending Webmentions to localhost"** only tells *senders*
not to send to loopback addresses. The far larger exposure is the receiver:
it performs a GET on a `source` URL chosen by a stranger (3.2.2), follows
redirects, and in practice also fetches author pages, avatars and, with the
Private Webmention extension, POSTs to a token endpoint discovered from that
stranger's response headers. A receiver on a server that also runs a
database, a cache or a cloud metadata service can be made to talk to them.

Suggest a receiver-side paragraph: resolve the host first and refuse
loopback, private, link-local and other special-purpose ranges (including
the IPv4 forms embedded in IPv6 addresses); connect only to the address that
was checked; re-check every redirect hop; restrict ports to web ports; and
never forward credentials to a host the receiver did not choose.

## 2. Limits on fetching should name body size and content type

**Section 4.2, "Limits on GET requests"** says receivers SHOULD "place limits
on the amount of data and time they spend fetching unverified source URLs".
Concretely useful limits are: a maximum response body size, enforced while
downloading rather than after; a time budget for the whole redirect chain,
not per request (eight redirects at the per-request timeout is a long time);
and rejecting content types the receiver cannot verify before downloading
them (a HEAD first, which 4.2 already permits). The example redirect limit of
20 is generous; this receiver follows 8.

## 3. Redirects launder the source

**Section 3.2.2** requires following redirects when fetching `source` but
says nothing about which URL the result is attributed to, or which URL policy
applies to. A receiver that stores and moderates by the submitted `source`
lets a blocked host reappear behind a fresh redirecting host. Suggest:
moderation and blocklists apply to every URL in the chain, and a receiver MAY
treat a redirect to another origin as a different source (or reject it).

## 4. Properties read from the source are unconstrained

**Section 4.1** requires data picked up from `source` to be encoded or
filtered against XSS and CSRF. It does not mention that the *values* are
also attacker-chosen: a page can claim any `u-url`, any `u-author` name,
photo and URL, and any `rel=canonical`. A receiver that renders the entry's
`url` as the link to the mention is a link-laundering and impersonation
service. Suggest noting that URLs and identities picked up from the source
are unverified, and that a receiver SHOULD show them only when they share an
origin with the fetched source, or always alongside the fetched source URL.

## 5. Deletion on 404

**Section 3.2.4** says a receiver SHOULD delete an existing Webmention when
re-verification gets a 410 Gone. Many implementations (this one included)
also delete on 404. Anyone can re-submit a (source, target) pair, and many
hosts answer 404 rather than 401 or 403 to an unauthenticated fetch of a
private page, so with the Private Webmention extension a third party can
delete someone else's private mention by re-sending it without a code.
Suggest: delete on 410 only, or on 404 only when re-verification used the
same authentication as the original.

## 6. Amplification

**Section 4.1** recommends asynchronous processing to prevent DoS, but a
receiver is also a reflector: each accepted (source, target) pair costs at
least one outgoing request, and pairs are free to mint by varying a query
parameter. Suggest recommending rate limits per client and per source
origin, and a bounded queue that refuses (with 429 or 503) rather than
growing without limit.

## 7. Target validity

**Section 3.2.1** says the receiver SHOULD check that `target` is a valid
resource it can accept Webmentions for. Done at receipt time this is
unbounded work per request; done at verification time it is free. Suggest
saying it MAY be deferred, and that a target answering 404 or 410 SHOULD NOT
create any state on the receiver.

## 8. Source equals target

**Section 3.2.1** requires rejecting a request whose `source` equals
`target`, without saying whether URLs are normalised first. As written,
`http://a/b` may mention `https://a/b`, and `https://a/b` may mention
`https://a/b/`. The strict "exact match" rule in 3.2.2 is deliberate for
finding the link; the self-mention rule probably ought to normalise scheme,
host case and default port.

## 9. Sanitise at display, not only at storage

**Section 4.1**'s "encoded and/or filtered" is right, but a receiver that
sanitises once, when it stores content, inherits every past sanitiser's bugs
forever. This review found a parser code path (XRay's GitHub format) that
never sanitised at all, and the stored rows outlive the fix. Suggest
recommending sanitising at output, or re-sanitising stored content whenever
the rules change.

## 10. Private Webmention (extension)

Not part of the W3C spec, but the same review applies. A receiver that
answers public, target-keyed queries will return private mentions to anyone
unless it deliberately scopes them; the extension should say private
mentions MUST be excluded from unauthenticated reads. It should also say the
access token obtained for the fetch must not be forwarded across a redirect
to another origin, and that the `code` is a secret to be kept out of queues
and logs where practical.

## 11. CSRF wording

**Section 4.4** addresses endpoints that accept requests with additional
headers. It would be clearer to say plainly that a Webmention endpoint is a
cross-origin form target by design and therefore must never rely on cookies
or other ambient credentials for anything.

## 12. Multi-tenant receivers

The spec assumes the receiver is the site. A hosted receiver serving many
sites has to decide who may claim a domain. Nothing in the spec suggests a
way to prove it, though the natural one exists: the domain's pages advertise
the claimant's endpoint via `rel=webmention`. A note that hosted receivers
SHOULD verify a claimed domain this way would close a real hole (anyone who
could add a domain could inject mentions into that domain's public results).
