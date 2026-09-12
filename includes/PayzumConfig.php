<?php
/**
 * Config for the Payzum gateway: reads the fields the admin saved on Tutor's
 * payment-settings screen and exposes them to the PaymentHub repository.
 */

namespace Payzum\TutorLMS;

use Ollyo\PaymentHub\Core\Payment\BaseConfig;
use Ollyo\PaymentHub\Contracts\Payment\ConfigContract;
use Tutor\Ecommerce\Settings;
use Tutor\PaymentGateways\Configs\PaymentUrlsTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PayzumConfig extends BaseConfig implements ConfigContract {

	use PaymentUrlsTrait;

	protected $name = 'payzum';

	private $environment    = 'test';
	private $api_key        = '';
	private $webhook_secret = '';
	private $pay_currency   = 'all';

	public function __construct() {
		parent::__construct();

		$settings = Settings::get_payment_gateway_settings( $this->name );
		if ( $settings ) {
			foreach ( array( 'environment', 'api_key', 'webhook_secret', 'pay_currency' ) as $key ) {
				$value = $this->get_field_value( $settings, $key );
				if ( '' !== $value && ! is_null( $value ) ) {
					$this->$key = $value;
				}
			}
		}
	}

	public function getMode(): string {
		return 'live' === $this->environment ? 'live' : 'test';
	}

	public function getSecretKey(): string {
		return trim( (string) $this->api_key );
	}

	public function getWebhookSecretKey(): string {
		return trim( (string) $this->webhook_secret );
	}

	/** The REST route with the method suffix, so the webhook routes back to us. */
	public function getWebhookUrl(): string {
		$url = home_url( 'wp-json/tutor/v1/ecommerce-webhook' );
		if ( function_exists( 'tutor_is_dev_mode' ) && tutor_is_dev_mode() && defined( 'TUTOR_ECOMMERCE_WEBHOOK_URL' ) ) {
			$url = TUTOR_ECOMMERCE_WEBHOOK_URL;
		}
		return apply_filters( 'tutor_ecommerce_webhook_url', $url ) . '/payzum';
	}

	public function createConfig(): void {
		parent::createConfig();

		$pay_currency = strtolower( trim( (string) $this->pay_currency ) );
		$pay_currency = preg_replace( '/[^a-z0-9]/', '', $pay_currency );

		$this->updateConfig( array(
			'pay_currency' => '' !== $pay_currency ? $pay_currency : 'all',
		) );
	}

	/**
	 * Whether the gateway is usable. The webhook secret is part of "configured"
	 * on purpose: orders are marked paid from signed notifications only, so a
	 * checkout without the secret could take money it can never credit.
	 */
	public function is_configured() {
		return '' !== $this->getSecretKey() && '' !== $this->getWebhookSecretKey();
	}
}
