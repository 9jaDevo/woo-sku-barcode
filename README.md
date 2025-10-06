# SKU & Barcode Manager for WooCommerce

SKU & Barcode Manager for WooCommerce keeps WooCommerce catalog data tidy by auto-generating 11-digit SKUs and producing print-ready barcode labels. The project ships as a WordPress plugin and bundles TCPDF plus Picqer barcode helpers for offline-friendly PDFs.

## Features

- Deterministic 11-digit SKU generation for products and variations.
- Enhanced product picker powered by WooCommerce SelectWoo with variation filtering.
- PDF label output with popular page sizes (40x30 mm, 52x25 mm, 100x50 mm).
- Optional per-stock duplication and cached barcode assets for quick reprints.
- Settings page for defaults, batch limits, and cache maintenance.

## Getting Started

1. Clone or download this repository into `wp-content/plugins/` inside your development site.
2. Activate **Woo SKU & Barcode Manager** from the WordPress admin Plugins screen.
3. Open **WooCommerce > Barcodes** to generate labels, or **WooCommerce > Barcode Settings** to configure defaults.

For detailed installation steps, FAQs, screenshots, and the changelog, see the companion [readme.txt](readme.txt) that follows the WordPress.org plugin readme specification.

## Development

- Autoloaded classes live under `includes/` and follow the `WSBM\` namespace.
- The barcode generation form and settings UI render from PHP templates in `templates/`.
- Third-party libraries (TCPDF and Picqer) are bundled under `lib/` and excluded from coding-standard checks.
- Continuous integration runs the official [WordPress Plugin Check](https://github.com/WordPress/plugin-check-action) workflow on pushes and pull requests.

### Coding Standards

Run the plugin check locally via Docker or WP-CLI:

```bash
wp plugin check woo-sku-barcode --strict
```

Feel free to add unit or integration tests under a `tests/` directory (not yet present) and wire them into GitHub Actions as needed.

## Contributing

Issues and pull requests are welcome. Please open a discussion describing the change, keep code comments succinct, and follow the repository’s PHP style (WordPress Coding Standards).

## License

GPL-2.0-or-later. See the header in [readme.txt](readme.txt) for full licensing details.
