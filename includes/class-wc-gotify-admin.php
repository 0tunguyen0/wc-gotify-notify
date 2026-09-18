<?php
/**
 * Admin settings page and configuration handler.
 *
 * @package WooCommerceToGotifyNotifications
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WC_Gotify_Admin {

    /**
     * Singleton instance.
     *
     * @var WC_Gotify_Admin|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return WC_Gotify_Admin
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
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'admin_post_wc_gotify_test', array( $this, 'handle_test_message' ) );
        add_filter( 'plugin_action_links_' . WC_GOTIFY_BASENAME, array( $this, 'plugin_action_links' ) );
    }

    /**
     * Enqueue admin styles on the plugin settings page only.
     *
     * @param string $hook Page hook suffix.
     */
    public function enqueue_admin_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'toplevel_page_wc-gotify' !== $hook && 'wc-gotify' !== $page ) {
            return;
        }

        wp_enqueue_style(
            'wc-gotify-admin',
            WC_GOTIFY_URL . 'assets/css/admin.css',
            array(),
            WC_GOTIFY_VERSION
        );
    }

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
                'default'           => WC_Gotify_Core::default_settings(),
            )
        );

        add_settings_section(
            'wc_gotify_main',
            '',
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
        $output = WC_Gotify_Core::default_settings();

        $output['server_url']      = esc_url_raw( trim( isset( $input['server_url'] ) ? $input['server_url'] : '' ) );
        $output['app_token']       = sanitize_text_field( trim( isset( $input['app_token'] ) ? $input['app_token'] : '' ) );
        $output['priority']        = max( 0, min( 10, intval( isset( $input['priority'] ) ? $input['priority'] : 5 ) ) );
        $output['title']           = sanitize_text_field( isset( $input['title'] ) ? $input['title'] : $output['title'] );
        $output['message']         = sanitize_textarea_field( isset( $input['message'] ) ? $input['message'] : $output['message'] );
        $output['async']           = empty( $input['async'] ) ? 0 : 1;
        $output['basic_auth_user'] = sanitize_text_field( isset( $input['basic_auth_user'] ) ? $input['basic_auth_user'] : '' );
        $output['basic_auth_pass'] = isset( $input['basic_auth_pass'] ) ? (string) $input['basic_auth_pass'] : '';

        // Invalidate in-memory settings cache.
        WC_Gotify_Core::instance()->flush_settings_cache();

        return $output;
    }

    /**
     * Render the section description.
     */
    public function render_section_info() {
        echo '<p class="description">' . esc_html__( 'Configure how WooCommerce sends new order notifications to your Gotify server.', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_server_url() {
        $s = WC_Gotify_Core::instance()->get_settings();
        printf(
            '<input type="url" id="wc_gotify_server_url" name="%s[server_url]" value="%s" class="regular-text" placeholder="https://gotify.example.com" />',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_attr( $s['server_url'] )
        );
        echo '<p class="description">' . esc_html__( 'Base URL of your Gotify instance, e.g. https://gotify.example.com (no trailing slash).', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_app_token() {
        $s = WC_Gotify_Core::instance()->get_settings();
        printf(
            '<input type="text" id="wc_gotify_app_token" name="%s[app_token]" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_attr( $s['app_token'] )
        );
        echo '<p class="description">' . esc_html__( 'Create an "Application" in Gotify (Apps tab) and paste the generated token here.', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_priority() {
        $s = WC_Gotify_Core::instance()->get_settings();
        printf(
            '<input type="number" min="0" max="10" step="1" id="wc_gotify_priority" name="%s[priority]" value="%d" class="small-text" />',
            esc_attr( WC_GOTIFY_OPTION ),
            intval( $s['priority'] )
        );
        echo '<p class="description">' . esc_html__( 'Message priority from 0 (lowest) to 10 (highest).', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_title() {
        $s = WC_Gotify_Core::instance()->get_settings();
        printf(
            '<input type="text" id="wc_gotify_title" name="%s[title]" value="%s" class="large-text" />',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_attr( $s['title'] )
        );
        echo '<p class="description">' . esc_html__( 'Available placeholders: {order_id}, {customer_name}, {site_name}, {date}.', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_message() {
        $s = WC_Gotify_Core::instance()->get_settings();
        printf(
            '<textarea id="wc_gotify_message" name="%s[message]" rows="8" class="large-text code">%s</textarea>',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_textarea( $s['message'] )
        );
        echo '<p class="description">' . esc_html__( 'Placeholders: {order_id}, {customer_name}, {customer_email}, {customer_phone}, {total}, {currency}, {items_count}, {payment_method}, {order_status}, {billing_city}, {billing_country}, {admin_url}, {site_url}, {site_name}, {date}.', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_async() {
        $s         = WC_Gotify_Core::instance()->get_settings();
        $available = function_exists( 'as_enqueue_async_action' );
        $checked   = ! empty( $s['async'] ) && $available;

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
            echo '<p class="description">' . esc_html__( 'Action Scheduler is not detected. Notifications will be sent synchronously during checkout.', 'wc-gotify-notify' ) . '</p>';
        }
    }

    public function render_field_auth_user() {
        $s = WC_Gotify_Core::instance()->get_settings();
        printf(
            '<input type="text" id="wc_gotify_basic_auth_user" name="%s[basic_auth_user]" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr( WC_GOTIFY_OPTION ),
            esc_attr( $s['basic_auth_user'] )
        );
        echo '<p class="description">' . esc_html__( 'Only fill if your Gotify instance sits behind HTTP Basic Auth.', 'wc-gotify-notify' ) . '</p>';
    }

    public function render_field_auth_pass() {
        $s = WC_Gotify_Core::instance()->get_settings();
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

            <div class="wc-gotify-admin-layout">
                <!-- Main Content Column -->
                <div class="wc-gotify-main-column">
                    <!-- Server Configuration Card -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2 class="hndle"><?php esc_html_e( 'Server Configuration', 'wc-gotify-notify' ); ?></h2>
                        </div>
                        <div class="inside">
                            <form method="post" action="options.php">
                                <?php
                                settings_fields( 'wc_gotify_group' );
                                do_settings_sections( 'wc-gotify' );
                                submit_button( __( 'Save Settings', 'wc-gotify-notify' ) );
                                ?>
                            </form>
                        </div>
                    </div>

                    <!-- Test Notification Card -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2 class="hndle"><?php esc_html_e( 'Test Notification', 'wc-gotify-notify' ); ?></h2>
                        </div>
                        <div class="inside">
                            <p><?php esc_html_e( 'Send a test push to verify your configuration. The current Title and Message templates will be used; placeholders will be removed.', 'wc-gotify-notify' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'wc_gotify_test', 'wc_gotify_test_nonce' ); ?>
                                <input type="hidden" name="action" value="wc_gotify_test" />
                                <?php submit_button( __( 'Send Test Message', 'wc-gotify-notify' ), 'secondary', 'wc_gotify_test_submit', false ); ?>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Support Sidebar Column -->
                <?php
                WC_Gotify_Support_Sidebar::render( array(
                    'endpoint'    => 'https://0tunguyen0.github.io/support/data.json',
                    'support_url' => 'https://0tunguyen0.github.io/support/',
                ) );
                ?>
            </div>
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

        $settings = WC_Gotify_Core::instance()->get_settings( true );
        $result   = WC_Gotify_Core::instance()->send_to_gotify( $settings, null );

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
}
