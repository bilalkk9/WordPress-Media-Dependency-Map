# Media Dependency Map

By [Bilal](https://profiles.wordpress.org/mbilalkk/).

Development preview of a WordPress media dependency inspector. Version 0.2.0 adds transactional
reference storage and read-only core inspections through WP-CLI. Whole-site scans, the dependency
browser and replacement are pending.
This is not a production release.

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

## License

Copyright 2026 Bilal. GPL-2.0-or-later. See `media-dependency-map/LICENSE`.
