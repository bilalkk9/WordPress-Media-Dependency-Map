# Foundation validation

Date: 2026-09-16. Author: Bilal.

## Verified

- Local site: api-local only, WordPress 7.1, PHP 8.2.29.
- Installed and activated preview 0.1.0.
- PHPCS with WordPress Coding Standards: no errors or warnings.
- PHPStan level 5: no errors.
- PHPUnit: 2 tests, 5 assertions passed.
- Live WP-CLI integration check: deactivate/reactivate, settings retention,
  administrator-only capability assignment, no automatically scheduled scan,
  admin output and non-autoloaded settings passed.
- Composer dependency audit: no reported advisories during dependency resolution.

Installed integration baseline: Elementor 4.2.4, Pro Elements 3.35.0,
WPBakery 9.0.1 and Bricks theme 2.3.6. ACF and WooCommerce were absent.

## Outstanding

- Browser inspection requires an authenticated admin session.
- Remote CI execution and minimum-version runtime tests are not yet evidenced.
- Product name and slug clearance remains pending; no availability guarantee.
- Plugin Check and final release packaging gates remain pending.
- Indexing, adapters, replacement and rollback are not implemented in this preview.

The Local CLI configuration initially warned about a missing Imagick DLL. The
repository's ignored CLI configuration omits that extension for foundation tests;
the site's PHP configuration was not changed. Media processing tests must revisit it.
