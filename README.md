# g7-webzine-addon

[![Release](https://img.shields.io/github/v/release/William1607cho/g7-webzine-addon?sort=semver)](https://github.com/William1607cho/g7-webzine-addon/releases)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)

A [Gnuboard7](https://sir.kr/) plugin that adds a **webzine-type board** to
`sirsoft-board` and renders its list page as a 1-column list — each row a
small square thumbnail on the left and the title/summary/author/stats on the
right, in the style of an activity feed rather than a photo grid.

`sirsoft-board` and the visitor template (`sirsoft-basic`) are **never
modified**. The add-on works only through its own `board_type` registration
and two `core.layout_extension.after_apply` / `sirsoft-board.post
.filter_content_thumbnail` filter hooks that splice into the compiled
`board/index` layout tree and the post-save thumbnail-extraction pipeline.

## Features

| Feature | What it does |
|---|---|
| **Webzine board type** | `install()` adds a `webzine` row to `board_types` (kept alive across re-seeds by a filter listener, same pattern as `g7-forum-addon`'s `forum` type). `uninstall()` removes it, or refuses if a board still uses it. |
| **Thumbnail + summary list** | `board/index` renders as a 1-column list for `webzine` boards — a fixed 80×80px square thumbnail on the left, and on the right: title (with notice/category/new badges, truncated), a 150-character plain-text summary, author, timestamp, view count and comment count. Secret/blinded/deleted posts keep their lock/translucency overlays and badges. |
| **Configurable no-thumbnail rendering** (1.1.0) | An admin settings page picks what a post with no thumbnail shows: **summary only** (default — no thumbnail area at all, text uses the full row width), the **default placeholder** box, or an admin-supplied **fallback image** (uploaded here, or an existing image URL). |
| **Broken-thumbnail fallback** (1.1.0) | If a post *has* a thumbnail but the image fails to load (e.g. an external image was deleted), the row falls back to the same setting. If the fallback image itself fails, the row falls back to summary only. |
| **External image thumbnails** | The core only uses the first *internal* (self-uploaded) image as a post's thumbnail. On `webzine` boards, a first internal image still wins if present, but if the post has none, the first safe `http(s)` external image in the body is used instead. `data:`/`javascript:`/`blob:` schemes are still rejected. Other board types are unaffected. |

No add-on database tables — the thumbnail (`content_thumbnail_url`, computed
by `sirsoft-board` at save time) and 150-character summary
(`content_preview`, computed via a DB-level `SUBSTRING`) are already part of
`sirsoft-board`'s core list API response for every board type; this plugin
only changes how they're laid out. Since 1.1.0 the add-on serves three small
routes of its own (fallback-image upload / fallback-image serving / the
broken-thumbnail fallback script) — see **Settings** below.

## Settings

Admin → **Webzine Board Add-on** (`/admin/plugins/g7-webzine-addon/settings`).

### When no thumbnail

| Value | Behaviour |
|---|---|
| **Summary only** (default) | The thumbnail area is not rendered at all; the title and summary use the full row width. |
| **Default placeholder** | The grey "no image" box (icon + label). |
| **Fallback image** | An image you configure below, cropped square (`object-fit: cover`) exactly like a real thumbnail. |

The public default is **summary only** — that is this add-on's original
intent. (1.0.0 shipped the placeholder unconditionally, because the list
markup was adapted from the core card layout and the placeholder came along
with it.)

### Fallback image

Two mutually exclusive sources:

- **Upload** (recommended) — the file is stored in this plugin's own storage
  and served from `/api/plugins/g7-webzine-addon/fallback-image/{hash}`.
  The `{hash}` is the file's content hash, so replacing the image changes
  the URL and busts every browser/CDN cache immediately; the response
  carries a one-year `immutable` cache header. Replacing and saving deletes
  the previous file.
- **Image URL** — any `https://` / `http://` address, or a site-internal
  path starting with `/`. The settings screen warns that an external
  original can be deleted or changed out from under you; if it breaks, rows
  fall back to summary only.

An **alt text** field is offered; when left empty the site name is used.

#### Upload security

- Allowed: `png`, `jpg`/`jpeg`, `webp`, `gif`. **SVG is rejected** — it can
  carry script, and this image is rendered as a plain `<img>` on every
  visitor's list page.
- Three independent checks: the client-side extension, the real MIME type
  sniffed from the file's bytes, and a `getimagesize()` decode whose
  detected type must match the extension. A `.png` that is really an SVG,
  an HTML file or a text file is rejected by the second and third checks.
- Maximum size 2MB.
- The stored filename is **generated by the server** (UUID + verified
  extension); the original filename is kept only as a display label.
- Upload requires the core `core.plugins.update` permission (the same one
  that gates saving plugin settings). Settings read/write go through the
  core plugin-settings API, which enforces the same permission. Only the
  fallback image's public URL ever reaches the front end — no setting value
  is exposed to the browser.

### Note on saving

Saving these settings bumps the core extension cache version (the same
mechanism the core uses when a layout or extension changes), so the change
shows up after a single refresh instead of waiting out the layout response's
cache lifetime. As with activating any plugin, that schedules a republish of
the static extension bundle, so the first request after saving can be
slightly slower.

## List summary recalculation (temporary)

Webzine board lists rebuild each post's summary from its body instead of using the one the core
sends. The core builds summaries from the first 200 characters of the **raw HTML**, so a post that
opens with an image spends that budget on `<figure class="image"><img …></figure>` and gets an
empty summary. Leading `&nbsp;` survives the core's whitespace cleanup, and the ellipsis is added
after the 150-character cut, making the result 153.

**This is a workaround for a core defect and is meant to be removed.** It is one middleware plus
one pure class. When the core is fixed, delete the `getMiddleware()` entry in `plugin.php` and both
files — nothing else was touched.

What it does and does not touch:

- Only the visitor board-list route, and only `webzine` boards. The admin list is untouched.
- Only posts the core already published a summary for: secret, blinded and deleted posts keep the
  core's value. The plugin never re-decides permissions — it reads the flags the response already
  carries.
- One extra query per list response, regardless of how many posts are on the page.
- Bot SSR calls the same route internally, so it gets the same summaries.

## Fallback image derivative

The fallback image is drawn in an 80x80 CSS px box, but the stored original is usually much larger.
Since 1.2.0 a **240px-wide WebP derivative** is built when the image is saved and the list uses it.
The original is kept — the settings preview and image replacement still use it.

The fallback is also drawn **only on rows that have no thumbnail** now. Before 1.2.0 it sat under
every row as a base layer, so a row with a real thumbnail still downloaded it, fully hidden.

### If you upgraded from 1.1.0

An image saved under 1.1.0 has no derivative. Build it once:

```
php artisan g7-webzine-addon:build-fallback-thumb --dry-run
php artisan g7-webzine-addon:build-fallback-thumb
```

This is optional. Without it the list serves the original address and looks exactly as before.

- Needs **imagick**. This environment's GD is a bundled build with no WebP encoder.
  Without imagick no derivative is built, a warning is logged, and the original is used.
- An original 240px wide or narrower is left alone — nothing is upscaled.
- Derivatives are never built on a public request. Only the settings save and this command build them.

## Public contract for other extensions (1.3.0+)

Other extensions (g7-home-widgets' webzine-style widget, for one) get webzine card values through
**one class only** — never through the add-on's internal classes:

```php
use Plugins\G7\Webzine\Addon\PublicApi\WebzineCards;

// WebzineCards::VERSION === 1
$cards = WebzineCards::cards($items);
// $items: [['id' => 12, 'is_secret' => false, 'status' => 'published', 'deleted_at' => null, 'thumbnail' => '/…'], …]
// $cards: [12 => ['summary' => '…', 'thumbnail' => '/…', 'fallback_image' => null], …]
```

- **Input** — post items shaped like a subset of the core `PostResource`: `id` (int, required),
  `is_secret`, `status`, `deleted_at`, `thumbnail`. Other keys are ignored. At most 100 items.
- **Output** — post id => `summary` (?string, up to 150 characters including the ellipsis, the same
  recalculation as the webzine list), `thumbnail` (?string, the value passed in),
  `fallback_image` (?string, only for posts without a thumbnail and only when the add-on is set to
  show a fallback image). Items without a valid `id` are left out.
- **Secret posts** get `null` for all three values, even when a thumbnail is passed in. Posts that
  are not `published`, or are deleted, get no summary.
- **No permission check.** Pass only posts you already filtered through the core board services
  (readable boards, `PostService`, `PostResource`).
- One query reads the start of the bodies, whatever the number of items.
- **This input and output are a promise.** They stay the same when the internals change. An
  incompatible change raises `VERSION` and is listed in the CHANGELOG.
- Check availability before calling: the plugin is active
  (`PluginRepositoryInterface::findActiveByIdentifier('g7-webzine-addon')`) **and**
  `class_exists(WebzineCards::class) && WebzineCards::VERSION >= 1`.

## Requirements

- Gnuboard7 `>= 7.0.10`
- Module **`sirsoft-board >= 1.1.2`**
- PHP **imagick** — only for the fallback image derivative (1.2.0). Everything else works without
  it; the list simply serves the fallback original.
- Template **`sirsoft-basic >= 1.1.4`** (not a hard-enforced dependency — the
  core plugin manager only declares module/plugin dependencies, not
  templates — but the layout-splice logic matches literal strings in
  `sirsoft-basic`'s compiled `board/index` layout; a very different template
  version could change those strings and break the match. If that happens
  the listener logs an error instead of silently doing nothing — see Known
  issues.)

## Installation

### From GitHub (CLI)

```bash
# drop this repo into plugins/g7-webzine-addon, then:
php artisan plugin:install g7-webzine-addon
php artisan plugin:activate g7-webzine-addon
php artisan plugin:cache-clear
php artisan cache:clear
```

### From the admin UI

Admin → Plugins → g7-webzine-addon → Install → Activate.

### Using it

Admin → Boards → Create (or edit an existing board) → set **Type** to
**Webzine** (웹진형). The board's list page immediately renders as the
thumbnail list. Nothing else needs configuring — see **Settings** above if
you want posts without a thumbnail to show a placeholder or a fallback image
instead of just their summary.

## Uninstalling

```bash
php artisan plugin:uninstall g7-webzine-addon
```

Uninstall is refused while a board still uses the `webzine` type — change or
delete that board first. There is no `--delete-data` variant since this
plugin owns no database tables; uninstalling does delete any uploaded
fallback image from the plugin's storage.

## Known issues

- **Summary length is fixed at 150 characters**, reusing `sirsoft-board`'s
  existing (hard-coded, no filter hook) list-preview computation rather than
  a add-on-specific 300-character field. Extending it would mean either
  changing a core constant that affects every board type's preview length,
  or adding a second API round-trip per page load purely to re-read and
  re-truncate post bodies — both judged not worth it for now.
- **External-image thumbnails are `webzine`-only by design**, not a general
  toggle. Every other board type keeps the core's original same-origin-only
  thumbnail rule unchanged.
- **The no-thumbnail setting is site-wide**, not per board. The `board/index`
  layout is built once and shared by every board, so one setting governs all
  `webzine` boards.
- **The broken-thumbnail fallback needs JavaScript.** Layout JSON has no
  `error` event type, so a tiny script (served by the add-on, injected into
  the list layout's `scripts`) hides images that fail to load. Everything
  else — including which of the three modes applies — is rendered
  server-side and works without it. With scripting off, a dead thumbnail
  shows the browser's own broken-image box, as it did before 1.1.0.
- **The placeholder / fallback image sits *under* the thumbnail**, always in
  the DOM, so the script only ever has to hide the failed image rather than
  build new markup. Both layers carry `aria-hidden` so screen readers do not
  read them out on rows that have a working thumbnail.
- **The layout splice matches literal strings** in `sirsoft-basic`'s compiled
  `board/index` layout (the board-type dispatch branch's `if` condition). If
  a future `sirsoft-basic` update rephrases that condition, the match can
  silently stop firing for a *new* install — actually, it does not fail
  silently: a missed match is written to the Laravel log
  (`[g7-webzine-addon] board/index 유형 분기 앵커...`) so it's visible in
  `storage/logs`, but there is no UI-visible warning.

## Acknowledgments

The list-row markup (fixed-size square thumbnail wrapped in a rounded,
`overflow-hidden` `Div`, next to a `flex-1 min-w-0` text column) is adapted
from patterns already used repeatedly in SIRSOFT's MIT-licensed
`sirsoft-basic` template — its shopping-cart item row and my-page order-list
rows use the same shape. The board-type registration pattern (a filter
listener that keeps a custom slug alive across `sirsoft-board`'s
`BoardTypeSeeder` re-seeds) follows the same approach as this author's own
`g7-forum-addon`. **No source files were copied** from either — both are
adapted/rewritten for this plugin's own data and layout.

## License

MIT © 2026 William Cho. See [LICENSE](LICENSE).
