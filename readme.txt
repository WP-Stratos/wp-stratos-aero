=== Aero • Minify, Compress and Cache HTML, CSS & JavaScript ===
Contributors: wpstratos
Tags: minify, compress, html, css, javascript, js, performance, load, psi, pagespeed insights
Requires at least: 3.5
Tested up to: 6.8
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A lightweight plugin that automatically minifies, compresses, and caches HTML, CSS, and JavaScript on demand to improve your website's load speed.

== Description ==
**Aero automatically minifies, compresses, and caches HTML, CSS & JavaScript files (inline and individual) on demand to enhance website's load speed.**

Once activated, the plugin seamlessly compresses HTML, inline CSS, and JavaScript, reducing file sizes for faster page loading. This optimisation helps improve your site's Google PageSpeed Insights and GTmetrix performance scores.

Additionally, Aero minifies individual JavaScript and CSS files, ensuring they load correctly and are automatically updated whenever the original files are modified or added — no manual settings needed!

Optimise your website effortlessly and deliver a faster, smoother experience to your visitors.

To check whether this plugin works properly, simply view your site source or press Ctrl + U from your keyboard. In the end of the source, you should see message something like:

*** Optimized by Aero vX.X.X - https://wpstratos.com ***

**Features:**
* ⚡ Automatic HTML, CSS, and JavaScript minification
* 🔄 Automatic cache updates when files are modified
* 🎨 Dark mode admin interface with responsive grid layout
* 📊 Real-time cache statistics sidebar
* 🚀 Improves Google PageSpeed Insights scores
* 💨 Zero configuration required - works out of the box
* 🔧 Optional fine-tuned control via settings page
* ⚡ Non-render-blocking CSS loading - eliminates PageSpeed render-blocking warnings
* 📱 Includes noscript fallback for accessibility
* 🎯 Advanced settings accordion for power users
* 🧹 Quick cache clear from WordPress admin toolbar
* 📐 Responsive design works on all screen sizes

#### Development & Support

Visit [WP Stratos](https://wpstratos.com) for support and documentation.

#### Credits

Developed by [WP Stratos](https://wpstratos.com).

Built with minification libraries by Steve Clay and Matthias Mullie. While these libraries are no longer actively maintained, their work has been invaluable.

== Installation ==
= Automatic Installation (Recommended) =
1. Go to your WordPress Dashboard → Plugins → Add New.
2. Search for `Aero`.
3. Click Install Now, then Activate the plugin.
4. The plugin is now ready to use!

= Manual Installation (Upload via WordPress Dashboard) =
1. Download the latest version of the plugin (.zip file).
2. In your WordPress Dashboard, go to Plugins → Add New → Upload Plugin.
3. Click Choose File, select the downloaded .zip file, and click Install Now.
4. Once installed, click Activate Plugin.

= Manual Installation (FTP/SFTP Method) =
1. Download and extract the plugin .zip file.
2. Connect to your server via FTP/SFTP.
3. Upload the extracted folder to /wp-content/plugins/.
4. In your WordPress Dashboard, go to Plugins and activate `Aero`.

== Frequently Asked Questions ==
= What does this plugin do? =
This plugin automatically minifies, compresses, and caches HTML, CSS & JavaScript files (inline and individual) to enhance website's load speed.

= Do I need to do anything when I modify an original CSS or JS file? =
No — you don't need to do anything. This plugin automatically updates the minified and compressed version of the file whenever the original is modified.

= Any specific requirements for this plugin to work? =
No. Just activate and it works.

= Does this work with other caching plugins? =
Yes, Aero works alongside other caching plugins like WP Super Cache, W3 Total Cache, and WP Rocket.

== Screenshots ==
1. Dark Mode Admin Settings
2. Minified CSS & JS files served during page load
3. Reduced HTML source size after compression
4. Performance impact visualization
5. Google PageSpeed Insights improvement

== Changelog ==
= 1.0.0, Date =
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
* Inline SVG logo - no image dependencies