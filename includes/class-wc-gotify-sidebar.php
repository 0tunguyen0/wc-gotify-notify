<?php
/**
 * Sidebar Support Module for WooCommerce to Gotify Notifications.
 *
 * Fetches dynamic JSON data from an offsite URL
 * with transient caching, responsive sidebar styling, auto-play slider, and support box.
 *
 * @package WooCommerceToGotifyNotifications
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Gotify_Support_Sidebar' ) ) {

    class WC_Gotify_Support_Sidebar {

        /**
         * Configuration options.
         *
         * @var array
         */
        protected $args = array();

        /**
         * Constructor.
         *
         * @param array $args Configuration parameters.
         */
        public function __construct( array $args = array() ) {
            $defaults = array(
                'endpoint'         => 'https://0tunguyen0.github.io/support/data.json',
                'cache_key'        => 'wc_gotify_sidebar_data',
                'cache_time'       => 12 * HOUR_IN_SECONDS,
                'error_cache_time' => 2 * HOUR_IN_SECONDS,
                'timeout'          => 4,
                'width'            => '280px',
                'support_url'      => 'https://0tunguyen0.github.io/support/',
                'support_title'    => __( 'Need Help?', 'wc-gotify-notify' ),
                'support_text'     => __( 'Have questions, issues, or suggestions for this plugin?', 'wc-gotify-notify' ),
                'support_button'   => __( 'Visit Support Page', 'wc-gotify-notify' ),
                'slide_interval'   => 4500,
            );

            $this->args = wp_parse_args( $args, $defaults );

            // Sanitize numeric arguments with safe minimums to prevent non-expiring transients
            $this->args['timeout']          = min( 10, max( 1, absint( $this->args['timeout'] ) ) );
            $this->args['slide_interval']   = max( 1000, absint( $this->args['slide_interval'] ) );
            $this->args['cache_time']       = max( MINUTE_IN_SECONDS, absint( $this->args['cache_time'] ) );
            $this->args['error_cache_time'] = max( MINUTE_IN_SECONDS, absint( $this->args['error_cache_time'] ) );

            // Security: Validate width against strict CSS length allowlist to prevent CSS declaration injection
            if ( ! preg_match( '/^\d+(?:\.\d+)?(?:px|em|rem|%|vw)$/', (string) $this->args['width'] ) ) {
                $this->args['width'] = '280px';
            }

            // Cache key (safe length <= 45 characters)
            $this->args['cache_key'] = sanitize_key( ! empty( $this->args['cache_key'] ) ? $this->args['cache_key'] : 'wc_gotify_sidebar_data' );
        }

        /**
         * Static helper to instantiate and render in a single call.
         *
         * @param array $args Configuration parameters.
         */
        public static function render( array $args = array() ) {
            $instance = new self( $args );
            $instance->display();
        }

        /**
         * Static helper to delete transient cache (useful in deactivation/uninstall hook).
         *
         * @param string $cache_key Transient key. Defaults to 'wc_gotify_sidebar_data' if empty.
         */
        public static function clear_cache( $cache_key = 'wc_gotify_sidebar_data' ) {
            $sanitized_key = sanitize_key( ! empty( $cache_key ) ? $cache_key : 'wc_gotify_sidebar_data' );
            delete_transient( $sanitized_key );
            delete_transient( $sanitized_key . '_lock' );
        }

        /**
         * Fetch remote ad data with transient caching and concurrency protection.
         *
         * @return array|false
         */
        public function get_ad_data() {
            if ( empty( $this->args['endpoint'] ) ) {
                return false;
            }

            // Security: Validate the remote URL format
            $endpoint = wp_http_validate_url( $this->args['endpoint'] );
            if ( ! $endpoint ) {
                return false;
            }

            $cache_key = $this->args['cache_key'];
            $ad_data   = get_transient( $cache_key );

            if ( false === $ad_data ) {
                $lock_key = $cache_key . '_lock';
                if ( get_transient( $lock_key ) ) {
                    return false;
                }

                // Concurrency lock (30s) prevents thundering-herd remote requests
                set_transient( $lock_key, 1, 30 );

                // Security: wp_safe_remote_get and reject_unsafe_urls prevent SSRF on redirects
                $response = wp_safe_remote_get( $endpoint, array(
                    'timeout'            => (int) $this->args['timeout'],
                    'headers'            => array( 'Accept' => 'application/json' ),
                    'sslverify'          => true,
                    'reject_unsafe_urls' => true,
                ) );

                delete_transient( $lock_key );

                if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                    // Cache network failure temporarily with short error TTL
                    set_transient( $cache_key, array( 'active' => false ), (int) $this->args['error_cache_time'] );
                    return false;
                }

                $body = wp_remote_retrieve_body( $response );
                $json = json_decode( $body, true );

                // Treat non-JSON or parse failures as transport errors with error TTL
                if ( ! is_array( $json ) ) {
                    set_transient( $cache_key, array( 'active' => false ), (int) $this->args['error_cache_time'] );
                    return false;
                }

                if ( empty( $json['active'] ) ) {
                    $ad_data = array( 'active' => false );
                } else {
                    $display_mode = isset( $json['display_mode'] ) ? sanitize_key( $json['display_mode'] ) : 'slider';
                    $banners      = array();

                    // Multiple banners array format
                    if ( ! empty( $json['banners'] ) && is_array( $json['banners'] ) ) {
                        foreach ( $json['banners'] as $item ) {
                            if ( is_array( $item ) && ! empty( $item['image_url'] ) && ! empty( $item['link_url'] ) ) {
                                $banners[] = array(
                                    'image_url'   => esc_url_raw( $item['image_url'] ),
                                    'link_url'    => esc_url_raw( $item['link_url'] ),
                                    'alt_text'    => sanitize_text_field( $item['alt_text'] ?? '' ),
                                    'title'       => sanitize_text_field( $item['title'] ?? '' ),
                                    'description' => sanitize_text_field( $item['description'] ?? '' ),
                                );
                            }
                        }
                    }

                    if ( empty( $banners ) ) {
                        $ad_data = array( 'active' => false );
                    } else {
                        $ad_data = array(
                            'active'       => true,
                            'display_mode' => $display_mode,
                            'banners'      => $banners,
                        );
                    }
                }

                set_transient( $cache_key, $ad_data, (int) $this->args['cache_time'] );
            }

            return ( ! empty( $ad_data['active'] ) && ! empty( $ad_data['banners'] ) ) ? $ad_data : false;
        }

        /**
         * Render the sidebar HTML directly.
         */
        public function display() {
            echo $this->get_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        /**
         * Build and return the sidebar HTML.
         *
         * @return string
         */
        public function get_html() {
            $ad_data = $this->get_ad_data();
            $banners = array();

            if ( $ad_data && ! empty( $ad_data['banners'] ) ) {
                $banners = $ad_data['banners'];
                // If display_mode is 'random', pick one random banner for this page load
                if ( ( $ad_data['display_mode'] ?? 'slider' ) === 'random' && count( $banners ) > 1 ) {
                    $random_index = array_rand( $banners );
                    $banners      = array( $banners[ $random_index ] );
                }
            }

            $slider_id = 'wc-gotify-sb-slider-' . wp_rand( 1000, 9999 );
            $width     = esc_attr( $this->args['width'] );

            ob_start();
            ?>
            <!-- Right Sidebar Column -->
            <div class="wc-gotify-sidebar-column" style="flex: 0 0 <?php echo $width; ?>; width: <?php echo $width; ?>;">
                <?php if ( ! empty( $banners ) ) : ?>
                    <div id="<?php echo esc_attr( $slider_id ); ?>" class="postbox wc-gotify-sb-slider">
                        <div class="wc-gotify-sb-slides-wrap">
                            <?php foreach ( $banners as $index => $banner ) : ?>
                                <div class="wc-gotify-sb-slide" style="<?php echo 0 === $index ? 'display: block;' : 'display: none;'; ?>">
                                    <?php if ( ! empty( $banner['title'] ) ) : ?>
                                        <div class="postbox-header">
                                            <h2 class="hndle"><?php echo esc_html( $banner['title'] ); ?></h2>
                                        </div>
                                    <?php endif; ?>
                                    <div class="inside">
                                        <a href="<?php echo esc_url( $banner['link_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                            <img src="<?php echo esc_url( $banner['image_url'] ); ?>" alt="<?php echo esc_attr( $banner['alt_text'] ); ?>" loading="lazy" decoding="async" />
                                            <?php if ( ! empty( $banner['description'] ) ) : ?>
                                                <p class="wc-gotify-sb-desc"><?php echo esc_html( $banner['description'] ); ?></p>
                                            <?php endif; ?>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ( count( $banners ) > 1 ) : ?>
                            <div class="wc-gotify-sb-slider-dots">
                                <?php foreach ( $banners as $index => $banner ) : ?>
                                    <button type="button" class="wc-gotify-sb-dot<?php echo 0 === $index ? ' is-active' : ''; ?>" data-index="<?php echo esc_attr( $index ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Slide %d', 'wc-gotify-notify' ), $index + 1 ) ); ?>"></button>
                                <?php endforeach; ?>
                            </div>
                            <script>
                            (function() {
                                var slider = document.getElementById('<?php echo esc_js( $slider_id ); ?>');
                                if (!slider) return;
                                var slides = slider.querySelectorAll('.wc-gotify-sb-slide');
                                var dots = slider.querySelectorAll('.wc-gotify-sb-dot');
                                var currentIndex = 0;
                                var intervalTime = <?php echo (int) $this->args['slide_interval']; ?>;
                                var timer = null;

                                function showSlide(idx) {
                                    slides[currentIndex].style.display = 'none';
                                    dots[currentIndex].classList.remove('is-active');

                                    currentIndex = (idx + slides.length) % slides.length;

                                    slides[currentIndex].style.display = 'block';
                                    dots[currentIndex].classList.add('is-active');
                                }

                                function startAutoPlay() {
                                    stopAutoPlay();
                                    timer = setInterval(function() {
                                        showSlide(currentIndex + 1);
                                    }, intervalTime);
                                }

                                function stopAutoPlay() {
                                    if (timer) {
                                        clearInterval(timer);
                                        timer = null;
                                    }
                                }

                                dots.forEach(function(dot) {
                                    dot.addEventListener('click', function(e) {
                                        e.preventDefault();
                                        showSlide(parseInt(this.getAttribute('data-index'), 10));
                                        startAutoPlay();
                                    });
                                });

                                slider.addEventListener('mouseenter', stopAutoPlay);
                                slider.addEventListener('mouseleave', startAutoPlay);

                                startAutoPlay();
                            })();
                            </script>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $this->args['support_url'] ) ) : ?>
                    <div class="postbox wc-gotify-support-card">
                        <div class="postbox-header">
                            <h2 class="hndle"><?php echo esc_html( $this->args['support_title'] ); ?></h2>
                        </div>
                        <div class="inside">
                            <p><?php echo esc_html( $this->args['support_text'] ); ?></p>
                            <p>
                                <a href="<?php echo esc_url( $this->args['support_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                                    <?php echo esc_html( $this->args['support_button'] ); ?>
                                </a>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php
            return ob_get_clean();
        }
    }
}
