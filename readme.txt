=== SKU & Barcode Manager for WooCommerce ===
Contributors: 9jaDevo
Donate link: https://github.com/9jaDevo
Tags: sku, barcode, woocommerce, inventory, labels
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-generate 11-digit SKUs, print professional barcode labels, and streamline WooCommerce inventory workflows.

== Description ==

SKU & Barcode Manager for WooCommerce keeps product identifiers in sync and delivers PDF-ready barcode labels in a couple of clicks. The plugin automatically assigns deterministic 11-digit SKUs to products and variations, supports selective label printing, and leverages TCPDF for high quality output that works with popular label sizes.

= Highlights =

- Enforce consistent 11-digit SKU patterns across simple and variable products.
- Search and select products or individual variations via the WooCommerce enhanced select UI.
- Generate paginated barcode PDFs using bundled TCPDF and Picqer libraries.
- Control label size, printed fields, and per-stock duplication from a single admin screen.
- Cache generated barcode images under `wp-content/uploads` for faster repeat jobs.
- Manage defaults (label size, fields, batch limits) and clear caches through a dedicated settings page.

== Installation ==

1. Upload the `sku-barcode-manager-for-woocommerce` folder to the `/wp-content/plugins/` directory, or install via the Plugins screen by searching for "Woo SKU & Barcode Manager".
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **WooCommerce > Barcodes** to configure and print labels.
4. Optional: Visit **WooCommerce > Barcode Settings** to adjust defaults and maintenance options.

== Frequently Asked Questions ==

= Will the plugin overwrite existing SKUs? =
No. Existing SKUs remain untouched. Automatic generation only runs when a product or variation lacks a SKU.

= Can I print barcodes for specific variations? =
Yes. Use the variation checklist that appears once a variable product is selected to include or exclude individual variations.

= Where are barcode images stored? =
Generated barcode PNG files are cached in `wp-content/uploads/woo-sbm-cache`. You can clear them from the settings page.

= How many labels can I create at once? =
The default batch limit is 500 labels. You can change this value under **WooCommerce > Barcode Settings**.

== Screenshots ==

1. Admin barcode page with product selector and label options.
2. Barcode settings screen with default preferences and cache controls.
3. Sample 40x30 mm label output featuring SKU, price, and barcode.

== Changelog ==

= 2.0.0 =
- Introduced modular architecture with autoloaded classes and templates.
- Added settings screen for label defaults and cache clearing.
- Improved variation selection, SKU generation reliability, and label batching.
- Hardened AJAX endpoints and admin notices.

= 1.3.1 =
- Ensured variation SKU auto-generation and bulk SKU creation.
- Added multi-product selection, variation filtering, and bulk print action.
- Patched barcode rendering edge cases in PDF output.

== Upgrade Notice ==

= 2.0.0 =
Refactor introduces new settings and caching paths. After upgrading, visit **WooCommerce > Barcode Settings** to verify defaults and clear caches if necessary.

== License ==

This plugin is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License version 2 as published by the Free Software Foundation.
