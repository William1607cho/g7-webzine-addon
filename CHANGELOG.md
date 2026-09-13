# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
