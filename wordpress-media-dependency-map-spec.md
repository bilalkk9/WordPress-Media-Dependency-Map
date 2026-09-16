# WordPress Media Dependency Map

## Product Requirements, Technical Architecture, and WordPress.org Delivery Specification

**Document status:** Implementation-ready specification  
**Intended implementer:** Bilal  
**Target distribution:** Free plugin published in the official WordPress.org Plugin Directory  
**Working product name:** Media Dependency Map  
**Working slug:** `media-dependency-map`  
**Text domain:** `media-dependency-map`  
**License:** GPL-2.0-or-later  
**Initial release target:** 1.0.0  

> Treat the product name and slug as provisional until exact WordPress.org, trademark, domain, and commercial-name availability have been checked. Do not claim that the plugin finds every possible reference. WordPress permits arbitrary plugins and themes to store data in undocumented formats, so the product must communicate coverage and confidence honestly.

---

## 1. Executive Summary

WordPress does not provide a dependable, native answer to a basic operational question:

> Where is this image, document, video, SVG, or other Media Library item being used?

A media attachment can be referenced through core post content, featured images, Gutenberg blocks, Elementor data, Advanced Custom Fields (ACF), WooCommerce products, term metadata, user metadata, options, widgets, customizer settings, reusable templates, shortcodes, CSS declarations, or third-party serialized data. The Media Library normally shows the file but not its complete dependency graph.

This creates three expensive risks:

1. A developer deletes an apparently unused file and silently breaks a page, template, product, or download.
2. A developer must manually search multiple systems before replacing an outdated image, logo, PDF, or document.
3. Media-cleanup tools can produce false positives because they do not understand builder-specific and field-specific storage.

Media Dependency Map will build a searchable index of known media references and display where each attachment is used, how it is referenced, which integration detected it, and how reliable that detection is. The plugin will support safe replacement only through storage adapters that explicitly implement and verify replacement. Unknown or heuristic matches will be reported but will never be automatically modified.

The initial product is a local, privacy-conscious WordPress plugin. It must not require a cloud account, transmit site content externally, or depend on a paid API.

---

## 2. Problem Statement

### 2.1 User problem

WordPress stores attachments as posts, but references to those attachments are distributed across unrelated storage mechanisms. Core WordPress has no universal reverse-reference index connecting an attachment to every consumer.

Site owners and developers therefore cannot confidently answer:

- Is this file genuinely unused?
- Which pages, posts, products, templates, fields, menus, or settings use it?
- Is it referenced by attachment ID, URL, filename, shortcode, block attribute, CSS, or serialized configuration?
- Will replacing or deleting it break desktop, tablet, mobile, a translated page, a template, or a hidden dynamic field?
- If a file URL changed after migration or offloading, which stored references are stale?
- Can the asset be safely replaced without changing attachment identity or damaging structured data?

### 2.2 Why ordinary search is insufficient

Media references may appear as:

- Numeric attachment IDs.
- Absolute or relative URLs.
- Original filenames or generated thumbnail filenames.
- Gutenberg block attributes.
- HTML `src`, `srcset`, `poster`, `href`, `data-*`, inline `style`, and CSS `url()` values.
- Elementor JSON stored in post meta.
- ACF scalar values, arrays, gallery fields, image fields, file fields, repeaters, groups, flexible content, clone fields, options, term fields, user fields, and relationship-adjacent metadata.
- WooCommerce product images, galleries, downloadable files, category thumbnails, variations, and extension-controlled settings.
- Theme mods, widgets, navigation metadata, site icons, logos, options, and customizer data.
- Serialized PHP arrays or JSON belonging to third-party plugins.
- References to alternate image sizes rather than the original attachment URL.
- Remote/CDN URLs created by offload or optimization plugins.

A raw SQL `%LIKE%` search is not a safe solution. Numeric attachment IDs create false positives, serialized values can be corrupted by careless replacement, URLs can exist in encoded or transformed forms, and direct database writes bypass WordPress APIs, cache invalidation, revision behavior, hooks, and plugin-specific consistency rules.

### 2.3 Business impact

The absence of a reliable dependency map causes:

- Broken images, PDFs, downloads, product galleries, and backgrounds.
- Slow and risky media-library cleanup.
- Expensive manual audits during redesigns and migrations.
- Duplicate assets because teams are afraid to replace existing files.
- Outdated brand files remaining in obscure templates.
- Inaccessible media metadata remaining undiscovered.
- Client disputes when a deleted file breaks content days later.
- Storage and backup growth caused by keeping everything indefinitely.

### 2.4 Existing-solution gap

Many existing media tools focus on one of these categories:

- Removing apparently unused files.
- Replacing a physical media file.
- Regenerating thumbnails.
- Organizing the Media Library into folders.
- Optimizing or offloading images.
- Searching post content for URLs.

The product gap is a transparent, extensible reverse dependency index that explains **where**, **how**, and **with what confidence** an attachment is used across WordPress core and major site-building ecosystems.

---

## 3. Solution Statement

Media Dependency Map will scan supported WordPress data sources, normalize discovered references to attachment IDs where possible, and store a reverse index of attachment-to-consumer relationships.

For each attachment, the plugin will show:

- Usage count.
- Referencing object type and title.
- Edit/view link for the consumer.
- Source adapter, such as Core Content, Featured Image, Gutenberg, Elementor, ACF, WooCommerce, Options, Widgets, or Theme Mods.
- Reference form, such as attachment ID, URL, block attribute, CSS URL, shortcode attribute, or structured field.
- Exact logical path where available, such as `background_image.url`, `gallery[2].id`, or an ACF field name.
- Detection confidence: exact, strong, heuristic, or unresolved.
- Whether the reference is replaceable automatically.
- Last scan time and whether the reference is current, stale, or unresolved.

