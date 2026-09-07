# Advanced

Material that most people will never need. The [README](../README.md) covers
installation and setup.

---

## The `.well-known` endpoint

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
pass the request to WordPress, commonly because a TLS client aliased that
directory for ACME challenges. When the self-check detects this, the plugin
writes the file for you, then re-checks. Since the server is already serving
that directory from disk, the file is picked up immediately.

You do not have to choose. **Sync publication and verify** tries interception,
detects failure, writes the file, and confirms the result. It only asks you to
do something by hand if both paths fail, and then it prints the exact path and
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

**nginx.** If a `location ^~ /.well-known/` block exists, either let the plugin
write the file, or route this one path to WordPress:

```nginx
location = /.well-known/site.standard.publication {
    try_files $uri /index.php?$args;
}
```

**Apache.** An `Alias` or `<Directory>` block for `/.well-known/`, usually added
by certbot, will take the path away from WordPress. The file-on-disk approach
works regardless.

---

## Keeping the app password out of the database

By default the app password is stored in the `wp_options` table in a readable
form, because WordPress has no secret store. Anyone with database or filesystem
access can read it.

If this is of concern to you, you can define it in `wp-config.php` instead:

```php
define( 'CONTROLLED_ATMOSPHERE_APP_PASSWORD', 'xxxx-xxxx-xxxx-xxxx' );
```

The constant takes precedence and the settings field is disabled.

---

## Adding fields to the document record

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
