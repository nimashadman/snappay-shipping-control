<?php
/**
 * Plugin Name: کنترل روش حمل‌ونقل براساس درگاه پرداخت ووکامرس
 * Plugin URI: https://nimashadman.ir
 * Description: با انتخاب هر درگاه پرداخت در صفحه تسویه‌حساب ووکامرس، روش‌های حمل‌ونقل مشخص‌شده را غیرفعال می‌کند.
 * Version: 1.0.0
 * Author: نیما شادمان
 * Author URI: https://nimashadman.ir
 * Text Domain: snappay-shipping-control
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 *
 * @package SnappayShippingControl
 */

defined( 'ABSPATH' ) || exit;

define( 'SSC_VERSION', '1.0.0' );
define( 'SSC_PLUGIN_FILE', __FILE__ );
define( 'SSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SSC_OPTION_RULES', 'ssc_gateway_shipping_rules' );

/**
 * Main plugin class.
 */
final class SSC_Snappay_Shipping_Control {
	/**
	 * Boot plugin hooks.
	 */
	public static function init(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_assets' ) );

		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_shipping_rates' ), 100, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'store_selected_payment_method' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_selected_shipping_method' ) );
	}

	/**
	 * Load translations.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'snappay-shipping-control', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Register WooCommerce settings page.
	 */
	public static function register_settings_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'حمل‌ونقل براساس پرداخت', 'snappay-shipping-control' ),
			__( 'حمل‌ونقل براساس پرداخت', 'snappay-shipping-control' ),
			'manage_woocommerce',
			'snappay-shipping-control',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public static function register_settings(): void {
		register_setting(
			'ssc_settings',
			SSC_OPTION_RULES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_rules' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin hook.
	 */
	public static function enqueue_admin_assets( string $hook ): void {
		if ( 'woocommerce_page_snappay-shipping-control' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ssc-admin',
			SSC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SSC_VERSION
		);
	}

	/**
	 * Enqueue checkout assets.
	 */
	public static function enqueue_checkout_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_script(
			'ssc-checkout',
			SSC_PLUGIN_URL . 'assets/js/checkout.js',
			array( 'jquery', 'wc-checkout' ),
			SSC_VERSION,
			true
		);
	}

	/**
	 * Store selected payment method in WooCommerce session.
	 *
	 * @param string $posted_data Serialized checkout data.
	 */
	public static function store_selected_payment_method( string $posted_data ): void {
		if ( ! WC()->session ) {
			return;
		}

		parse_str( $posted_data, $data );

		if ( ! empty( $data['payment_method'] ) ) {
			WC()->session->set( 'ssc_chosen_payment_method', sanitize_key( wp_unslash( $data['payment_method'] ) ) );
		}
	}

	/**
	 * Remove blocked shipping rates for the chosen payment gateway.
	 *
	 * @param array $rates WooCommerce package rates.
	 * @param array $package Current package.
	 * @return array
	 */
	public static function filter_shipping_rates( array $rates, array $package ): array {
		unset( $package );

		$payment_method = self::get_chosen_payment_method();

		if ( '' === $payment_method ) {
			return $rates;
		}

		$blocked_methods = self::get_blocked_shipping_methods_for_gateway( $payment_method );

		if ( empty( $blocked_methods ) ) {
			return $rates;
		}

		foreach ( $rates as $rate_id => $rate ) {
			$method_id    = is_object( $rate ) && isset( $rate->method_id ) ? $rate->method_id : '';
			$instance_id  = is_object( $rate ) && isset( $rate->instance_id ) ? (string) $rate->instance_id : '';
			$rate_matches = array(
				(string) $rate_id,
				(string) $method_id,
			);

			if ( '' !== $method_id && '' !== $instance_id ) {
				$rate_matches[] = $method_id . ':' . $instance_id;
			}

			if ( array_intersect( $blocked_methods, $rate_matches ) ) {
				unset( $rates[ $rate_id ] );
			}
		}

		return $rates;
	}

	/**
	 * Prevent submitting an order with a blocked shipping method.
	 */
	public static function validate_selected_shipping_method(): void {
		$payment_method = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $payment_method || empty( $_POST['shipping_method'] ) || ! is_array( $_POST['shipping_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$blocked_methods = self::get_blocked_shipping_methods_for_gateway( $payment_method );

		if ( empty( $blocked_methods ) ) {
			return;
		}

		foreach ( wc_clean( wp_unslash( $_POST['shipping_method'] ) ) as $selected_method ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$selected_method = (string) $selected_method;

			if ( in_array( $selected_method, $blocked_methods, true ) ) {
				wc_add_notice( __( 'روش حمل‌ونقل انتخاب‌شده برای این درگاه پرداخت فعال نیست. لطفا روش حمل‌ونقل دیگری انتخاب کنید.', 'snappay-shipping-control' ), 'error' );
				return;
			}
		}
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$rules            = self::get_rules();
		$payment_gateways = self::get_payment_gateways();
		$shipping_methods = self::get_shipping_methods();
		?>
		<div class="wrap ssc-wrap">
			<h1><?php esc_html_e( 'حمل‌ونقل براساس پرداخت', 'snappay-shipping-control' ); ?></h1>
			<p>
				<?php esc_html_e( 'مشخص کنید با انتخاب هر درگاه پرداخت، کدام روش‌های حمل‌ونقل در صفحه تسویه‌حساب غیرفعال شوند.', 'snappay-shipping-control' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'ssc_settings' ); ?>

				<table class="widefat striped ssc-rules-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'درگاه پرداخت', 'snappay-shipping-control' ); ?></th>
							<th><?php esc_html_e( 'روش‌های حمل‌ونقل غیرفعال', 'snappay-shipping-control' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $payment_gateways as $gateway_id => $gateway_title ) : ?>
							<tr>
								<th scope="row">
									<strong><?php echo esc_html( $gateway_title ); ?></strong>
									<code><?php echo esc_html( $gateway_id ); ?></code>
								</th>
								<td>
									<?php foreach ( $shipping_methods as $method_id => $method_title ) : ?>
										<label class="ssc-checkbox">
											<input
												type="checkbox"
												name="<?php echo esc_attr( SSC_OPTION_RULES ); ?>[<?php echo esc_attr( $gateway_id ); ?>][]"
												value="<?php echo esc_attr( $method_id ); ?>"
												<?php checked( in_array( $method_id, $rules[ $gateway_id ] ?? array(), true ) ); ?>
											/>
											<span><?php echo esc_html( $method_title ); ?></span>
											<code><?php echo esc_html( $method_id ); ?></code>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>

			<div class="ssc-footer">
				<?php
				printf(
					/* translators: %s: developer link. */
					esc_html__( 'توسعه‌دهنده: %s', 'snappay-shipping-control' ),
					'<a href="https://nimashadman.ir" target="_blank" rel="noopener noreferrer">نیما شادمان</a>'
				);
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize saved rules.
	 *
	 * @param mixed $input Raw option value.
	 * @return array
	 */
	public static function sanitize_rules( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$rules = array();

		foreach ( $input as $gateway_id => $method_ids ) {
			$gateway_id = sanitize_key( $gateway_id );

			if ( '' === $gateway_id || ! is_array( $method_ids ) ) {
				continue;
			}

			$rules[ $gateway_id ] = array_values(
				array_unique(
					array_filter(
						array_map( 'wc_clean', wp_unslash( $method_ids ) )
					)
				)
			);
		}

		return $rules;
	}

	/**
	 * Get saved rules.
	 *
	 * @return array
	 */
	private static function get_rules(): array {
		$rules = get_option( SSC_OPTION_RULES, array() );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Get currently chosen payment method.
	 *
	 * @return string
	 */
	private static function get_chosen_payment_method(): string {
		if ( isset( $_POST['payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_key( wp_unslash( $_POST['payment_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( WC()->session ) {
			return (string) WC()->session->get( 'ssc_chosen_payment_method', '' );
		}

		return '';
	}

	/**
	 * Get blocked method IDs for a payment gateway.
	 *
	 * @param string $gateway_id Payment gateway ID.
	 * @return array
	 */
	private static function get_blocked_shipping_methods_for_gateway( string $gateway_id ): array {
		$rules = self::get_rules();

		return isset( $rules[ $gateway_id ] ) && is_array( $rules[ $gateway_id ] )
			? array_map( 'strval', $rules[ $gateway_id ] )
			: array();
	}

	/**
	 * Get available payment gateways.
	 *
	 * @return array
	 */
	private static function get_payment_gateways(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}

		$gateways = array();

		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			$gateways[ $gateway_id ] = $gateway->get_title() ?: $gateway_id;
		}

		return $gateways;
	}

	/**
	 * Get configured shipping methods including instance IDs.
	 *
	 * @return array
	 */
	private static function get_shipping_methods(): array {
		$methods = array();

		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return $methods;
		}

		$zones = WC_Shipping_Zones::get_zones();
		$zones[] = array(
			'zone_id'          => 0,
			'zone_name'        => __( 'مکان‌هایی که در ناحیه‌های دیگر پوشش داده نشده‌اند', 'snappay-shipping-control' ),
			'shipping_methods' => WC_Shipping_Zones::get_zone( 0 )->get_shipping_methods(),
		);

		foreach ( $zones as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( ! $method || ! isset( $method->id, $method->instance_id ) ) {
					continue;
				}

				$method_key             = $method->id . ':' . $method->instance_id;
				$method_title           = $method->get_title() ?: $method->method_title;
				$methods[ $method_key ] = sprintf(
					'%1$s - %2$s',
					$zone['zone_name'],
					$method_title
				);
			}
		}

		return $methods;
	}
}

add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( 'WooCommerce' ) ) {
			SSC_Snappay_Shipping_Control::init();
			return;
		}

		add_action(
			'admin_notices',
			static function (): void {
				?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'افزونه کنترل روش حمل‌ونقل براساس درگاه پرداخت برای اجرا به ووکامرس فعال نیاز دارد.', 'snappay-shipping-control' ); ?></p>
				</div>
				<?php
			}
		);
	}
);
