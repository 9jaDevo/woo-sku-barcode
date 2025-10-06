<?php
/**
 * Core business logic for Woo SKU & Barcode Manager.
 *
 * @package Woo_SKU_Barcode_Manager
 */

namespace WSBM;

defined( 'ABSPATH' ) || exit;

use WC_Product;

class Manager {

    /**
     * Stored option name for plugin settings.
     */
    public const OPTION_NAME = 'wsbm_settings';

    /**
     * Hook registrations.
     */
    public function __construct() {
        add_action( 'woocommerce_admin_process_product_object', [ $this, 'maybe_generate_sku_admin' ], 99 );
        add_action( 'woocommerce_save_product_variation', [ $this, 'maybe_generate_sku_variation' ], 99, 2 );
        add_action( 'wp_ajax_woo_sbm_render_labels', [ $this, 'ajax_render_labels' ] );
    }

    /**
     * Default settings.
     */
    public function get_default_settings() : array {
        return [
            'default_label'        => '40x30',
            'default_fields'       => [ 'name', 'price', 'barcode', 'sku' ],
            'print_per_stock'      => true,
            'max_labels_per_batch' => 500,
        ];
    }

    public function get_settings() : array {
        $saved = get_option( self::OPTION_NAME, [] );
        $defaults = $this->get_default_settings();

        if ( empty( $saved ) ) {
            return $defaults;
        }

        $merged = array_merge( $defaults, $saved );

        if ( isset( $merged['default_fields'] ) && is_array( $merged['default_fields'] ) ) {
            $merged['default_fields'] = array_values( array_intersect( $defaults['default_fields'], $merged['default_fields'] ) );
        } else {
            $merged['default_fields'] = $defaults['default_fields'];
        }

        $merged['print_per_stock']      = (bool) $merged['print_per_stock'];
        $merged['max_labels_per_batch'] = max( 1, absint( $merged['max_labels_per_batch'] ) );

        return $merged;
    }

    public function get_setting( string $key, $default = null ) {
        $settings = $this->get_settings();
        return $settings[ $key ] ?? $default;
    }

    /**
     * Ensure cache directory exists.
     */
    public function ensure_cache_directory() : void {
        $dir = $this->get_cache_directory();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
    }

