# To-do

Open work. `docs/` is export-ignored, so this file never ships in the release
archive.

---

## Broadside overwrites the View Publication badging

The fix is in [Broadside](https://github.com/cruftbox/broadside), not in this
plugin, but the two interact and the symptom shows up here.

**What is confirmed.** Broadside builds its own link preview card and attaches
it as `app.bsky.embed.external` (`app/bluesky.py:214`), for any entry that has a
link and no image. A Bluesky post has exactly one embed slot, so that card is
what the post record carries.

**What is observed.** Posting a weblog link through Broadside produces no View
Publication badging at all. Confirmed by Michael on 2026-09-07. The same URL
pasted into the bsky.app composer does get the badging.

**What is not yet established.** Why. Two hypotheses survive the observation
above, because both predict exactly it: either Broadside's embed occupies the
slot and stops Bluesky resolving the URL at all, or the embed is missing
whatever field triggers the augmentation. Note that a card can carry both a
preview and the badging at once, so the embed's mere presence is not
disqualifying. Do not start writing a fix until this is settled.

**The test that settles it.** Post the same weblog URL twice, once through
Broadside and once through the bsky.app composer. Read both `app.bsky.feed.post`
records out of the repo and diff the `embed` object. That says exactly what
bsky.app does differently.

**Then, depending on the answer.** Either suppress the link card in Broadside
when the target page carries a `<link rel="site.standard.document">` tag,
leaving the embed slot empty so Bluesky resolves the URL itself; or match
whatever field bsky.app sets that triggers the badging.
