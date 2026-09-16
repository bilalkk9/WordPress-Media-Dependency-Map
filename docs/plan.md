# Implementation plan

Author: Bilal

Only one phase is active at a time. Each phase must pass its checks before the next begins.

| Phase | Scope | Status |
| --- | --- | --- |
| 0 | Repository, tooling, activation and development environment | Foundation checks passed |
| 1 | Domain model, persistence, migrations | Reference persistence checks passed |
| 2 | Resolver and core adapters | Active: core inspection verified; coverage extensions remain |
| 3 | Batched scans, locks, resume, incremental work and CLI | Pending |
| 4 | Dependency browser, coverage, exports and accessibility | Pending |
| 5 | Elementor, ACF and WooCommerce adapters | Pending |
| 6 | Verified replacement and conflict-aware rollback | Pending |
| 7 | Compatibility, performance and release packaging | Pending |

Bricks and WPBakery are compatibility fixtures; dedicated scanners are outside the current scope.
Product name and slug remain provisional. Directory lookup alone cannot establish trademark clearance.

Version 0.2.0 delivers the reference value model, transactional consumer reconciliation, schema
installation and initial read-only core adapters. Operation tables are reserved now; journal
creation, encryption/retention and mutation APIs remain gated by Phase 6. The four-table schema
will evolve before release. Phase 2 coverage gaps are explicit in core-inspection.md; no
site-wide index-completeness claim is made. Diagnostic CLI commands support this phase and do
not implement the Phase 3 scan engine.
