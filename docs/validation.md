# Development validation

Date: 2026-09-16. Author: Bilal.

## Version 0.1.0 verified

- Local site: api-local only, WordPress 7.1, PHP 8.2.29.
- Installed and activated preview 0.1.0.
- PHPCS with WordPress Coding Standards: no errors or warnings.
- PHPStan level 5: no errors.
- PHPUnit: 2 tests, 5 assertions passed.
- Live WP-CLI integration check: deactivate/reactivate, settings retention,
  administrator-only capability assignment, no automatically scheduled scan,
  admin output and non-autoloaded settings passed.
- Composer dependency audit: no reported advisories during dependency resolution.
- Signed-in browser: Media submenu and coverage preview visibly present on api-local.
- GitHub Actions run 35093666978: successful PHP 7.4, 8.2 and 8.3 quality matrix.

Installed integration baseline: Elementor 4.2.4, Pro Elements 3.35.0,
WPBakery 9.0.1 and Bricks theme 2.3.6. ACF and WooCommerce were absent.

## Outstanding

- WordPress minimum-version integration testing is not yet evidenced.
- Product name and slug clearance remains pending; no availability guarantee.
- Plugin Check and final release packaging gates remain pending.
- Whole-site indexing, third-party adapters, replacement and rollback are not implemented in this preview.

The Local CLI configuration initially warned about a missing Imagick DLL. The
repository's ignored CLI configuration omits that extension for foundation tests;
the site's PHP configuration was not changed. Media processing tests must revisit it.

## Version 0.2.0 local verification

- PHPCS: no errors or warnings. PHPStan level 5: no errors.
- PHPUnit: 16 tests, 21 assertions passed.
- Live storage fixtures: repeat schema installation, duplicate references, first-seen
  retention, forced mid-transaction insertion failure, preserved previous snapshot,
  default uninstall retention, explicit uninstall on disposable tables and completed-run rejection passed.
- Live scanner fixtures: registered derivatives, unregistered suffix rejection,
  ambiguous paths, nested media blocks, explicit gallery IDs, media HTML, image widgets,
  unresolved URLs and repeated occurrences passed. Fixtures are removed and identity settings restored.
- CLI: status and identity succeeded as administrator; status without a selected user
  exited with an error. Inspection of an existing api-local page produced valid JSON.
- Browser: updated core coverage and limitations visible on the signed-in admin screen.
- Exact 0.2.0 ZIP installed over the previous preview on api-local; scanner fixtures passed afterward.

An isolated WordPress 6.6.2 integration job is included in CI. Its result must be checked
after pushing; local evidence uses WordPress 7.1 with the previously recorded integrations.
