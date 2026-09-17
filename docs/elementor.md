# Elementor adapter preview

Author: Bilal. Version: 0.4.0.

The adapter loads only with Elementor 3.20 through 4.x and the plugin instance API.
It reads saved document data using the document API, and control definitions from
registered element instances. All references remain read-only. No widgets are rendered,
no dynamic tags are evaluated, and no CSS regeneration or content mutation occurs.

Supported registered control shapes are media (ID and URL), gallery, URL, uploaded SVG
icons and repeaters containing those controls. Responsive variants use registered
control definitions and active breakpoints, including Elementor's editor-only duplication
mode. Backgrounds, posters and carousel images are covered when their controls use
these shapes. Paths contain element IDs, widget types, control names and nested rows.
Page settings, saved templates and Theme Builder documents are included as documents.

Unknown numeric settings are ignored. Dynamic media-bearing values produce unresolved
records instead of treating their fallback as an exact reference. ID and URL fields are
separate occurrences; conflicting values are preserved for review. External URLs do not
map to the local Media Library.

Limitations: rendered HTML/CSS, dynamic output, indirect template inclusion, atomic
properties that do not expose these classic control schemas, unknown add-on controls,
inactive breakpoint variants and global variable resolution are not covered. Missing
widget definitions fail the consumer rather than silently declaring it unreferenced.
API range acceptance is not a guarantee for every widget or add-on in those versions.

Each document has a 2 MiB input cap per storage record, 32 nesting levels, 50,000 traversal
steps, 5,000 references and a six-second traversal deadline. Parsing cannot preempt a
single third-party PHP call. Malformed JSON, unavailable documents and exhausted budgets
preserve the previous published generation through the shared engine's failure handling.

Metadata changes and Elementor document saves enqueue post updates. Plugin activation
changes request a full rebuild. Changing the active adapter set marks a published index
stale until a full scan succeeds. After upgrading an integration, run a new full scan.

The disposable integration fixture uses the installed Elementor public widget/control
APIs and verifies media, gallery, repeater, URL, SVG, responsive and dynamic values,
non-media numbers, malformed/oversized input, missing widgets and unchanged stored data.
Commercial plugin code and site data are never included in the repository or package.

References: [Media control](https://developers.elementor.com/docs/editor-controls/control-media),
[Gallery control](https://developers.elementor.com/docs/editor-controls/control-gallery),
and the installed Elementor document, controls-stack and element-manager APIs.
