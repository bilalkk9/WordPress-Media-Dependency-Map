# Replacement and rollback

Author: Bilal

Version 0.6.0 supports core featured-image references on non-product posts. It does
not rewrite blocks, HTML, shortcodes, Elementor, ACF, WooCommerce or site identity.
These are explicitly listed as read-only in every preview. Files are never deleted.

A current successful index is required to create a dry run. Source and target must
be different image attachments that the administrator may edit. Previews contain
at most 200 occurrences, expire after 30 minutes, and change no content. Each
confirmed request applies at most ten eligible changes. The operation report is
the authoritative per-item outcome; repeat the action while planned items remain.

The journal is committed before content changes. Only attachment IDs, consumer IDs,
paths, hashes and statuses are retained, not content snapshots or raw private values.
Consequently this writer does not need encrypted before-state blobs. Each item locks
the worker lease, attachment rows, consumer row and thumbnail metadata in an InnoDB
transaction. A fresh fingerprint must match; duplicate metadata is rejected. Writes
use WordPress metadata APIs and their stored result is verified before commit.
Change hooks schedule index refreshes. Cache entries are invalidated after rollback.

Rollback only restores this operation's source ID when the current value matches
its recorded target hash. Conflicts preserve later edits. Repeated apply is a no-op.
Metadata-only changes do not create content revisions. Third-party hook side effects
outside the shared database connection cannot be transactionally rolled back.

Administrators need manage_options and mdm_replace_references plus edit permission
for both attachments and each consumer. Browser mutations also require a valid
nonce and explicit confirmation. Journals expire after 30 days by default (1-365
configurable), with bounded cleanup on the scan watchdog. Traffic or working Cron
is required; expired records may remain until the watchdog runs.

Deletion protection is off by default. Enabling it blocks permanent deletion when
the last published index has exact or strong references. An old reference can keep
a file protected until rescanning; unsupported sources can still be absent. No
reference count is a guarantee of safe deletion.
