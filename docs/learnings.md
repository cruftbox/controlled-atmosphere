# Learnings

Findings from building this plugin, recorded because most of them cost time and
none are obvious from the documentation. Everything here was verified against a
live PDS or a live server on 2026-09-06, not inferred.

---

## 1. Standard.site is not JSON-LD

The original specification for this project was built on a false premise: that
Bluesky's article card is triggered by a `<script type="application/ld+json">`
block using an `@context` of `https://standard.site/ns/jsonld/v1`.

**No such mechanism exists.** There is no JSON-LD context, no `@type: "Article"`,
no `Profile` object. Standard.site is a set of AT Protocol lexicons.

If you find advice recommending the JSON-LD approach, it is wrong and will
produce a page that Bluesky ignores completely.

## 2. The card requires three things, not one

All three must be true. Missing any one produces a plain link preview with no
error anywhere.

1. **Records exist in the author's repo** — one `site.standard.publication` for
   the site, one `site.standard.document` per article.
2. **The page points at its record** — `<link rel="site.standard.document"
   href="at://…">` in the `<head>`.
3. **The domain confirms it owns the publication** — `/.well-known/site.standard.publication`
   returns the publication's AT-URI.

Point 3 is the one people miss. The `<link rel="site.standard.publication">` tag
on a home page is explicitly *only a discovery hint*; verification depends on the
`.well-known` endpoint. A site can have perfect records and perfect link tags and
still render nothing.

## 3. Record keys must be TIDs — no derived keys

Both collections reject anything that is not a valid TID:

```
HTTP 400: Invalid record key for site.standard.publication:
          Invalid TID string (got "self")

HTTP 400: Invalid record key for site.standard.document:
          Invalid TID string (got "wp-test")
```

`self` is the AT Protocol convention for singleton records (`app.bsky.actor.profile`
uses it), so it is a natural guess for a one-per-site publication record. It does
not work here.

**Consequences:**

- A record key cannot be derived from a post ID, so `putRecord` cannot serve as
  an idempotent create-or-update.
- Use `createRecord` for the first write, store the server-minted key, then
  `putRecord` with that stored key for updates.
- **The stored key becomes the only handle on the record.** If it is lost, the
  record cannot be deleted — there is nothing to derive and guessing a TID would
  target an unrelated record. Losing post meta orphans records.
- Reconciling orphans requires listing the repo and matching on record contents.

## 4. Publication records can already exist

A site may already have a `site.standard.publication` record written by other
tooling. Creating a second one leaves two records competing for a single
verification endpoint, and only one can win.

Always list the collection and adopt a record whose `url` matches the site before
creating a new one.

This is not hypothetical — it happened twice during development. Once from a
previously-installed plugin, and once from a failed `createRecord` run that
succeeded server-side after appearing to fail.

## 5. `site` accepts the publication AT-URI

The lexicon declares `site` as `format: uri`, and the docs say it may be "a
publication record `at://` or a publication url `https://`".

The AT-URI form is confirmed working — a card rendered with it. The `https://`
form was not tested.

## 6. `.well-known` may never reach the application

On a server using Let's Encrypt, `/etc/httpd/conf.d/lets-encrypt.conf` commonly
contains:

```apache
<Location /.well-known/>
  RewriteEngine off
</Location>
```

This disables mod_rewrite for the **entire** `.well-known` path, not just
`acme-challenge`. WordPress's rewrite to `index.php` never fires, so no amount of
PHP can answer that URL. Apache falls through to the filesystem and returns a
stock 404.

**Diagnostic** — compare two 404s:

```sh
curl -s -o /dev/null -w "%{http_code} %{size_download}\n" https://example.com/.well-known/nope
curl -s -o /dev/null -w "%{http_code} %{size_download}\n" https://example.com/nope
```

A small response (~236 bytes) from the first and a large themed one from the
second means the web server is answering, not the application.

**Fix:** write a real file. Since the server is already serving that directory
from disk, a file is picked up immediately. This is why the plugin writes the
file rather than relying only on request interception.

## 7. Verified lexicon constraints

Read from `com.atproto.lexicon.schema` in the publisher's repo
(`did:plc:re3ebnp5v7ffagz6rb6xfei4`), not from prose docs.

| Field | Constraint |
|---|---|
| `document.title` | maxLength 5000, maxGraphemes 500 |
| `document.description` | maxLength 30000, maxGraphemes 3000 |
| `document.tags` | array, no length cap; items maxGraphemes 128 |
| `document.coverImage` | blob, `accept: ["image/*"]`, maxSize 1000000 |
| `publication.icon` | blob, `accept: ["image/*"]`, maxSize 1000000 |
| `publication.name` | maxLength 5000, maxGraphemes 500 |
| Required — document | `site`, `title`, `publishedAt` |
| Required — publication | `url`, `name` |

`maxSize` is 1,000,000 bytes, not 1 MiB. Limits count **graphemes**, not bytes.

## 8. What the card actually shows

Confirmed by rendering. The card is built entirely from the **record**, never
from the page. The page supplies only the pointer.

**Rendered:** cover image (full width, uncropped at 393×233), title, description,
publish date, publication name, author handle, and a **View publication** button.

**Not rendered:** `tags`. They are stored and presumably useful to other
ATmosphere readers, but invisible on the Bluesky card. The plugin caps them at
25 as a deliberate sanity limit; the lexicon imposes no cap.

**Implication:** the featured image on the blog and the `coverImage` in the record
are two separate copies. Bluesky serves the card image from the PDS blob, never
from the origin site. Changing a post's featured image requires rewriting the
record or the card keeps showing the old one.

## 9. Negative results are not cached for long

A URL that Bluesky had already fetched as a 404 rendered correctly once the files
were in place, with no cache-busting needed. Stale previews were less of an
obstacle than expected.

## 10. Prior art: ATmosphere already does most of this

[ATmosphere](https://github.com/Automattic/wordpress-atmosphere) (Automattic)
publishes both record types, emits both link tags, handles `.well-known`
verification, offers per-post opt-out, adds a posts-list column, and ships a
WP-CLI backfill with rate-limit pacing.

Since 2.2.0 it can also produce document records **without** cross-posting:

```php
add_filter( 'atmosphere_should_publish_bluesky_post', '__return_false' );
```

Anyone evaluating this plugin should evaluate that filter first. This plugin's
justification is scope — a small codebase that writes records and nothing else —
not capability.

---

## Process learnings

**Verify before writing, not after.** Every assumption in this project that was
later checked turned out to be wrong: the JSON-LD premise, `self` as a record
key, `wp-<ID>` as a document key, the cause of the `.well-known` interception,
and whether ATmosphere could already do the job. Marking something "unconfirmed"
in a comment is not a substitute for the one API call that would settle it.

**Test the mechanism before testing the implementation.** Creating records by
hand — with two static files and no WordPress at all — proved the card renders
and caught a record-key bug that would have made every write fail. Doing this
before installing the plugin meant a failure could only have one cause.

**Read the schema, not the prose.** The lexicon records are published on the
network and are authoritative. The documentation site omits constraints the
schema enforces.
