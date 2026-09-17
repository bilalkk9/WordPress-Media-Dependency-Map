# ACF adapter preview

Author: Bilal. Version: 0.5.0.

ACF 6.x is required. The adapter asks ACF for saved field definitions, selects active
field groups and loads unformatted values through its APIs. It never treats arbitrary
numeric metadata as an attachment. Image and file fields resolve stored IDs independently
of the configured ID/array/URL display return format. Supported array or URL values from
value hooks are resolved conservatively; external URLs are excluded.

Coverage includes posts/pages and templates, terms, users, comments, and the default
`options` context. Term/user/comment scans have separate high-water marks and persisted
cursors. Editing requires the corresponding object capability; the entire browser remains
administrator-only. Default options records appear as site settings. Metadata and ACF save
hooks enqueue updates; definition changes request full rebuilds. Delete events remove stale
consumer references. Local PHP/JSON definition changes require a new full scan.

Groups are tested through real ACF Free APIs. Gallery, repeater, flexible-content and
expanded grouped-clone traversal are implemented against field/value structures with field
keys, row indices and layout names in paths. Pro-only types require their installed field
APIs. Public fixtures verify those structures but do not establish ACF Pro runtime compatibility.
Live ACF Pro verification remains a release gate; no proprietary code is redistributed.

Excluded: attachment custom fields, orphaned or inactive definitions, custom options storage
identifiers, unresolved seamless clone definitions, custom field types, ACF block attributes
inside post content, and arbitrary numeric/relationship fields. The inspector does not
render dynamic content or modify field values. Known-reference counts are not proof that
an attachment is unused. Multisite context isolation remains a broader release gate.

Loading is limited to 1,000 supported root fields with a six-second deadline checked between
API calls. The snapshot is capped at 2 MiB, 10,000 traversal steps, 32 levels and 5,000
references, with a separate six-second traversal deadline. An individual ACF/custom-filter
call cannot be preempted, and metadata enumeration can allocate memory before these guards.
Malformed layouts and exhausted budgets fail the consumer, preserving the last published index.

Local tests use official ACF Free 6.8.10, isolated plugin tables and disposable objects.
They verify all five contexts, return formats, groups, numeric/orphan exclusion, permissions,
unchanged stored data, source-specific cursors, incremental removal and deleted terms.

API references: [get_field_objects](https://www.advancedcustomfields.com/resources/get_field_objects/),
[get_field](https://www.advancedcustomfields.com/resources/get_field/), and
[context identifiers](https://www.advancedcustomfields.com/resources/get_fields/).
