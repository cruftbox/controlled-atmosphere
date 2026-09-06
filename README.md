# Controlled Atmosphere

A WordPress plugin that makes your posts eligible for Bluesky's native
**"View Publication"** article card — without ever posting to your feed.

> **Status: early development.** The settings page, publication record, and
> verification endpoint are implemented. Per-post record creation is not yet
> built. See [Project status](#project-status).

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
at://did:plc:xxxxxxxxxxxxxxxxxxxxxxxx/site.standard.publication/self
```

The plugin serves this by intercepting the request in PHP. **Many web servers
handle `/.well-known/` themselves and never pass the request to WordPress.** When
that happens the plugin cannot help — every setting will look correct and no card
will ever render.

This is why setup runs an external self-check. Always click **Sync publication
and verify** and read the result.

### Diagnosing

```sh
curl -i https://your-site.example/.well-known/site.standard.publication
```

You want `200 OK` and the AT-URI as the body.

**nginx** — a `location ^~ /.well-known/` block (often added by a TLS client) may
be serving a static directory. Add an exception ahead of it:

```nginx
location = /.well-known/site.standard.publication {
    try_files $uri /index.php?$args;
}
```

**Apache** — confirm `.htaccess` rewrites reach WordPress for dotted paths. Some
configurations block dot-directories outright.

**Static fallback** — if the server insists on serving the path itself, create the
file by hand containing only the AT-URI from the Status panel. It changes only if
you connect a different Bluesky account.

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
  handling, record writes, blob uploads
- Settings page with validation and a status panel
- Publication record sync
- `.well-known` verification endpoint and external self-check
- Front-page publication link tag
- Per-post opt-out control

Not yet built:

- Per-post document record creation and lifecycle
- Retry queue for failed writes
- Backfill
- WP-CLI commands

Some details of the Standard.site record format are undocumented and are being
confirmed empirically. See [`docs/specification.md`](docs/specification.md) for
the full design and the list of open questions.

## References

- [Standard.site](https://standard.site/) — lexicons and verification
- [Integrating Standard Site Into Bluesky](https://github.com/bluesky-social/atproto/discussions/4978)
- [Now in your timeline: Standard.site](https://atproto.com/blog/standard-site-bluesky-timeline)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
