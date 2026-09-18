# Implementation plan

Author: Bilal

Only one phase is active at a time. Each phase must pass its checks before the next begins.

| Phase | Scope | Status |
| --- | --- | --- |
| 0 | Repository, tooling, activation and development environment | Foundation checks passed |
| 1 | Domain model, persistence, migrations | Reference persistence checks passed |
| 2 | Resolver and core adapters | Core fixtures passed, including synced patterns and implicit galleries |
| 3 | Batched scans, locks, resume, incremental work and CLI | Preview validation passed |
| 4 | Dependency browser, coverage, exports and accessibility | Preview validation passed |
| 5 | Elementor, ACF and WooCommerce adapters | Elementor, ACF and WooCommerce implemented and integration tested; live ACF Pro qualification pending |
| 6 | Verified replacement and conflict-aware rollback | Featured-image writer and rollback tested; complex references explicitly read-only |
| 7 | Compatibility, performance and release packaging | Development package validated; production qualification remains open |

Bricks and WPBakery are compatibility fixtures; dedicated scanners are outside the current scope.
Product name and slug remain provisional. Directory lookup alone cannot establish trademark clearance.

Version 0.6.0 includes integration scanning, a bounded journaled featured-image
writer, rollback, deletion protection, retention settings and translation sources.
See replacement.md for exact write coverage and validation.md for evidence.

Production qualification still requires live ACF Pro testing, large-library
benchmarks, a full accessibility audit and broader host compatibility. The
GitHub build retains Update URI and is not a WordPress.org submission package.
