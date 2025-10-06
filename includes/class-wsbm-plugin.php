<?php
/**
 * Main plugin bootstrap class.
 *
 * @package Woo_SKU_Barcode_Manager
 */

namespace WSBM;

defined( 'ABSPATH' ) || exit;

class Plugin {

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Manager handling business logic.
     *
     * @var Manager
     */
    private $manager;

    /**
     * Admin helper.
     *
     * @var Admin
     */
    private $admin;

    public static function instance() : self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->register_hooks();
    }

    private function register_hooks() : void {
        add_action( 'plugins_loaded', [ $this, 'bootstrap_components' ] );
        register_activation_hook( WSBM_PLUGIN_FILE, [ $this, 'on_activate' ] );
    }

    public function bootstrap_components() : void {
        if ( ! class_exists( '\\WooCommerce' ) ) {
            return;
        }

        $this->manager = new Manager();
        $this->admin   = new Admin( $this->manager );
    }

    public function on_activate() : void {
        if ( ! class_exists( '\\WooCommerce' ) ) {
            return;
        }

        $manager = new Manager();
        $manager->ensure_cache_directory();
    }
}
