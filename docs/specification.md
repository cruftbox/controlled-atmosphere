# Specification: Controlled Atmosphere (WordPress Plugin)

## 1. Goal

Let a WordPress site owner install this plugin, configure it once, and from that
point forward have every published post carry the metadata and AT Protocol
records required for Bluesky to render its native **"View Publication"** article
card.

The user then posts to Bluesky **manually and normally**: open any Bluesky
client, write whatever text they want, paste their blog post URL, hit post. The
link resolves to the enhanced article card.

### Explicit non-goal

The plugin **never creates an `app.bsky.feed.post`.** It does not cross-post,
syndicate, auto-share, or place anything in anyone's timeline. Publishing a blog
post creates a data record in the user's own repo and nothing else. Whether to
post about that article — and when, and with what text — remains entirely a
manual decision made in a Bluesky client.

This is the distinction the plugin is built around: **writing records is not
posting.**

### Scope decisions

| Decision | Choice |
|---|---|
| Distribution | Public on GitHub. Installable and documented for others, but not submitted to the WordPress.org plugin directory. |
| Authorship | One site-wide Bluesky account. All records live in that repo regardless of which WordPress user wrote the post. |
| Coverage | Every published post automatically, with a per-post opt-out checkbox. |
| Testing | Against a live, publicly reachable WordPress site. |

Consequences of the GitHub-not-directory choice: ship a `README.md` rather than a
`readme.txt`, GPL-2.0-or-later to match WordPress, and no obligation to the
directory's review rules or its packaging conventions. Code should still be
i18n-ready (text domain, wrapped strings) and follow WordPress Coding Standards,
because both are cheap up front and expensive to retrofit — but no translation
files ship in v1. Multisite is out of scope.

---

## 2. How the "View Publication" card actually works

Standard.site is **not a JSON-LD schema.** It is a set of AT Protocol lexicons.
There is no `@context`, no `application/ld+json` block, and no
`https://standard.site/ns/jsonld/v1`. Injecting JSON-LD accomplishes nothing.

The real mechanism has three parts, all of which must be present:

1. **Records exist in the user's AT Protocol repo.** A
   `site.standard.publication` record for the site, and one
   `site.standard.document` record per article. Bluesky ingests `site.standard.*`
   records into its database.

2. **The article page points at its record.** A `<link>` tag in the page `<head>`
   carrying the record's AT-URI.

3. **The domain proves it owns the publication record.** A `.well-known`
   endpoint at the domain root returns the publication's AT-URI. The link tag
   alone is only a discovery hint — verification requires the endpoint.

When a URL is pasted into the Bluesky composer, Bluesky fetches the page, reads
the link tag, resolves the AT-URI, checks verification, and renders the card.
The preview appears in the composer before posting; no special client support
and no `associatedRefs` manipulation is needed for a hand-composed post.

Writing these records requires authenticating to the user's PDS with a Bluesky
**app password**. There is no read-only path to the card.

---

## 3. Data model

### 3.1 `site.standard.publication` — one per site

| Field | Type | Req | Constraints | Plugin value |
|---|---|---|---|---|
| `url` | string | Yes | no trailing slash | Site URL, from `home_url()`, trailing slash stripped |
| `name` | string | Yes | max 5000 chars / 500 graphemes | Configurable; defaults to `get_bloginfo('name')` |
| `description` | string | No | max 30000 / 3000 graphemes | Configurable; defaults to `get_bloginfo('description')` |
| `icon` | blob | No | square, >=256x256, <1MB | Site icon via `get_site_icon_url()` if set; uploaded as a blob |
| `basicTheme` | ref | No | `site.standard.theme.basic` | Not set in v1 |
| `labels` | union | No | `com.atproto.label.defs#selfLabels` | Not set in v1 |
| `preferences.showInDiscover` | bool | No | — | Configurable checkbox, **default `false`** (see 4.5) |

### 3.2 `site.standard.document` — one per published post

