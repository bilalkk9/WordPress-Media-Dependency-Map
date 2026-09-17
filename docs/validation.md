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

- Broader WordPress, multisite and integration-version coverage remains pending.
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

GitHub Actions run 35095770839 passed the PHP 7.4/8.2/8.3 quality matrix and isolated
WordPress 6.6.2 lifecycle, persistence and scanner integration tests. Local evidence uses
WordPress 7.1 with the previously recorded integrations.


## Version 0.3.0 local verification (September 17, 2026)

- PHPCS and PHPStan level 5 passed; PHPUnit passed 23 tests and 28 assertions.
- JavaScript syntax check passed.
- All five integration suites passed on api-local: lifecycle, persistence, core
  scanners, scan engine and admin browser. Fixtures use isolated temporary tables
  for engine/database failure tests and clean up after themselves.
- Engine checks cover lease expiry/fencing, checkpoint resume, deferred generation
  publication, failure preservation, queue debouncing/racing revisions and deletion.
- Admin checks cover permissions/nonces, escaping, SQL sort allowlists, filters,
  confidence, batched usage counts and stale-index reporting.
- Exact 0.3.0 ZIP installed on api-local. CLI batch, WordPress Cron event and CLI
  resume completed generation 6 with 17 processed consumers, no errors, no queued
  changes and current=true.
- Live browser scan completed; demo attachment 72 has four occurrences in draft
  post 74. Attachment 73 has no known core references. These labeled demonstration
  fixtures remain available for manual review.
- Browser detail links, usage filtering and filtered CSV download succeeded.
  The native admin layout was visually checked; no console errors were observed
  during the browser-driven scan. A full accessibility audit is still pending.
- Integration-specific scanners, verified replacement, rollback, large-library
  benchmarks and Plugin Check remain later gates. This is a core-only preview.


## Version 0.4.0 Elementor milestone (September 17, 2026)

- All six api-local integration suites passed, including the installed Elementor 4.2.4
  API fixtures. The fixture registered a temporary widget with real public control APIs;
  it covered nested templates, media/gallery/repeater/URL/SVG controls, responsive values,
  unresolved dynamics, unrelated numbers, malformed/oversized input and missing widgets.
- PHPCS, PHPStan and the existing 23 unit tests passed. No plugin content mutation is
  introduced. The scanner's stored-data equality assertion passed.
- The initial responsive fixture exposed Elementor's editor-only control duplication;
  active breakpoint expansion fixed the omission and the fixture then passed.
- The activation fixture caught scheduling caused by watching active_plugins directly;
  integration activation/deactivation hooks now exclude this plugin's own lifecycle.
- A labeled draft Elementor page (115) was saved through Elementor's document API.
  Attachment 72 now has two Elementor occurrences alongside its four core occurrences.
- Complete live generation 10 processed 27 consumers with no errors or queued changes.
  The signed-in browser showed both Elementor ID/URL paths; filtering to Elementor
  retained exactly those two occurrences.
- Phase 5 remains active: ACF and WooCommerce adapters are not yet implemented.


## Version 0.5.0 ACF milestone (September 17, 2026)

- Installed official ACF Free 6.8.10 on api-local for this integration milestone.
- All seven integration suites passed with ACF and Elementor active. PHPCS and PHPStan
  passed; PHPUnit passed the existing 23 tests and 28 assertions.
- Real ACF APIs verified image/file/group fields across post, term, user, comment and
  default options contexts, unformatted return values, permissions and unchanged metadata.
- Disposable schema/value fixtures exercised gallery, repeater, flexible-content and
  expanded grouped-clone traversal. Live ACF Pro verification remains pending.
- Engine fixtures verified separate object ceilings, complete publication, incremental
  user-field removal and deleted-term cleanup. Fixture users, comments, terms, options
  and temporary plugin tables were removed afterward.
- Installed the 0.5.0 ZIP and completed generation 15: 51 consumers processed, zero errors,
  zero queued changes and current=true. Core, Elementor and all five ACF context adapters ran.
- Persistent manual demos: draft page 142, category 5, field group 140, attachment 72.
  The browser ACF filter displayed the page and category with their exact field paths.
- WooCommerce, verified replacement/rollback and remaining release gates are pending.
