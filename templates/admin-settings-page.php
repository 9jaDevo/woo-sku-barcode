<?php
/**
 * Settings page template.
 *
 * @var array $settings
 *
 * @package Woo_SKU_Barcode_Manager
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
  <h1><?php esc_html_e( 'Barcode Settings', 'sku-barcode-manager-for-woocommerce' ); ?></h1>
  <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
    <?php
    settings_fields( 'wsbm_settings_group' );
    do_settings_sections( 'wsbm_settings' );
    submit_button();
    ?>
  </form>

  <hr>

  <h2><?php esc_html_e( 'Maintenance', 'sku-barcode-manager-for-woocommerce' ); ?></h2>
  <p><?php esc_html_e( 'If barcode assets become outdated you can clear the cache to rebuild them automatically.', 'sku-barcode-manager-for-woocommerce' ); ?></p>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="wsbm_clear_cache">
    <?php wp_nonce_field( 'wsbm_clear_cache' ); ?>
  <?php submit_button( __( 'Clear barcode cache', 'sku-barcode-manager-for-woocommerce' ), 'secondary' ); ?>
  </form>
</div>
