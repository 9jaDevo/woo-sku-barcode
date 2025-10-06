<?php
/**
 * Admin layer for Woo SKU & Barcode Manager.
 *
 * @package Woo_SKU_Barcode_Manager
 */

namespace WSBM;

defined( 'ABSPATH' ) || exit;

class Admin {

    public const MENU_SLUG = 'woo-sbm-barcodes';

    /**
     * @var Manager
     */
    private $manager;

    public function __construct( Manager $manager ) {
        $this->manager = $manager;
        $this->register_hooks();
    }

    private function register_hooks() : void {
        add_filter( 'bulk_actions-edit-product', [ $this, 'register_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-product', [ $this, 'handle_bulk_actions' ], 10, 3 );
        add_filter( 'post_row_actions', [ $this, 'add_row_action' ], 10, 2 );
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_notices', [ $this, 'maybe_render_notices' ] );
        add_action( 'admin_post_wsbm_clear_cache', [ $this, 'handle_clear_cache' ] );
    }

    public function register_bulk_actions( array $actions ) : array {
        $actions['wsbm_generate_sku']  = __( 'Generate missing SKUs', 'woo-sku-barcode' );
        $actions['wsbm_print_barcodes'] = __( 'Print barcodes', 'woo-sku-barcode' );
        return $actions;
    }

    public function handle_bulk_actions( string $redirect_to, string $action, array $post_ids ) : string {
        if ( 'wsbm_generate_sku' === $action ) {
            $count = 0;
            foreach ( $post_ids as $post_id ) {
                $product = wc_get_product( $post_id );
                if ( $product ) {
                    $before = $product->get_sku();
                    $this->manager->ensure_product_sku( $product );
                    if ( ! $before && $product->get_sku() ) {
                        $count++;
                    }
                }
            }

            return add_query_arg(
                [
                    'wsbm_notice' => 'sku-generated',
                    'wsbm_count'  => $count,
                ],
                $redirect_to
            );
        }

        if ( 'wsbm_print_barcodes' === $action && ! empty( $post_ids ) ) {
            $items = implode( ',', array_map( 'absint', $post_ids ) );
            return add_query_arg(
                [
                    'page'  => self::MENU_SLUG,
                    'items' => $items,
                ],
                admin_url( 'admin.php' )
            );
        }

        return $redirect_to;
    }

    public function add_row_action( array $actions, \WP_Post $post ) : array {
        if ( 'product' !== $post->post_type || ! current_user_can( 'manage_woocommerce' ) ) {
            return $actions;
        }

        $url = add_query_arg(
            [
                'page'       => self::MENU_SLUG,
                'product_id' => $post->ID,
            ],
            admin_url( 'admin.php' )
        );

        $actions['wsbm_print'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Print barcodes', 'woo-sku-barcode' ) . '</a>';
        return $actions;
    }

    public function register_menu() : void {
        add_submenu_page(
            'woocommerce',
            __( 'Print Barcodes', 'woo-sku-barcode' ),
            __( 'Barcodes', 'woo-sku-barcode' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            [ $this, 'render_admin_page' ]
        );

        add_submenu_page(
            'woocommerce',
            __( 'Barcode Settings', 'woo-sku-barcode' ),
            __( 'Barcode Settings', 'woo-sku-barcode' ),
            'manage_woocommerce',
            'wsbm-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() : void {
        register_setting( 'wsbm_settings_group', Manager::OPTION_NAME, [ $this, 'sanitize_settings' ] );

        add_settings_section(
            'wsbm_general_section',
            __( 'General Options', 'woo-sku-barcode' ),
            '__return_false',
            'wsbm_settings'
        );

        add_settings_field(
            'default_label',
            __( 'Default label size', 'woo-sku-barcode' ),
            [ $this, 'render_default_label_field' ],
            'wsbm_settings',
            'wsbm_general_section'
        );

        add_settings_field(
            'default_fields',
            __( 'Default fields to print', 'woo-sku-barcode' ),
            [ $this, 'render_default_fields_field' ],
            'wsbm_settings',
            'wsbm_general_section'
        );

        add_settings_field(
            'print_per_stock',
            __( 'Print per stock by default', 'woo-sku-barcode' ),
            [ $this, 'render_print_per_stock_field' ],
            'wsbm_settings',
            'wsbm_general_section'
        );

        add_settings_field(
            'max_labels_per_batch',
            __( 'Maximum labels per batch', 'woo-sku-barcode' ),
            [ $this, 'render_max_labels_field' ],
            'wsbm_settings',
            'wsbm_general_section'
        );
    }

    public function sanitize_settings( array $values ) : array {
        $defaults = $this->manager->get_default_settings();

        $sanitized = [
            'default_label'        => isset( $values['default_label'] ) ? sanitize_text_field( $values['default_label'] ) : $defaults['default_label'],
            'default_fields'       => isset( $values['default_fields'] ) ? array_map( 'sanitize_text_field', (array) $values['default_fields'] ) : $defaults['default_fields'],
            'print_per_stock'      => ! empty( $values['print_per_stock'] ),
            'max_labels_per_batch' => isset( $values['max_labels_per_batch'] ) ? absint( $values['max_labels_per_batch'] ) : $defaults['max_labels_per_batch'],
        ];

        $allowed_labels = [ '40x30', '52x25', '100x50' ];
        if ( ! in_array( $sanitized['default_label'], $allowed_labels, true ) ) {
            $sanitized['default_label'] = $defaults['default_label'];
        }

        $allowed_fields             = [ 'name', 'price', 'barcode', 'sku' ];
        $sanitized['default_fields'] = array_values( array_intersect( $allowed_fields, $sanitized['default_fields'] ) );
        if ( empty( $sanitized['default_fields'] ) ) {
            $sanitized['default_fields'] = $defaults['default_fields'];
        }

        if ( $sanitized['max_labels_per_batch'] < 1 ) {
            $sanitized['max_labels_per_batch'] = $defaults['max_labels_per_batch'];
        }

        return $sanitized;
    }

    public function enqueue_assets( string $hook ) : void {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $target_hooks = [
            'woocommerce_page_' . self::MENU_SLUG,
            'woocommerce_page_wsbm-settings',
        ];

        if ( ! in_array( $hook, $target_hooks, true ) ) {
            return;
        }

        if ( ! wp_style_is( 'select2', 'registered' ) && function_exists( 'WC' ) ) {
            wp_register_style( 'select2', WC()->plugin_url() . '/assets/css/select2.css', [], WC()->version );
        }
        if ( ! wp_script_is( 'selectWoo', 'registered' ) && function_exists( 'WC' ) ) {
            wp_register_script( 'selectWoo', WC()->plugin_url() . '/assets/js/selectWoo/selectWoo.full.min.js', [ 'jquery' ], WC()->version, true );
        }
        if ( ! wp_script_is( 'wc-enhanced-select', 'registered' ) && function_exists( 'WC' ) ) {
            wp_register_script( 'wc-enhanced-select', WC()->plugin_url() . '/assets/js/admin/wc-enhanced-select.min.js', [ 'jquery', 'selectWoo' ], WC()->version, true );
        }

        wp_enqueue_style( 'select2' );
        wp_enqueue_script( 'selectWoo' );
        wp_enqueue_script( 'wc-enhanced-select' );
    }

    public function maybe_render_notices() : void {
        $notice = isset( $_GET['wsbm_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['wsbm_notice'] ) ) : '';

        if ( 'sku-generated' === $notice ) {
            $count = isset( $_GET['wsbm_count'] ) ? absint( wp_unslash( $_GET['wsbm_count'] ) ) : 0;
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf( _n( '%d SKU generated.', '%d SKUs generated.', $count, 'woo-sku-barcode' ), $count ) )
            );
        }

        if ( 'cache-cleared' === $notice ) {
            $count = isset( $_GET['wsbm_count'] ) ? absint( wp_unslash( $_GET['wsbm_count'] ) ) : 0;
            printf(
                '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
                esc_html( sprintf( _n( '%d cached barcode removed.', '%d cached barcodes removed.', $count, 'woo-sku-barcode' ), $count ) )
            );
        }
    }

    public function handle_clear_cache() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'woo-sku-barcode' ) );
        }

        check_admin_referer( 'wsbm_clear_cache' );

        $removed = $this->manager->clear_cache_directory();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'        => 'wsbm-settings',
                    'wsbm_notice' => 'cache-cleared',
                    'wsbm_count'  => $removed,
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public function render_admin_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-sku-barcode' ) );
        }

