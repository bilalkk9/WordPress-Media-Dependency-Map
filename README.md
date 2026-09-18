# Media Dependency Map

By [Bilal](https://profiles.wordpress.org/mbilalkk/).

Version 0.6.0 is a testable development build with core, Elementor, ACF and WooCommerce
media dependency scanning, an admin browser, CSV export, resumable scans and WP-CLI.
It adds dry-run featured-image replacement, conflict-aware rollback, opt-in deletion
protection and journal retention settings. Complex content and integration references
remain read-only. This is not yet a production or WordPress.org release.

Use **Media > Dependency Map** to scan, **Media > Media replacement** for dry runs,
and **Media > Dependency settings** for retention and deletion protection.
See [replacement scope](docs/replacement.md) and [validation](docs/validation.md).

## Development

The distributable plugin is in `media-dependency-map/`. Install that folder into the test site's
`wp-content/plugins/` directory. Run `composer install`, then `composer lint`, `composer analyse`
and `composer test` from the repository root. PHP 7.4+ is required.
Run `./scripts/package.ps1` in PowerShell to create the preview ZIP under `build/`.

Local development is restricted to the explicitly designated api-local site. Keep WordPress,
database dumps, uploaded media, credentials and third-party plugins outside this repository.
Use a site-specific WP-CLI `--path` for every operation. Never run bulk commands across Local sites.

See [the plan](docs/plan.md), [architecture](docs/architecture.md) and the product specification.
See [inspection commands and coverage](docs/core-inspection.md) for the current feature boundaries.

See [Elementor coverage and limitations](docs/elementor.md) for the current integration milestone.

See [ACF coverage and validation limits](docs/acf.md) for supported contexts and field types.

## License

Copyright 2026 Bilal. GPL-2.0-or-later. See `media-dependency-map/LICENSE`.
