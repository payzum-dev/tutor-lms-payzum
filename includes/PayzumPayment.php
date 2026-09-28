<?php
/**
 * The PaymentHub payment class for Payzum: creates the hosted-checkout invoice
 * and turns the signed IPN into Tutor's order-update object.
 *
 * The buyer's browser return only lands on Tutor's order-placement template —
 * it never decides the payment. The order is marked paid exclusively from the
 * verified server-to-server notification handled here.
 */

namespace Payzum\TutorLMS;

use Ollyo\PaymentHub\Core\Payment\BasePayment;
use Ollyo\PaymentHub\Core\Support\System;
use Payzum\Errors\PayzumException;
use Payzum\Errors\SignatureException;
use Payzum\Payzum;
use Payzum\PaymentStatus;
use Payzum\Webhooks\Verifier;
use Tutor\Models\OrderModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PayzumPayment extends BasePayment {

	/** Order meta holding recently seen IPN event ids — retries reuse the id. */
	const EVENT_IDS_META = '_payzum_ipn_event_ids';

	/** How many past event ids to keep per order for deduplication. */
	const EVENT_IDS_KEEP = 20;

	/**
	 * How far the settled amount may fall short of the order total before it
	 * is held. Half a cent: price_amount arrives as a JSON number and the two
	 * sides round differently, so `==` on floats would reject good payments.
	 */
	const AMOUNT_TOLERANCE = 0.005;

	/** Seconds to wait for the per-order IPN lock before asking for a retry. */
	const LOCK_TIMEOUT = 10;

	public function setup(): void {
		// The SDK needs no page-load initialisation.
	}

	public function check(): bool {
		return '' !== (string) $this->config->get( 'secret_key' )
			&& '' !== (string) $this->config->get( 'webhook_secret_key' );
	}

	protected function client(): Payzum {
		$api_key = (string) $this->config->get( 'secret_key' );
		return 'test' === $this->config->get( 'mode' )
			? Payzum::sandbox( $api_key )
			: new Payzum( $api_key );
	}

	/**
	 * "42-a1b2c3d4": the order id plus a salt-keyed slice only this install can
	 * produce — resolvable on the IPN without being forgeable from a guessed id.
	 */
	public static function order_reference( $order_id ): string {
		return (int) $order_id . '-' . substr( wp_hash( 'payzum-tutor-order-' . (int) $order_id ), 0, 8 );
	}

	/* ------------------------------------------------------------------ checkout */

	public function createPayment() {
		$data     = $this->getData();
		$order_id = (int) $data->order_id;

		$success_url = add_query_arg( 'order_id', $order_id, (string) $this->config->get( 'success_url' ) );
		$cancel_url  = add_query_arg( 'order_id', $order_id, (string) $this->config->get( 'cancel_url' ) );

		try {
			$invoice = $this->client()->payments->create(
				// total_price is the amount Tutor charges (its PayPal gateway
				// sends the same field); formatted with a fixed dot separator,
				// never the store's locale ones.
				priceAmount:      number_format( (float) $data->total_price, 2, '.', '' ),
				priceCurrency:    strtolower( (string) $data->currency->code ),
				payCurrency:      (string) ( $this->config->get( 'pay_currency' ) ?: 'all' ),
				orderId:          self::order_reference( $order_id ),
				orderDescription: mb_substr( $data->order_description . ' — ' . $data->store_name, 0, 2000 ),
				ipnCallbackUrl:   (string) $this->config->get( 'webhook_url' ),
				successUrl:       $success_url,
				cancelUrl:        $cancel_url,
				// The API does not enforce order_id uniqueness; without this
				// key a retried create could mint a second real invoice. The
				// site-hash prefix keeps two sites on one merchant apart.
				idempotencyKey:   'tutor-' . substr( md5( home_url( '/' ) ), 0, 8 ) . '-' . $order_id,
			);
		} catch ( PayzumException $e ) {
			// Tutor's checkout catches and shows the message to the buyer.
			throw new \Exception(
				esc_html__( 'Unable to start the crypto payment. Please try again or pick another payment method.', 'payzum-for-tutor-lms' )
			);
		}

		$invoice_url = isset( $invoice['invoice_url'] ) ? (string) $invoice['invoice_url'] : '';
		if ( '' === $invoice_url ) {
			// invoice_url is null when the gateway has no checkout base
			// configured for the merchant.
			throw new \Exception(
				esc_html__( 'The payment provider did not return a checkout URL. Please try again later.', 'payzum-for-tutor-lms' )
			);
		}

		header( 'Location: ' . $invoice_url );
		exit();
	}

	public function createRecurringPayment() {
		// Crypto has no card on file to pull from; the gateway is registered
		// with support_subscription=false, so Tutor never offers it — this is
		// a backstop, not a path.
		throw new \RuntimeException( 'Payzum does not support recurring payments.' );
	}

	/** Shown in the admin next to the gateway; nothing to register dashboard-side. */
	public function createWebhook(): ?object {
		return (object) array(
			'url'    => (string) $this->config->get( 'webhook_url' ),
			'secret' => '',
		);
	}

	/* ----------------------------------------------------------------------- IPN */

	/**
	 * Verify the signed notification and build Tutor's order-update object.
	 *
	 * Payzum retries a delivery several times whatever the response code and
	 * re-delivers settled invoices, so this handler is idempotent and the
	 * rejection paths stay cheap and side-effect-free. An empty object tells
	 * Tutor to change nothing (the delivery is still acknowledged with 200).
	 */
	public function verifyAndCreateOrderData( object $payload ): object {
		$raw = (string) ( $payload->stream ?? '' );
		if ( '' === $raw ) {
			$this->respond( 400, 'empty body' );
		}

		$secret = (string) $this->config->get( 'webhook_secret_key' );
		if ( '' === $secret ) {
			$this->respond( 500, 'not configured' );
		}

		$verifier = new Verifier( $secret );
		$headers  = array_filter( (array) ( $payload->server ?? array() ), 'is_string' );

		try {
			// Signature (HMAC-SHA-512 over the raw bytes) and the replay
			// window are checked before any field of the payload is read.
			$data = $verifier->verifyPaymentIpn( $raw, $headers );
		} catch ( SignatureException $e ) {
			$this->respond( 401, 'bad signature' );
			return new \stdClass(); // respond() exits; keeps static analysis honest.
		} catch ( PayzumException $e ) {
			$this->respond( 400, 'bad json' );
			return new \stdClass();
		}

		$reference = isset( $data['order_id'] ) ? (string) $data['order_id'] : '';
		$order_id  = (int) $reference;
		if ( ! $order_id || $reference !== self::order_reference( $order_id ) ) {
			$this->respond( 404, 'order not found' );
		}

		$order = ( new OrderModel() )->get_order_by_id( $order_id );
		if ( ! $order ) {
			$this->respond( 404, 'order not found' );
		}

		$wire_status = isset( $data['payment_status'] ) ? (string) $data['payment_status'] : '';
		try {
			$status = PaymentStatus::fromMerchant( $wire_status );
		} catch ( PayzumException $e ) {
			// A value from a future API version: acknowledge rather than 500.
			return new \stdClass();
		}

		// Serialize concurrent deliveries for this order. The lock is held for
		// the rest of the request (released on shutdown) because Tutor applies
		// the update AFTER this method returns.
		if ( ! $this->acquire_order_lock( $order_id ) ) {
			// Only late, not duplicate — a retry is the right outcome.
			$this->respond( 503, 'busy' );
		}

		$event_id = (string) $verifier->eventId( $headers );
		if ( '' !== $event_id && $this->is_duplicate_event( $order_id, $event_id ) ) {
			return new \stdClass();
		}

		// A redelivered terminal event must never downgrade a settled order.
		// (Tutor guards completed+paid itself; this also covers paid-only.)
		if ( OrderModel::PAYMENT_PAID === $order->payment_status ) {
			$this->remember_event( $order_id, $event_id );
			return new \stdClass();
		}

		if ( $status->isPaid() ) {
			if ( ! $this->amount_matches( $order, $data ) ) {
				// Acknowledged but never fulfilled: the invoice settled for a
				// different amount or currency than this order records, and a
				// retry would deliver the same figures.
				error_log( '[payzum-tutor] IPN finished for order ' . $order_id . ' does not match the order amount/currency — not crediting' ); // phpcs:ignore
				$this->remember_event( $order_id, $event_id );
				return new \stdClass();
			}
			$this->remember_event( $order_id, $event_id );
			return $this->return_data( $order_id, OrderModel::PAYMENT_PAID, $data );
		}

		if ( $status->isTerminal() ) { // expired or failed
			$this->remember_event( $order_id, $event_id );
			return $this->return_data( $order_id, OrderModel::PAYMENT_FAILED, $data );
		}

		// waiting / partially_paid: no state change. A partial payment is
		// underpaid and must not enrol anyone.
		$this->remember_event( $order_id, $event_id );
		return new \stdClass();
	}

	protected function return_data( $order_id, $payment_status, array $data ): object {
		$return                  = System::defaultOrderData();
		$return->id              = $order_id;
		$return->payment_status  = $payment_status;
		$return->transaction_id  = isset( $data['payment_id'] ) ? (string) $data['payment_id'] : '';
		$return->payment_method  = 'payzum';
		$return->payment_payload = addslashes( (string) wp_json_encode( $data ) );
		$return->fees            = null;
		$return->earnings        = null;
		return $return;
	}

	/**
	 * The verified payload's amount and currency must match the order.
	 *
	 * Fails closed: price_amount is required in the contract, so a payload
	 * without a readable amount cannot be shown to cover this order and must
	 * never credit it. Only a SHORTFALL counts — an overpayment still paid.
	 */
	protected function amount_matches( $order, array $data ): bool {
		$expected_amount = (float) $order->total_price;
		// setData() only runs at checkout — on the webhook request the store
		// currency comes from Tutor's own option, the same source
		// prepare_payment_data() priced the invoice from.
		$expected_currency = strtolower( (string) tutor_utils()->get_option( 'currency_code', 'USD' ) );

		if ( ! isset( $data['price_amount'] ) || ! is_numeric( $data['price_amount'] ) ) {
			return false;
		}
		if ( ( $expected_amount - (float) $data['price_amount'] ) > self::AMOUNT_TOLERANCE ) {
			return false;
		}
		$paid_currency = isset( $data['price_currency'] ) ? strtolower( trim( (string) $data['price_currency'] ) ) : '';
		if ( '' !== $paid_currency && '' !== $expected_currency && $paid_currency !== $expected_currency ) {
			return false;
		}
		return true;
	}

	/* ------------------------------------------------------------------- helpers */

	/**
	 * Cross-request lock for this order, held until the request ends (Tutor
	 * updates the order after this class returns). GET_LOCK is the only lock
	 * WordPress can count on; the name carries install, prefix, plugin tag and
	 * record id — GET_LOCK names are server-wide.
	 */
	protected function acquire_order_lock( $order_id ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return true; // No database handle — proceed unlocked rather than refuse.
		}
		$name = 'payzum_' . substr( md5( DB_NAME . '|' . $wpdb->prefix . '|tutor-order|' . $order_id ), 0, 32 );

		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT ) );
		if ( null === $got ) {
			return true; // Server without GET_LOCK — proceed unlocked.
		}
		if ( '1' !== (string) $got ) {
			return false;
		}
		register_shutdown_function( function () use ( $name ) {
			global $wpdb;
			if ( isset( $wpdb ) && is_object( $wpdb ) ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
		} );
		return true;
	}

	protected function seen_events( $order_id ): array {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->prefix}tutor_ordermeta WHERE order_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1",
			$order_id,
			self::EVENT_IDS_META
		) );
		$seen = $value ? json_decode( (string) $value, true ) : array();
		return is_array( $seen ) ? $seen : array();
	}

	protected function is_duplicate_event( $order_id, $event_id ): bool {
		return in_array( $event_id, $this->seen_events( $order_id ), true );
	}

	protected function remember_event( $order_id, $event_id ): void {
		if ( '' === $event_id ) {
			return;
		}
		global $wpdb;
		$seen   = $this->seen_events( $order_id );
		$seen[] = $event_id;
		$seen   = array_slice( $seen, -self::EVENT_IDS_KEEP );
		$table  = $wpdb->prefix . 'tutor_ordermeta';

		$exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE order_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1',
			$table,
			$order_id,
			self::EVENT_IDS_META
		) );
		if ( $exists ) {
			$wpdb->update( $table, array( 'meta_value' => wp_json_encode( $seen ) ), array( 'id' => $exists ) );
		} else {
			$wpdb->insert( $table, array(
				'order_id'   => $order_id,
				'meta_key'   => self::EVENT_IDS_META,
				'meta_value' => wp_json_encode( $seen ),
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			) );
		}
	}

	protected function respond( $code, $message ) {
		status_header( $code );
		nocache_headers();
		echo esc_html( $message );
		exit;
	}
}
