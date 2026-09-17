<?php
/**
 * Plugin Name:       WooCommerce to Gotify Notifications
 * Description:       Sends a push notification to a Gotify server whenever a new WooCommerce order is placed.
 * Version:           1.1.0
 * Author:            Tu Nguyen
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-gotify-notify
 * Requires at least: 5.6
 * Requires PHP:      7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WC_GOTIFY_VERSION', '1.1.0' );
define( 'WC_GOTIFY_OPTION', 'wc_gotify_settings' );
define( 'WC_GOTIFY_FILE', __FILE__ );
define( 'WC_GOTIFY_BASENAME', plugin_basename( __FILE__ ) );
define( 'WC_GOTIFY_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_GOTIFY_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_GOTIFY_ASYNC_HOOK', 'wc_gotify_send_async' );

/**
 * Main plugin class.
 */
final class WC_Gotify_Notify {

    /**
     * Singleton instance.
     *
     * @var WC_Gotify_Notify|null
     */
    private static $instance = null;

    /**
     * Cached settings for the current request.
     *
     * @var array|null
     */
    private $settings_cache = null;

    /**
     * Get the singleton instance.
     *
     * @return WC_Gotify_Notify
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — use instance().
     */
    private function __construct() {
        // Order event hooks (front-end only).
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout_processed' ), 10, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_checkout' ), 10, 1 );

        // Async sender (runs via Action Scheduler or directly).
        add_action( WC_GOTIFY_ASYNC_HOOK, array( $this, 'handle_async_send' ), 10, 1 );

        // i18n.
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Admin-only hooks.
        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_post_wc_gotify_test', array( $this, 'handle_test_message' ) );
            add_filter( 'plugin_action_links_' . WC_GOTIFY_BASENAME, array( $this, 'plugin_action_links' ) );
        }
    }

    /* ---------------------------------------------------------------------
     * Bootstrapping helpers
     * ------------------------------------------------------------------- */

    /**
     * Default settings (translatable).
     *
     * @return array
     */
    public static function default_settings() {
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
     * Load plugin textdomain.
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'wc-gotify-notify', false, dirname( WC_GOTIFY_BASENAME ) . '/languages' );
    }

    /**
     * Get merged settings, cached per-request.
     *
     * @param bool $force Bypass cache.
     * @return array
     */
    public function get_settings( $force = false ) {
        if ( null === $this->settings_cache || $force ) {
            $saved = get_option( WC_GOTIFY_OPTION, array() );
            if ( ! is_array( $saved ) ) {
                $saved = array();
            }
            $this->settings_cache = wp_parse_args( $saved, self::default_settings() );
        }
        return $this->settings_cache;
    }

    /* ---------------------------------------------------------------------
     * Order event handlers
     * ------------------------------------------------------------------- */

    /**
     * Classic shortcode checkout handler.
     *
     * @param int $order_id Order ID.
     */
    public function on_checkout_processed( $order_id ) {
        $this->dispatch_notification( $order_id );
    }

    /**
     * Block-based (Cart & Checkout blocks) checkout handler.
     *
     * @param WC_Order $order Order object.
     */
    public function on_store_api_checkout( $order ) {
        if ( $order instanceof WC_Order ) {
            $this->dispatch_notification( $order->get_id() );
        }
    }

    /**
     * Dispatch a notification — async via Action Scheduler when available,
     * or synchronously as a fallback.
     *
     * @param int $order_id Order ID.
     */
    public function dispatch_notification( $order_id ) {
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

        // De-dupe: never notify the same order twice.
        if ( $order->get_meta( '_wc_gotify_sent' ) ) {
            return;
        }

        // Use Action Scheduler (bundled with WC) for async, non-blocking delivery.
        if ( ! empty( $settings['async'] ) && function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( WC_GOTIFY_ASYNC_HOOK, array( $order_id ), 'wc-gotify' );
            return;
        }

        // Fallback: synchronous send.
        $this->handle_async_send( $order_id );
    }

    /**
     * Async handler — performs the actual HTTP request.
     *
     * @param int $order_id Order ID.
     */
    public function handle_async_send( $order_id ) {
        $order_id = (int) $order_id;
        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Final de-dupe (async may fire more than once).
        if ( $order->get_meta( '_wc_gotify_sent' ) ) {
            return;
        }

        $settings = $this->get_settings( true );
        $result   = $this->send_to_gotify( $settings, $order );

        if ( is_wp_error( $result ) ) {
            $this->log( sprintf( 'Order #%d notification failed: %s', $order_id, $result->get_error_message() ), 'error' );
            $order->update_meta_data( '_wc_gotify_sent_error', $result->get_error_message() );
        } else {
            $order->update_meta_data( '_wc_gotify_sent', current_time( 'mysql' ) );
        }
        $order->save();
    }

    /* ---------------------------------------------------------------------
     * Placeholder / template engine
     * ------------------------------------------------------------------- */

    /**
     * Build the placeholder map for a given order.
     *
     * @param WC_Order $order Order object.
     * @return array Key-value map of {placeholder} => value.
     */
    public function build_placeholders( WC_Order $order ) {
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
            '{admin_url}'       => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
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
    public function replace_placeholders( $text, array $placeholders ) {
        return strtr( (string) $text, $placeholders );
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
    public function send_to_gotify( array $settings, $order = null ) {
        $server_url = rtrim( (string) $settings['server_url'], '/' );
        if ( '' === $server_url ) {
            return new WP_Error( 'gotify_no_server', __( 'Gotify server URL is not configured.', 'wc-gotify-notify' ) );
        }

        if ( empty( $settings['app_token'] ) ) {
            return new WP_Error( 'gotify_no_token', __( 'Gotify application token is not configured.', 'wc-gotify-notify' ) );
        }

        $endpoint = $server_url . '/message?token=' . rawurlencode( $settings['app_token'] );

        $title    = isset( $settings['title'] ) ? $settings['title'] : '';
        $message  = isset( $settings['message'] ) ? $settings['message'] : '';
        $priority = isset( $settings['priority'] ) ? intval( $settings['priority'] ) : 5;

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
            $args['headers']['Authorization'] = 'Basic ' . base64_encode( $settings['basic_auth_user'] . ':' . $settings['basic_auth_pass'] );
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
                sprintf( __( 'HTTP %d response from Gotify: %s', 'wc-gotify-notify' ), $code, $body_raw )
            );
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * Admin UI — Settings page
     * ------------------------------------------------------------------- */

    /**
     * Register the admin menu page.
     */
    public function register_admin_menu() {
        add_menu_page(
            __( 'Gotify Notifications', 'wc-gotify-notify' ),
            __( 'Gotify', 'wc-gotify-notify' ),
            'manage_woocommerce',
            'wc-gotify',
            array( $this, 'render_settings_page' ),
            'dashicons-bell',
            56
        );
    }

    /**
     * Register settings, sections, and fields.
     */
    public function register_settings() {
        register_setting(
            'wc_gotify_group',
            WC_GOTIFY_OPTION,
            array(
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => self::default_settings(),
            )
        );

        add_settings_section(
            'wc_gotify_main',
            __( 'Server Configuration', 'wc-gotify-notify' ),
            array( $this, 'render_section_info' ),
            'wc-gotify'
        );

        $fields = array(
            'server_url'      => array( __( 'Gotify Server URL', 'wc-gotify-notify' ), 'render_field_server_url' ),
            'app_token'       => array( __( 'Application Token', 'wc-gotify-notify' ), 'render_field_app_token' ),
            'priority'        => array( __( 'Priority', 'wc-gotify-notify' ), 'render_field_priority' ),
            'title'           => array( __( 'Notification Title', 'wc-gotify-notify' ), 'render_field_title' ),
            'message'         => array( __( 'Notification Message', 'wc-gotify-notify' ), 'render_field_message' ),
            'async'           => array( __( 'Delivery Method', 'wc-gotify-notify' ), 'render_field_async' ),
            'basic_auth_user' => array( __( 'Basic Auth Username (optional)', 'wc-gotify-notify' ), 'render_field_auth_user' ),
            'basic_auth_pass' => array( __( 'Basic Auth Password (optional)', 'wc-gotify-notify' ), 'render_field_auth_pass' ),
        );

        foreach ( $fields as $id => $f ) {
            add_settings_field(
                'wc_gotify_' . $id,
                $f[0],
                array( $this, $f[1] ),
                'wc-gotify',
                'wc_gotify_main'
            );
        }
    }

    /**
     * Sanitize and validate settings input.
     *
     * @param array $input Raw input.
     * @return array Sanitized output.
     */
    public function sanitize_settings( $input ) {
        $output = self::default_settings();

        $output['server_url']      = esc_url_raw( trim( isset( $input['server_url'] ) ? $input['server_url'] : '' ) );
        $output['app_token']       = sanitize_text_field( trim( isset( $input['app_token'] ) ? $input['app_token'] : '' ) );
        $output['priority']        = max( 0, min( 10, intval( isset( $input['priority'] ) ? $input['priority'] : 5 ) ) );
        $output['title']           = sanitize_text_field( isset( $input['title'] ) ? $input['title'] : $output['title'] );
        $output['message']         = sanitize_textarea_field( isset( $input['message'] ) ? $input['message'] : $output['message'] );
        $output['async']           = empty( $input['async'] ) ? 0 : 1;
        $output['basic_auth_user'] = sanitize_text_field( isset( $input['basic_auth_user'] ) ? $input['basic_auth_user'] : '' );
        $output['basic_auth_pass'] = isset( $input['basic_auth_pass'] ) ? (string) $input['basic_auth_pass'] : '';

        // Invalidate cached settings so the next read picks up new values.
        $this->settings_cache = null;

        return $output;
    }

    /**
     * Render the section description.
     */
    public function render_section_info() {
        echo '<p>' . esc_html__( 'Configure how WooCommerce sends new order notifications to your Gotify server.', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_server_url() {
        $s = $this->get_settings();
        printf(
            '<input type="url" id="wc_gotify_server_url" name="%s[server_url]" value="%s" class="regular-text" placeholder="https://gotify.example.com" />',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_attr( $s['server_url'] )
        );
        echo '<p class="description">' . esc_html__( 'Base URL of your Gotify instance, e.g. https://gotify.example.com (no trailing slash).', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_app_token() {
        $s = $this->get_settings();
        printf(
            '<input type="text" id="wc_gotify_app_token" name="%s[app_token]" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_attr( $s['app_token'] )
        );
        echo '<p class="description">' . esc_html__( 'Create an "Application" in Gotify (Apps tab) and paste the generated token here.', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_priority() {
        $s = $this->get_settings();
        printf(
            '<input type="number" min="0" max="10" step="1" id="wc_gotify_priority" name="%s[priority]" value="%d" class="small-text" />',
            esc_attr( WC_GOTIFY_OPTION ),
            intval( $s['priority'] )
        );
        echo '<p class="description">' . esc_html__( 'Message priority from 0 (lowest) to 10 (highest).', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_title() {
        $s = $this->get_settings();
        printf(
            '<input type="text" id="wc_gotify_title" name="%s[title]" value="%s" class="large-text" />',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_attr( $s['title'] )
        );
        echo '<p class="description">' . esc_html__( 'Available placeholders: {order_id}, {customer_name}, {site_name}, {date}.', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_message() {
        $s = $this->get_settings();
        printf(
            '<textarea id="wc_gotify_message" name="%s[message]" rows="8" class="large-text code">%s</textarea>',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_textarea( $s['message'] )
        );
        echo '<p class="description">' . esc_html__( 'Placeholders: {order_id}, {customer_name}, {customer_email}, {customer_phone}, {total}, {currency}, {items_count}, {payment_method}, {order_status}, {billing_city}, {billing_country}, {admin_url}, {site_url}, {site_name}, {date}.', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_async() {
        $s           = $this->get_settings();
        $available   = function_exists( 'as_enqueue_async_action' );
        $checked     = ! empty( $s['async'] ) && $available;

        printf(
            '<label><input type="checkbox" id="wc_gotify_async" name="%s[async]" value="1" %s %s /> %s</label>',
            esc_attr( WC_GOTIFY_OPTION ),
            checked( $checked, true, false ),
            disabled( $available, false, false ),
            esc_html__( 'Send asynchronously via Action Scheduler (recommended)', 'wc-gotify-notify' )
        );

        if ( $available ) {
            echo '<p class="description">' . esc_html__( 'Notifications are dispatched in the background so checkout is never delayed.', 'wc-gotify-notify' ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Action Scheduler is not detected. Notifications will be sent synchronously during checkout (may add a few seconds of delay).', 'wc-gotify-notify' ) . '</p>';
        }
    }

    public function render_field_auth_user() {
        $s = $this->get_settings();
        printf(
            '<input type="text" id="wc_gotify_basic_auth_user" name="%s[basic_auth_user]" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_attr( $s['basic_auth_user'] )
        );
        echo '<p class="description">' . esc_html__( 'Only fill if your Gotify instance sits behind HTTP Basic Auth.', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_auth_pass() {
        $s = $this->get_settings();
        printf(
            '<input type="password" id="wc_gotify_basic_auth_pass" name="%s[basic_auth_pass]" value="%s" class="regular-text" autocomplete="new-password" />',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_attr( $s['basic_auth_pass'] )
        );
        echo '<p class="description">' . esc_html__( 'Password is stored in plain text in the WordPress database.', 'wc-gotify-notify' ) . '</p>';
    }

    /**
     * Render the full settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-gotify-notify' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'wc_gotify_group' );
                do_settings_sections( 'wc-gotify' );
                submit_button( __( 'Save Settings', 'wc-gotify-notify' ) );
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Test Notification', 'wc-gotify-notify' ); ?></h2>
            <p><?php esc_html_e( 'Send a test push to verify your configuration. The current Title and Message templates will be used; placeholders will be removed.', 'wc-gotify-notify' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wc_gotify_test', 'wc_gotify_test_nonce' ); ?>
                <input type="hidden" name="action" value="wc_gotify_test" />
                <?php submit_button( __( 'Send Test Message', 'wc-gotify-notify' ), 'secondary', 'wc_gotify_test_submit', false ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handle the "Send Test Message" admin-post action.
     */
    public function handle_test_message() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'wc-gotify-notify' ) );
        }
        check_admin_referer( 'wc_gotify_test', 'wc_gotify_test_nonce' );

        $settings = $this->get_settings( true );
        $result   = $this->send_to_gotify( $settings, null );

        if ( is_wp_error( $result ) ) {
            add_settings_error( 'wc_gotify', 'gotify_test_failed', sprintf( __( 'Test failed: %s', 'wc-gotify-notify' ), $result->get_error_message() ), 'error' );
        } else {
            add_settings_error( 'wc_gotify', 'gotify_test_ok', __( 'Test message sent successfully. Check your Gotify client!', 'wc-gotify-notify' ), 'updated' );
        }

        set_transient( 'settings_errors', get_settings_errors(), 30 );

        $redirect = wp_get_referer();
        if ( ! $redirect ) {
            $redirect = admin_url( 'admin.php?page=wc-gotify' );
        }
        wp_safe_redirect( add_query_arg( 'settings-updated', 'true', $redirect ) );
        exit;
    }

    /**
     * Add a "Settings" link on the Plugins page.
     *
     * @param array $links Existing links.
     * @return array
     */
    public function plugin_action_links( $links ) {
        $url = admin_url( 'admin.php?page=wc-gotify' );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wc-gotify-notify' ) . '</a>' );
        return $links;
    }

    /* ---------------------------------------------------------------------
     * Logging
     * ------------------------------------------------------------------- */

    /**
     * Write a log entry via WC_Logger when available, error_log otherwise.
     *
     * @param string $message Log message.
     * @param string $level   Log level: debug, info, notice, warning, error, critical, alert, emergency.
     */
    private function log( $message, $level = 'info' ) {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->log( $level, $message, array( 'source' => 'wc-gotify-notify' ) );
        } else {
            error_log( '[WC Gotify] ' . $message );
        }
    }
}

/**
 * Bootstrap the plugin.
 */
function wc_gotify_notify_boot() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>' .
                esc_html__( 'WooCommerce to Gotify Notifications requires WooCommerce to be installed and active.', 'wc-gotify-notify' ) .
                '</p></div>';
        } );
        return;
    }
    WC_Gotify_Notify::instance();
}
add_action( 'plugins_loaded', 'wc_gotify_notify_boot', 20 );
