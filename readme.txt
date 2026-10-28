=== Video SEO ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com/wordpress-plugins/video-seo/
Tags: video seo, video schema, video sitemap, youtube, key moments
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gets your videos into Google's video results: finds every YouTube, Vimeo and self-hosted video, adds VideoObject schema with key moments, and builds a video sitemap.

== Description ==

Most sites with videos give Google nothing to go on, so their videos never show up in video search, the Videos tab, or with a thumbnail in regular results. Video SEO fixes that automatically, with no setup per post.

* **Finds every video**: YouTube, Vimeo and video files, in posts, pages and custom post types. Works with the block editor, the classic editor, shortcodes and page builders (Elementor, Divi, Beaver Builder, Bricks, Oxygen, WPBakery and others). Only embedded videos count; a plain link isn't a video on the page.
* **VideoObject schema** for every video: title, description, thumbnail, upload date, duration, embed URL and view count.
* **Details pulled automatically** from YouTube (with a free YouTube Data API key) and Vimeo, and length and size read from your own video files.
* **Key moments**: chapter timestamps in a video's description ("0:00 Intro") become Clip markup, so Google can show jump-to-section links. Each link starts the video at that moment on your page.
* **Video sitemap** at /video-sitemap.xml that updates as videos are added or removed, listed in robots.txt and in your SEO plugin's sitemap index, ready to submit in Search Console.
* **Main content check**: flags pages where Google is unlikely to show a video result because the video isn't the main focus of the page.
* **Per-video changes** to title, description and thumbnail from a Video SEO panel in the editor's sidebar.
* **Lightweight embeds**: an optional click-to-play preview that shows the thumbnail until it's clicked, so YouTube and Vimeo scripts don't slow the page. By default each page's main video stays a real player, so Google still sees it.
* **Transcripts**: add one to a video and it goes into the schema, and can be shown on the page in a collapsible box.
* **Video report** listing every video on the site, the pages it's on, its schema status and any missing details.
* **Works with Yoast SEO, Rank Math, All in One SEO and SEOPress**: videos join their schema graph instead of adding duplicate markup, and a video they already describe is filled in rather than repeated.

Free, with no Pro tier.

Find it under Content Powered > Video SEO in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Privacy =

Video SEO asks YouTube (www.googleapis.com, www.youtube.com) and Vimeo (vimeo.com) for the public details of the videos embedded on your site. It sends the video's ID, your API key for YouTube's Data API, and your site's address. Nothing about your visitors is sent. With lightweight embeds on, YouTube and Vimeo players only load when a visitor presses play.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Brand icons by [Simple Icons](https://simpleicons.org), CC0 1.0. Brand names and logos are trademarks of their respective owners.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `video-seo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress. It scans your content for videos in the background.
3. Go to Content Powered > Video SEO > Settings and add a free YouTube Data API key (the page explains how).
4. Submit the video sitemap in Google Search Console (Content Powered > Video SEO > Video Sitemap).

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Do I need a YouTube API key? =

No, but it's worth five minutes. Without one, YouTube only shares a video's title and thumbnail. With one, the schema gets the real upload date, duration, description, view count and key moments. The key is free, and Video SEO uses one unit of the 10,000-unit daily quota per 50 videos.

= Why doesn't my video show in Google's video results? =

Since late 2023 Google only shows a video result when the video is the main content of the page. Check the Pages tab: it flags pages where the video comes after a lot of text or sits in a very long article.

= Will this create duplicate schema with my SEO plugin? =

No. With Yoast SEO, Rank Math or All in One SEO, the videos are added to that plugin's schema graph. If it already describes the same video, Video SEO fills in what's missing instead of adding a second VideoObject.

== Changelog ==

See https://github.com/jmsprsns/video-seo/releases for the full release history.
