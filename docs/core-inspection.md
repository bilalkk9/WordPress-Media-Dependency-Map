# Core inspection preview

Author: Bilal. Version: 0.3.0.

Use a WordPress administrator (or a user with the required custom capabilities) explicitly:

```sh
wp mdm status --user=admin
wp mdm inspect 123 --user=admin
wp mdm identity --user=admin
```

Always supply the target site's `--path` when not using its site shell. `inspect` requires an
editable post plus `mdm_run_scans` and `mdm_view_dependencies`; `identity` also requires
`manage_options`. These diagnostic commands return JSON and do not populate the index.
Use the post ID of a synced pattern/template to inspect its own stored content.

## Implemented coverage

| Source | Detection |
| --- | --- |
| Featured images | Exact attachment IDs |
| Core image, gallery, cover, media-text, file, audio and video blocks | Recognized IDs, nested blocks, local attribute URLs |
| Unknown block attributes | Recognized local URLs only; arbitrary numeric attributes ignored |
| HTML | src, anchor href, poster, srcset, image ID classes and simple inline CSS URLs |
| Shortcodes | Explicit and implicit gallery/playlist IDs, caption IDs; audio/video source and poster URLs |
| Synced patterns | Referenced pattern traversal with cycle and depth guards |
| Site identity | Site icon, current theme logo, header and background images |
| Core image widgets | Stored attachment ID, URL and link URL, including inactive widgets |
| URL resolution | Current uploads origin, upload-relative paths, registered sizes and original image metadata |

`exact` means the schema explicitly stores an existing attachment ID; `strong` means a known
local URL uniquely maps to an attachment. Missing or ambiguous local references are `unresolved`.
All results are read-only. Raw media values are hashed, not retained in reference records.
One asset can have several occurrences in a single post, including both block and HTML locations;
an occurrence count is not a distinct-page count.

## Remaining coverage work

- Dynamic block output.
- Uncommon shortcode forms.
- Complex CSS escapes and data-* attribute conventions.
- Historical upload domains, CDN aliases and offload adapters.
- Generic filename heuristics and arbitrary options; these are deliberately not guessed.
- ACF, WooCommerce and unsupported builder storage. Elementor registered controls are
  now covered separately; see [Elementor coverage](elementor.md).
- Additional core widget types, custom metadata and unsupported plugin storage.

Full scans build a generation-specific path index before scanning consumers. Diagnostic
inspection falls back to bounded metadata candidate searches. Per-consumer guards are
2 MiB of content, 5,000 references, 256 unique URL candidates and 64 block nesting levels.
Exceeding a budget fails the consumer; it never publishes incomplete results as a successful scan.

## Persistence guarantees

Eight site-prefixed InnoDB tables hold references, runs, coordination, paths and reserved journals.
The repository validates consumer ownership, deduplicates logical references, preserves first-seen
timestamps and reconciles a complete consumer within a transaction. A failed insertion rolls back
the prior deletion. Failed or completed runs cannot receive new reference snapshots. SQL is
confined to persistence classes. Deactivation and default uninstall preserve records; explicitly
opted-in uninstall removes only the current site's plugin tables.

## Indexed scans and browser

Administrators can start or resume scans under Media > Dependency Map, or use:

```sh
wp mdm scan --all --user=admin
wp mdm scan --resume --batch --user=admin
wp mdm rebuild --yes --user=admin
wp mdm references 123 --page=1 --user=admin
```

Every invocation must target the intended site. CLI processing yields after five minutes;
resume continues the saved checkpoint. Cron and the open admin page process bounded
batches. Cron requires traffic or an external WordPress Cron runner. Activation does not scan.

A fenced database lease serializes workers. Consumer snapshots and checkpoints commit
atomically; a full generation is published only after every supported consumer succeeds.
A failed rebuild preserves the last published generation. Save hooks enqueue IDs; shared
pattern or attachment changes request a rebuild. Queue revisions preserve racing updates.

The browser supports search, usage/MIME/date filters, sorting, reference/confidence details,
unresolved references, Media Library counts and filtered CSV exports (10,000-row cap).
CSV text is protected against spreadsheet formulas. Views require an administrator with
the dependency capability. Counts represent locations, not distinct consumers. Pending,
failed or interrupted work prevents the interface from claiming a current index.