The plugin will provide:

1. An attachment-centric dependency view.
2. A site-wide usage browser.
3. Incremental and full-site scans.
4. Extensible scanner adapters.
5. Safe, adapter-owned replacement with dry-run preview and rollback journal.
6. Deletion warnings based on indexed usage.
7. WP-CLI commands for agencies and large sites.
8. Privacy-respecting local processing.

---

## 4. Product Principles

The implementation must follow these principles:

### 4.1 Accuracy over impressive claims

Never label an attachment “unused” as an absolute fact. Use:

- **No known references found** when supported scanners found nothing.
- **Used** when at least one validated reference exists.
- **Possibly used** for heuristic or unresolved matches.
- **Not fully scanned** when coverage is incomplete.

### 4.2 Read broadly, write narrowly

Scanners may report heuristic matches. Replacement is allowed only when a registered adapter owns the storage format, can make a structured update, and can verify the result.

### 4.3 No destructive first-run behavior

Version 1.0 must not bulk-delete attachments. It may warn during deletion and link to dependencies. Cleanup/deletion automation is explicitly outside the MVP.

### 4.4 Local by default

All scanning and indexing occur on the WordPress site. No telemetry, external API, tracking, or remote content transfer may be added without a separate opt-in design, privacy documentation, and WordPress.org compliance review.

### 4.5 Graceful partial coverage

Unsupported plugins must not break scanning. The interface must show what was scanned, skipped, unavailable, or failed.

### 4.6 Performance is a feature

Never attempt an unbounded full database scan during a normal admin page request. Use resumable batches, locks, progress state, time budgets, and indexed tables.

### 4.7 Extensibility without tight coupling

Core scanners and integration adapters must use shared contracts. Optional integrations load only when their dependency is present.

---

## 5. Target Users and Jobs to Be Done

### 5.1 Primary users

- WordPress agencies managing multiple client websites.
- Developers working with Elementor, Gutenberg, ACF, and WooCommerce.
- Site administrators cleaning or reorganizing media.
- Content teams replacing logos, PDFs, product imagery, staff images, and campaign assets.
- Maintenance providers auditing sites before updates, redesigns, and migrations.

### 5.2 Primary jobs

#### Before deletion

> Before deleting an attachment, show every known place that may depend on it so I can avoid breaking the site.

#### Before replacement

> When an asset changes, show the impact and let me replace supported references safely.

#### During cleanup

> Help me distinguish referenced, possibly referenced, unreferenced, broken, and unscanned media.

#### During migration

> Find references that point to old domains, missing attachments, or obsolete upload paths.

#### During client handoff

> Give me a defensible report of where major assets are used.

---

## 6. Scope

### 6.1 Version 1.0 must include

- WordPress 6.6+ unless current plugin-directory expectations require a later minimum at implementation time.
- PHP 7.4+ for broad compatibility; prefer PHP 8.1+ internally where possible without using incompatible syntax. Re-evaluate minimum versions immediately before release.
- Single-site installations.
- WordPress multisite compatibility at the individual-site level; network aggregation is not required.
- Attachment list-table usage column.
- Attachment edit-screen dependency panel.
- Dedicated Media Dependency Map admin screen.
- Full scan and incremental rescanning.
- Core content adapter.
- Featured-image adapter.
- Gutenberg block adapter.
- Core site identity/theme adapter for custom logo, site icon, header/background images where supported.
- Elementor adapter when Elementor is active.
- ACF adapter when ACF or ACF Pro is active.
- WooCommerce adapter when WooCommerce is active.
- Exact ID and normalized URL matching.
- Strong/heuristic occurrence reporting.
- Deletion warning and optional deletion prevention when known references exist.
- One-at-a-time safe replacement for supported exact references.
- Dry-run replacement preview.
- Replacement operation log and rollback for changes performed by this plugin.
- WP-CLI scan, status, lookup, and reindex commands.
- Accessibility, internationalization, privacy, security, uninstall, testing, and WordPress.org packaging.

### 6.2 Explicitly out of scope for 1.0

- Automatic bulk deletion.
- Automatically generated alt text.
- Image compression, resizing, WebP/AVIF generation, or thumbnail regeneration.
- Media folders.
- Cloud dashboard or account system.
- Network-wide multisite dashboard.
- Guaranteed scanning of arbitrary custom database tables.
- Blind search-and-replace across serialized data.
- Replacing URLs inside compiled/minified CSS files.
- Editing PHP, JavaScript, CSS, theme files, or plugin files.
- Replacing remote media that cannot be resolved to a local attachment.
- Legal or accessibility compliance claims.
- Premium licensing code inside the WordPress.org release.

### 6.3 Future candidates

- Adapter SDK documentation and third-party adapter directory.
- WPML and Polylang language-aware grouping.
- REST API.
- Network dashboard.
- Broken-reference monitoring.
- Scheduled reports.
- Media duplicates by cryptographic hash.
- Offload-provider adapters.
- Exportable dependency graphs.
- Agency white labeling.
- CI checks that fail deployments when critical references break.

---

## 7. Terminology and Data Model

### 7.1 Attachment

A WordPress post with `post_type = attachment`.

### 7.2 Consumer

The WordPress object containing or owning a media reference, such as a post, term, user, option, widget, theme modification, product, Elementor template, or ACF options context.

### 7.3 Reference

