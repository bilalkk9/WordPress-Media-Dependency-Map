# Core inspection preview

Author: Bilal. Version: 0.2.0.

Use a WordPress administrator (or a user with the required custom capabilities) explicitly:

```sh
wp mdm status --user=admin
wp mdm inspect 123 --user=admin
wp mdm identity --user=admin
```

Always supply the target site's `--path` when not using its site shell. `inspect` requires an
editable post plus `mdm_run_scans` and `mdm_view_dependencies`; `identity` also requires
`manage_options`. Commands return JSON, do not write content and do not populate the index.
Use the post ID of a synced pattern/template to inspect its own stored content.

## Implemented coverage

| Source | Detection |
| --- | --- |
| Featured images | Exact attachment IDs |
| Core image, gallery, cover, media-text, file, audio and video blocks | Recognized IDs, nested blocks, local attribute URLs |
| Unknown block attributes | Recognized local URLs only; arbitrary numeric attributes ignored |
| HTML | src, anchor href, poster, srcset, image ID classes and simple inline CSS URLs |
| Shortcodes | Explicit gallery/playlist IDs; audio/video source and poster URLs |
| Site identity | Site icon, current theme logo, header and background images |
| Core image widgets | Stored attachment ID, URL and link URL, including inactive widgets |
| URL resolution | Current uploads origin, upload-relative paths, registered sizes and original image metadata |

`exact` means the schema explicitly stores an existing attachment ID; `strong` means a known
local URL uniquely maps to an attachment. Missing or ambiguous local references are `unresolved`.
All results are read-only. Raw media values are hashed, not retained in reference records.
One asset can have several occurrences in a single post, including both block and HTML locations;
an occurrence count is not a distinct-page count.

## Remaining coverage work

- Parent-to-synced-pattern dependency traversal and dynamic block output.
- Implicit gallery/playlist child queries, caption IDs and uncommon shortcode forms.
- Complex CSS escapes and data-* attribute conventions.
- Historical upload domains, CDN aliases and offload adapters.
- Generic filename heuristics and arbitrary options; these are deliberately not guessed.
- Elementor, ACF, WooCommerce and third-party builder-specific storage.
- Additional core widget types, custom metadata and unsupported plugin storage.

The current URL resolver uses bounded metadata candidate searches. It must be replaced or
augmented with a batched path index before large-site full scans. Per-consumer guards are
2 MiB of content, 5,000 references, 256 unique URL candidates and 64 block nesting levels.
Exceeding a budget fails the consumer; it never publishes incomplete results as a successful scan.

## Persistence guarantees

Four site-prefixed InnoDB tables hold references, scan runs and reserved operation journals.
The repository validates consumer ownership, deduplicates logical references, preserves first-seen
timestamps and reconciles a complete consumer within a transaction. A failed insertion rolls back
the prior deletion. Failed or completed runs cannot receive new reference snapshots. SQL is
confined to persistence classes. Deactivation and default uninstall preserve records; explicitly
opted-in uninstall removes only the current site's plugin tables.

Whole-site generation publication, distributed locking, cursor recovery, incremental queues and
operation journaling are later phases. Do not use the current repository as a concurrent scan engine.
