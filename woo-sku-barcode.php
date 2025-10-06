<?php
/**
 * Plugin Name: Woo SKU & Barcode Manager
 * Description: Auto-generate 11-digit SKUs, print barcodes with selectable fields and optional per-stock duplication.
 * Version:     1.3.1
 * Author:      Michael Akinwumi
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*───────────────────────────────────────────────────────────
 * PSR-4 Autoloader for Picqer\Barcode\* classes
 *───────────────────────────────────────────────────────────*/
spl_autoload_register( function( $class ) {
    $prefix   = 'Picqer\\Barcode\\';
    $base_dir = __DIR__ . '/lib/Picqer/';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

// Optional Composer autoload
// if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
//     require_once __DIR__ . '/vendor/autoload.php';
// }

class Woo_Sku_Barcode_Manager {

    const CACHE_DIR = WP_CONTENT_DIR . '/cache/woo_barcodes/';
    const MENU_SLUG = 'woo-sbm-barcodes';

    public function __construct() {
        // Auto-SKU on publish/save
        add_action( 'woocommerce_admin_process_product_object', [ $this, 'maybe_generate_sku_admin' ], 99 );
        add_action( 'woocommerce_save_product_variation',       [ $this, 'maybe_generate_sku_variation' ], 99, 2 );

        // Bulk action: Generate missing SKUs
        add_filter( 'bulk_actions-edit-product',    [ $this, 'register_bulk_action' ] );
        add_filter( 'handle_bulk_actions-edit-product', [ $this, 'handle_bulk_action' ], 10, 3 );

        // Row action: Print barcodes
        add_filter( 'post_row_actions', [ $this, 'row_action_print' ], 10, 2 );

        // Admin submenu: Barcodes
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );

        // Admin assets for barcode UI
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX: render labels PDF
        add_action( 'wp_ajax_woo_sbm_render_labels', [ $this, 'ajax_render_labels' ] );

        // Activation: ensure cache directory exists
        register_activation_hook( __FILE__, [ $this, 'on_activate' ] );
    }

    /*--------------------------------------------------------------
     * 1) SKU generation on admin save
     *-------------------------------------------------------------*/
    public function maybe_generate_sku_admin( $product ) {
        $raw_sku = $product->get_sku( 'edit' );
        if ( '' !== $raw_sku && null !== $raw_sku ) {
            return;
        }
        $product->set_sku( $this->generate_11_digit_sku( $product ) );
        $product->save();
    }

    public function maybe_generate_sku_variation( $variation_id, $index ) {
        $variation = wc_get_product( $variation_id );
        if ( ! $variation ) {
            return;
        }
        $raw_sku = $variation->get_sku( 'edit' );
        if ( '' !== $raw_sku && null !== $raw_sku ) {
            return;
        }
        $variation->set_sku( $this->generate_11_digit_sku( $variation ) );
        $variation->save();
    }

    private function generate_11_digit_sku( WC_Product $p ) : string {
        if ( ! $p->is_type( 'variation' ) ) {
            $sku = sprintf( '%09d00', $p->get_id() );
        } else {
            $parent = wc_get_product( $p->get_parent_id() );
            $base   = sprintf( '%09d', $parent ? $parent->get_id() : $p->get_parent_id() );
            $taken  = [];
            if ( $parent ) {
                foreach ( $parent->get_children() as $vid ) {
                    $child = wc_get_product( $vid );
                    if ( ! $child ) {
                        continue;
                    }
                    $vs = $child->get_sku();
                    if ( preg_match( '/^' . $base . '(\d{2})$/', (string) $vs, $m ) ) {
                        $taken[] = intval( $m[1] );
                    }
                }
            }
            $sku = '';
            for ( $i = 1; $i < 100; $i++ ) {
                if ( ! in_array( $i, $taken, true ) ) {
                    $sku = $base . sprintf( '%02d', $i );
                    break;
                }
            }
            if ( '' === $sku ) {
                $sku = $base . '00';
            }
        }
        while ( wc_get_product_id_by_sku( $sku ) ) {
            $sku = str_pad( ( intval( $sku ) + 1 ) % 99999999999, 11, '0', STR_PAD_LEFT );
        }
        return $sku;
    }

    /**
     * Ensure a product (or variation) has a persisted SKU, generating one if absent.
     */
    private function ensure_product_sku( WC_Product $product ) : string {
        $existing = $product->get_sku( 'edit' );
        if ( '' !== $existing && null !== $existing ) {
            return $product->get_sku();
        }
        $new_sku = $this->generate_11_digit_sku( $product );
        $product->set_sku( $new_sku );
        $product->save();
        return $new_sku;
    }

    /*--------------------------------------------------------------
     * 2) Bulk-action: Generate missing SKUs
     *-------------------------------------------------------------*/
    public function register_bulk_action( $actions ) {
        $actions['woo_sbm_bulk_sku'] = __( 'Generate missing SKUs', 'woo-sbm' );
        $actions['woo_sbm_bulk_print'] = __( 'Print barcodes', 'woo-sbm' );
        return $actions;
    }

    public function handle_bulk_action( $redirect, $action, $post_ids ) {
        if ( 'woo_sbm_bulk_sku' === $action ) {
            foreach ( $post_ids as $pid ) {
                $prod = wc_get_product( $pid );
                if ( $prod && ! $prod->get_sku() ) {
                    $prod->set_sku( $this->generate_11_digit_sku( $prod ) );
                    $prod->save();
                }
            }
            return add_query_arg( 'woo_sbm_done', count( $post_ids ), $redirect );
        }

        if ( 'woo_sbm_bulk_print' === $action && ! empty( $post_ids ) ) {
            $ids = implode( ',', array_map( 'absint', $post_ids ) );
            return add_query_arg(
                [
                    'page'  => self::MENU_SLUG,
                    'items' => $ids,
                ],
                admin_url( 'admin.php' )
            );
        }

        return $redirect;
    }

    /*--------------------------------------------------------------
     * 3) Row action: Print Barcodes link
     *-------------------------------------------------------------*/
    public function row_action_print( $actions, $post ) {
        if ( 'product' !== $post->post_type ) {
            return $actions;
        }
        $url = add_query_arg(
            [ 'page' => self::MENU_SLUG, 'product_id' => $post->ID ],
            admin_url( 'admin.php' )
        );
        $actions['woo_sbm_print'] = '<a href="' . esc_url( $url ) . '">' . __( 'Print barcodes', 'woo-sbm' ) . '</a>';
        return $actions;
    }

    /*--------------------------------------------------------------
     * 4) Admin submenu & page
     *-------------------------------------------------------------*/
    public function register_admin_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Print Barcodes', 'woo-sbm' ),
            __( 'Barcodes',      'woo-sbm' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            [ $this, 'render_admin_page' ]
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
            return;
        }
        $wc_version = function_exists( 'WC' ) ? WC()->version : '1.0.0';
        if ( ! wp_style_is( 'select2', 'registered' ) && function_exists( 'WC' ) ) {
            wp_register_style( 'select2', WC()->plugin_url() . '/assets/css/select2.css', [], $wc_version );
        }
        if ( ! wp_script_is( 'selectWoo', 'registered' ) && function_exists( 'WC' ) ) {
            wp_register_script( 'selectWoo', WC()->plugin_url() . '/assets/js/selectWoo/selectWoo.full.min.js', [ 'jquery' ], $wc_version, true );
        }
        if ( ! wp_script_is( 'wc-enhanced-select', 'registered' ) && function_exists( 'WC' ) ) {
            wp_register_script( 'wc-enhanced-select', WC()->plugin_url() . '/assets/js/admin/wc-enhanced-select.min.js', [ 'jquery', 'selectWoo' ], $wc_version, true );
        }

        wp_enqueue_style( 'select2' );
        wp_enqueue_script( 'selectWoo' );
        wp_enqueue_script( 'wc-enhanced-select' );
    }

    public function render_admin_page() {
        $pid     = absint( $_GET['product_id'] ?? 0 );
        $prod    = $pid ? wc_get_product( $pid ) : null;
        $fields  = [ 'name', 'price', 'barcode', 'sku' ];
        $sizes   = [
            '40x30'  => __( '40×30 mm (single column)', 'woo-sbm' ),
            '52x25'  => __( '52×25 mm (Avery L7161)', 'woo-sbm' ),
            '100x50' => __( '100×50 mm (thermal)', 'woo-sbm' ),
        ];
        $default_size = '40x30';

        $requested_ids = [];
        if ( ! empty( $_GET['items'] ) ) {
            $raw = explode( ',', sanitize_text_field( wp_unslash( $_GET['items'] ) ) );
            foreach ( $raw as $maybe_id ) {
                $requested_id = absint( $maybe_id );
                if ( $requested_id ) {
                    $requested_ids[] = $requested_id;
                }
            }
        }

        if ( ! $prod && ! empty( $requested_ids ) ) {
            $maybe = wc_get_product( $requested_ids[0] );
            if ( $maybe ) {
                $prod = $maybe;
                $pid  = $prod->get_id();
            }
        }

        $selected_items = [];
        foreach ( $requested_ids as $rid ) {
            $prefill = wc_get_product( $rid );
            if ( $prefill ) {
                $selected_items[ $prefill->get_id() ] = wp_strip_all_tags( $prefill->get_formatted_name() );
            }
        }
        if ( $prod ) {
            $selected_items[ $prod->get_id() ] = wp_strip_all_tags( $prod->get_formatted_name() );
        }

        if ( function_exists( 'wc_enqueue_js' ) ) {
            $search_nonce = wp_create_nonce( 'search-products' );
            $ajax_url     = esc_url_raw( admin_url( 'admin-ajax.php' ) );
            wc_enqueue_js(
                "jQuery( function( $ ) {
                    var $select = $( '#woo-sbm-items' );
                    if ( ! $select.length || $select.hasClass( 'enhanced' ) || typeof $.fn.selectWoo !== 'function' ) {
                        return;
                    }
                    $select.selectWoo({
                        minimumInputLength: 2,
                        allowClear: true,
                        multiple: true,
                        placeholder: $select.data( 'placeholder' ),
                        ajax: {
                            url: '{$ajax_url}',
                            dataType: 'json',
                            delay: 250,
                            data: function( params ) {
                                return {
                                    term: params.term,
                                    action: 'woocommerce_json_search_products_and_variations',
                                    security: '{$search_nonce}'
                                };
                            },
                            processResults: function( data ) {
                                var results = [];
                                if ( data ) {
                                    $.each( data, function( id, text ) {
                                        results.push({ id: id, text: text });
                                    } );
                                }
                                return { results: results };
                            },
                            cache: true
                        },
                        escapeMarkup: function( markup ) { return markup; }
                    }).addClass( 'enhanced' );
                } );"
            );
        }

        $variation_entries = [];
        if ( $prod && $prod->is_type( 'variable' ) ) {
            $child_ids            = $prod->get_children();
            $prefilled_variations = array_intersect( $requested_ids, $child_ids );
            foreach ( $child_ids as $vid ) {
                $variation = wc_get_product( $vid );
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
        ?>
                <div class="wrap">
                    <h1><?php _e( 'Print Barcodes', 'woo-sbm' ); ?></h1>
                    <p><?php _e( 'Search for products or specific variations, configure the label, and generate your barcode sheet.', 'woo-sbm' ); ?></p>
                    <form method="post" target="_blank" action="<?php echo admin_url( 'admin-ajax.php' ); ?>">
                        <input type="hidden" name="action"     value="woo_sbm_render_labels">
                        <input type="hidden" name="product_id" value="<?php echo esc_attr( $pid ); ?>">
                        <?php wp_nonce_field( 'woo_sbm_print_' . $pid ); ?>

                        <table class="form-table">
                            <tr>
                                <th><?php _e( 'Products or variations', 'woo-sbm' ); ?></th>
                                <td>
                                    <select id="woo-sbm-items" class="wc-product-search" multiple="multiple" style="width:100%;" name="items[]" data-placeholder="<?php esc_attr_e( 'Search products or variations…', 'woo-sbm' ); ?>" data-allow_clear="true">
                                        <?php foreach ( $selected_items as $sid => $label ) : ?>
                                            <option value="<?php echo esc_attr( $sid ); ?>" selected="selected"><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php _e( 'Pick any mix of simple products or individual variations to include in the PDF.', 'woo-sbm' ); ?></p>
                                </td>
                            </tr>

                            <?php if ( ! empty( $variation_entries ) ) : ?>
                            <tr>
                                <th><?php _e( 'Limit to variations', 'woo-sbm' ); ?></th>
                                <td>
                                    <p class="description"><?php _e( 'Tick the exact variations you want labels for. Leave all unchecked to include every variation of this product.', 'woo-sbm' ); ?></p>
                                    <div style="max-height:220px; overflow:auto; border:1px solid #ccd0d4; padding:12px;">
                                        <?php foreach ( $variation_entries as $entry ) : ?>
                                            <label style="display:block; margin-bottom:6px;">
                                                <input type="checkbox" name="variation_ids[]" value="<?php echo esc_attr( $entry['id'] ); ?>" <?php checked( $entry['checked'] ); ?>>
                                                <span><?php echo esc_html( $entry['label'] ); ?></span>
                                                <?php if ( $entry['sku'] ) : ?>
                                                    <span style="color:#555;">&nbsp;<?php printf( esc_html__( '(SKU: %s)', 'woo-sbm' ), esc_html( $entry['sku'] ) ); ?></span>
                                                <?php endif; ?>
                                                <?php if ( null !== $entry['stock'] ) : ?>
                                                    <span style="color:#555;">&nbsp;<?php printf( esc_html__( 'Stock: %s', 'woo-sbm' ), esc_html( $entry['stock'] ) ); ?></span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <tr>
                                <th><?php _e( 'Label size', 'woo-sbm' ); ?></th>
                                <td>
                                    <select name="label">
                                        <?php foreach ( $sizes as $k => $lbl ) : ?>
                                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $default_size ); ?>><?php echo esc_html( $lbl ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e( 'Fields to print', 'woo-sbm' ); ?></th>
                                <td>
                                    <?php foreach ( $fields as $f ) : ?>
                                        <label style="margin-right:1em;">
                                            <input type="checkbox" name="fields[]" value="<?php echo esc_attr( $f ); ?>" checked>
                                            <?php echo esc_html( ucfirst( $f ) ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e( 'Quantity per item', 'woo-sbm' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="print_stock" value="1" checked>
                                        <?php _e( 'Print one label per stock unit', 'woo-sbm' ); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button( __( 'Generate & Print', 'woo-sbm' ) ); ?>
                    </form>
        </div>
        <?php
    }

    /*--------------------------------------------------------------
     * 5) AJAX: Render PDF with TCPDF + Picqer
     *-------------------------------------------------------------*/
    // public function ajax_render_labels() {
    //     $pid = absint( $_POST['product_id'] ?? 0 );
    //     check_admin_referer( 'woo_sbm_print_' . $pid );

    //     // Load TCPDF if needed
    //     if ( ! class_exists( 'TCPDF' ) && file_exists( __DIR__ . '/lib/TCPDF/tcpdf.php' ) ) {
    //         require_once __DIR__ . '/lib/TCPDF/tcpdf.php';
    //     }

    //     // User inputs
    //     $size        = sanitize_text_field( $_POST['label'] ?? '52x25' );
    //     $fields      = array_map( 'sanitize_text_field', (array) $_POST['fields'] );
    //     $print_stock = isset( $_POST['print_stock'] );

    //     // Gather raw items
    //     $raw_items = [];
    //     $product   = wc_get_product( $pid );
    //     if ( $product ) {
    //         if ( $product->is_type( 'variable' ) ) {
    //             $want = sanitize_text_field( $_POST['variations'] ?? 'all' );
    //             $sel  = array_map( 'absint', $_POST['variation_ids'] ?? [] );
    //             foreach ( $product->get_children() as $vid ) {
    //                 if ( $want === 'selected' && ! in_array( $vid, $sel, true ) ) {
    //                     continue;
    //                 }
    //                 $raw_items[] = wc_get_product( $vid );
    //             }
    //         } else {
    //             $raw_items[] = $product;
    //         }
    //     }

    //     // Expand by stock quantity or once
    //     $items = [];
    //     foreach ( $raw_items as $it ) {
    //         $qty = $print_stock ? max(1,intval($it->get_stock_quantity())) : 1;
    //         for ( $i = 0; $i < $qty; $i++ ) {
    //             $items[] = $it;
    //         }
    //     }

    //     // Prepare PDF
    //     list( $w, $h ) = explode( 'x', $size );
    //     $w = floatval( $w ); $h = floatval( $h );
    //     $cols = ( $size === '40x30' ) ? 1 : floor(210 / $w);

    //     $pdf = new TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
    //     $pdf->setPrintHeader(false);
    //     $pdf->setPrintFooter(false);
    //     $pdf->SetMargins(0,0,0);
    //     $pdf->SetAutoPageBreak(false);
    //     $pdf->AddPage();

    //     // Render each label
    //     $x = 0; $y = 0; $col = 0;
    //     foreach ( $items as $it ) {
    //         $name      = wp_trim_words( $it->get_name(), 5, '…' );
    //         $raw_price = wc_price( $it->get_price() );
    //         $price     = html_entity_decode(strip_tags($raw_price), ENT_QUOTES, 'UTF-8');
    //         $sku       = $it->get_sku() ?: 'NOSKU';
    //         $png       = in_array('barcode',$fields) ? $this->draw_barcode_png($sku) : '';

    //         // layout offsets
    //         $margin_top = 2;
    //         $name_y     = $y + $margin_top;
    //         $price_y    = $name_y + 4;
    //         $barcode_y  = $price_y + 4;
    //         $barcode_h  = 12;
    //         $sku_y      = $barcode_y + $barcode_h + 1;

    //         // 1) Name (bold)
    //         if ( in_array('name',$fields) ) {
    //             $pdf->SetFont('helvetica','B',8);
    //             $pdf->SetXY($x,$name_y);
    //             $pdf->Cell($w,4,$name,0,0,'C');
    //         }

    //         // 2) Price
    //         // if ( in_array('price',$fields) ) {
    //         //     $pdf->SetFont('helvetica','',8);
    //         //     $pdf->SetXY($x,$price_y);
    //         //     $pdf->Cell($w,4,$price,0,0,'C');
    //         // }
            
    //         // 2) Price (now with DejaVu Sans for ₦)
    //         if ( in_array('price',$fields) ) {
    //             // switch to DejaVu
    //             $pdf->SetFont('dejavusans','',8);
    //             $pdf->SetXY($x, $price_y);
    //             $pdf->Cell($w, 4, $price, 0, 0, 'C');
    //             // restore for SKU (optional)
    //             $pdf->SetFont('helvetica','',7);
    //         }

    //         // 3) Barcode
    //         if ( $png && in_array('barcode',$fields) ) {
    //             $barcode_w = $w * 0.8;
    //             $xoff_bar  = $x + (($w - $barcode_w)/2);
    //             $pdf->Image($png,$xoff_bar,$barcode_y,$barcode_w,$barcode_h,'PNG');
    //         }

    //         // 4) SKU
    //         if ( in_array('sku',$fields) ) {
    //             $pdf->SetFont('helvetica','',7);
    //             $pdf->SetXY($x,$sku_y);
    //             $pdf->Cell($w,4,$sku,0,0,'C');
    //         }

    //         // next cell
    //         $x += $w; $col++;
    //         if ( $col >= $cols ) {
    //             $col = 0; $x = 0; $y += $h;
    //         }
    //     }

    //     $pdf->Output('labels.pdf','I');
    //     exit;
    // }

    public function ajax_render_labels() {
        $pid = absint( $_POST['product_id'] ?? 0 );
        check_admin_referer( 'woo_sbm_print_' . $pid );

        // 1) Load TCPDF if needed
        if ( ! class_exists( 'TCPDF' ) && file_exists( __DIR__ . '/lib/TCPDF/tcpdf.php' ) ) {
            require_once __DIR__ . '/lib/TCPDF/tcpdf.php';
        }

        // 2) User inputs
        $size        = sanitize_text_field( $_POST['label'] ?? '40x30' );
        $fields      = array_map( 'sanitize_text_field', (array) $_POST['fields'] );
        $print_stock = isset( $_POST['print_stock'] );

        $allowed_sizes  = [ '40x30', '52x25', '100x50' ];
        $allowed_fields = [ 'name', 'price', 'barcode', 'sku' ];

        if ( ! in_array( $size, $allowed_sizes, true ) ) {
            $size = '40x30';
        }

        $fields = array_values( array_intersect( $allowed_fields, $fields ) );
        if ( empty( $fields ) ) {
            $fields = $allowed_fields;
        }

        // 3) Build product pool based on submitted selections
        $item_ids = array_map( 'absint', (array) ( $_POST['items'] ?? [] ) );
        if ( empty( $item_ids ) && $pid ) {
            $item_ids[] = $pid;
        }
        $item_ids = array_unique( array_filter( $item_ids ) );

        $variation_filter = array_map( 'absint', (array) ( $_POST['variation_ids'] ?? [] ) );
        $variation_filter = array_unique( array_filter( $variation_filter ) );
        if ( ! empty( $variation_filter ) ) {
            $item_ids = array_unique( array_merge( $item_ids, $variation_filter ) );
        }

        $raw_items = [];
        foreach ( $item_ids as $item_id ) {
            $item = wc_get_product( $item_id );
            if ( ! $item ) {
                continue;
            }

            $this->ensure_product_sku( $item );

            if ( $item->is_type( 'variation' ) ) {
                $raw_items[ $item->get_id() ] = $item;
                continue;
            }

            if ( $item->is_type( 'variable' ) ) {
            $children            = $item->get_children();
            $selected_for_parent = empty( $variation_filter ) ? $children : array_intersect( $variation_filter, $children );
            $targets             = ! empty( $selected_for_parent ) ? $selected_for_parent : $children;
            foreach ( $targets as $vid ) {
                    $variation = wc_get_product( $vid );
                    if ( $variation ) {
                        $this->ensure_product_sku( $variation );
                        $raw_items[ $variation->get_id() ] = $variation;
                    }
                }
                continue;
            }

            $raw_items[ $item->get_id() ] = $item;
        }

        if ( empty( $raw_items ) ) {
            wp_die( __( 'No products selected for printing.', 'woo-sbm' ), '', [ 'response' => 400 ] );
        }

        // 4) Expand by stock quantity or once
        $items = [];
        foreach ( $raw_items as $it ) {
            $qty = $print_stock ? max( 1, intval( $it->get_stock_quantity() ) ) : 1;
            for ( $i = 0; $i < $qty; $i++ ) {
                $items[] = $it;
            }
        }

        // 5) Compute label dimensions
        list( $w, $h ) = explode( 'x', $size );
        $w = floatval( $w );
        $h = floatval( $h );

        // 6) Initialize PDF with custom page size for 40×30 mm (4× per page)
        if ( $size === '40x30' ) {
            $labels_per_page = 4;
            $page_h_mm       = $h * $labels_per_page; // 30mm * 4 = 120mm
            $pdf = new TCPDF( 'P', 'mm', [ $w, $page_h_mm ], true, 'UTF-8', false );
        } else {
            $pdf = new TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
        }

        $pdf->setPrintHeader( false );
        $pdf->setPrintFooter( false );
        $pdf->SetMargins( 0, 0, 0 );
        $pdf->SetAutoPageBreak( false );
        $pdf->AddPage();

        // 7) If not 40×30, prepare grid counters
        if ( $size !== '40x30' ) {
            $cols = max( 1, floor( 210 / $w ) );
            $col  = 0;
        }

        // 8) Loop through items and draw each label
        $count = count( $items );
        for ( $i = 0; $i < $count; $i++ ) {
            $it        = $items[ $i ];
            $name      = wp_trim_words( $it->get_name(), 5, '…' );
            $raw_price = wc_price( $it->get_price() );
            $price     = html_entity_decode( strip_tags( $raw_price ), ENT_QUOTES, 'UTF-8' );
            $sku       = $it->get_sku() ?: 'NOSKU';
            $png       = in_array( 'barcode', $fields )
                         ? $this->draw_barcode_png( $sku )
                         : '';

            // Determine X,Y for this label
            if ( $size === '40x30' ) {
                // New page every 4 labels
                if ( $i > 0 && $i % $labels_per_page === 0 ) {
                    $pdf->AddPage();
                }
                $pos = $i % $labels_per_page;
                $x   = 0;
                $y   = $pos * $h;
            } else {
                $row = floor( $col / $cols );
                if ( $row > 0 && ( ( $row * $h ) + $h ) > 297 ) {
                    $pdf->AddPage();
                    $col = 0;
                    $row = 0;
                }
                $x = ( $col % $cols ) * $w;
                $y = $row * $h;
                $col++;
            }

            // Layout offsets inside the cell
            $margin_top  = 2;
            $name_y      = $y + $margin_top;
            $price_y     = $name_y + 4;
            $barcode_y   = $price_y + 4;
            $barcode_h   = 12;
            $sku_y       = $barcode_y + $barcode_h + 1;

            // 1) Product Name (bold)
            if ( in_array( 'name', $fields ) ) {
                $pdf->SetFont( 'helvetica', 'B', 8 );
                $pdf->SetXY( $x, $name_y );
                $pdf->Cell( $w, 4, $name, 0, 0, 'C' );
            }

            // 2) Price (DejaVu Sans for currency symbol)
            if ( in_array( 'price', $fields ) ) {
                $pdf->SetFont( 'dejavusans', '', 8 );
                $pdf->SetXY( $x, $price_y );
                $pdf->Cell( $w, 4, $price, 0, 0, 'C' );
            }

            // 3) Barcode image
            if ( $png && in_array( 'barcode', $fields ) ) {
                $barcode_w = $w * 0.8;
                $xoff_bar  = $x + ( ( $w - $barcode_w ) / 2 );
                $pdf->Image( $png, $xoff_bar, $barcode_y, $barcode_w, $barcode_h, 'PNG' );
            }

            // 4) SKU
            if ( in_array( 'sku', $fields ) ) {
                $pdf->SetFont( 'helvetica', '', 7 );
                $pdf->SetXY( $x, $sku_y );
                $pdf->Cell( $w, 4, $sku, 0, 0, 'C' );
            }
        }

        // 9) Output inline
        $pdf->Output( 'labels.pdf', 'I' );
        exit;
    }

    /**
     * Generate & cache a Code-128 PNG
     */
    private function draw_barcode_png( $code ) {
        if ( ! is_dir( self::CACHE_DIR ) ) {
            wp_mkdir_p( self::CACHE_DIR );
        }
        $key  = md5( $code );
        $file = self::CACHE_DIR . "$key.png";
        if ( ! file_exists( $file ) ) {
            $gen = new \Picqer\Barcode\BarcodeGeneratorPNG();
            $png = $gen->getBarcode($code,$gen::TYPE_CODE_128,2,60);
            file_put_contents($file,$png);
        }
        return $file;
    }

    /*--------------------------------------------------------------
     * Activation hook: ensure cache directory
     *-------------------------------------------------------------*/
    public function on_activate() {
        if ( ! is_dir( self::CACHE_DIR ) ) {
            wp_mkdir_p( self::CACHE_DIR );
        }
    }
}

// Initialize
new Woo_Sku_Barcode_Manager();
