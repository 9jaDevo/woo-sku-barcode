<?php
// Back-compat loader for legacy bootstrap filename. The main plugin file moved to
// sku-barcode-manager-for-woocommerce.php. Loading that file preserves behavior
// for installations that still reference the old path.

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/sku-barcode-manager-for-woocommerce.php';
