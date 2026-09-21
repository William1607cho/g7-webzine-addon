# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
