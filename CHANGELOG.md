# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - 1.5.0

### Added

- **Settings tabs.** The settings screen is split into **Basic** (all existing items, unchanged) and
  **Design**. Existing saved values and keys are not touched.
- **Design settings** — list width (current / same as the post body / custom px, 480-1600),
  list thumbnail size (fit row height, default / custom 48-200 px) and summary lines (1-3,
  default 2). Below 768 px the list is always full width. Applied to every webzine board.
- **Fixed row height (768 px and wider).** Each list row is as tall as one title line + the summary
  lines setting + the author line, so rows with short summaries keep the same height (the space
  below stays empty). Titles stay on one line with an ellipsis, as before.
- **Thumbnail "fit row height" (new default).** At 768 px and wider the list thumbnail is a square
  as tall as the row (78 / 98 / 118 px for 1 / 2 / 3 summary lines); below 768 px it stays 80 px.
  This changes the look at 768 px and wider after updating; choose the custom size 80 to get the
  previous thumbnail size back (rows keep the fixed height). A custom size taller than the row makes
  the row as tall as the thumbnail.
- **Random box above the list (off by default).** Chosen webzine boards can show a random set of
  posts as cards above the list, using the template's gallery card (or a built-in card if the
  template has none). Options: count, columns, aspect ratio, box width (same as the list /
  original template width / follow the post body width / custom 480-1600 px), heading, candidate
  range (all up to the latest 500, or latest N), only posts with images, exclude notices, and either
  a new set on every reload or the same set for N minutes.
- Box cards show the image (or lock / fallback image), a badge and the title only; author, date,
  view and comment counts are left out of the copied card.
- **"Recommended" badge** on each box card (top left, trophy icon, site primary colour). On by
  default with the text "추천게시물"; it can be turned off or given another text (plain text only,
  up to 20 characters; an empty text uses the default). The badge is drawn below the lock overlay.
- Shown only on the first list page, on screens at least 768 px wide, and never while searching or
  filtering by category. It is not added to the bot SSR page.
- `GET /api/plugins/g7-webzine-addon/random-box/{slug}` — same `posts.read` permission as the board
  list. The response has no post body or author e-mail. `data-g7wz-randombox`, `data-g7wz-boxdesign`
  and `data-g7wz-design` attributes show which card source, box width and design values were applied.
- Admin endpoint `GET /api/plugins/g7-webzine-addon/admin/webzine-boards` for the board picker.
- Unit tests for settings normalization, badge text cleanup, candidate selection, the seeded pick,
  the card copy, the box width placement, the row height formula and the design layout changes.

### Changed

- Thumbnails in the random box use g7-image-delivery 960/1600 variants through its public contract
  when available; otherwise the original thumbnail address. Not a required dependency.

### Unchanged

- Basic tab behaviour and saved keys, the public contract `WebzineCards` (`VERSION = 1`), bot SSR
  output of the list, the list below 768 px. No migration.

## [1.4.0] - 2026-09-27

### Changed

- **List buttons follow the template.** The webzine list's write buttons (top and bottom), admin
  links, include-deleted toggle and empty-state buttons are now copied from the template's own
  card list (then gallery, then basic) in the compiled `board/index` layout, instead of the add-on's
  fixed copy. On `wc-community` fork-20260926 they become the same icon buttons with tooltips,
  `aria-label` and the pressed state on the toggle; on `sirsoft-basic` they stay the template's text
  buttons. Nodes are found by the template's `data-testid` markers first, then by their permission
  expressions; list rows are never used. A slot found neither way keeps the 1.3.0 button and logs a
  `warning`.

### Added

- `data-g7wz-controls` on the webzine branch `Div` — shows which branch the buttons came from and
  how each slot was found, so a template change that breaks the lookup can be seen without logs.
- Unit tests for the lookup (`tests/Unit/TemplateListControlsTest.php`): marked and unmarked
  templates, list rows ignored, card → gallery → basic order, the add-on's own branch never used as
  a source, fallback to the add-on's buttons.

