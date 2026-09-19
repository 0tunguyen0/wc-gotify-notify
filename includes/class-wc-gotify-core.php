<?php
/**
 * Core notification handler and Gotify API dispatcher.
 *
 * @package WooCommerceToGotifyNotifications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WC_Gotify_Core {

	/**
	 * Singleton instance.
	 *
	 * @var WC_Gotify_Core|null
	 */
	private static ?WC_Gotify_Core $instance = null;

	/**
	 * Cached settings for the current request.
	 *
	 * @var array|null
	 */
	private ?array $settings_cache = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WC_Gotify_Core
	 */
	public static function instance(): WC_Gotify_Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {
		// Order event hooks.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout_processed' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_checkout' ), 10, 1 );

		// Async sender (runs via Action Scheduler or directly).
		add_action( WC_GOTIFY_ASYNC_HOOK, array( $this, 'process_notification' ), 10, 1 );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings(): array {
		return array(
			'server_url'      => '',
			'app_token'       => '',
			'priority'        => 5,
			'title'           => __( 'New Order #{order_id}', 'wc-gotify-notify' ),
			'message'         => __( "Customer: {customer_name}\nEmail: {customer_email}\nTotal: {total}\nItems: {items_count}\nPayment: {payment_method}\nStatus: {order_status}\n\nView order: {admin_url}", 'wc-gotify-notify' ),
			'basic_auth_user' => '',
			'basic_auth_pass' => '',
			'async'           => 1,
		);
	}

	/**
	 * Get merged settings, cached per-request.
	 *
	 * @param bool $force Bypass memory cache.
	 * @return array
	 */
	public function get_settings( bool $force = false ): array {
		if ( null === $this->settings_cache || $force ) {
			$saved = get_option( WC_GOTIFY_OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			$this->settings_cache = wp_parse_args( $saved, self::default_settings() );
		}
		return $this->settings_cache;
	}

	/**
	 * Invalidate in-memory settings cache.
	 */
	public function flush_settings_cache(): void {
		$this->settings_cache = null;
	}

	/* ---------------------------------------------------------------------
	 * Order event handlers
	 * ------------------------------------------------------------------- */

	/**
	 * Classic shortcode checkout handler.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_checkout_processed( int $order_id ): void {
		$this->dispatch_notification( $order_id );
	}

	/**
	 * Block-based (Cart & Checkout blocks) checkout handler.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function on_store_api_checkout( $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->dispatch_notification( $order->get_id() );
		}
	}

	/**
	 * Dispatch notification — async via Action Scheduler when available,
	 * or synchronously as fallback.
	 *
	 * Guards against duplicate scheduling with a queued meta flag so that
	 * concurrent hook fires (e.g. classic + block checkout) don't enqueue
	 * the same order twice.
	 *
	 * @param int $order_id Order ID.
	 */
	public function dispatch_notification( int $order_id ): void {
		$order_id = (int) $order_id;
		if ( ! $order_id ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['server_url'] ) || empty( $settings['app_token'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Prevent duplicate delivery: skip if already sent or already queued.
		if ( $order->get_meta( '_wc_gotify_sent' ) || $order->get_meta( '_wc_gotify_queued' ) ) {
			return;
		}

		// Use Action Scheduler (bundled with WC) for async, non-blocking delivery.
		if ( ! empty( $settings['async'] ) && function_exists( 'as_enqueue_async_action' ) ) {
			$order->update_meta_data( '_wc_gotify_queued', time() );
			$order->save();
			as_enqueue_async_action( WC_GOTIFY_ASYNC_HOOK, array( $order_id ), 'wc-gotify' );
			return;
		}

		// Synchronous send.
		$this->process_notification( $order_id );
	}

	/**
	 * Process notification — executes HTTP delivery and records order meta.
	 *
	 * @param int $order_id Order ID.
	 */
	public function process_notification( int $order_id ): void {
		$order_id = (int) $order_id;
		if ( ! $order_id ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['server_url'] ) || empty( $settings['app_token'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_wc_gotify_sent' ) ) {
			return;
		}

		$result = $this->send_to_gotify( $settings, $order );

		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( '_wc_gotify_sent_error', $result->get_error_message() );
		} else {
			$order->update_meta_data( '_wc_gotify_sent', current_time( 'mysql' ) );
			$order->delete_meta_data( '_wc_gotify_sent_error' );
		}

		// Clean up the queued flag regardless of outcome.
		$order->delete_meta_data( '_wc_gotify_queued' );
		$order->save();
	}

	/* ---------------------------------------------------------------------
	 * Gotify HTTP API
	 * ------------------------------------------------------------------- */

	/**
	 * Send a message to Gotify.
	 *
	 * @param array          $settings Plugin settings.
	 * @param WC_Order|null  $order    Order object for placeholder replacement, or null for test.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function send_to_gotify( array $settings, ?WC_Order $order = null ): true|WP_Error {
		$server_url = rtrim( (string) ( $settings['server_url'] ?? '' ), '/' );
		if ( '' === $server_url ) {
			return new WP_Error( 'gotify_no_server', __( 'Gotify server URL is not configured.', 'wc-gotify-notify' ) );
		}

		if ( empty( $settings['app_token'] ) ) {
			return new WP_Error( 'gotify_no_token', __( 'Gotify application token is not configured.', 'wc-gotify-notify' ) );
		}

		$endpoint = $server_url . '/message?token=' . rawurlencode( $settings['app_token'] );

		$title    = $settings['title'] ?? '';
		$message  = $settings['message'] ?? '';
		$priority = (int) ( $settings['priority'] ?? 5 );

		if ( $order instanceof WC_Order ) {
			$placeholders = $this->build_placeholders( $order );
			$title        = $this->replace_placeholders( $title, $placeholders );
			$message      = $this->replace_placeholders( $message, $placeholders );
		} else {
			// Test message: strip any leftover placeholders.
			$title   = preg_replace( '/\{[^}]+\}/', '', $title );
			$message = preg_replace( '/\{[^}]+\}/', '', $message );
			if ( '' === trim( $message ) ) {
				$message = __( 'This is a test notification from WooCommerce → Gotify.', 'wc-gotify-notify' );
			}
		}

		$body = array(
			'title'    => $title,
			'message'  => $message,
			'priority' => max( 0, min( 10, $priority ) ),
		);

		$args = array(
			'method'      => 'POST',
			'headers'     => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'        => wp_json_encode( $body ),
			'timeout'     => 10,
			'redirection' => 2,
		);

		if ( ! empty( $settings['basic_auth_user'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( $settings['basic_auth_user'] . ':' . ( $settings['basic_auth_pass'] ?? '' ) );
		}

		/**
		 * Filter the wp_remote_post arguments before sending to Gotify.
		 *
		 * @param array          $args     HTTP request args.
		 * @param array          $settings Plugin settings.
		 * @param WC_Order|null  $order    Order object or null for test.
		 */
		$args = apply_filters( 'wc_gotify_http_args', $args, $settings, $order );

		$response = wp_remote_post( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body_raw = wp_remote_retrieve_body( $response );
			return new WP_Error(
				'gotify_http_error',
				/* translators: 1: HTTP status code, 2: response body. */
				sprintf( __( 'HTTP %1$d response from Gotify: %2$s', 'wc-gotify-notify' ), $code, $body_raw )
			);
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Placeholders
	 * ------------------------------------------------------------------- */

	/**
	 * Build the placeholder map for a given order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array Key-value map of {placeholder} => value.
	 */
	public function build_placeholders( WC_Order $order ): array {
		$total_plain = wp_strip_all_tags( $order->get_formatted_order_total() );
		$total_plain = html_entity_decode( $total_plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$country_code = $order->get_billing_country();
		$country_name = $country_code;

		if ( WC() && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			if ( isset( $countries[ $country_code ] ) ) {
				$country_name = $countries[ $country_code ];
			}
		}

		$admin_url = $order->get_edit_order_url();

		$placeholders = array(
			'{order_id}'        => $order->get_order_number(),
			'{customer_name}'   => trim( $order->get_formatted_billing_full_name() ),
			'{customer_email}'  => $order->get_billing_email(),
			'{customer_phone}'  => $order->get_billing_phone(),
			'{total}'           => $total_plain,
			'{currency}'        => $order->get_currency(),
			'{items_count}'     => (string) $order->get_item_count(),
			'{payment_method}'  => $order->get_payment_method_title(),
			'{order_status}'    => wc_get_order_status_name( $order->get_status() ),
			'{site_name}'       => get_bloginfo( 'name' ),
			'{site_url}'        => home_url(),
			'{admin_url}'       => $admin_url,
			'{billing_city}'    => $order->get_billing_city(),
			'{billing_country}' => $country_name,
			'{date}'            => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);

		/**
		 * Filter the placeholder map for Gotify notifications.
		 *
		 * @param array    $placeholders Key-value placeholder map.
		 * @param WC_Order $order        Order object.
		 */
		return apply_filters( 'wc_gotify_placeholders', $placeholders, $order );
	}

	/**
	 * Replace placeholders in a text string.
	 *
	 * @param string $text         Text with placeholders.
	 * @param array  $placeholders Key-value map.
	 * @return string
	 */
	public function replace_placeholders( string $text, array $placeholders ): string {
		return strtr( $text, $placeholders );
	}
}
