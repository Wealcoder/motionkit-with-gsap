=== MotionKit With Gsap ===
Contributors: wealcoder
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
License: Proprietary

Plays the animations you build in the MotionKit editor on your WordPress site.

== Description ==

MotionKit With Gsap plays the animations you build in the MotionKit editor on your WordPress site.

* Animation Engine - runs your scroll, page-load, click and hover animations.
* GSAP - loads GSAP and only the GSAP plugins you switch on.
* ScrollSmoother - smooth scrolling for the whole site or a single page.
* Page Transition - animated transitions between pages.
* Preloader - a loading screen that covers the page until it is ready.

Works together with the free MotionKit plugin from WordPress.org, which connects your site to the editor. Requires WordPress 6.7 or later and PHP 7.4 or later.

== Changelog ==

= 2.0.3 =
* Improve: The update check reads the latest version from the public release first, and from the store if that does not answer.

= 2.0.2 =
* Improve: Updates and auto-updates work on every site, with or without a license key.
* Remove: The license key field in the plugin row, which updates no longer need.

= 2.0.1 =
* Update: The animation engine is rebuilt from the latest MotionKit editor.
* Tweak: The translation template is refreshed for this version.

= 2.0.0 =
* New: Animated posts carry the MotionKit logo in the post lists; switch it off from Screen Options.
* New: Page transitions run only when the site's MotionKit plan includes them.
* Security: The editor cannot save to a disconnected site, and editor session secrets are cleared when the site reconnects.
* Improve: The editor's page search matches post titles only, for more accurate results.
* Update: The animation engine is rebuilt from the latest MotionKit editor.

= 1.7.2 =
* Tweak: The license key field appears only on sites that have used MotionKit for a month without a key.
* Improve: The editor's page list shows how many animations each page has.
* Improve: View details shows the bundled description, changelog and icon when the store has none.
* Fix: The Plugins row shows one Visit site link instead of two.
* Update: The animation engine is rebuilt from the latest MotionKit editor.

= 1.7.1 =
* Update: The animation engine is rebuilt from the latest MotionKit editor.

= 1.3.1 =
* Security: Hardened the REST endpoints, the session check and the signing secret.
* Security: The core MotionKit plugin is installed from WordPress.org only when you ask for it.
* Fix: The core plugin stays connected when this plugin is uninstalled.
* Fix: JavaScript optimizers and Rocket Loader no longer break the editor preview.
* Fix: The editor preview keeps working on sites that restrict the REST API.
* Fix: Backslashes in saved animation settings are kept.
* Fix: Removing the old MotionKit Connector never touches this plugin's own folder.
* Tweak: Renamed from MotionKit Connector to MotionKit With Gsap.

= 1.1.0 =
* New: A license key field in the plugin row, shown when your MotionKit account gives no key.
* Fix: The license key is kept when the server sends none.
* Fix: GSAP plugins you switch off are no longer loaded for visitors.

= 1.0.1 =
* New: Updates from MotionKit, with a Check for updates link in the plugin row.
* New: The editor knows which MotionKit plugins the site has.
* Fix: Switching off a GSAP plugin respects the global settings.