### Unchanged

- The thumbnail list (thumbnail, summary, badges, colours), settings, routes and the public
  contract `WebzineCards` (`VERSION = 1`). No migration.

## [1.3.0] - 2026-09-26

### Added

- **Public contract for other extensions** — `Plugins\G7\Webzine\Addon\PublicApi\WebzineCards`
  (`VERSION = 1`) with one static method `cards(array $items): array`. g7-home-widgets uses it for
  its webzine-style recent posts widget; other extensions should call only this class, never the
  add-on's internal classes (`Support\*` and so on).
  - Input: post items shaped like a subset of the core `PostResource` — `id` (int, required),
    `is_secret`, `status`, `deleted_at`, `thumbnail`. Other keys are ignored; at most 100 items.
  - Output: post id => `summary` (?string, up to 150 characters including the ellipsis, the same
    recalculation as the webzine list), `thumbnail` (?string, the input value), `fallback_image`
    (?string, only for posts without a thumbnail, when the add-on is set to show a fallback image).
  - **Secret posts get `null` for all three**, even if a thumbnail is passed in. Posts that are not
    `published` or are deleted get no summary.
  - It does **not** check permissions: callers pass posts they already filtered through the core
    board services. One query reads the start of the bodies, however many items there are.
  - **This input/output is a promise.** It stays the same when the internals change; an
    incompatible change raises `VERSION` and is noted here. Callers check the plugin is active
    (`findActiveByIdentifier('g7-webzine-addon')`) and `class_exists(WebzineCards::class) &&
    WebzineCards::VERSION >= 1`.

### Unchanged

- The webzine board list (layout, summary recalculation, thumbnails, fallback image), settings
  schema and routes are the same as 1.2.0. No migration.

## [1.2.0] - 2026-09-21

### Added

- List summaries on webzine boards are recalculated from the post body. The core builds them from
  the first 200 characters of the **raw HTML**, so a post that opens with an image spends that
  whole budget on `<figure class="image"><img …></figure>` and ends up with an empty summary. Two
  more core defects ride along: `&nbsp;` decodes to U+00A0 but the whitespace collapse has no `/u`
  modifier and `trim()` only strips ASCII, so a leading non-breaking space survives; and the
  ellipsis is appended *after* the 150-character cut, making the result 153.

  Measured across 127 posts on ten webzine boards: of the 125 with any text, only 66 (52.8%) could
  reach 150 characters. The rest were cut short even though the post was long enough.

  **This is a temporary workaround for a core defect.** It lives in one middleware and one pure
  class; when the core is fixed, delete the `getMiddleware()` entry and both files. Nothing else —
  no layout, no frontend, no schema — was touched.

  Only the visitor board-list route is affected, and only for `webzine` boards. The admin list is
  left alone. Bot SSR calls the same route internally and therefore gets the same result.

- A 240px-wide WebP derivative of the fallback image, built when the image is saved and used by the
  list. The fallback is drawn in an 80x80 CSS px box but the stored original is much larger (one
  site: 335x335, 135,548 B). The original is kept for the settings preview and for replacing it.

  `g7-webzine-addon:build-fallback-thumb` (with `--dry-run`) builds the derivative for an image
  that was already saved under 1.1.0. Sites that never run it keep serving the original — the list
  falls back to the original address and looks exactly as it did.

  Requires imagick. This environment's GD is a bundled build with no WebP encoder, which is why
  g7-image-delivery uses imagick too. Without imagick no derivative is built and a warning is
  logged; nothing breaks.

- `loading="lazy"`, `decoding="async"`, `width="80"` and `height="80"` on list thumbnails and on
  the fallback image.

### Changed

- The fallback image is drawn **only on rows that have no thumbnail**. Until now it was a base
  layer under every row, with the thumbnail painted on top — so a row with a real thumbnail still
  downloaded the fallback, fully hidden, on every list page view. The failure-fallback script now
  looks for the base layer in the DOM instead of inferring it from the configured mode.