A discovered relationship between one attachment and one logical location within a consumer.

### 7.4 Adapter

A component that knows how to enumerate a storage source, extract media candidates, validate references, describe their logical location, and optionally replace them safely.

### 7.5 Confidence

- **Exact:** The storage schema explicitly identifies an attachment ID and that attachment exists.
- **Strong:** A normalized local upload URL or recognized derivative URL maps uniquely to an attachment.
- **Heuristic:** A filename, ambiguous number, CSS fragment, encoded string, or unsupported structure suggests a match but cannot prove it.
- **Unresolved:** A media-like reference exists, but no local attachment can be mapped.

### 7.6 Replaceability

- **Replaceable:** The owning adapter implements structured replacement, permission checks, preview, verification, and rollback data.
- **Read-only:** The reference can be detected reliably but not safely changed.
- **Unsupported:** The match is heuristic or belongs to unknown storage.

---

## 8. Functional Requirements

### 8.1 Admin navigation

Create **Media → Dependency Map**. Do not add a top-level menu in version 1.0.

The screen must include:

- Search by attachment title, filename, ID, MIME type, consumer title, or URL.
- Filters for usage status, confidence, media type, adapter, replaceability, scan state, and upload date.
- Sort by usage count, last scanned, size, title, and upload date.
- Pagination using WordPress list-table patterns or an accessible equivalent.
- A clear scan-status summary.
- A “Start full scan” action.
- A “Resume scan” action after interruption.
- A “Rebuild index” action requiring confirmation.
- Coverage information listing active and unavailable adapters.

### 8.2 Media Library integration

Add a **Usage** column to the list view of Media Library attachments:

- `12 references` for confirmed references.
- `2 confirmed + 1 possible` when heuristic matches exist.
- `No known references` only after a complete applicable scan.
- `Not scanned` when no valid scan exists.

Do not overload the grid view in 1.0 unless it can be implemented using stable WordPress APIs without fragile JavaScript overrides.

### 8.3 Attachment details

On the attachment edit screen, show a dependency panel containing:

- Summary counts.
- Scan coverage and timestamp.
- Grouped references by adapter.
- Consumer title and type.
- Reference location/path.
- Confidence badge with accessible text.
- View and edit links when the current user has permission.
- Rescan-this-attachment action.
- Replace action only if at least one reference is safely replaceable.

### 8.4 Reference detail

Each reference record must expose:

- Attachment ID.
- Adapter ID and version.
- Consumer object type.
- Consumer object ID or stable key.
- Consumer display label.
- Context/subtype.
- Logical data path.
- Reference kind.
- Raw value fingerprint, not necessarily the full sensitive raw value.
- Confidence.
- Replaceability.
- First-seen and last-seen timestamps.
- Last validation state.

### 8.5 Full scan

A full scan must:

1. Acquire a site-level scan lock with expiry.
2. Create a scan run record.
3. Enumerate work per adapter.
4. Process small bounded batches.
5. Persist the cursor after every batch.
6. Refresh the lock heartbeat.
7. Record adapter errors without terminating unrelated adapters.
8. Mark references not seen in the completed adapter scan as stale, then remove or archive them according to retention policy.
9. Mark the run complete only when every enabled adapter has completed or has an explicit failure state.
10. Preserve the last known-good index if a rebuild fails.

The admin UI may drive background batches through REST/AJAX requests, but the scan engine must not depend on a browser remaining open. Provide WP-Cron continuation and WP-CLI processing. If Action Scheduler is used, bundle or integrate it in a conflict-safe manner; do not require WooCommerce solely to obtain it.

### 8.6 Incremental scanning

Schedule targeted rescans when supported objects change:

- Post save/update/trash/delete.
- Attachment metadata change.
- Term meta change where relevant.
- User meta change where relevant.
- Option/theme modification change where relevant.
- Elementor document save.
- ACF save events.
- WooCommerce product/variation save.

Debounce repeated changes. Do not perform expensive extraction synchronously during a content save. Queue targeted background work and remove old references for the affected consumer only after a successful rescan.

### 8.7 Deletion protection

Before permanent attachment deletion:

- If confirmed references exist, display a clear warning.
- Provide a setting: `Warn only` or `Prevent deletion when confirmed references exist`.
- Default to `Warn only` to avoid unexpected workflow changes.
- Never block deletion based solely on heuristic references.
- Respect capabilities and normal WordPress deletion flows.
- Do not interfere with automated cleanup tools without documenting the interaction.

### 8.8 Replacement workflow

Replacement means changing supported references from one existing attachment to another existing attachment. Physical in-place file replacement is not required in version 1.0.

Required flow:

1. User selects a source attachment.
2. User selects an existing target attachment.
3. Validate permissions, existence, MIME compatibility guidance, and target status.
4. Show a dry-run list grouped into replaceable, read-only, heuristic, and unresolved references.
5. Require explicit confirmation.
6. Create an operation journal before the first mutation.
7. Call only adapter-specific structured replacement methods.
8. Use WordPress/plugin APIs where available.
9. Verify each changed consumer by rescanning it.
10. Report success, skipped references, and failures individually.
11. Offer rollback for the recorded operation.

Rules:

- Do not automatically replace heuristic matches.
- Do not mutate unknown serialized values.
- Do not write directly to Elementor or ACF storage when their public APIs or supported update mechanisms are available.
- Do not treat a failed partial operation as fully successful.
- Preserve unrelated fields byte-for-byte or semantically unchanged.
- Flush only necessary caches.
- Create normal content revisions when WordPress supports them; the plugin journal remains required because not all consumers support revisions.
- If old and new MIME types differ materially, warn and allow adapters to reject replacement.
- Replacing an image with a PDF, or a PDF with an image, must be rejected unless the adapter explicitly supports the context.

