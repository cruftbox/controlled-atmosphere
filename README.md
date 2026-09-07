# Controlled Atmosphere

A WordPress plugin that makes your posts eligible for Bluesky's native
**"View Publication"** article card — without ever posting to your feed.

> **Status: early development, not yet tested against a live PDS.** The full
> publish pipeline is implemented — publishing a post writes a record and emits
> its link tag. Some details of the record format are still unconfirmed. See
> [Project status](#project-status).

## What it does

Every syndication plugin for Bluesky works by posting for you. This one doesn't
post at all.

When you publish a WordPress post, Controlled Atmosphere writes a
[Standard.site](https://standard.site/) `site.standard.document` record to your
AT Protocol repo and adds a `<link>` tag to that post's HTML head pointing at the
record.

That's the whole automatic behavior. Nothing enters your timeline, nothing
appears on your profile, nobody is notified.

Later — whenever you feel like it, or never — you open Bluesky, write a post in
your own words, and paste the URL. Bluesky reads the link tag, resolves the
record, and renders a native article card instead of a plain link preview.

**The plugin makes your posts eligible for the card. You decide if and when to
use it.**

### What it will not do

- It never creates an `app.bsky.feed.post`. No auto-posting, no syndication, no
  cross-posting, no scheduling.
- It does not publish your article text — only title, description, date, tags,
  and optionally a cover image.
- It does not delete records from your repo when uninstalled.

## How it actually works

Worth understanding before you install, because one part fails silently.

Three things must be true for the card to render:

1. **A record exists in your repo.** One `site.standard.publication` for the
   site, and one `site.standard.document` per article. Bluesky ingests
   `site.standard.*` records into its database.
2. **The article page points at its record**, via
   `<link rel="site.standard.document" href="at://..." />` in the page head.
3. **Your domain proves it owns the publication record**, by serving its AT-URI
   at `/.well-known/site.standard.publication`. The link tag alone is only a
   discovery hint — verification depends on this endpoint.

Writing records requires authenticating to your PDS with a Bluesky app password.
There is no read-only way to get the card.

> **Note:** Standard.site is a set of AT Protocol lexicons, not a JSON-LD schema.
> If you have seen advice about injecting `application/ld+json` with a
> `standard.site` context, that mechanism does not exist and will do nothing.

## Requirements

- WordPress 6.0 or later
- PHP 8.0 or later
- A Bluesky account and an app password
- A publicly reachable site — Bluesky's crawler must be able to fetch your pages
  and your `.well-known` endpoint. This cannot work on localhost.

## Installation

Clone into your plugins directory:

```sh
cd wp-content/plugins
git clone https://github.com/cruftbox/controlled-atmosphere.git
```

Or download the repository as a ZIP and upload it via **Plugins → Add New →
Upload Plugin**.

Then activate it in **Plugins**.

## Setup

1. In Bluesky, go to **Settings → Privacy and Security → App Passwords** and
   create one. It looks like `xxxx-xxxx-xxxx-xxxx`.
   **Do not use your account password.**
2. In WordPress, go to **Settings → Controlled Atmosphere**.
3. Enter your handle (for example `example.bsky.social`) or your DID, plus the
   app password. Save.
4. Click **Sync publication and verify**.
5. Confirm every row in the Status panel is green.

Step 5 is not optional — see below.

### Keeping the app password out of the database

By default the app password is stored in the `wp_options` table in a readable
form, because WordPress has no secret store. Anyone with database or filesystem
access can read it.

To avoid that, define it in `wp-config.php` instead:

```php
define( 'CONTROLLED_ATMOSPHERE_APP_PASSWORD', 'xxxx-xxxx-xxxx-xxxx' );
```

The constant takes precedence and the settings field is disabled.

App passwords can be revoked from Bluesky at any time without affecting your
account password.

## Troubleshooting: the `.well-known` endpoint

**This is the most likely thing to go wrong, and it fails invisibly.**

Bluesky verifies domain ownership by fetching:

```
https://your-site.example/.well-known/site.standard.publication
```

It must return your publication's AT-URI as plain text:

```
at://did:plc:xxxxxxxxxxxxxxxxxxxxxxxx/site.standard.publication/3muuw2vqqcyop
```

The plugin answers this two ways, trying the tidier one first.

**1. PHP interception.** The plugin watches for that request and answers it
directly. Nothing is written to disk, and the value updates itself if you ever
connect a different account. This works on most hosts.

**2. A file on disk.** Many servers handle `/.well-known/` themselves and never
pass the request to WordPress — commonly because a TLS client aliased that
directory for ACME challenges. When the self-check detects this, the plugin
writes the file for you, then re-checks. Since the server is already serving
that directory from disk, the file is picked up immediately.

You do not have to choose. **Sync publication and verify** tries interception,
detects failure, writes the file, and confirms the result. It only asks you to
do something by hand if both paths fail — and then it prints the exact path and
contents you need.

Automatic writing needs WordPress to have direct filesystem access to your site
root, which is common but not universal. Managed hosts with read-only webroots
will refuse it.

If the plugin created the file, uninstalling removes it. A file you placed by
hand is left alone.

### Diagnosing by hand

```sh
curl -i https://your-site.example/.well-known/site.standard.publication
```

You want `200 OK` and the AT-URI as the body.

To find out whether your server is intercepting the path, compare two 404s:

```sh
curl -s -o /dev/null -w "%{http_code} %{size_download}\n" https://your-site.example/.well-known/nope
curl -s -o /dev/null -w "%{http_code} %{size_download}\n" https://your-site.example/nope
```

A small response (a few hundred bytes) from the first and a large themed one
from the second means the web server is answering, not WordPress.

**nginx** — if a `location ^~ /.well-known/` block exists, either let the plugin
write the file, or route this one path to WordPress:

```nginx
location = /.well-known/site.standard.publication {
    try_files $uri /index.php?$args;
}
```

**Apache** — an `Alias` or `<Directory>` block for `/.well-known/`, usually added
by certbot, will take the path away from WordPress. The file-on-disk approach
works regardless.

## Excluding a post

Each post has an **Exclude from Bluesky publications** checkbox in the editor
sidebar, available in both the block and classic editors.

Ticking it on a post that already has a record **deletes that record from your
repo**, not just the link tag. An orphaned record would otherwise stay in
Bluesky's index with nothing pointing at it.

## Existing posts

Configuring the plugin does not retroactively create records. A recent-posts
backfill handles the most recent *N* posts (default 10) for validating setup;
large archives are handled separately via WP-CLI, since AT Protocol enforces
per-account write limits.

## Privacy

Records written to your repo are **public data on the AT Protocol.** Anyone can
read them with any repo browser, whether or not you ever link to the post. This
is inherent to publishing on atproto, not a choice this plugin makes.

The publication record carries a `showInDiscover` preference, exposed as a
setting and **off by default**. It has no known effect in Bluesky today, which
surfaces Standard.site records only as link cards on posts, but other apps may
use it to show your publication to people who have not seen a link.

## Project status

Implemented:

- AT Protocol client — handle and DID resolution, PDS discovery, session
  handling with refresh, record writes, blob uploads, rate-limit handling
- Settings page with validation and a status panel
- Publication record sync
- `.well-known` verification endpoint and external self-check
- Link tags for both the publication and individual documents
- Document records with full lifecycle — created on publish, updated on edit,
  deleted on unpublish, trash, exclusion, or permanent delete
- Per-post opt-out control, in both editors
- Record status column on the Posts screen
- Bounded backfill for recent posts

Not yet built:

- Retry queue for failed writes. Failures are recorded and shown in the Posts
  column, but are not retried automatically — re-saving the post retries it.
- WP-CLI command for large archives. The admin backfill is capped at 50 posts
  because AT Protocol enforces per-account write limits.

**Not yet verified against a live PDS.** The code parses and the logic is
complete, but no record has been written to a real repo yet. Two details of the
record format are inferred rather than documented — the record key convention,
and whether the `site` field wants an AT-URI or an https URL. Both are isolated
to single locations for cheap correction. See
[`docs/specification.md`](docs/specification.md) for the full design and the
list of open questions.

### Extending

The document record passes through a filter before it is written, so additional
lexicon fields can be added without modifying the plugin:

```php
add_filter( 'controlled_atmosphere_document_record', function ( $record, $post ) {
    $record['textContent'] = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 10000 );
    return $record;
}, 10, 2 );
```

`textContent` is deliberately omitted by default: it contributes nothing to the
Bluesky card, and setting it publishes a copy of your article body as public
data. The snippet above opts in if you want reader apps to render your writing
natively.

## References

- [Standard.site](https://standard.site/) — lexicons and verification
- [Integrating Standard Site Into Bluesky](https://github.com/bluesky-social/atproto/discussions/4978)
- [Now in your timeline: Standard.site](https://atproto.com/blog/standard-site-bluesky-timeline)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
