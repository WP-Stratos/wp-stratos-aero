=== Aero • Minify, Compress and Cache HTML, CSS & JavaScript ===
Contributors: wpstratos
Tags: minify, compress, html, css, javascript, js, performance, load, psi, pagespeed insights
Requires at least: 3.5
Tested up to: 7.0.4
Stable tag: 2.10.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A lightweight plugin that minifies, compresses, and caches HTML, CSS, and JavaScript to improve your website's load speed.

== Description ==
**Aero is a performance plugin that minifies and caches your HTML, CSS, and JavaScript, converts your images to WebP and AVIF, and runs an Edge Cache CDN layer with automatic cache warming after every purge.**

Turn it on and Aero starts compressing HTML, inline CSS, and JavaScript right away. Smaller files load faster, and that usually shows up as a better score in Google PageSpeed Insights and GTmetrix. Edit a stylesheet or add a script, and Aero rebuilds the minified copy on its own.

Aero also converts your media library to WebP and AVIF locally, on your own server. No third-party service, no images leaving your hosting environment. Quality presets, per-format sliders, and three delivery modes cover everything from a standard .htaccess rewrite to a picture-tag mode for servers that ignore .htaccess.

On the caching side, Aero is built to work with Batcache and Edge Cache rather than around them. Most plugins leave your homepage cold after a purge until the next visitor pays that cost. Aero's Cache Warmer rebuilds the cache in the background right after a flush instead.

To check whether the plugin is working, view your site source (press Ctrl + U). Near the bottom of the source you should see a line like this:

*** Optimized by Aero vX.X.X - https://wpstratos.com ***

**Features:**
* Automatic HTML, CSS, and JavaScript minification
* Automatic cache updates when source files change
* Dark mode admin interface with a responsive grid layout
* Real time cache statistics sidebar
* Improves Google PageSpeed Insights scores
* Zero configuration required, works out of the box
* Optional fine tuned control through the settings page
* Non render blocking CSS loading, clears PageSpeed's render blocking warnings
* Noscript fallback for accessibility
* Advanced settings accordion for power users
* One click cache clearing from the WordPress admin toolbar
* Local WebP and AVIF image optimization, three delivery modes
* Edge Cache CDN layer with Defensive Mode
* Background Cache Warmer that refills cache after a purge
* Responsive design, works on every screen size

#### Documentation