| Field | Type | Req | Constraints | Plugin value |
|---|---|---|---|---|
| `site` | string | Yes | no trailing slash | The publication record's AT-URI |
| `title` | string | Yes | max 5000 / 500 graphemes | `get_the_title()`, entities decoded to plain text |
| `publishedAt` | datetime | Yes | ISO 8601 | `get_post_time('c', true, $post)` (UTC) |
| `path` | string | No | leading slash | Path component of the post permalink |
| `description` | string | No | max 30000 / 3000 graphemes | Excerpt if set; else generated from content (see 3.3) |
| `coverImage` | blob | No | <1MB | Featured image if set; uploaded as a blob (see 3.4) |
| `textContent` | string | No | — | **Not set in v1** (see 3.3) |
| `tags` | array | No | items max 1280 / 128 graphemes | Post tags, name strings |
| `updatedAt` | datetime | No | ISO 8601 | `get_post_modified_time('c', true, $post)` on updates |
| `content` | union | No | requires `$type` | Not set in v1 |
| `bskyPostRef` | ref | No | — | **Never set.** Would reference a feed post; out of scope by design |
| `links` | union | No | — | Not set in v1 |
| `labels` | union | No | — | Not set in v1 |
| `contributors` | array | No | `did`/`role`/`displayName` | Not set in v1 |

### 3.3 Text derivation

`description` is derived from post content and must be plain text — no HTML, no
shortcodes, no block comments.

- Pipeline: `strip_shortcodes()` -> `wp_strip_all_tags()` -> decode HTML entities
  -> collapse whitespace -> trim.
- Use the manual excerpt when one exists. Otherwise truncate the derived text on
  a word boundary. The lexicon permits 3000 graphemes; the plugin targets ~300
  characters for a card-appropriate summary. (The original spec's 160-char limit
  came from the meta-description convention and does not apply here.)
- Length enforcement counts **graphemes**, not bytes. Use `mb_*` functions and
  truncate conservatively; over-length values will be rejected by the PDS.

### 3.3.1 Why `textContent` is omitted

The Bluesky card renders from `title`, `description`, `publishedAt`, and
`coverImage`. `textContent` contributes nothing to it.

Its purpose is broader than Bluesky: it is the plaintext representation that
ATmosphere reader apps would render to display an article natively. Setting it
means publishing a copy of the article body into the repo as public data.

Since v1's goal is the Bluesky card, `textContent` stays unset — the article text
remains on the site, and readers click through. Adding it later is additive and
requires no migration of existing records.

