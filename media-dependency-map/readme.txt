=== Media Dependency Map ===
Contributors: mbilalkk
Tags: media, attachments
Requires at least: 6.6
Requires PHP: 7.4
Stable tag: 0.6.0
Tested up to: 7.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Development preview of a local Media Library dependency inspector by Bilal.

== Description ==

This development preview installs reference storage and a permission-protected screen under Media > Dependency Map.
Resumable scans index supported core references, with incremental updates, searchable attachment details and CSV exports.
Registered Elementor media controls are scanned when its supported API is available.
ACF 6.x saved media fields are indexed across posts, terms, users, comments and default options.
WooCommerce product/variation images, galleries, local download URLs and category thumbnails are indexed.
Core featured images on non-product posts support dry-run replacement and conflict-aware rollback.
Other reference types are read-only. Media > Dependency settings controls deletion protection and journal retention.
No site content is sent to an external service. No telemetry is included.

== Installation ==

1. Copy the media-dependency-map folder into wp-content/plugins.
2. Activate Media Dependency Map on your test site.
3. Open Media > Dependency Map.

== Frequently Asked Questions ==

= Does this preview find unused media? =

No. The index reports known references and partial coverage, not a guarantee that a file is unused.

= How do I inspect a post? =

Run `wp mdm inspect 123 --user=admin` with an authorized WordPress user and a post ID.
Run `wp mdm identity --user=admin` for site identity and stored core image widget references.
Run `wp mdm status --user=admin` for scan status. Run `wp mdm scan --all --user=admin` to build the dependency index,
or `wp mdm scan --resume --user=admin` to continue saved work. Inspect and identity remain read-only diagnostics.

= What happens on deactivation or uninstall? =

Deactivation stops scheduled work and keeps data. Uninstall keeps data by default.

== Changelog ==

= 0.6.0 =
* Added WooCommerce media scanning.
* Added journaled featured-image replacement, rollback and conflict detection.
* Added opt-in deletion protection, journal retention and privacy information.

= 0.5.0 =
* Added field-aware ACF image/file and nested schema traversal.
* Added independent term, user and comment scan cursors and incremental invalidation.
* Added ACF source filtering, protected consumer links and integration fixtures.

= 0.4.0 =
* Added read-only Elementor document and registered control scanning.
* Added responsive breakpoint handling, nested repeater traversal and dynamic-value reporting.
* Added Elementor source filtering and coverage-aware index freshness.

= 0.3.0 =
* Added generation-based scans, fenced worker locks, checkpoints and incremental queues.
* Added an indexed upload-path resolver, synced-pattern traversal and implicit galleries.
* Added attachment search, reference details, coverage status, Media Library counts and CSV exports.
* Added administrator scan, resume, rebuild and reference CLI commands.

= 0.2.0 =
* Added transactional reference storage and schema upgrades.
* Added conservative attachment URL and registered image-size resolution.
* Added core post, Gutenberg, HTML, explicit media shortcode and site identity inspection.
* Added permission-checked WP-CLI diagnostics and integration fixtures.

= 0.1.0 =
* Initial development foundation and protected admin screen.
