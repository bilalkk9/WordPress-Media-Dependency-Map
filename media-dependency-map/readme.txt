=== Media Dependency Map ===
Contributors: mbilalkk
Tags: media, attachments
Requires at least: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Development preview of a local Media Library dependency inspector by Bilal.

== Description ==

This early development preview installs a permission-protected screen under Media > Dependency Map.
Scanning, integration adapters, replacement, and rollback are not yet available.
No site content is sent to an external service. No telemetry is included.

== Installation ==

1. Copy the media-dependency-map folder into wp-content/plugins.
2. Activate Media Dependency Map on your test site.
3. Open Media > Dependency Map.

== Frequently Asked Questions ==

= Does this preview find unused media? =

No. Scanning is under development. Even future scans will report known references and coverage, not guarantee that a file is unused.

= What happens on deactivation or uninstall? =

Deactivation stops scheduled work and keeps data. Uninstall keeps data by default.

== Changelog ==

= 0.1.0 =
* Initial development foundation and protected admin screen.