        $pid     = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
        $product = $pid ? wc_get_product( $pid ) : null;

        $requested_ids = [];
        if ( ! empty( $_GET['items'] ) ) {
            $raw = explode( ',', sanitize_text_field( wp_unslash( $_GET['items'] ) ) );
            foreach ( $raw as $maybe_id ) {
                $id = absint( $maybe_id );
                if ( $id ) {
                    $requested_ids[] = $id;
                }
            }
        }

        if ( ! $product && ! empty( $requested_ids ) ) {
            $maybe = wc_get_product( $requested_ids[0] );
            if ( $maybe ) {
                $product = $maybe;
                $pid     = $product->get_id();
            }
        }

        $selected_items = [];
        foreach ( $requested_ids as $rid ) {
            $prefill = wc_get_product( $rid );
            if ( $prefill ) {
                $selected_items[ $prefill->get_id() ] = wp_strip_all_tags( $prefill->get_formatted_name() );
            }
        }

        if ( $product ) {
            $selected_items[ $product->get_id() ] = wp_strip_all_tags( $product->get_formatted_name() );
        }

        $variation_entries = [];
        if ( $product && $product->is_type( 'variable' ) ) {
            $child_ids            = $product->get_children();
            $prefilled_variations = array_intersect( $requested_ids, $child_ids );
            foreach ( $child_ids as $child_id ) {
                $variation = wc_get_product( $child_id );
                if ( ! $variation ) {
                    continue;
                }
                $variation_entries[] = [
                    'id'      => $variation->get_id(),
                    'label'   => wp_strip_all_tags( $variation->get_formatted_name() ),
                    'sku'     => $variation->get_sku(),
                    'stock'   => $variation->get_stock_quantity(),
                    'checked' => in_array( $variation->get_id(), $prefilled_variations, true ),
                ];
            }
        }

