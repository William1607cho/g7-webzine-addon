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
| **Thumbnail + summary list** | `board/index` renders as a 1-column list for `webzine` boards — a fixed 80×80px square thumbnail on the left (or a placeholder icon when the post has none), and on the right: title (with notice/category/new badges, truncated), a 150-character plain-text summary, author, timestamp, view count and comment count. Secret/blinded/deleted posts keep their lock/translucency overlays and badges. |
| **External image thumbnails** | The core only uses the first *internal* (self-uploaded) image as a post's thumbnail. On `webzine` boards, a first internal image still wins if present, but if the post has none, the first safe `http(s)` external image in the body is used instead. `data:`/`javascript:`/`blob:` schemes are still rejected. Other board types are unaffected. |

No add-on database tables, no add-on API routes — the thumbnail
(`content_thumbnail_url`, computed by `sirsoft-board` at save time) and
150-character summary (`content_preview`, computed via a DB-level
`SUBSTRING`) are already part of `sirsoft-board`'s core list API response for
every board type; this plugin only changes how they're laid out.

## Requirements

- Gnuboard7 `>= 7.0.10`
- Module **`sirsoft-board >= 1.1.2`**
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
thumbnail list; nothing else needs configuring.

## Uninstalling

```bash
php artisan plugin:uninstall g7-webzine-addon
```

Uninstall is refused while a board still uses the `webzine` type — change or
delete that board first. There is no `--delete-data` variant since this
plugin owns no database tables.

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
