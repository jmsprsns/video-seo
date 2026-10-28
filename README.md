# Video SEO

Gets your videos into Google's video results. Finds every YouTube, Vimeo and self-hosted video on the site, adds the VideoObject schema Google needs (with key moments), and builds a video sitemap, with no setup per post. Free, with no Pro tier.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/video-seo/).

## What it does

- **Detection.** Every post of the scanned types is checked when it's saved, and a background scan covers existing content after activation. Videos are found in block markup, classic content, shortcodes, iframes and page builders' saved layouts (`_elementor_data`, Bricks, Beaver Builder, Oxygen, Thrive, Themify, Cornerstone, Breakdance; Divi and WPBakery are shortcodes in the content). Only embeds count: links, URLs mid-sentence and code samples are skipped.
- **Details.** YouTube Data API v3 when a key is saved (50 videos per request, 1 quota unit), else YouTube oEmbed (title and thumbnail only). Vimeo oEmbed, sent with the site as referrer so domain-restricted videos answer. Video files: the media library's metadata, or getID3 on files under `wp-content`. Files on other hosts are never downloaded.
- **Schema.** Joins Yoast SEO (`wpseo_schema_graph`), Rank Math (`rank_math/json_ld`) and All in One SEO (`aioseo_schema_output`) graphs, linked to their WebPage node. A VideoObject they already have for the same video is filled in, not repeated. Otherwise (no SEO plugin, SEOPress, or schema off for the page) a standalone JSON-LD block is printed in `wp_head`.
- **Key moments.** Description lines like `0:00 Intro` (three or more, starting at 0:00, in order) become `Clip` parts. Each links to the page with `?t=seconds`; `assets/front.js` starts the video there (`vseo_video=2` picks a later video).
- **Sitemap.** `/video-sitemap.xml` (or `?vseo_sitemap=main` without pretty permalinks), split into `/video-sitemap-N.xml` files past 1,000 pages. Published, non-password, non-noindex pages only (Yoast, Rank Math, SEOPress and AIOSEO noindex respected). Listed in robots.txt and in the SEO plugins' sitemap indexes.
- **Main content check.** Flags pages where the first video comes after 300+ words (and a third of the text), or the page has 2,000+ words.
- **Editor panel.** Block editor sidebar (or a classic editor box): each video's details and status, key moments, the main content check, and per-page changes to title, description, thumbnail and transcript, plus "leave out".
- **Lightweight embeds.** Optional click-to-play previews for YouTube and Vimeo (off, every video except each page's first, or all).
- **Report.** Content Powered > Video SEO: videos with the pages they're on and schema status, pages with the main content check and sitemap status, the sitemap, and settings.

## Data

- `{prefix}vseo_videos`: one row per video (key `youtube:ID`, `vimeo:ID` or `file:md5`), with fetched details, `status` (pending/ok/error/unavailable) and report `state`.
- `{prefix}vseo_post_videos`: which videos each post has, in page order.
- Post meta `_vseo_scan` (scan summary, only on posts with videos), `_vseo_flag` (main content warning), `_vseo_overrides` (per-page changes).
- Cron: `video_seo_scan_batch`, `video_seo_fetch`, `video_seo_daily` (refreshes details older than 30 days, removes videos no page uses).

## Filters

| Filter | Use |
|---|---|
| `video_seo_post_types` | Post types scanned |
| `video_seo_meta_keys` | Post meta keys read for videos, besides the content |
| `video_seo_detected_videos` | Videos found in a post |
| `video_seo_video` | A video as built for a page |
| `video_seo_schema_node` | A VideoObject before output |
| `video_seo_sitemap_exclude_post` | Leave a page out of the sitemap |

## Automatic updates

Updates come from https://github.com/jmsprsns/video-seo through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `video-seo.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `video-seo.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'VIDEO_SEO_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'VIDEO_SEO_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
video-seo.php                         Plugin header, constants, boot, activation
uninstall.php                         Removes tables, options, post meta and cron jobs
readme.txt                            WordPress readme; feeds the "View details" popup
includes/
  class-video-seo-plugin.php          Main singleton, settings, scanned post types
  class-video-seo-db.php              The two tables and their queries
  class-video-seo-detector.php        Finds embedded videos in content and builder data
  class-video-seo-fetcher.php         YouTube API / oEmbed, Vimeo oEmbed, file metadata
  class-video-seo-video.php           A video on a page: merged details, schema, key moments, main content check
  class-video-seo-scanner.php         Scans on save, background scan, cron jobs
  class-video-seo-schema.php          VideoObject output and SEO plugin graphs
  class-video-seo-sitemap.php         /video-sitemap.xml, robots.txt, sitemap indexes
  class-video-seo-frontend.php        Click-to-play previews, transcripts, ?t= markers
  class-video-seo-editor.php          Editor panel and its REST routes
  class-video-seo-admin.php           Content Powered > Video SEO page and Plugins-screen links
  class-video-seo-videos-table.php    Video report table
  class-video-seo-pages-table.php     Pages table
  class-video-seo-menu.php            Shared top-level "Content Powered" menu
  class-video-seo-feedback.php        Shared Submit Feedback and Report a Bug pages
  class-video-seo-updater.php         GitHub update checks (PUC wiring)
  class-video-seo-icons.php           Inline Lucide and Simple Icons helper
assets/
  admin.css, admin.js                 Loaded only on the plugin's page
  editor.js, editor.css               Editor panel (block editor and classic)
  front.js                            Front end: previews and key-moment start times
  icon.svg                            Icon on the update screens
  content-powered.svg                 Sumo logo for the Content Powered menu
icons/                                Lucide icons used by the plugin (plus LICENSE)
plugin-update-checker/                Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every Content Powered plugin puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-video-seo-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-video-seo-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icon set in `icons/`. Print one inline with:

```php
echo Video_SEO_Icons::get( 'package', 20 );           // name, size in px
echo Video_SEO_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Simple Icons](https://simpleicons.org) brand icons** (`icons/simpleicons/`): CC0 1.0 (public domain). Brand names and logos are trademarks of their respective owners. Using one doesn't imply endorsement.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
