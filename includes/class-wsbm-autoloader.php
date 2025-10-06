<?php
/**
 * Simple PSR-4 autoloader for the Woo SKU & Barcode Manager plugin.
 *
 * @package Woo_SKU_Barcode_Manager
 */

namespace WSBM;

defined( 'ABSPATH' ) || exit;

class Autoloader {

    /**
     * Namespace prefix for plugin classes.
     */
    private const PREFIX = 'WSBM\\';

    /**
     * Base directory for the namespace prefix.
     *
     * @var string
     */
    private $base_dir;

    public function __construct( string $base_dir ) {
        $this->base_dir = untrailingslashit( $base_dir ) . '/';
    }

    public function register() : void {
        spl_autoload_register( [ $this, 'autoload' ] );
    }

    private function autoload( string $class ) : void {
        if ( 0 !== strpos( $class, self::PREFIX ) ) {
            return;
        }

    $relative_class = substr( $class, strlen( self::PREFIX ) );
    $relative_path  = 'class-wsbm-' . strtolower( str_replace( '\\', '-', $relative_class ) ) . '.php';
        $file           = $this->base_dir . $relative_path;

        if ( file_exists( $file ) ) {
            require $file;
        }
    }
}
