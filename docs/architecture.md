# Architecture decisions

Author: Bilal

## Processing

Use a shared bounded scan engine driven by WP-Cron, manual requests and WP-CLI.
Persist cursors after each idempotent batch. No scanning during activation or content saves.
Adapters own extraction and structured mutation; the engine owns scheduling and coverage.
Replacement remains unavailable until preview, authorization, journal and rollback tests pass.

## Persistence

Dedicated prefixed tables will store references, scan runs, operations and operation items.
Use deterministic reference keys, indexed consumer/attachment lookups and generation-based
publication so failed scans cannot discard the last successful index. SQL belongs in repositories.
Settings and schema versions are not autoloaded. Deactivation preserves data; uninstall requires
prior explicit opt-in for deletion. Network activation is rejected in the preview; per-site
multisite lifecycle testing is required before release.

## Packaging

Only media-dependency-map/ is distributable. Development dependencies, tests, site content,
credentials and commercial integrations never enter the ZIP. Production requires no Composer.
WordPress 6.6 and PHP 7.4 are provisional minimums until compatibility testing is complete.