    public function clear_cache_directory() : int {
        $dir = $this->get_cache_directory();
        if ( ! is_dir( $dir ) ) {
            return 0;
        }

        $removed = 0;
        $files   = glob( trailingslashit( $dir ) . '*.png' );
        if ( $files ) {
            foreach ( $files as $file ) {
                if ( unlink( $file ) ) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    public function get_cache_directory() : string {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . 'woo-sbm-cache';
    }

    public function maybe_generate_sku_admin( WC_Product $product ) : void {
        $raw_sku = $product->get_sku( 'edit' );
        if ( '' !== $raw_sku && null !== $raw_sku ) {
            return;
        }
        $product->set_sku( $this->generate_11_digit_sku( $product ) );
        $product->save();
    }

    public function maybe_generate_sku_variation( int $variation_id ) : void {
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

    public function ensure_product_sku( WC_Product $product ) : string {
        $existing = $product->get_sku( 'edit' );
        if ( '' !== $existing && null !== $existing ) {
            return $product->get_sku();
        }
        $new_sku = $this->generate_11_digit_sku( $product );
        $product->set_sku( $new_sku );
        $product->save();
        return $new_sku;
    }

    private function generate_11_digit_sku( WC_Product $product ) : string {
        if ( ! $product->is_type( 'variation' ) ) {
            $sku = sprintf( '%09d00', $product->get_id() );
        } else {
            $parent = wc_get_product( $product->get_parent_id() );
            $base   = sprintf( '%09d', $parent ? $parent->get_id() : $product->get_parent_id() );
            $taken  = [];

            if ( $parent ) {
                foreach ( $parent->get_children() as $child_id ) {
                    $child = wc_get_product( $child_id );
                    if ( ! $child ) {
                        continue;
                    }
                    $child_sku = $child->get_sku();
                    if ( preg_match( '/^' . $base . '(\d{2})$/', (string) $child_sku, $matches ) ) {
                        $taken[] = (int) $matches[1];
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
            $sku = str_pad( ( (int) $sku + 1 ) % 99999999999, 11, '0', STR_PAD_LEFT );
        }

        return $sku;
    }

    public function ajax_render_labels() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'woo-sku-barcode' ), '', [ 'response' => 403 ] );
        }

        $pid = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        check_admin_referer( 'woo_sbm_print_' . $pid );

        $size        = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : $this->get_setting( 'default_label', '40x30' );
        $fields      = isset( $_POST['fields'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['fields'] ) ) : $this->get_setting( 'default_fields', [] );
        $print_stock = isset( $_POST['print_stock'] );

        $allowed_sizes  = [ '40x30', '52x25', '100x50' ];
        $allowed_fields = [ 'name', 'price', 'barcode', 'sku' ];

        if ( ! in_array( $size, $allowed_sizes, true ) ) {
            $size = '40x30';
        }

        $fields = array_values( array_intersect( $allowed_fields, $fields ) );
        if ( empty( $fields ) ) {
            $fields = $this->get_setting( 'default_fields', $allowed_fields );
        }

        $item_ids = isset( $_POST['items'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['items'] ) ) : [];
        if ( empty( $item_ids ) && $pid ) {
            $item_ids[] = $pid;
        }
        $item_ids = array_unique( array_filter( $item_ids ) );

        $variation_filter = isset( $_POST['variation_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['variation_ids'] ) ) : [];
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
                foreach ( $targets as $variation_id ) {
                    $variation = wc_get_product( $variation_id );
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
            wp_die( esc_html__( 'No products selected for printing.', 'woo-sku-barcode' ), '', [ 'response' => 400 ] );
        }

        $items   = [];
        $max_run = $this->get_setting( 'max_labels_per_batch', 500 );

        foreach ( $raw_items as $product ) {
            $quantity = $print_stock ? max( 1, (int) $product->get_stock_quantity() ) : 1;
            if ( $quantity + count( $items ) > $max_run ) {
                wp_die( esc_html__( 'Label request exceeds the configured batch limit. Reduce quantity or disable per-stock printing.', 'woo-sku-barcode' ), '', [ 'response' => 400 ] );
            }
            for ( $i = 0; $i < $quantity; $i++ ) {
                $items[] = $product;
            }
        }

        list( $width, $height ) = array_map( 'floatval', explode( 'x', $size ) );

        if ( ! class_exists( '\\TCPDF' ) ) {
            $tcpdf_path = WSBM_PLUGIN_PATH . 'lib/TCPDF/tcpdf.php';
            if ( file_exists( $tcpdf_path ) ) {
                require_once $tcpdf_path;
            } else {
                wp_die( esc_html__( 'Unable to load TCPDF library.', 'woo-sku-barcode' ), '', [ 'response' => 500 ] );
            }
        }

        if ( '40x30' === $size ) {
            $labels_per_page = 4;
            $page_height     = $height * $labels_per_page;
            $pdf             = new \TCPDF( 'P', 'mm', [ $width, $page_height ], true, 'UTF-8', false );
        } else {
            $pdf = new \TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
        }

        $pdf->setPrintHeader( false );
        $pdf->setPrintFooter( false );
        $pdf->SetMargins( 0, 0, 0 );
        $pdf->SetAutoPageBreak( false );
        $pdf->AddPage();

        if ( '40x30' !== $size ) {
            $cols = max( 1, (int) floor( 210 / $width ) );
            $col  = 0;
        }

        $count = count( $items );
        for ( $index = 0; $index < $count; $index++ ) {
            $product = $items[ $index ];
            $name    = wp_trim_words( $product->get_name(), 5, '…' );
            $price   = html_entity_decode( wp_strip_all_tags( wc_price( $product->get_price() ) ), ENT_QUOTES, 'UTF-8' );
            $sku     = $product->get_sku() ?: 'NOSKU';
            $barcode = in_array( 'barcode', $fields, true ) ? $this->draw_barcode_png( $sku ) : '';

            if ( '40x30' === $size ) {
                if ( $index > 0 && 0 === $index % $labels_per_page ) {
                    $pdf->AddPage();
                }
                $position = $index % $labels_per_page;
                $x        = 0;
                $y        = $position * $height;
            } else {
                $row = (int) floor( $col / $cols );
                if ( $row > 0 && ( ( $row * $height ) + $height ) > 297 ) {
                    $pdf->AddPage();
                    $col = 0;
                    $row = 0;
                }
                $x = ( $col % $cols ) * $width;
                $y = $row * $height;
                $col++;
            }

            $margin_top = 2;
            $name_y     = $y + $margin_top;
            $price_y    = $name_y + 4;
            $barcode_y  = $price_y + 4;
            $barcode_h  = 12;
            $sku_y      = $barcode_y + $barcode_h + 1;

            if ( in_array( 'name', $fields, true ) ) {
                $pdf->SetFont( 'helvetica', 'B', 8 );
                $pdf->SetXY( $x, $name_y );
                $pdf->Cell( $width, 4, $name, 0, 0, 'C' );
            }

            if ( in_array( 'price', $fields, true ) ) {
                $pdf->SetFont( 'dejavusans', '', 8 );
                $pdf->SetXY( $x, $price_y );
                $pdf->Cell( $width, 4, $price, 0, 0, 'C' );
            }

            if ( $barcode && in_array( 'barcode', $fields, true ) ) {
                $barcode_w = $width * 0.8;
                $xoff_bar  = $x + ( ( $width - $barcode_w ) / 2 );
                $pdf->Image( $barcode, $xoff_bar, $barcode_y, $barcode_w, $barcode_h, 'PNG' );
            }

            if ( in_array( 'sku', $fields, true ) ) {
                $pdf->SetFont( 'helvetica', '', 7 );
                $pdf->SetXY( $x, $sku_y );
                $pdf->Cell( $width, 4, $sku, 0, 0, 'C' );
            }
        }

        $pdf->Output( 'labels.pdf', 'I' );
        exit;
    }

    private function draw_barcode_png( string $code ) : string {
        $this->ensure_cache_directory();
        $key  = md5( $code );
        $file = trailingslashit( $this->get_cache_directory() ) . $key . '.png';

        if ( ! file_exists( $file ) ) {
            if ( ! class_exists( '\\Picqer\\Barcode\\BarcodeGeneratorPNG' ) ) {
                require_once WSBM_PLUGIN_PATH . 'lib/Picqer/BarcodeGeneratorPNG.php';
            }
            $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();
            $png       = $generator->getBarcode( $code, $generator::TYPE_CODE_128, 2, 60 );
            file_put_contents( $file, $png );
        }

        return $file;
    }
}