Reference point, not a convention: one Eleventy implementation
([brennan.day](https://brennan.day/publishing-my-eleventy-blog-to-the-atmosphere-with-standard-site/))
sets `textContent` capped at 10,000 characters — a generous excerpt rather than a
full mirror. That is a single data point, not an established norm. If this is
enabled later, cap it similarly rather than sending unbounded text.

### 3.4 Blob uploads

`icon` and `coverImage` are AT Protocol blobs, not URLs. Each must be uploaded
via `com.atproto.repo.uploadBlob` first, and the returned blob reference embedded
in the record.

- Enforce the <1MB limit before upload. Prefer an intermediate WordPress image
  size over the full-size original; skip the field entirely if no variant fits.
- Blob uploads are the slowest part of a publish. Cache the blob reference in
  post meta (keyed by attachment ID and file hash) so republishing an unchanged
  image does not re-upload it.
- A failed blob upload must not fail the whole record write — omit the field and
  record a warning.

---

## 4. Configuration

Settings page at **Settings -> Controlled Atmosphere**.

One Bluesky account serves the whole site. There is no per-user credential
storage and no per-author settings section; the post author in WordPress has no
bearing on which repo a record is written to. The `contributors` field stays
unset in v1 (see 3.2) — adding it later is additive and needs no migration.

### 4.1 Fields

| Field | Notes |
|---|---|
| Bluesky handle or DID | Accept either. Handles are resolved to a DID; DIDs must match `did:plc:` or `did:web:`. |
| App password | **App password only, never the account password.** Link to Bluesky -> Settings -> Privacy and Security -> App Passwords. Rendered as a password input, never echoed back in full once saved — display a masked placeholder. |
| PDS host | Optional override. Auto-discovered (4.2); shown read-only once resolved, with a manual override for self-hosted PDSs. |
| Publication name | Defaults to site title. |
| Publication description | Defaults to tagline. |
| Show in discovery feeds | Checkbox -> `preferences.showInDiscover`. Unchecked by default (see 4.5). |

Plus a **Test connection** button and a **status panel** (4.4).

### 4.2 Identity resolution

Do not assume `bsky.social`. On save:

1. If a handle was given, resolve to a DID via `com.atproto.identity.resolveHandle`.
2. Resolve the DID document — `https://plc.directory/{did}` for `did:plc:`, or the
   `did:web:` well-known location.
3. Read the PDS endpoint from the DID document's `#atproto_pds` service entry.
4. Authenticate against that host. Honor the manual override if set.

### 4.3 Credential storage

The app password is stored in the `wp_options` table. **State this plainly in the
UI:** it is stored in the database in a form the site can read, so anyone with
database or filesystem access can retrieve it. WordPress provides no secret
store; this is inherent, not a plugin defect.

- Support overriding via a `wp-config.php` constant
  (`CONTROLLED_ATMOSPHERE_APP_PASSWORD`) for users who prefer keeping the secret
  out of the database. When the constant is set, the field is disabled in the UI
  and the constant wins.
- Never expose the stored value through REST, admin-ajax, or export.
- Provide a "revoke and clear" action that deletes the stored credential locally
  and reminds the user to revoke the app password in Bluesky.

### 4.4 Setup sequence and status

Saving valid credentials triggers, in order:

1. Authenticate (`com.atproto.server.createSession`).
2. Create or update the `site.standard.publication` record.
3. Persist the publication AT-URI and CID.
4. Activate the `.well-known` endpoint (5.2).
5. Self-check: fetch the site's own `.well-known` URL over HTTP and confirm the
   response matches the stored AT-URI.

The status panel must report each step's real state — authenticated as (handle,
DID, PDS), publication record AT-URI, `.well-known` reachable yes/no, count of
posts with document records, count of posts that failed. **If the `.well-known`
self-check fails, say so prominently.** Without it the card will not render, and
the failure is otherwise invisible to the user.

---

### 4.5 Discovery preference

`preferences.showInDiscover` on the publication record is a user-selectable
checkbox, **unchecked by default.**

Rationale for the default: the field is documented as deciding "whether the
publication should appear in discovery feeds." Bluesky's current implementation
notes describe no such surface — standard.site records are ingested but only
render as the enhanced card on a post that references them — so setting it either
way has no observable effect today. It is a capability the schema anticipates.
Since the plugin's entire premise is that the user decides when their work
surfaces, the default is off, and turning it on is a deliberate act.

UI requirements:

- Label: *"Allow this publication to appear in discovery feeds"*.
- Help text must be honest about the uncertainty: this has no known effect in
  Bluesky today, but consuming apps may use it to surface the publication to
  people who have not seen a link to it.
- Changing the checkbox updates the existing publication record — it is not
  applied only at creation time.

---

## 5. Frontend output

### 5.1 Link tags

- **Single posts** (`is_singular('post')`, and only when a document AT-URI exists
  in post meta):
  ```html
  <link rel="site.standard.document" href="at://did:plc:.../site.standard.document/RKEY" />
  ```
- **Front page** (`is_front_page()`):
  ```html
  <link rel="site.standard.publication" href="at://did:plc:.../site.standard.publication/RKEY" />
  ```

Both hooked to `wp_head`. Nothing is emitted on archives, feeds, search,
attachments, or other post types. Escape the AT-URI with `esc_url()`; register
`at` as a permitted protocol via the `kses_allowed_protocols` filter, or use
`esc_attr()` if `esc_url()` strips the scheme.

### 5.2 Verification endpoint

Serve `GET /.well-known/site.standard.publication` returning the publication's
AT-URI as a plain-text body (`Content-Type: text/plain`), HTTP 200. Return 404
when no publication record is configured.

Implement by matching `$_SERVER['REQUEST_URI']` on `init` (or `parse_request`)
and exiting before WordPress renders a 404 page. Do not rely on rewrite rules
alone — `.well-known` paths are frequently intercepted by the web server, and
some hosts serve a static directory there.

**The setup self-check in 4.4 exists because this step fails silently on many
hosts.** If the server intercepts `.well-known`, the plugin cannot fix it; it
must detect and report it, and document the manual workaround (a server-level
alias or a static file containing the AT-URI).

---

## 6. Record lifecycle

Driven by post status transitions (`transition_post_status`), restricted to the
`post` type.

| Transition | Action |
|---|---|
| -> `publish` (first time) | Create document record. Store AT-URI, rkey, CID in post meta. |
| `publish` -> `publish` (edit) | Update the record in place; set `updatedAt`. |
| `publish` -> `draft`/`pending`/`private` | Delete the record. Clear the meta. |
| -> `trash` | Delete the record. Retain the rkey in meta so an untrash can restore it. |
| permanent delete (`before_delete_post`) | Delete the record. |
| Permalink change | Recompute `path` and update the record. |
| Opt-out ticked on a post with a record | Delete the record. Clear the AT-URI meta. |
| Opt-out un-ticked on a published post | Create the record. |

Notes:

- **Use a deterministic record key** derived from the post ID (e.g. the post's
  GUID hashed, or a stored TID). Write with `com.atproto.repo.putRecord` so
  create and update are the same idempotent call and a lost meta value cannot
  orphan a record. *(The lexicon docs do not state an rkey convention for either
  record type; `self` is the AT Protocol convention for singletons and is the
  reasonable choice for the publication record, but this is an inference — verify
  against a working implementation before relying on it.)*
- **Never let a failed record write block publishing a post.** Catch every
  failure, keep the post published, log the error, and surface it.
- Scheduled posts transition on cron. The transition hook covers this; do not
  additionally hook `save_post`, which fires in contexts (autosave, REST partial
  updates, bulk edit) where a network call is inappropriate.

### 6.1 Per-post opt-out

Every published post gets a document record by default. A single checkbox —
*"Exclude from Bluesky publications"* — can suppress it for a given post.

- Stored as post meta (`_controlled_atmosphere_exclude`), absent by default, so
  existing posts and posts published before the plugin was configured need no
  migration.
- Presented in the block editor as a `PluginDocumentSettingPanel`, and in the
  classic editor as a meta box, so it works regardless of the user's editor.
- Registered with `register_post_meta()` using an `auth_callback` that checks
  `edit_post` on the specific post, so it is settable via REST by the block
  editor without becoming publicly writable.
- Ticking it on a post that already has a record **deletes the record**, rather
  than merely suppressing the link tag. Leaving an orphaned record in the repo
  that no page points to would still be ingested by Bluesky and would still
  surface in discovery feeds — suppression has to happen at the record, not the
  page.
- Excluded posts are skipped by backfill.

### 6.2 Backfill

The target site has **well over a thousand published posts.** Backfill is
therefore staged, not a single all-or-nothing operation.

**Stage 1 — the recent window (v1).** An admin-triggered action that processes
the *N* most recent published posts, default 10, with *N* selectable. This is the
proving run: enough to confirm records write correctly and cards render, small
enough to finish in one request and to undo by hand if something is wrong.
Deliver this first and validate the whole pipeline on it before touching the
archive.

**Stage 2 — the archive (deferred).** Processing 1,000+ posts is a separate
problem with its own constraints, and is explicitly *not* part of getting the
plugin working:

- AT Protocol enforces per-account write limits. A thousand records plus a
  thousand blob uploads will hit them; the run must budget against the limit,
  honor `RateLimit-Reset` on 429, and resume across many passes.
- Progress must persist in the database, not in a page's request lifetime. A
  browser-driven loop is the wrong shape at this size.
- Ship this as a **WP-CLI command** (`wp controlled-atmosphere backfill`), with
  the admin UI limited to the recent-window action. CLI gives an unbounded
  execution window, real progress output, and safe interruption.

Both stages must skip posts that already have a record, skip opted-out posts, and
never run automatically on activation or upgrade.

---

## 7. Networking and reliability

- All HTTP via `wp_remote_post()` / `wp_remote_get()`. No cURL, no Guzzle, no
  external dependencies.
- **Session handling:** `createSession` returns a short-lived `accessJwt` and a
  longer-lived `refreshJwt`. Cache the access token in a transient with a margin
  before expiry; refresh via `com.atproto.server.refreshSession`. Re-authenticate
  from stored credentials only when refresh fails. Do not call `createSession` on
  every request — it is rate-limited.
- **Rate limits:** AT Protocol enforces per-account write limits. Backfill in
  particular must throttle and back off on HTTP 429, honoring `RateLimit-Reset`.
- **Failure queue:** persist failed writes and retry on a cron schedule with
  exponential backoff. A transient PDS outage during publish must not silently
  leave a post without a record.
- Set a sane timeout (~15s). A slow PDS must not hang the publish request.

### 7.1 XRPC endpoints used

| Endpoint | Purpose |
|---|---|
| `com.atproto.identity.resolveHandle` | Handle -> DID |
| `com.atproto.server.createSession` | Authenticate with app password |
| `com.atproto.server.refreshSession` | Refresh access token |
| `com.atproto.repo.putRecord` | Create/update publication and document records |
| `com.atproto.repo.deleteRecord` | Remove records |
| `com.atproto.repo.uploadBlob` | Upload icon and cover images |

**No `app.bsky.*` write endpoint is called. Ever.**

---

## 8. Security requirements

- Settings page gated on `manage_options`; nonce-verified via the Settings API
  (`settings_fields()`), with `check_admin_referer()` on any custom action
  handler (test connection, backfill, revoke).
- Sanitize on input: DID against the `did:plc:`/`did:web:` grammar, handle
  against a hostname pattern, PDS override as a URL restricted to `https`.
- Escape on output: `esc_attr()` / `esc_html()` in admin markup, `esc_url()` for
  links, `wp_json_encode()` for all request bodies.
- Never log the app password or JWTs. Redact them from any error output or
  status display.
- Nothing plugin-controlled is emitted on the frontend except the two `<link>`
  tags and the `.well-known` response.

---

## 9. Structure

A single file is not appropriate given PDS authentication, credential handling,
record CRUD, blob uploads, per-post state, and a retry queue. Use a conventional
multi-file plugin:

```
controlled-atmosphere/
  controlled-atmosphere.php     # header, bootstrap, activation/deactivation
  includes/
    class-settings.php          # admin page, options, validation, status panel
    class-atproto-client.php    # XRPC transport, session, identity resolution
    class-publication.php       # publication record + .well-known endpoint
    class-document.php          # document record build + lifecycle hooks
    class-blobs.php             # image selection, size enforcement, upload cache
    class-queue.php             # failure queue, retries, backfill batching
    class-post-meta.php         # opt-out checkbox: meta registration, editor UI
    class-cli.php               # WP-CLI archive backfill (stage 2, deferred)
  uninstall.php                 # delete options and post meta
  README.md                     # install, configure, troubleshoot
  LICENSE                       # GPL-2.0-or-later
```

Requirements: WordPress 6.0+, PHP 8.0+, no Composer dependencies, i18n-ready via
a text domain, and a documented uninstall that removes options and post meta.
Uninstall must **not** delete remote records — offer that as a deliberate
pre-uninstall action instead, since deleting a user's repo data on plugin removal
is destructive and surprising.

`README.md` must cover the `.well-known` failure mode explicitly, since it is the
one problem a user cannot diagnose from inside WordPress: what the endpoint is,
how to confirm it works, and what to add to nginx or Apache config when the web
server intercepts the path.

---

## 10. Acceptance criteria

1. Fresh install, enter handle + app password, save -> publication record created,
   `.well-known` returns its AT-URI, status panel reports all green.
2. Publish a new post -> document record created; page source contains the
   `site.standard.document` link tag.
3. Paste that post's URL into the Bluesky composer -> the "View Publication" card
   renders in the preview, before posting.
4. Edit the post -> record updates, `updatedAt` set.
5. Unpublish the post -> record deleted; link tag gone; pasting the URL yields a
   plain preview.
6. Tick the opt-out on a post that has a record -> record deleted from the repo,
   link tag gone.
7. No `app.bsky.feed.post` record is created at any point in any of the above.
8. With credentials removed or invalid, the site continues to function normally
   and publishing still works.

Criteria 1-6 are verified on the live site, since the crawler must reach it.
Record writes and deletes during testing are real operations on a real repo.

---

## 11. Open questions

Two of the original four were answered empirically on 2026-09-06 by inspecting a
live repo that already carried Standard.site records.

### Resolved

1. **Record key convention — publication.** RESOLVED. Not `self`. A record
   written by other tooling used a server-minted TID (`3muuw2vqqcyop`).
   Hardcoding `self` would have created a *second* publication record rather
   than updating the existing one, leaving two records competing for a single
   verification endpoint. The plugin now stores the key it wrote, adopts any
   existing record whose `url` matches the site, and otherwise lets the server
   mint one via `createRecord`.

2. **Verification endpoint reachability.** RESOLVED, and it is a real
   obstacle. On the target host, Apache handles `/.well-known/` itself and the
   request never reaches WordPress — confirmed by fingerprinting: a 404 under
   `/.well-known/` returns Apache's 236-byte stock page, while a 404 elsewhere
   returns WordPress's 48KB themed page. **The plugin's PHP interception cannot
   work on this host.** The self-check detects it and the error message now
   gives the exact file path and contents to create instead. Any plugin faces
   this equally; it is a server configuration issue, not a plugin defect.

### Also resolved

3. **Record key convention — documents.** RESOLVED, and it invalidated the
   original design. `site.standard.document` also requires a TID. A key derived
   from the post ID is rejected outright:

   ```
   HTTP 400: Invalid record key for site.standard.document:
             Invalid TID string (got "wp-test")
   ```

   Deterministic, post-derived keys are therefore impossible for both record
   types. The server mints the key on first write via `createRecord`; the
   plugin stores it and uses `putRecord` for subsequent updates.

   **Consequence for deletes.** The stored key is now the *only* way to address
   a record. If post meta is lost, the record cannot be deleted — there is no
   key to derive and guessing would target the wrong record. `Document::remove()`
   clears the local pointers and leaves the repo untouched in that case, which
   orphans the record rather than deleting something unintended. Reconciling
   orphans needs a repo-listing sweep, which is not built.

### Also resolved

4. **`site` field format.** RESOLVED. The publication AT-URI works. A document
   record carrying `site: at://.../site.standard.publication/<tid>` rendered the
   native card, with the **View publication** button, in the Bluesky composer on
   2026-09-06. The `https://` alternative was not tested and does not need to be.

### Still open

5. **Verification caching.** How long Bluesky caches the `.well-known` result and
   ingested records is unknown. Determines how soon after publishing a post
   becomes card-eligible. Note that a URL previously fetched as a 404 rendered
   correctly once the files were in place, so negative results are evidently not
   cached for long.

---

## 12. End-to-end validation, 2026-09-06

The whole mechanism was proven by hand, with no WordPress involvement, before
the plugin was installed. Two static files plus two records produced the card.

**Confirmed working:**

- Both record shapes are accepted by a live PDS.
- Server-minted TIDs for both collections.
- `site` as the publication AT-URI.
- A static file at `/.well-known/site.standard.publication` satisfies
  verification.
- `<link rel="site.standard.document">` and `...publication` on a plain static
  HTML page are read by Bluesky's crawler.
- The composer renders the native card with a **View publication** button,
  showing title, description, publish date, publication name, and author handle.

**Cover image and tags, confirmed separately the same day:**

- `uploadBlob` accepted a 30,036-byte PNG and returned a reference the record
  embedded without complaint; the blob reads back byte-identical from the PDS.
- The card renders the cover image full width and **uncropped** at 393x233
  (~1.69:1). Selecting an image variant by file size rather than aspect ratio is
  therefore safe.
- `tags` are stored but render nowhere on the card. The 25-tag cap is a
  deliberate sanity limit, not a lexicon requirement.

**Still not exercised:**

- The 1,000,000-byte blob ceiling and the "no variant fits" branch. The test
  image was 30 KB.
- Updating an existing document record via `putRecord`.
- Any plugin code. The plugin has still never run.

### Lexicon constraints, verified against the published schema

Fetched from `com.atproto.lexicon.schema` in the lexicon publisher's repo
(`did:plc:re3ebnp5v7ffagz6rb6xfei4`) rather than from prose documentation.

| Field | Constraint |
|---|---|
| `document.title` | maxLength 5000, maxGraphemes 500 |
| `document.description` | maxLength 30000, maxGraphemes 3000 |
| `document.tags` | array; each item maxLength 1280, maxGraphemes 128. **No cap on array length.** |
| `document.coverImage` | blob, `accept: ["image/*"]`, maxSize 1000000 |
| `document.site` | string, `format: uri` — so both `at://` and `https://` satisfy it |
| `publication.icon` | blob, `accept: ["image/*"]`, maxSize 1000000 |
| `publication.name` | maxLength 5000, maxGraphemes 500 |
| Required — document | `site`, `title`, `publishedAt` |
| Required — publication | `url`, `name` |

The plugin's limits match. Two notes: the 25-tag cap is self-imposed, not a
lexicon requirement; and `maxSize` is 1,000,000 bytes, not 1 MiB.

### Host-specific finding

On the target host, `/etc/httpd/conf.d/lets-encrypt.conf` contains:

```apache
<Location /.well-known/>
  RewriteEngine off
</Location>
```

mod_rewrite is disabled for that entire path, so WordPress's rewrite to
`index.php` never fires and Apache serves the filesystem directly. **PHP
interception cannot work on this host at all** — only a real file will do. The
plugin's fallback is therefore the operative path here, not a contingency.

PHP-FPM runs as `cruftbox` via `SuexecUserGroup`, and the document root is
`cruftbox:cruftbox` mode 2755, so the plugin's `WP_Filesystem` write has the
permissions it needs.

---

## 13. Prior art

**ATmosphere** (Automattic) covers much of this specification and more:
`site.standard.publication` and `site.standard.document` records, both link
tags, per-post opt-out, a posts-list column, WP-CLI backfill with rate-limit
pacing, and `.well-known` verification.

Critically, since 2.2.0 it can produce document records *without* cross-posting:

```php
add_filter( 'atmosphere_should_publish_bluesky_post', '__return_false' );
```

It also exposes `atmosphere_publication_show_in_discover`,
`atmosphere_transform_document`, and `atmosphere_connection_only_mode`.

This was evaluated on 2026-09-06 and deliberately not adopted. This plugin's
justification is therefore not capability but scope: a small, single-purpose
codebase that writes records and nothing else, versus a larger plugin that also
handles OAuth, comment import, reactions, and reply publishing. That is a
maintenance-surface argument, not a functionality one, and it should be
restated honestly rather than implying the capability does not exist elsewhere.

---

## 14. Sources

- [Standard.site](https://standard.site/)
- [Document Lexicon](https://standard.site/docs/lexicons/document)
- [Publication Lexicon](https://standard.site/docs/lexicons/publication/)
- [Verification](https://standard.site/docs/verification/)
- [Integrating Standard Site Into Bluesky — bluesky-social/atproto discussion #4978](https://github.com/bluesky-social/atproto/discussions/4978)
- [Now in your timeline: Standard.site — atproto.com](https://atproto.com/blog/standard-site-bluesky-timeline)
