<?php
/**
 * The Tutor gateway wrapper: tells GatewayBase which PaymentHub payment and
 * config classes drive the Payzum method, and which autoloaders they need.
 */

namespace Payzum\TutorLMS;

use Tutor\PaymentGateways\GatewayBase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PayzumGateway extends GatewayBase {

	public function get_root_dir_name(): string {
		return 'payzum-for-tutor-lms';
	}

	public function get_payment_class(): string {
		return PayzumPayment::class;
	}

	public function get_config_class(): string {
		return PayzumConfig::class;
	}

	/**
	 * The Ollyo\PaymentHub library ships inside Tutor's PayPal package; the
	 * second autoload brings the payzum SDK. Both are also loaded at boot,
	 * this keeps the gateway self-sufficient if instantiated earlier.
	 */
	public static function get_autoload_file() {
		return array(
			tutor()->path . 'ecommerce/PaymentGateways/Paypal/vendor/autoload.php',
			PAYZUM_TUTOR_PATH . 'vendor/autoload.php',
		);
	}
}