### 8.9 Rollback

Rollback must:

- Be available only for operations executed by this plugin.
- Store sufficient before-state per changed logical field.
- Validate that the current value still matches the plugin’s post-replacement fingerprint before reverting.
- Refuse to overwrite later human edits silently.
- Support partial rollback and report conflicts.
- Require the same or stronger capability as replacement.
- Retain journals for a configurable period, default 30 days.

### 8.10 Reports and exports

Version 1.0 must support CSV export of filtered results. Escape spreadsheet-formula prefixes (`=`, `+`, `-`, `@`) to prevent CSV injection. Do not expose data the exporting user cannot normally inspect.

---

## 9. Adapter Requirements

### 9.1 Adapter contract

Define an internal interface similar to:

```php
interface MDM_Adapter_Interface {
    public function get_id(): string;
    public function get_label(): string;
    public function is_available(): bool;
    public function get_version(): string;
    public function get_coverage_description(): string;
    public function enumerate_consumers( array $cursor, int $limit ): MDM_Consumer_Page;
    public function scan_consumer( MDM_Consumer $consumer ): array;
    public function can_replace( MDM_Reference $reference ): bool;
    public function preview_replace( MDM_Reference $reference, int $target_attachment_id ): MDM_Replacement_Preview;
    public function replace( MDM_Reference $reference, int $target_attachment_id ): MDM_Replacement_Result;
    public function rollback( MDM_Journal_Item $item ): MDM_Rollback_Result;
}
```

Exact class names may differ, but responsibilities must stay separated. Provide filters/actions for registering adapters without allowing arbitrary code to bypass central authorization and journaling.

### 9.2 Core content adapter

Scan public and non-public post types that the current scan process is authorized to inspect. Exclude revisions and auto-drafts by default while documenting filters.

Recognize:

- `<img src>` and `srcset`.
- `<a href>` links to Media Library files.
- `<video poster>` and media sources.
- `<audio>` and `<video>` sources.
- `wp-image-{id}` classes.
- Gallery and playlist shortcodes.
- Core media shortcodes and attachment-ID attributes.
- Inline CSS `url()` references in post content.

Parse HTML with appropriate parsers rather than regex alone. Regex may be used for candidate discovery but must be followed by validation.

Replacement must update only parsed attributes or recognized shortcode attributes, preserve unrelated content, use WordPress update APIs, and create a revision where applicable.

### 9.3 Featured image adapter

Use core thumbnail APIs/meta semantics. This adapter provides exact detection and safe replacement.

### 9.4 Gutenberg adapter

Use `parse_blocks()` recursively, including inner blocks and reusable/synced pattern references where applicable. Register resolvers for known core block attributes:

- Image.
- Gallery.
- Media & Text.
- Cover/background.
- File.
- Audio.
- Video/poster.
- Navigation or site-logo-related blocks when stored in block content.

Preserve valid block serialization. Use `serialize_blocks()` after a structured change and verify the resulting block tree.

Unknown block attributes may produce read-only candidates when they contain a resolvable local media URL. Do not rewrite unknown block structures.

### 9.5 Core site identity and settings adapter

Where supported by public APIs, scan:

- Custom logo.
- Site icon.
- Custom header/background images.
- Theme modifications containing recognized attachment IDs or local URLs.
- Core image widgets and legacy widget settings.

Arbitrary option scanning is read-only and opt-in because options may contain secrets or large cached values. Exclude transients, rewrite rules, cron, sessions, caches, and known sensitive options. Never display full option values unnecessarily.

### 9.6 Elementor adapter

Load only when Elementor is active and compatible.

Requirements:

- Scan Elementor document data recursively rather than performing raw string replacement.
- Support image, gallery, background, background overlay, video poster, icon/image, carousel, slideshow, and link/file values when their schema is known.
- Support global templates and Theme Builder documents stored as Elementor documents.
- Record element ID, widget type, control name, responsive key, and nested path where possible.
- Distinguish attachment ID and URL representations.
- Handle responsive settings and nested containers.
- Use supported Elementor document/data APIs where available.
- Clear/regenerate only the necessary Elementor CSS/cache after replacement.
- Detect incompatible Elementor versions and downgrade to read-only rather than guessing.

Do not claim support for every third-party Elementor add-on. Allow those vendors to register their own control-path resolvers.

### 9.7 ACF adapter

Load only when ACF/ACF Pro is active and compatible.

Requirements:

- Enumerate active field groups and identify media-bearing fields from field definitions.
- Support Image, File, Gallery, Repeater, Group, Flexible Content, and Clone structures.
- Traverse nested field values using field keys and names.
- Support fields attached to posts, terms, users, comments, and options where ACF exposes the context safely.
- Account for return formats: ID, array, and URL.
- Treat the field definition as the authority; never classify every numeric meta value as an attachment.
- Use ACF APIs such as field-aware value retrieval/update when compatible and appropriate.
- Record the field key, field name, row/layout path, and object context.
- Preserve nested structures and return-format expectations.

If a field definition is missing but orphaned meta looks like a media reference, report it through a separate read-only heuristic scanner rather than the ACF structured adapter.

### 9.8 WooCommerce adapter

Load only when WooCommerce is active and compatible.

Support:

- Product featured image.
- Product gallery.
- Variation images.
- Product-category thumbnails.
- Downloadable product files, including local Media Library URLs where resolvable.

