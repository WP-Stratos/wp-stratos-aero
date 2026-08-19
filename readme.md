# Aero by WP Stratos

Aero is the performance plugin that ships pre-installed on every WP Stratos site. It handles four jobs that usually take four separate plugins: minifying and compressing HTML, CSS, and JavaScript, converting images to WebP and AVIF, running an Edge Cache CDN layer, and warming that cache after every purge so visitors never land on a cold page.

Turn it on and Aero starts compressing HTML, inline CSS, and JavaScript right away. Smaller files load faster, and that usually shows up as a better score in Google PageSpeed Insights and GTmetrix. Edit a stylesheet or add a script, and Aero rebuilds the minified copy on its own.

Aero also converts your media library to WebP and AVIF locally, on your own server. No third-party service, no images leaving your hosting environment. Quality presets, per-format sliders, and three delivery modes cover everything from a standard .htaccess rewrite to a picture-tag mode for servers that ignore .htaccess.

On caching, Aero works with WP Stratos's Batcache and Edge Cache rather than around them. Most plugins leave your homepage cold after a purge until the next visitor pays that cost. Aero's Cache Warmer rebuilds the cache in the background right after a flush instead.

To check that it's working, view your site source (Ctrl+U). Near the bottom you should see a line like this:

*** Optimized by Aero vX.X.X - https://wpstratos.com ***

## Documentation

Full docs: [docs.wpstratos.com/plugins/aero/overview](https://docs.wpstratos.com/plugins/aero/overview)

* [Cache](https://docs.wpstratos.com/plugins/aero/caching): Batcache purge triggers, per page flushing
* [Edge Cache](https://docs.wpstratos.com/plugins/aero/edge-cache): CDN layer, Defensive Mode for traffic spikes
* [Cache Warmer](https://docs.wpstratos.com/plugins/aero/cache-warmer): background rebuilds after a flush
* [Purge and Schedule](https://docs.wpstratos.com/plugins/aero/purge-schedule): flush order, WP-Cron safety net
* [Optimization](https://docs.wpstratos.com/plugins/aero/optimization): minification, JS deferral, async CSS, fonts
* [Images](https://docs.wpstratos.com/plugins/aero/images): WebP and AVIF conversion, media replacement
* [Experimental](https://docs.wpstratos.com/plugins/aero/experimental): Guest Mode, cache isolation
* [Debug](https://docs.wpstratos.com/plugins/aero/debug): diagnostic report for support tickets

Cache issues after a flush? See [Cache Issues](https://docs.wpstratos.com/troubleshooting/cache-issues). Aero reactivates automatically since it's managed hosting; to disable one feature instead of the whole plugin, see [Plugin Reactivation](https://docs.wpstratos.com/troubleshooting/plugin-reactivation).

## Installation
* Download the plugin from WP Stratos or GitHub
* Upload the folder to '/wp-content/plugins/'
* Activate the plugin through the 'Plugins' menu in WordPress
* Configure settings at Settings > Aero
* That's it

## Features
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

## Support
Visit [WP Stratos](https://wpstratos.com) for general support, or the [Aero documentation](https://docs.wpstratos.com/plugins/aero/overview) for module by module detail. The [Debug screen](https://docs.wpstratos.com/plugins/aero/debug) generates a diagnostic report worth pasting into any support ticket, and the [FAQ](https://docs.wpstratos.com/troubleshooting/faq) covers common questions.

## Credits
Developed by [WP Stratos](https://wpstratos.com).

Built with minification libraries by Steve Clay and Matthias Mullie.
