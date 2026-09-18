# WooCommerce coverage

Author: Bilal

The read-only adapter uses WooCommerce public CRUD read APIs (supported major
versions 8 through 11). It scans product and explicit variation images, product
galleries, local upload download URLs and product category thumbnail metadata.
Inherited variation fallback images are not counted as stored variation usage.
Remote downloads, rendered output and extension-specific stores are excluded.
Images and gallery IDs are exact references; resolved upload URLs are strong.
Unresolvable local values remain unresolved. Collections are bounded to 1,000
media values per product. Product and term writes invalidate their scan consumers.

The api-local integration fixture verifies real simple/variable products, explicit
and inherited variation images, galleries, local downloads, category images,
permissions and unchanged product metadata using WooCommerce 11.1.0.
