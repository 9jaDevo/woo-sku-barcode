<?php
/**
 * Plugin Name: Woo SKU & Barcode Manager
 * Description: Auto-generate 11-digit SKUs, print barcodes with selectable fields and optional per-stock duplication.
 * Version:     2.0.0
 * Author:      Michael Akinwumi
 * License:     GPL-2.0+
 * Text Domain: woo-sku-barcode
 */

defined( 'ABSPATH' ) || exit;

define( 'WSBM_VERSION', '2.0.0' );

define( 'WSBM_PLUGIN_FILE', __FILE__ );
define( 'WSBM_PLUGIN_PATH', trailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WSBM_PLUGIN_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );

// Register Picqer autoloader for bundled library.
spl_autoload_register( static function ( $class ) {
    $prefix   = 'Picqer\\Barcode\\';
    $base_dir = WSBM_PLUGIN_PATH . 'lib/Picqer/';

    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    $file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

require_once WSBM_PLUGIN_PATH . 'includes/class-wsbm-autoloader.php';

( new WSBM\Autoloader( WSBM_PLUGIN_PATH . 'includes' ) )->register();

// Bootstrap plugin.
WSBM\Plugin::instance();
