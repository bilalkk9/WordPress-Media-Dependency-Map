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
| 5 | Elementor, ACF and WooCommerce adapters | Active: Elementor preview implemented; ACF and WooCommerce pending |
| 6 | Verified replacement and conflict-aware rollback | Pending |
| 7 | Compatibility, performance and release packaging | Pending |

Bricks and WPBakery are compatibility fixtures; dedicated scanners are outside the current scope.
Product name and slug remain provisional. Directory lookup alone cannot establish trademark clearance.

Version 0.3.0 adds generation publication, a fenced lease, resumable checkpoints,
incremental queues and an admin dependency browser. Eight site-prefixed tables now
include coordination and path-index storage. Replacement journals remain reserved;
mutation and rollback APIs are gated by Phase 6. Core coverage is not whole-site
coverage when unsupported integrations are present.

Further release work includes large-library benchmarks, broader accessibility audits,
attachment-targeted verification and the integration/replacement phases above.