        if ( function_exists( 'wc_enqueue_js' ) ) {
            $search_nonce = wp_create_nonce( 'search-products' );
            $ajax_url     = admin_url( 'admin-ajax.php' );
            $script       = sprintf(
                "jQuery( function( $ ) {
                    var selectEl = jQuery( '#woo-sbm-items' );
                    if ( ! selectEl.length || selectEl.hasClass( 'enhanced' ) || typeof jQuery.fn.selectWoo !== 'function' ) {
                        return;
                    }
                    selectEl.selectWoo({
                        minimumInputLength: 2,
                        allowClear: true,
                        multiple: true,
                        placeholder: selectEl.data( 'placeholder' ),
                        ajax: {
                            url: '%s',
                            dataType: 'json',
                            delay: 250,
                            data: function( params ) {
                                return {
                                    term: params.term,
                                    action: 'woocommerce_json_search_products_and_variations',
                                    security: '%s'
                                };
                            },
                            processResults: function( data ) {
                                var results = [];
                                if ( data ) {
                                    jQuery.each( data, function( id, text ) {
                                        results.push({ id: id, text: text });
                                    } );
                                }
                                return { results: results };
                            },
                            cache: true
                        },
                        escapeMarkup: function( markup ) { return markup; }
                    }).addClass( 'enhanced' );
                } );",
                esc_js( $ajax_url ),
                esc_js( $search_nonce )
            );
            wc_enqueue_js( $script );
        }

        $settings      = $this->manager->get_settings();
        $label_sizes   = [
            '40x30'  => __( '40×30 mm (single column)', 'woo-sku-barcode' ),
            '52x25'  => __( '52×25 mm (Avery L7161)', 'woo-sku-barcode' ),
            '100x50' => __( '100×50 mm (thermal)', 'woo-sku-barcode' ),
        ];
        $field_options = [ 'name', 'price', 'barcode', 'sku' ];

        include WSBM_PLUGIN_PATH . 'templates/admin-barcode-page.php';
    }

    public function render_settings_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-sku-barcode' ) );
        }

        $settings = $this->manager->get_settings();
        include WSBM_PLUGIN_PATH . 'templates/admin-settings-page.php';
    }

    public function render_default_label_field() : void {
        $settings    = $this->manager->get_settings();
        $label_sizes = [
            '40x30'  => __( '40×30 mm (single column)', 'woo-sku-barcode' ),
            '52x25'  => __( '52×25 mm (Avery L7161)', 'woo-sku-barcode' ),
            '100x50' => __( '100×50 mm (thermal)', 'woo-sku-barcode' ),
        ];
        ?>
        <select name="<?php echo esc_attr( Manager::OPTION_NAME ); ?>[default_label]">
            <?php foreach ( $label_sizes as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['default_label'], $key ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_default_fields_field() : void {
        $settings       = $this->manager->get_settings();
        $field_options  = [ 'name', 'price', 'barcode', 'sku' ];
        ?>
        <?php foreach ( $field_options as $field ) : ?>
            <label style="display:block;">
                <input type="checkbox" name="<?php echo esc_attr( Manager::OPTION_NAME ); ?>[default_fields][]" value="<?php echo esc_attr( $field ); ?>" <?php checked( in_array( $field, $settings['default_fields'], true ) ); ?>>
                <?php echo esc_html( ucfirst( $field ) ); ?>
            </label>
        <?php endforeach; ?>
        <?php
    }

    public function render_print_per_stock_field() : void {
        $settings = $this->manager->get_settings();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( Manager::OPTION_NAME ); ?>[print_per_stock]" value="1" <?php checked( $settings['print_per_stock'] ); ?>>
            <?php esc_html_e( 'Enable per-stock label printing by default', 'woo-sku-barcode' ); ?>
        </label>
        <?php
    }

    public function render_max_labels_field() : void {
        $settings = $this->manager->get_settings();
        ?>
        <input type="number" min="1" step="1" name="<?php echo esc_attr( Manager::OPTION_NAME ); ?>[max_labels_per_batch]" value="<?php echo esc_attr( $settings['max_labels_per_batch'] ); ?>">
        <p class="description"><?php esc_html_e( 'Maximum number of labels that can be generated in a single batch.', 'woo-sku-barcode' ); ?></p>
        <?php
    }
}