Use WooCommerce CRUD objects and public APIs. Do not mutate WooCommerce data using direct post-meta writes when CRUD setters exist. Unknown extension fields remain read-only.

---

## 10. Matching and Normalization

### 10.1 Attachment resolver

Create a central resolver that can map candidates to attachments using:

- Exact attachment ID.
- GUID only as a discovery signal, never as the sole universally reliable URL authority.
- Current attachment URL.
- `_wp_attached_file` upload-relative path.
- Generated metadata sizes and derivative filenames.
- Normalized scheme/host/path variants.
- Known current site URL and uploads base URL.

### 10.2 URL normalization

Normalization should:

- Decode safe HTML entities.
- Normalize scheme-relative URLs.
- Separate query strings and fragments for matching while retaining the original value.
- Normalize redundant path segments safely.
- Consider current and recognized prior site/upload domains only when configured or discovered with high confidence.
- Avoid lowercasing case-sensitive paths.
- Avoid assuming all CDN URLs map to local attachments.

### 10.3 Derivative images

Map registered intermediate-size filenames to their source attachment through attachment metadata. Do not rely solely on stripping `-WIDTHxHEIGHT`, because legitimate original filenames can match that pattern.

### 10.4 False-positive control

- Never infer an attachment from a standalone number without schema context.
- Require unique URL/path resolution for strong matches.
- If multiple attachments map to one candidate, mark it ambiguous and do not replace it.
- Store why a confidence level was assigned.

---

## 11. Persistence Architecture

Use dedicated custom tables rather than storing the dependency index in post meta or options.

Suggested tables, using `$wpdb->prefix`:

### 11.1 `mdm_references`

Suggested columns:

- `id` bigint unsigned primary key.
- `attachment_id` bigint unsigned nullable for unresolved references.
- `adapter_id` varchar(64).
- `adapter_version` varchar(32).
- `consumer_type` varchar(64).
- `consumer_id` bigint unsigned nullable.
- `consumer_key` varchar(191) nullable.
- `context` varchar(191) nullable.
- `data_path` text nullable.
- `reference_kind` varchar(32).
- `confidence` varchar(16).
- `replaceability` varchar(16).
- `value_hash` char(64).
- `candidate_preview` text nullable and sanitized/redacted.
- `scan_run_id` bigint unsigned.
- `first_seen_gmt` datetime.
- `last_seen_gmt` datetime.
- `status` varchar(16).

Indexes must cover attachment lookup, consumer lookup, adapter/status cleanup, and scan reconciliation. Create a deterministic uniqueness key from adapter, consumer identity, logical path, attachment/candidate, and reference kind.

### 11.2 `mdm_scan_runs`

- Run ID.
- Type: full, incremental, attachment, consumer.
- Status.
- Start/end timestamps.
- Adapter states/cursors stored in bounded JSON.
- Counts.
- Error summary.
- Plugin/schema version.

Do not place unbounded per-item logs in an autoloaded option.

### 11.3 `mdm_operations`

- Operation ID and type.
- User ID.
- Source/target attachment IDs.
- Status.
- Created/completed timestamps.
- Summary counts.

### 11.4 `mdm_operation_items`

- Operation item ID.
- Operation ID.
- Adapter and consumer identity.
- Logical path.
- Before/after fingerprints.
- Encrypted or serialized before-state only when needed for rollback; protect and bound its contents.
- Status/error code.

### 11.5 Schema management

- Install/update with `dbDelta()` where appropriate.
- Store a non-autoloaded schema version.
- Make migrations idempotent and resumable.
- Never delete tables automatically on ordinary plugin deactivation.
- On uninstall, respect a setting: `Keep data` by default; delete plugin tables/options only if the administrator previously opted into removal.

---

## 12. Background Processing and Performance

### 12.1 Constraints

The plugin must work on low-resource shared hosting and large sites.

- Default batch size: conservative and filterable.
- Default processing window: approximately 10–15 seconds per web request.
- Release locks on fatal/recoverable interruption through expiry.
- Use keyset pagination where possible; avoid deep `OFFSET` pagination.
- Avoid loading full attachment libraries or post tables into memory.
- Do not issue one database query per candidate when candidates can be resolved in batches.
- Cache attachment path maps per batch, not indefinitely in autoloaded options.
- Avoid scanning revisions, transients, caches, sessions, logs, and generated CSS by default.

### 12.2 Scheduling

Use WordPress Cron for resumability but expose manual and WP-CLI processing because WP-Cron is traffic-dependent. Admin-triggered scans should continue through loopback batches only within safe limits.

### 12.3 Concurrency

- One full scan per site.
- Targeted rescans may queue behind a full scan or be coalesced.
- Use atomic lock acquisition where practical.
- Make every batch idempotent.
- Protect against two administrators starting duplicate rebuilds.

### 12.4 Observability

Store structured error codes and limited diagnostic context. Never log secrets, complete options, personal form data, or arbitrary raw post content.

---

## 13. Security Requirements

Security is a release blocker, not a later enhancement.

### 13.1 Authorization

Define custom capabilities, mapped on activation to administrators by default:

- `mdm_view_dependencies`.
- `mdm_run_scans`.
- `mdm_replace_references`.
- `mdm_manage_settings`.

Additionally confirm the user can edit the relevant consumer and source/target attachment before exposing edit links or performing replacement.

### 13.2 Request protection

- Verify capabilities on every admin, REST, AJAX, CLI, export, scan, replace, rollback, and settings action.
- Use nonces for state-changing browser requests.
- Do not treat a nonce as authorization.
- Restrict REST endpoints with explicit `permission_callback` functions.

