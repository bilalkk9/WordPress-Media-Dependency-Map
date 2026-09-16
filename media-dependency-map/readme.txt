=== Media Dependency Map ===
Contributors: mbilalkk
Tags: media, attachments
Requires at least: 6.6
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Development preview of a local Media Library dependency inspector by Bilal.

== Description ==

This development preview installs reference storage and a permission-protected screen under Media > Dependency Map.
Read-only WP-CLI inspections detect supported core references in one post or the current site's identity settings.
Whole-site scanning, the dependency browser, integration adapters, replacement, and rollback are not yet available.
No site content is sent to an external service. No telemetry is included.

== Installation ==

1. Copy the media-dependency-map folder into wp-content/plugins.
2. Activate Media Dependency Map on your test site.
3. Open Media > Dependency Map.

== Frequently Asked Questions ==

= Does this preview find unused media? =

No. Whole-site scanning is under development. Inspection reports known references and partial coverage, not a guarantee that a file is unused.

= How do I inspect a post? =

Run `wp mdm inspect 123 --user=admin` with an authorized WordPress user and a post ID.
Run `wp mdm identity --user=admin` for site identity and stored core image widget references.
Run `wp mdm status --user=admin` for availability. These diagnostics return JSON and do not populate the index.

= What happens on deactivation or uninstall? =

Deactivation stops scheduled work and keeps data. Uninstall keeps data by default.

== Changelog ==

= 0.2.0 =
* Added transactional reference storage and schema upgrades.
* Added conservative attachment URL and registered image-size resolution.
* Added core post, Gutenberg, HTML, explicit media shortcode and site identity inspection.
* Added permission-checked WP-CLI diagnostics and integration fixtures.

= 0.1.0 =
* Initial development foundation and protected admin screen.
