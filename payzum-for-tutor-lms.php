<?php
/**
 * Plugin Name: Payzum for Tutor LMS
 * Plugin URI: https://github.com/payzum-dev/tutor-lms-payzum
 * Description: Accept crypto & stablecoin payments (USDC, USDT and more, multi-chain) for Tutor LMS course orders through Payzum — non-custodial, funds settle directly to your own wallet.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Requires Plugins: tutor
 * Author: Payzum
 * Author URI: https://payzum.com
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: payzum-tutor-lms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PAYZUM_TUTOR_VERSION', '1.0.0' );
define( 'PAYZUM_TUTOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'PAYZUM_TUTOR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Boot after Tutor LMS. The native ecommerce gateway framework (GatewayBase +
 * the Ollyo\PaymentHub library it builds on) ships from Tutor 3.0.
 */
add_action( 'plugins_loaded', function () {
	if ( ! function_exists( 'tutor' ) || ! class_exists( \Tutor\PaymentGateways\GatewayBase::class ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Payzum for Tutor LMS requires Tutor LMS 3.0 or newer (the version that ships native ecommerce).', 'payzum-tutor-lms' );
			echo '</p></div>';
		} );
		return;
	}

	// The Ollyo\PaymentHub library our classes extend lives inside Tutor's own
	// PayPal gateway package; the second autoload brings the payzum SDK.
	require_once tutor()->path . 'ecommerce/PaymentGateways/Paypal/vendor/autoload.php';
	require_once PAYZUM_TUTOR_PATH . 'vendor/autoload.php';
	require_once PAYZUM_TUTOR_PATH . 'includes/PayzumConfig.php';
	require_once PAYZUM_TUTOR_PATH . 'includes/PayzumPayment.php';
	require_once PAYZUM_TUTOR_PATH . 'includes/PayzumGateway.php';

	// Runtime resolution: checkout, webhook routing and configured-check all
	// read this map (tutor_gateways_with_class filters the same array).
	add_filter( 'tutor_payment_gateways_with_class', function ( $gateways ) {
		$gateways['payzum'] = array(
			'gateway_class' => \Payzum\TutorLMS\PayzumGateway::class,
			'config_class'  => \Payzum\TutorLMS\PayzumConfig::class,
		);
		return $gateways;
	} );

	add_filter( 'tutor_payment_method_labels', function ( $labels ) {
		$labels['payzum'] = __( 'Crypto / stablecoin (Payzum)', 'payzum-tutor-lms' );
		return $labels;
	} );

	// The admin payment-settings screen: gateway entry + its config fields.
	add_filter( 'tutor_payment_gateways', function ( $gateways ) {
		$gateways[] = array(
			'name'                 => 'payzum',
			'label'                => 'Payzum (crypto / stablecoin)',
			'is_installed'         => true,
			'is_plugin_active'     => true,
			'is_active'            => false,
			'icon'                 => PAYZUM_TUTOR_URL . 'assets/payzum.svg',
			// Crypto has no card on file to pull from — recurring cannot work.
			'support_subscription' => false,
			'fields'               => array(
				array(
					'name'    => 'environment',
					'label'   => __( 'Environment', 'payzum-tutor-lms' ),
					'type'    => 'select',
					'options' => array(
						'test' => __( 'Test (staging.payzum.com, separate API keys)', 'payzum-tutor-lms' ),
						'live' => __( 'Live', 'payzum-tutor-lms' ),
					),
					'value'   => 'test',
				),
				array(
					'name'  => 'api_key',
					'type'  => 'secret_key',
					'label' => __( 'API key', 'payzum-tutor-lms' ),
					'value' => '',
				),
				array(
					'name'  => 'webhook_secret',
					'type'  => 'secret_key',
					'label' => __( 'Webhook secret', 'payzum-tutor-lms' ),
					'value' => '',
				),
				array(
					'name'  => 'pay_currency',
					'type'  => 'text',
					'label' => __( 'Pay currency ("all" lets the buyer choose; or e.g. "usdcmatic")', 'payzum-tutor-lms' ),
					'value' => 'all',
				),
			),
		);
		return $gateways;
	} );
}, 20 );