### 13.3 Input/output handling

- Sanitize according to semantic type at input boundaries.
- Validate IDs, enums, cursor structures, MIME types, and adapter IDs.
- Escape late for HTML, attributes, URLs, JavaScript data, and translations.
- Use `$wpdb->prepare()` for dynamic SQL.
- Use allowlists for sort/order/filter fields.
- Prevent CSV formula injection.
- Do not unserialize untrusted arbitrary data with unrestricted classes.
- Do not execute, include, or evaluate scanned values.

### 13.4 Replacement integrity

- Journal before mutation.
- Revalidate current values immediately before writing.
- Use optimistic fingerprints to detect concurrent changes.
- Never follow a heuristic match into a write.
- Treat adapter errors as contained failures.

### 13.5 Privacy

- No external requests by default.
- No analytics or telemetry in 1.0.
- Do not scan arbitrary options by default.
- Do not retain full content when hashes and short redacted previews suffice.
- Add WordPress privacy-policy suggested text only if the released feature set processes personal information.

---

## 14. Accessibility and User Experience

The plugin UI must meet WordPress admin conventions and WCAG 2.2 AA to the practical extent applicable.

- Every input has a visible label.
- All controls work by keyboard.
- Focus is visible and logical.
- Status is not conveyed by color alone.
- Dynamic progress updates use appropriate live regions without excessive announcements.
- Tables have headers and accessible pagination.
- Confirmation dialogs manage focus correctly.
- Error summaries link to relevant fields/actions.
- Icons have text alternatives or are decorative.
- Do not introduce an inaccessible custom component library merely for visual polish.
- Respect reduced-motion and admin color schemes.

Critical wording must distinguish `No known references` from `Unused`.

---

## 15. Internationalization

- Load the `media-dependency-map` text domain.
- All user-facing strings must be translatable.
- Do not concatenate translated sentence fragments.
- Use translator comments for placeholders.
- Use proper plural functions for counts.
- Format dates, times, and numbers through WordPress functions.
- Generate a POT file during release packaging.
- Avoid using the plugin slug/text domain belonging to another project.

---

## 16. Code Architecture and Standards

Suggested structure:

```text
media-dependency-map/
├── media-dependency-map.php
├── readme.txt
├── uninstall.php
├── composer.json
├── package.json
├── phpcs.xml.dist
├── phpunit.xml.dist
├── languages/
├── assets/
│   ├── src/
│   └── build/
├── includes/
│   ├── Bootstrap.php
│   ├── Activation.php
│   ├── Admin/
│   ├── Adapters/
│   │   ├── Contracts/
│   │   ├── Core/
│   │   ├── Gutenberg/
│   │   ├── Elementor/
│   │   ├── ACF/
│   │   └── WooCommerce/
│   ├── Domain/
│   ├── Index/
│   ├── Matching/
│   ├── Replacement/
│   ├── Persistence/
│   ├── REST/
│   ├── CLI/
│   └── Support/
└── tests/
    ├── Unit/
    ├── Integration/
    ├── Fixtures/
    └── E2E/
```

The implementer may adjust paths to follow WordPress.org packaging needs, but must preserve separation of concerns.

Requirements:

- Namespaced PHP using a unique vendor prefix; do not place generic classes/functions in the global namespace.
- Guard direct file access where appropriate.
- A thin main plugin bootstrap.
- Dependency injection/composition rather than service-locator calls scattered throughout the code.
- No business logic in templates.
- No raw SQL outside the persistence layer.
- No integration-specific logic inside the central scan engine.
- WordPress Coding Standards enforced with PHPCS.
- PHPStan or Psalm at a practical strictness level.
- Composer autoloading may be used, but production dependencies must be scoped/isolated if collision is possible.
- JavaScript dependencies must be built into distributable assets; do not require production users to run npm.
- Prefer native WordPress components and APIs over a heavy custom frontend.
- Do not ship development dependencies, tests, source maps containing local paths, or unnecessary source files in the release ZIP.

---

## 17. Hooks and Extensibility

Provide documented hooks with the plugin prefix. Potential examples:

- Register/unregister adapters.
- Filter eligible post types.
- Filter batch size and time budget.
- Filter recognized prior upload domains.
- Filter deletion-protection behavior.
- Action before/after scan run.
- Action before/after adapter batch.
- Action before/after successful replacement.
- Filter retention periods.

All public hooks must have stable argument contracts, inline documentation, and automated coverage. Do not expose mutable internal database rows directly.

---

## 18. WP-CLI Requirements

Provide commands comparable to:

```bash
wp mdm scan --all
wp mdm scan --attachment=123
wp mdm status
wp mdm references 123 --format=table
wp mdm rebuild --yes
wp mdm verify
```

Requirements:

- Clear exit codes.
- Progress output that can be suppressed with `--quiet`.
- JSON/CSV/table output where appropriate.
- No interactive confirmation when `--yes` is supplied.
- Capability-equivalent safety checks and explicit warnings for destructive index rebuilds.
- Replacement through CLI may be deferred until after 1.0 unless it can preserve the full preview/journal/verification contract.

---

## 19. Compatibility Behavior

### 19.1 Missing integrations

If Elementor, ACF, or WooCommerce is inactive:

- Do not load its adapter classes unnecessarily.
- Do not show PHP errors or persistent nags.
- Show the adapter as unavailable only on the coverage screen.

### 19.2 Unsupported versions

- Detect integration versions.
- If a storage schema/API is unverified, scan read-only where safe or disable that adapter with an explanation.
- Never guess during replacement.

