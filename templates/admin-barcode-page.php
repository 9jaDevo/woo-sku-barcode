<?php
/**
 * Barcode generation admin page template.
 *
 * @var int   $pid
 * @var array $selected_items
 * @var array $variation_entries
 * @var array $settings
 * @var array $label_sizes
 * @var array $field_options
 *
 * @package Woo_SKU_Barcode_Manager
 */

defined( 'ABSPATH' ) || exit;

$nonce_action = 'woo_sbm_print_' . $pid;
$selected_fields = $settings['default_fields'];
?>
<div class="wrap">
  <h1><?php esc_html_e( 'Print Barcodes', 'woo-sku-barcode' ); ?></h1>
  <p><?php esc_html_e( 'Search for products or specific variations, configure the label, and generate your barcode sheet.', 'woo-sku-barcode' ); ?></p>

  <form method="post" target="_blank" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
    <input type="hidden" name="action" value="woo_sbm_render_labels">
    <input type="hidden" name="product_id" value="<?php echo esc_attr( $pid ); ?>">
    <?php wp_nonce_field( $nonce_action ); ?>

    <table class="form-table" role="presentation">
      <tbody>
        <tr>
          <th scope="row"><label for="woo-sbm-items"><?php esc_html_e( 'Products or variations', 'woo-sku-barcode' ); ?></label></th>
          <td>
            <select id="woo-sbm-items" class="wc-product-search" multiple="multiple" style="width:100%;" name="items[]" data-placeholder="<?php esc_attr_e( 'Search products or variations…', 'woo-sku-barcode' ); ?>" data-allow_clear="true" data-action="woocommerce_json_search_products_and_variations">
              <?php foreach ( $selected_items as $id => $label ) : ?>
                <option value="<?php echo esc_attr( $id ); ?>" selected="selected"><?php echo esc_html( $label ); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e( 'Pick any mix of simple products or individual variations to include in the PDF.', 'woo-sku-barcode' ); ?></p>
          </td>
        </tr>

        <?php if ( ! empty( $variation_entries ) ) : ?>
          <tr>
            <th scope="row"><?php esc_html_e( 'Limit to variations', 'woo-sku-barcode' ); ?></th>
            <td>
              <p class="description"><?php esc_html_e( 'Tick the exact variations you want labels for. Leave all unchecked to include every variation of this product.', 'woo-sku-barcode' ); ?></p>
              <div style="max-height:220px; overflow:auto; border:1px solid #ccd0d4; padding:12px;">
                <?php foreach ( $variation_entries as $entry ) : ?>
                  <label style="display:block; margin-bottom:6px;">
                    <input type="checkbox" name="variation_ids[]" value="<?php echo esc_attr( $entry['id'] ); ?>" <?php checked( $entry['checked'] ); ?>>
                    <span><?php echo esc_html( $entry['label'] ); ?></span>
                    <?php if ( $entry['sku'] ) : ?>
                      <span style="color:#555;">&nbsp;<?php
                      /* translators: %s: variation SKU. */
                      printf( esc_html__( '(SKU: %s)', 'woo-sku-barcode' ), esc_html( $entry['sku'] ) );
                      ?></span>
                    <?php endif; ?>
                    <?php if ( null !== $entry['stock'] ) : ?>
                      <span style="color:#555;">&nbsp;<?php
                      /* translators: %s: current stock quantity. */
                      printf( esc_html__( 'Stock: %s', 'woo-sku-barcode' ), esc_html( $entry['stock'] ) );
                      ?></span>
                    <?php endif; ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
        <?php endif; ?>

        <tr>
          <th scope="row"><label for="woo-sbm-label-size"><?php esc_html_e( 'Label size', 'woo-sku-barcode' ); ?></label></th>
          <td>
            <select id="woo-sbm-label-size" name="label">
              <?php foreach ( $label_sizes as $size_key => $label ) : ?>
                <option value="<?php echo esc_attr( $size_key ); ?>" <?php selected( $settings['default_label'], $size_key ); ?>><?php echo esc_html( $label ); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>

        <tr>
          <th scope="row"><?php esc_html_e( 'Fields to print', 'woo-sku-barcode' ); ?></th>
          <td>
            <?php foreach ( $field_options as $field ) : ?>
              <label style="margin-right:1em;">
                <input type="checkbox" name="fields[]" value="<?php echo esc_attr( $field ); ?>" <?php checked( in_array( $field, $selected_fields, true ) ); ?>>
                <?php echo esc_html( ucfirst( $field ) ); ?>
              </label>
            <?php endforeach; ?>
          </td>
        </tr>

        <tr>
          <th scope="row"><?php esc_html_e( 'Quantity per item', 'woo-sku-barcode' ); ?></th>
          <td>
            <label>
              <input type="checkbox" name="print_stock" value="1" <?php checked( $settings['print_per_stock'] ); ?>>
              <?php esc_html_e( 'Print one label per stock unit', 'woo-sku-barcode' ); ?>
            </label>
            <p class="description"><?php
            /* translators: %d: maximum number of labels allowed per batch. */
            printf( esc_html__( 'Current batch limit: %d labels.', 'woo-sku-barcode' ), esc_html( $settings['max_labels_per_batch'] ) );
            ?></p>
          </td>
        </tr>
      </tbody>
    </table>

    <?php submit_button( __( 'Generate & Print', 'woo-sku-barcode' ) ); ?>
  </form>
</div>
