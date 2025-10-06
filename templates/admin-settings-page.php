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
  <h1><?php esc_html_e( 'Barcode Settings', 'woo-sku-barcode' ); ?></h1>
  <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
    <?php
    settings_fields( 'wsbm_settings_group' );
    do_settings_sections( 'wsbm_settings' );
    submit_button();
    ?>
  </form>

  <hr>

  <h2><?php esc_html_e( 'Maintenance', 'woo-sku-barcode' ); ?></h2>
  <p><?php esc_html_e( 'If barcode assets become outdated you can clear the cache to rebuild them automatically.', 'woo-sku-barcode' ); ?></p>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="wsbm_clear_cache">
    <?php wp_nonce_field( 'wsbm_clear_cache' ); ?>
    <?php submit_button( __( 'Clear barcode cache', 'woo-sku-barcode' ), 'secondary' ); ?>
  </form>
</div>