### 19.3 Common environments

Test with:

- Classic themes.
- Block themes.
- Pretty and plain permalinks.
- Subdirectory WordPress.
- HTTPS and mixed historical URLs.
- Multisite subdirectory and subdomain installations at individual-site level.
- Object cache enabled.
- Sites with thousands of attachments and tens of thousands of posts.
- WP-Cron disabled, using CLI/manual continuation.
- Elementor, ACF, and WooCommerce individually and together.

---

## 20. Testing Strategy

### 20.1 Unit tests

Cover:

- URL normalization.
- Attachment resolution.
- Derivative filename resolution.
- Confidence assignment.
- Reference uniqueness.
- Cursor serialization.
- Capability decisions.
- CSV injection prevention.
- Replacement fingerprint checks.
- Adapter parser fixtures.

### 20.2 Integration tests

Use the WordPress test suite to cover:

- Table installation and upgrades.
- Post creation/update/trash/delete indexing.
- Featured images.
- Gutenberg nested blocks and serialization.
- Scan resumption and locking.
- Stale-reference reconciliation.
- Permission boundaries.
- Uninstall behavior.
- REST/AJAX authorization.
- Replacement and rollback.

### 20.3 Integration-plugin fixtures

Where licensing permits, test against supported public versions of Elementor, ACF Free, and WooCommerce. ACF Pro-only structures should use legally distributable fixtures/mocks in public CI and be verified separately without committing proprietary code.

### 20.4 End-to-end tests

Use Playwright or the WordPress e2e stack for:

- Starting/resuming a scan.
- Filtering attachments.
- Viewing dependency details.
- Keyboard navigation.
- Dry-run replacement.
- Successful replacement.
- Partial failure reporting.
- Rollback conflict behavior.
- Deletion warning/prevention.

### 20.5 Performance tests

Create repeatable datasets, including at minimum:

- 10,000 posts.
- 5,000 attachments.
- Nested Gutenberg content.
- Representative Elementor JSON.
- Nested ACF-like structures.
- WooCommerce product galleries and variations.

Record:

- Batch time.
- Peak memory.
- Query count.
- Index-table growth.
- Attachment lookup latency.

Define budgets before release based on representative shared hosting. A batch must adapt downward instead of exhausting memory/time.

### 20.6 Static and release checks

- PHPCS with WordPress Coding Standards.
- PHPStan/Psalm.
- PHPUnit.
- JavaScript linting and tests if JavaScript is used.
- Plugin Check plugin/CLI.
- PHP compatibility scan.
- Dependency vulnerability audit.
- Test installation from the final ZIP, not only the repository checkout.

---

## 21. Acceptance Criteria for Version 1.0

Release is permitted only when all criteria pass.

### Core behavior

- A featured image appears as an exact, replaceable reference.
- Core image, gallery, cover, file, audio, and video blocks are indexed correctly in fixtures.
- Nested Gutenberg blocks are handled recursively.
- Supported Elementor media controls are identified with document, element, control, and responsive context.
- Supported ACF image/file/gallery and nested structures are indexed using field definitions.
- WooCommerce product, gallery, variation, category, and supported download references are indexed.
- A generated thumbnail URL maps to the correct source attachment in test fixtures.
- Ambiguous matches are not marked exact and cannot be automatically replaced.
- An incomplete scan never displays an attachment as definitively unused.

### Safety

- No replacement can execute without capability and nonce/CLI authorization checks.
- Dry run and final operation operate on the same validated reference set or warn about drift.
- A partial replacement failure is visible and recoverable.
- Rollback refuses to overwrite a later unrelated edit.
- Heuristic and unsupported references are never modified.
- Deactivation preserves data.
- Uninstall preserves data unless explicit removal was previously selected.

### Performance

- Scans are batched, resumable, idempotent, and lock-protected.
- No full scan runs during plugin activation.
- No unbounded scan runs during ordinary admin page rendering or content saving.
- Admin attachment queries do not perform live full-content searches per row.

### Quality

- No PHP warnings/notices in supported environments with debug mode enabled.
- No JavaScript console errors in tested admin flows.
- All automated test suites pass.
- Plugin Check produces no unresolved errors.
- Translation template is generated.
- Final ZIP installs and activates on a clean WordPress site.
- Readme, screenshots, changelog, upgrade notice policy, license, and privacy statements match actual behavior.

---

## 22. WordPress.org Publication Requirements

Before submission, verify the current official Plugin Directory guidelines and current WordPress/PHP compatibility expectations; they may change after this document is written.

Required release work:

- GPL-compatible code and dependencies.
- Original branding and no trademark misuse.
- Accurate plugin header metadata.
- Unique prefix/namespace.
- `readme.txt` formatted for WordPress.org.
- Stable tag matching the release tag.
- `Requires at least`, `Requires PHP`, and `Tested up to` based on actual testing.
- No obfuscated code.
- No unauthorized tracking.
- No remote code execution or externally served executable code.
- No deceptive claims such as “100% safe,” “finds every reference,” or “guarantees unused media.”
- Clear disclosure of any external service if one is ever added.
- No admin-notice spam, dashboard hijacking, or affiliate links without clear relevance/disclosure.
- Secure upgrade path and semantic versioning.
- Assets prepared at current directory-specified dimensions.
- SVN release containing only production artifacts.
- Tag first public release as `1.0.0`; do not use `trunk` as the stable production version unless intentionally following current directory practice.
- Run Plugin Check and manually inspect the exact SVN/ZIP artifact before committing the tag.

Suggested plugin description:

> Discover where Media Library files are referenced across WordPress core, Gutenberg, Elementor, ACF, and WooCommerce. Review confidence and coverage before deleting or replacing an asset.

Suggested short description:

> See where WordPress media files are used before replacing or deleting them.

---

## 23. Documentation Requirements

Ship and maintain:

- Installation instructions.
- First-scan explanation.
- Status/confidence definitions.
- Coverage matrix by adapter and version.
- Explanation of why “No known references” does not guarantee an unused file.
- Replacement and rollback guide.
- Deletion-protection guide.
- WP-CLI guide.
- Troubleshooting for stuck scans and WP-Cron.
- Privacy statement.
- Developer adapter/hook documentation.
- Known limitations.

The readme must not advertise unreleased premium features as though they are included.

---

## 24. Delivery Plan for the developer

the developer must implement this project in gated phases. It must run tests and show evidence before proceeding past each release gate. It must not generate the entire plugin as an unverified one-shot code dump.

### Phase 0: Repository and standards

- Confirm product name/slug availability.
- Create plugin skeleton, namespaces, build tooling, PHPCS, static analysis, PHPUnit, and CI.
- Create an architectural decision record for background processing and database schema.
- Add development environment instructions.

**Gate:** Clean activation/deactivation and all empty-skeleton checks pass.

### Phase 1: Domain model and persistence

- Implement references, consumers, scan runs, operations, repositories, schema installation, and migrations.
- Test uniqueness, reconciliation, schema upgrades, and uninstall policy.

**Gate:** Persistence integration tests pass and no index data is autoloaded unnecessarily.

### Phase 2: Resolver and core adapters

- Implement attachment resolution, normalization, featured images, core HTML/shortcodes, Gutenberg, and site identity.
- Add fixture-heavy unit/integration tests.

**Gate:** Core acceptance fixtures pass with documented confidence.

### Phase 3: Scan orchestration

- Implement batched full scans, cursors, locking, resumption, targeted rescans, WP-Cron continuation, and WP-CLI.

**Gate:** Forced interruption resumes without duplicates or lost references.

### Phase 4: Admin experience

- Build dependency list, attachment usage column/panel, filters, coverage screen, progress, errors, and exports.
- Complete keyboard and screen-reader-oriented testing.

**Gate:** E2E tests pass for scan and inspection flows.

### Phase 5: Integration adapters

- Implement Elementor, ACF, and WooCommerce adapters separately with version guards.
- Add fixtures and compatibility matrix.

**Gate:** Each adapter can be disabled independently and failure does not affect others.

### Phase 6: Replacement and rollback

- Implement only for references proven safe by adapters.
- Add preview, optimistic validation, journal, partial failure reporting, verification rescan, and rollback conflicts.

**Gate:** Destructive-path tests pass, including simulated mid-operation failure and later-edit conflict.

### Phase 7: Hardening and release

- Test compatibility matrix.
- Run static, security, performance, accessibility, Plugin Check, and final-package verification.
- Finalize documentation and WordPress.org assets.
- Install the exact release ZIP on a clean site and a representative populated site.

**Gate:** Every version 1.0 acceptance criterion is evidenced in a release checklist.

---

## 25. Instructions the developer Must Follow During Implementation

Use the following operational instructions when this specification is handed to the developer:

1. Read the entire specification before editing files.
2. Inspect the existing repository and any `AGENTS.md` instructions before planning changes.
3. Verify current official WordPress, Elementor, ACF, WooCommerce, PHP, Plugin Directory, and Plugin Check documentation before relying on an integration API or release requirement.
4. Maintain a phased implementation plan with only one active phase at a time.
5. Do not silently expand version 1.0 scope.
6. Prefer official public APIs over direct database manipulation.
7. Do not invent integration schemas. Create fixtures from documented/observed supported versions and version-guard them.
8. Never implement arbitrary serialized search-and-replace.
9. Never automatically mutate heuristic matches.
10. Add automated tests with every parser, resolver, persistence, permission, and mutation feature.
11. Treat replacement, rollback, deletion interception, REST endpoints, exports, and migrations as security-sensitive.
12. Keep the plugin usable without Elementor, ACF, WooCommerce, Composer, npm, a cloud account, or an external service at runtime.
13. Preserve user data on deactivation and by default on uninstall.
14. Keep the release package free of development-only files and proprietary test dependencies.
15. After every phase, run the relevant tests and report exact failures rather than claiming completion.
16. Before release, test the exact packaged ZIP and WordPress.org SVN contents.
17. Do not state that the plugin is ready for WordPress.org until all acceptance criteria and current directory requirements pass.

---

## 26. Definition of Done

The project is complete when:

- A user can install the final ZIP on a clean supported WordPress site.
- The plugin can build and maintain a resumable reverse-reference index without timing out normal admin requests.
- Core WordPress, Gutenberg, supported Elementor, ACF, and WooCommerce references appear with useful context and honest confidence.
- Users can distinguish confirmed, possible, unresolved, unscanned, and no-known-reference states.
- Supported exact references can be previewed, replaced, verified, journaled, and safely rolled back.
- Unsupported or heuristic storage remains read-only.
- Security, permissions, privacy, accessibility, performance, compatibility, coding standards, automated tests, documentation, and package checks pass.
- The final WordPress.org description accurately reflects shipped functionality and limitations.
- The repository contains reproducible build and release instructions.
- The exact submitted artifact has been independently inspected and tested.

This definition deliberately excludes universal detection and universal replacement. A credible dependency map must expose the boundary of its knowledge instead of presenting guesses as certainty.