Full docs: [docs.wpstratos.com/plugins/aero/overview](https://docs.wpstratos.com/plugins/aero/overview)

* [Cache](https://docs.wpstratos.com/plugins/aero/caching): Batcache purge triggers, per page flushing
* [Edge Cache](https://docs.wpstratos.com/plugins/aero/edge-cache): CDN layer, Defensive Mode
* [Cache Warmer](https://docs.wpstratos.com/plugins/aero/cache-warmer): background rebuilds after a flush
* [Purge and Schedule](https://docs.wpstratos.com/plugins/aero/purge-schedule): flush order, WP-Cron safety net
* [Optimization](https://docs.wpstratos.com/plugins/aero/optimization): minification, JS deferral, async CSS, fonts
* [Images](https://docs.wpstratos.com/plugins/aero/images): WebP and AVIF conversion, media replacement
* [Experimental](https://docs.wpstratos.com/plugins/aero/experimental): Guest Mode, cache isolation
* [Debug](https://docs.wpstratos.com/plugins/aero/debug): diagnostic report for support tickets

Cache issues after a flush? See [Cache Issues](https://docs.wpstratos.com/troubleshooting/cache-issues). Aero reactivates automatically since it's managed hosting; to disable one feature instead of the whole plugin, see [Plugin Reactivation](https://docs.wpstratos.com/troubleshooting/plugin-reactivation).

#### Development & Support

Visit [WP Stratos](https://wpstratos.com) for support, or the [Aero documentation](https://docs.wpstratos.com/plugins/aero/overview) above for module specific detail.

#### Credits

Developed by [WP Stratos](https://wpstratos.com).

Built with minification libraries by Steve Clay and Matthias Mullie. Neither library is actively maintained anymore, but the work still holds up, and we're glad to build on it.

The image optimization engine is derived from CompressX (GPL-3.0-or-later, WPvivid Team) and rebuilt from the ground up to fit Aero's interface.

== Installation ==
= Automatic Installation (Recommended) =
1. Go to your WordPress Dashboard, then Plugins, then Add New.
2. Search for `Aero`.
3. Click Install Now, then Activate the plugin.
4. That's it. The plugin is ready to use.

= Manual Installation (Upload via WordPress Dashboard) =
1. Download the latest version of the plugin (.zip file).
2. In your WordPress Dashboard, go to Plugins, then Add New, then Upload Plugin.
3. Click Choose File, select the downloaded .zip file, then click Install Now.
4. Once installed, click Activate Plugin.

= Manual Installation (FTP/SFTP Method) =
1. Download and extract the plugin .zip file.
2. Connect to your server via FTP/SFTP.
3. Upload the extracted folder to /wp-content/plugins/.
4. In your WordPress Dashboard, go to Plugins and activate `Aero`.

== Frequently Asked Questions ==
= What does this plugin do? =
It minifies, compresses, and caches your HTML, CSS, and JavaScript, and it optimizes the images in your media library to WebP and AVIF.

= Do I need to do anything when I modify an original CSS or JS file? =
No, you don't need to do anything. Aero automatically updates the minified and compressed version of the file whenever the original is modified.

= Any specific requirements for this plugin to work? =
No. Just activate it and it works.

= Does this work with other caching plugins? =
Yes, Aero works alongside other caching plugins like WP Super Cache, W3 Total Cache, and WP Rocket.

= Where can I read the full documentation? =
[docs.wpstratos.com/plugins/aero/overview](https://docs.wpstratos.com/plugins/aero/overview), covering all eight modules plus troubleshooting.

= Why does Aero turn back on after I deactivate it? =
It's a managed plugin on WP Stratos hosting, so it reactivates on the next update cycle by design. To turn off one feature instead, see [Plugin Reactivation](https://docs.wpstratos.com/troubleshooting/plugin-reactivation).

== Screenshots ==
1. Dark Mode Admin Settings
2. Minified CSS & JS files served during page load
3. Reduced HTML source size after compression
4. Performance impact visualization
5. Google PageSpeed Insights improvement

== Changelog ==

= 2.10.1 =
* Image optimization now stays switched off on any site that was not already using it. Turning a feature this significant on by itself during a routine update would be an unwelcome surprise, and on a site already running another optimizer it would create the very conflict Aero warns about. Sites that were already running Aero's optimizer keep it on and see no change: the decision is made once from evidence the earlier version left behind, such as its settings, its optimization tasks, rows in its meta table, or files it generated. A site that has explicitly set the switch either way always keeps its own choice.
* Added a one-time notice introducing the optimizer on sites where it starts off, so the feature is discoverable rather than sitting dormant. The notice mentions any other image plugin already handling images, and retires itself as soon as the optimizer is switched on.
* A disabled optimizer no longer installs anything on activation. No rewrite rules are written and no delivery mode is changed; only the folders and the database table are created, so switching it on later needs no repair step.

= 2.10.0 =
* Added a master switch for the image optimizer. One toggle at the top of the Images screen stops all conversion, background processing and delivery rewriting, and unregisters the hooks rather than merely skipping them. Files already generated stay on disk, so switching back on picks up exactly where you left off. Work-starting requests are refused server-side while the switch is off, so a stale browser tab cannot queue a task that would then sit unprocessed.
* Aero now warns across wp-admin when a second image optimization plugin is active. Two optimizers hooking the same upload pipeline and delivery layer interfere with each other, and the symptoms usually look like Aero being broken rather than like a conflict. Around twenty dedicated optimizers are recognised. Plugins that merely include an optional image module, such as LiteSpeed Cache, WP-Optimize, Autoptimize and Jetpack, are only flagged when that module is actually switched on, so the warning stays meaningful. The notice can be dismissed, and returns if a different plugin shows up later.
* Background images in CSS are handled far more thoroughly. Every url() inside a background declaration is now wrapped individually, so gradient overlays and multi-layer stacks survive intact instead of being skipped. This is what page builder sections are built from, Elementor overlays especially.
* Stylesheet files are covered too. Page builders write their section CSS to real files that never pass through the page output, which is why those backgrounds kept serving originals. Each local stylesheet is processed once into a cached copy, with relative paths made absolute and backgrounds wrapped in image-set(). The cache is keyed by the source file's modification time, so a rebuild by Elementor, Divi or anything else produces a fresh copy automatically. There is a button to clear the processed copies by hand.
* Lazy-loaded backgrounds are handled. Lazy loaders keep their URLs in data attributes and apply them with their own JavaScript, out of reach of any server-side rewrite. Aero tags those elements with the derivatives that exist, and a small script, injected only on pages that need it, swaps them once it knows which formats the browser can decode.
* Added an image URL replace tool. Point every reference to one image at another across post content, custom fields, term meta and options. Serialized values are unpacked and rebuilt rather than string-replaced, which is what keeps them from being corrupted when the new URL is a different length, and the slash-escaped JSON form that Elementor stores its layouts in is handled as well. Every run starts with a check that reports exactly how many rows would change before anything is written, and caches are flushed through the sequential engine afterwards.
* The bulk progress bar now stays solidly filled when the library is fully optimized. Previously a finished library still showed an empty bar after a page reload, which read as though nothing had been done.

= 2.9.2 =
* Fixed the statistics permanently showing zero. The cause was a defect inherited from the upstream CompressX engine: after recomputing the aggregate from the meta table, the result was stored only in a short-lived freshness cache while both readers pulled from a database option that was never written. Optimized counts, space saved, and compression ratio always came back empty no matter how many images had been converted. The aggregate is now persisted where the readers look, and both readers heal older installs that computed stats before the fix.
* Statistics now update live during a bulk run. The engine clears its freshness gate after every batch, and the screen recomputes the band roughly every twelve seconds while optimization is in progress, so the donut and tiles climb as images are processed instead of sitting still until the end.
* Picture-tag delivery now covers CSS background images. Backgrounds declared in inline style attributes and in style blocks are augmented with an image-set() declaration listing the AVIF and WebP files that actually exist on disk, and the browser picks the best format it supports. The original declaration is kept first, so browsers without image-set() support are unaffected, and because nothing depends on request headers the HTML stays identical for every visitor, which keeps Batcache and Edge Cache safe. Gradient composites, multi-layer backgrounds, external hosts, and images without derivatives are left untouched. Backgrounds referenced inside external stylesheet files keep their original URLs, and the Delivery section says so.

= 2.9.1 =
* Bulk optimization now runs entirely in the background through a chained WP-Cron engine. Closing or reloading the Images page no longer stops a run: the server keeps processing on its own, a rescue event picks the chain back up if a batch dies mid-flight, and reopening the page resumes the live progress display exactly where the task stands.
* Fixed statistics showing zero after images had clearly been optimized. The screen was reading a cached aggregate that predated the run instead of honoring the engine's freshness gate. Stats now recompute from the meta table whenever optimization work has happened, and there is a hard reset path that recovers even from a crashed stats pass.
* Nginx is now detected automatically. Since Nginx ignores .htaccess files, Aero switches delivery to picture-tag mode on its own, which rewrites image markup in PHP at render time with no server configuration, php.ini, or wp-config changes. The .htaccess options are disabled in the interface with an explanation, existing installs are migrated on update, and an optional ready-made Nginx snippet is available for anyone who can edit the server config and prefers rewrites.
* Cancelling a bulk run is now reliable. Previously a batch in progress could write the task back to the database after it had been cancelled and quietly continue. A cancel request is now recorded as a flag the background driver checks at every batch boundary.
* The Images page was redesigned to match the rest of Aero. Statistics use the same donut-and-tiles band as the Optimization screen, with the ring showing the WebP versus AVIF composition of generated bytes, the optimized image count in the center, and tiles for space saved, average compression, generated file size, the active converter with format support, and the delivery method with the detected server. Sections were reordered into a sensible flow and the destructive restore action moved to the bottom.
* The delivery test now understands the active mode: in picture mode it verifies the pipeline itself instead of running a rewrite check that could never pass, and its result messages say precisely what was tested.
* Fixed several response-shape mismatches between the interface and the optimization engine that made progress reporting unreliable, including the scan progress fields, the live log stream, and single-image conversion from the media library.

= 2.9.0 =
* New Images screen: Aero now converts and compresses your media library to WebP and AVIF, locally on your own server. The optimization engine is derived from CompressX (GPL-3.0-or-later, WPvivid Team) and rebuilt into Aero's interface from the ground up.
* Bulk optimization runs in the background with a live progress bar, per-image log streaming, cancel support, and a re-optimize-everything option for when you change quality settings.
* New uploads can be optimized automatically the moment WordPress finishes generating thumbnails, with folder exclusions respected on upload as well as during bulk runs.
* Three delivery modes: .htaccess rewrite (original URLs, the server negotiates the format), a compatibility rewrite variant for CDNs and unusual document roots, and picture-tag replacement for servers that ignore .htaccess. A one-click delivery test confirms the browser is actually receiving converted files.
* Quality presets from lossless to maximum lossy, plus custom per-format sliders, converter selection between GD and Imagick with live capability detection, EXIF stripping, oversized-original resizing, per-thumbnail-size skipping, and per-format exceptions for PNG and JPG.
* Conversions that end up larger than the original are removed automatically so the smaller file always wins.
* Custom folders: point Aero at directories inside wp-content but outside the media library (theme assets, page-builder output) and optimize those too. Paths are validated server-side so the scanner can never wander outside wp-content or into cache and plugin directories.
* Media replacement: swap the file behind any attachment without changing its URL or ID. Thumbnails regenerate, the image is re-optimized under your current rules, and Aero's caches are flushed through the sequential purge engine so the new image appears everywhere at once.
* Media library integration: an Aero Images column in list mode and a panel on the attachment screen show original size, WebP and AVIF savings, and thumbnail counts, with one-click convert and delete per image.
* When a bulk run finishes, Aero can flush its caches through the sequential purge order (object cache, then Batcache, then Edge) so pages start serving the new formats immediately. This is optional and on by default.
* Restore deletes every generated file and all optimization data in one click. Originals are never modified by conversion, so restoring returns the site to its exact prior state. Uninstalling the plugin performs the same cleanup automatically, including the rewrite rules.
* License updated to GPL-3.0-or-later to accommodate the bundled CompressX-derived engine.

= 1.0.0 =
* Initial release by WP Stratos
* Dark mode admin interface with full body background (#000)
* Responsive CSS Grid layout with main content + sidebar
* Real-time cache statistics sidebar showing file counts and sizes
* Automatic HTML, CSS, and JavaScript minification
* Smart cache management with automatic updates
* Zero configuration required - works out of the box
* Non-render-blocking CSS loading eliminates PageSpeed Insights warnings
* Asynchronous CSS loading with noscript fallback
* Advanced settings accordion for power users
* Toggle for async CSS (enabled by default)
* Admin toolbar integration for quick cache clearing
* Custom brand color (#2e5aac) throughout interface
* Responsive design for desktop, tablet, and mobile
* Inline SVG logo, no image dependencies