- The comment-count icon and number use `text-orange-700` / `dark:text-orange-600` instead of
  `text-blue-600` / `dark:text-blue-400`. The grey treatment for blinded and deleted posts is
  unchanged.

## [1.1.0] - 2026-09-16

### Added

- **Admin settings page** (`/admin/plugins/g7-webzine-addon/settings`, also a
  sidebar menu entry) with a single decision — what a post with **no
  thumbnail** shows in the list: *summary only*, the *default placeholder*
  box, or an admin-supplied *fallback image*.
- **Fallback image**, from either an upload or an image URL (mutually
  exclusive), with a live preview and an alt-text field that defaults to the
  site name.
  - Uploads are stored in the plugin's own storage under a server-generated
    filename and served from
    `/api/plugins/g7-webzine-addon/fallback-image/{content-hash}`, so
    replacing the image changes its URL and invalidates browser/CDN caches
    at once (the response carries a one-year `immutable` cache header).
    The previous file is deleted when the new one is saved.
  - Uploads accept `png` / `jpg` / `jpeg` / `webp` / `gif` up to 2MB and
    **reject SVG**; the extension, the MIME type sniffed from the file's
    bytes, and a `getimagesize()` decode must all agree, so a disguised
    extension is refused.
  - Upload and settings write both require the core `core.plugins.update`
    permission. No setting value is exposed to the front end — only the
    fallback image's public URL.
- **Fallback for thumbnails that fail to load.** A post whose thumbnail URL
  is dead (an external image that was deleted, say) now falls back to the
  same setting instead of showing a broken image; if the fallback image
  itself fails, the row falls back to summary only. The placeholder and
  fallback image are rendered as a layer *under* the thumbnail, so the small
  script that handles this (layout JSON has no `error` event type) only ever
  hides the failed image — the choice of what shows instead is made
  server-side and works without JavaScript.

### Changed

- **Posts with no thumbnail now show summary only by default**, which was
  this add-on's original intent. 1.0.0 always drew the "no image"
  placeholder box, inherited from the core card layout the list markup was
  adapted from. Existing installs keep the placeholder only if an
  administrator picks it explicitly.
- Saving the settings bumps the core extension cache version, so a change
  takes effect on the next refresh rather than after the layout response's
  cache lifetime expires.
- The thumbnail image now paints on an opaque background when a layer sits
  beneath it, so transparent PNG thumbnails no longer let the placeholder
  text or fallback image show through at the edges.

## [1.0.0] - 2026-09-13

Initial public release.

### Added

- `webzine` board type, registered via `plugin.php::install()` + a
  `seed.sirsoft-board.board_types.translations` filter listener (survives
  `sirsoft-board`'s `BoardTypeSeeder` re-seeds), following the same pattern
  `g7-forum-addon` already uses for its `forum` type.
- `board/index` (list page) rendered as a 1-column list for `webzine`
  boards — a fixed 80×80px square thumbnail on the left, title (with
  notice/category/new badges) and a 150-character summary, author, and view
  / comment counts on the right. A `core.layout_extension.after_apply`
  filter listener inserts a `webzine`-only branch into the layout's
  type-dispatch tree and excludes `webzine` from the basic-fallback
  branch's condition. Posts with no thumbnail show a placeholder icon;
  secret/blinded/deleted posts keep their lock/translucency overlays.
- Thumbnails allow external (off-site) images for `webzine` posts, not just
  self-uploaded ones — via a `sirsoft-board.post.filter_content_thumbnail`
  filter listener, scoped to `webzine` boards only (other board types keep
  the core's internal-image-only default unchanged).
- No add-on database tables or API routes — the thumbnail and 150-character
  summary fields are already present in `sirsoft-board`'s core list API
  response for every board type.

`sirsoft-board` and the visitor template (`sirsoft-basic`) are never modified.
