<?php
/**
 * Main plugin class
 *
 * @package HarborCache\Core
 */

namespace HarborCache\Core;

use WP_Error;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class definition
 */
class Plugin {

    /**
     * Settings page uri
     *
     * @var string $page
     */
    private $page = '';

    /**
     * Settings page slug
     *
     * @var string $screen
     */
    private $screen = '';

    /**
     * Allowed setting page actions
     *
     * @var string[] $actions
     */
    private $actions = [
        'enable-cache',
        'disable-cache',
        'flush-cache',
        'update-dropin',
    ];

    /**
     * Plugin instance property
     *
     * @var Plugin|null
     */
    private static $instance;

    /**
     * Plugin instantiation method
     *
     * @return Plugin
     */
    public static function instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ( is_multisite() ) {
            $this->page = 'settings.php?page=harborcache';
            $this->screen = 'settings_page_harborcache-network';
        } else {
            $this->page = 'options-general.php?page=harborcache';
            $this->screen = 'settings_page_harborcache';
        }

        Metrics::init();

        $this->add_actions_and_filters();
    }

    /**
     * Adds all necessary hooks
     *
     * @return void
     */
    public function add_actions_and_filters() {
        add_action( 'deactivate_plugin', [ $this, 'on_deactivation' ] );
        add_action( 'admin_init', [ $this, 'maybe_update_dropin' ] );
        add_action( 'admin_init', [ $this, 'maybe_redirect' ] );
        add_action( 'init', [ $this, 'init' ] );

        add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', [ $this, 'add_admin_menu_page' ] );

        add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );
        add_action( 'network_admin_notices', [ $this, 'show_admin_notices' ] );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_redis_metrics' ] );

        add_action( 'admin_bar_menu', [ $this, 'render_admin_bar' ], 998 );

        add_action( 'load-settings_page_harborcache', [ $this, 'do_admin_actions' ] );

        add_action( 'wp_dashboard_setup', [ $this, 'setup_dashboard_widget' ] );
        add_action( 'wp_network_dashboard_setup', [ $this, 'setup_dashboard_widget' ] );

        add_action( 'wp_ajax_roc_dismiss_notice', [ $this, 'ajax_dismiss_notice' ] );
        add_action( 'wp_ajax_roc_flush_cache', [ $this, 'ajax_flush_cache' ] );

        add_filter( 'gettext_redis-cache', [ $this, 'get_text' ], 10, 2 );

        add_filter( 'plugin_row_meta', [ $this, 'add_plugin_row_meta' ], 10, 2 );
        add_filter( sprintf( '%splugin_action_links_%s', is_multisite() ? 'network_admin_' : '', WP_REDIS_BASENAME ), [ $this, 'add_plugin_actions_links' ] );

        add_action( 'wp_head', [ $this, 'register_shutdown_hooks' ] );



        add_filter( 'qm/collectors', [ $this, 'register_qm_collector' ], 25 );
        add_filter( 'qm/outputter/html', [ $this, 'register_qm_output' ] );

        add_filter( 'perflab_disable_object_cache_dropin', '__return_true' );
        add_filter( 'w3tc_config_item_objectcache.enabled', '__return_false' );
        add_action( 'litespeed_init', [ $this, 'litespeed_disable_objectcache' ] );
    }

    /**
     * Callback of the `init` hook.
     *
     * @return void
     */
    public function init() {
        if ( is_admin() && ! wp_next_scheduled( 'rediscache_discard_metrics' ) ) {
            wp_schedule_event( time(), 'hourly', 'rediscache_discard_metrics' );
        }
    }

    /**
     * Adds a submenu page to "Settings"
     *
     * @return void
     */
    public function add_admin_menu_page() {
        add_submenu_page(
            is_multisite() ? 'settings.php' : 'options-general.php',
            __( 'HarborCache', 'harborcache' ),
            __( 'HarborCache', 'harborcache' ),
            $this->manage_redis_capability(),
            'harborcache',
            [ $this, 'show_admin_page' ]
        );
    }

    /**
     * Displays the settings page
     *
     * @return void
     */
    public function show_admin_page() {
        \HarborCache\Console::render();
    }

    /**
     * Adds the dashboard metrics widget
     *
     * @return void
     */
    public function setup_dashboard_widget() {
        \HarborCache\Console::widget();
    }

    /**
     * Displays the dashboard widget
     *
     * @return void
     */
    public function show_dashboard_widget() {
        \HarborCache\Console::widget();
    }

    /**
     * Adds the settings page to the plugin action links on the plugin page
     *
     * @param string[] $links The current plugin action links.
     * @return string[]
     */
    public function add_plugin_actions_links( $links ) {
        $settings = sprintf(
            '<a href="%s">%s</a>',
            network_admin_url( $this->page ),
            esc_html__( 'Settings', 'harborcache' )
        );

        return array_merge( [ $settings ], $links );
    }

    /**
     * Ensure Author URI has UTM parameters.
     *
     * @param string $translation Translated text.
     * @param string $text Text to translate.
     * @return string
     */
    public function get_text( $translation, $text ) {
        return $translation;
    }

    /**
     * Adds plugin meta links on the plugin page
     *
     * @param string[] $plugin_meta An array of the plugin's metadata.
     * @param string   $plugin_file Path to the plugin file relative to the plugins directory.
     * @return string[] An array of the plugin's metadata.
     */
    public function add_plugin_row_meta( array $plugin_meta, $plugin_file ) {
        return $plugin_meta;
    }

    /**
     * Returns the link to Object Cache Pro.
     *
     * @param string $medium
     * @param bool $as_html
     * @return string
     */
    public function link_to_ocp($medium, $as_html = true){
        return '';
    }

    /**
     * Enqueues admin style resources
     *
     * @return void
     */
    public function enqueue_admin_styles() {
        return;
    }

    /**
     * Enqueues admin script resources
     *
     * @return void
     */
    public function enqueue_admin_scripts() {
        return;
    }

    /**
     * Enqueues scripts to display recorded metrics
     *
     * @return void
     */
    public function enqueue_redis_metrics() {
        return;
    }

    /**
     * Registers a new cache collector for the Query Monitor plugin
     *
     * @param array $collectors Array of currently registered collectors.
     * @return array
     */
    public function register_qm_collector( array $collectors ) {
        $collectors['cache'] = new QM_Collector();

        return $collectors;
    }

    /**
     * Registers a new cache output using our collector for the Query Monitor plugin
     *
     * @param array $output Array of current QM_Output handlers.
     * @return array
     */
    public function register_qm_output( $output ) {
        $collector = \QM_Collectors::get( 'cache' );

        if (
            $collector instanceof QM_Collector &&
            method_exists( 'QM_Output_Html', 'before_non_tabular_output' )
        ) {
            $output['cache'] = new QM_Output( $collector );
        }

        return $output;
    }

    /**
     * Checks if the `object-cache.php` drop-in exists
     *
     * @return bool
     */
    public function object_cache_dropin_exists() {
        return file_exists( WP_CONTENT_DIR . '/object-cache.php' );
    }

    /**
     * Validates the `object-cache.php` drop-in
     *
     * @return bool
     */
    public function validate_object_cache_dropin() {
        if ( ! $this->object_cache_dropin_exists() ) {
            return false;
        }

        $dropin = get_plugin_data( WP_CONTENT_DIR . '/object-cache.php' );
        $plugin = get_plugin_data( WP_REDIS_PLUGIN_PATH . '/includes/object-cache.php' );

        /**
         * Filters the drop-in validation state
         *
         * @since 2.0.16
         * @param bool   $state      The validation state of the drop-in.
         * @param string $dropin     The `PluginURI` of the drop-in.
         * @param string $plugin     The `PluginURI` of the plugin.
         */
        return apply_filters(
            'redis_cache_validate_dropin',
            $dropin['PluginURI'] === $plugin['PluginURI'],
            $dropin['PluginURI'],
            $plugin['PluginURI']
        );
    }

    /**
     * Checks if the `object-cache.php` drop-in is outdated
     *
     * @return bool
     */
    public function object_cache_dropin_outdated() {
        if ( ! $this->object_cache_dropin_exists() ) {
            return false;
        }

        $dropin = get_plugin_data( WP_CONTENT_DIR . '/object-cache.php' );
        $plugin = get_plugin_data( WP_REDIS_PLUGIN_PATH . '/includes/object-cache.php' );

        if ( $dropin['PluginURI'] === $plugin['PluginURI'] ) {
            return version_compare( $dropin['Version'], $plugin['Version'], '<' );
        }

        return false;
    }

    /**
     * Retrieves the current human-readable status
     *
     * @return string
     */
    public function get_status() {
        global $wp_object_cache;

        if ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED ) {
            return __( 'Disabled (via config constant)', 'harborcache' );
        }

        if ( ! $this->object_cache_dropin_exists() ) {
            return __( 'Not enabled', 'harborcache' );
        }

        if ( $this->object_cache_dropin_outdated() ) {
            return __( 'Drop-in is outdated', 'harborcache' );
        }

        if ( ! $this->validate_object_cache_dropin() ) {
            return __( 'Drop-in is invalid', 'harborcache' );
        }

        if ( method_exists( $wp_object_cache, 'redis_status' ) ) {
            return $wp_object_cache->redis_status()
                ? __( 'Connected', 'harborcache' )
                : __( 'Not connected', 'harborcache' );
        }

        return __( 'Unknown', 'harborcache' );
    }

    /**
     * Retrieves the Redis connection status
     *
     * @return bool|null Boolean Redis connection status if available, null otherwise.
     */
    public function get_redis_status() {
        global $wp_object_cache;

        if ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED ) {
            return null;
        }

        if ( $this->object_cache_dropin_outdated() ) {
            return null;
        }

        if ( ! $this->validate_object_cache_dropin() ) {
            return null;
        }

        if ( ! method_exists( $wp_object_cache, 'redis_status' ) ) {
            return null;
        }

        return $wp_object_cache->redis_status();
    }

    /**
     * Check whether we can connect to Redis via Predis.
     *
     * @return bool|string
     */
    public function check_redis_connection() {
        try {
            $predis = new Predis();
            $predis->connect();
        } catch ( Exception $error ) {
            return $error->getMessage();
        }

        return true;
    }

    /**
     * Returns the redis version if possible
     *
     * @see WP_Object_Cache::redis_version()
     * @return null|string
     */
    public function get_redis_version() {
        global $wp_object_cache;

        if ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED ) {
            return null;
        }

        if ( ! $this->validate_object_cache_dropin() || ! method_exists( $wp_object_cache, 'redis_version' ) ) {
            return null;
        }

        return $wp_object_cache->redis_version();
    }

    /**
     * Returns the currently used redis client (if any)
     *
     * @return null|string
     */
    public function get_redis_client_name() {
        global $wp_object_cache;

        if ( isset( $wp_object_cache->diagnostics[ 'client' ] ) ) {
            return $wp_object_cache->diagnostics[ 'client' ];
        }

        if ( ! defined( 'WP_REDIS_CLIENT' ) ) {
            return null;
        }

        return WP_REDIS_CLIENT;
    }

    /**
     * Fetches the redis diagnostics data
     *
     * @return null|array
     */
    public function get_diagnostics() {
        global $wp_object_cache;

        if ( ! $this->validate_object_cache_dropin() || ! property_exists( $wp_object_cache, 'diagnostics' ) ) {
            return null;
        }

        return $wp_object_cache->diagnostics;
    }

    /**
     * Retrieves the redis prefix
     *
     * @return null|mixed
     */
    public function get_redis_prefix() {
        return defined( 'WP_REDIS_PREFIX' ) ? WP_REDIS_PREFIX : null;
    }

    /**
     * Retrieves the redis maximum time to live
     *
     * @return null|mixed
     */
    public function get_redis_maxttl() {
        return defined( 'WP_REDIS_MAXTTL' ) ? WP_REDIS_MAXTTL : null;
    }

    /**
     * Displays admin notices
     *
     * @return void
     */
    public function show_admin_notices() {
        $this->pro_notice();
        $this->wc_pro_notice();

        // Only show admin notices to users with the right capability.
        if ( ! $this->current_user_can_manage_redis() ) {
            return;
        }

        // Do not display the dropin message if you want
        if ( defined( 'WP_REDIS_DISABLE_DROPIN_BANNERS' ) && WP_REDIS_DISABLE_DROPIN_BANNERS ) {
            return;
        }

        if ( defined( 'RedisCachePro\Version' ) || defined( 'ObjectCachePro\Version' ) ) {
            return;
        }

        if ( $this->object_cache_dropin_exists() ) {
            if ( $this->validate_object_cache_dropin() ) {
                if ( $this->object_cache_dropin_outdated() ) {
                    $message = sprintf(
                        // translators: %s = Action link to update the drop-in.
                        __( 'The Redis object cache drop-in is outdated. Please <a href="%s">update the drop-in</a>.', 'harborcache' ),
                        $this->action_link( 'update-dropin' )
                    );
                }
            } else {
                $message = sprintf(
                    // translators: %s = Link to settings page.
                    __( 'A foreign object cache drop-in was found. To use Redis for object caching, please <a href="%s">enable the drop-in</a>.', 'harborcache' ),
                    esc_url( network_admin_url( $this->page ) )
                );
            }

            if ( isset( $message ) ) {
                printf( '<div class="update-nag notice notice-warning inline">%s</div>', wp_kses_post( $message ) );
            }
        }
    }

    /**
     * Display the admin bar menu item.
     *
     * @param \WP_Admin_Bar $wp_admin_bar
     *
     * @return void
     */
    public function render_admin_bar( $wp_admin_bar ) {
        \HarborCache\Console::adminbar( $wp_admin_bar );
    }

    /**
     * Returns the admin-bar <style> tag.
     *
     * @return string
     */
    protected function admin_bar_style() {
        return '';
    }

    /**
     * Returns the admin-bar <script> tag.
     *
     * @return string
     */
    protected function admin_bar_script() {
        return '';
    }

    /**
     * Executes admin actions
     *
     * @return void
     */
    public function do_admin_actions() {
        return;
    }

    /**
     * Dismisses the admin notice for the current user
     *
     * @return void
     */
    public function ajax_dismiss_notice() {
        if ( isset( $_POST['notice'] ) ) {
            check_ajax_referer( 'roc_dismiss_notice' );

            $notice = sprintf(
                'roc_dismissed_%s',
                sanitize_key( $_POST['notice'] )
            );

            update_user_meta( get_current_user_id(), $notice, '1' );
        }

        wp_die();
    }

    /**
     * Flushes object cache
     *
     * @return void
     */
    public function ajax_flush_cache() {
        if ( ! \HarborCache\Console::allowed() ) { wp_die( esc_html__( 'You do not have permission to manage HarborCache.', 'harborcache' ), '', array( 'response' => 403 ) ); }
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            wp_die( esc_html__( 'Invalid Nonce.', 'harborcache' ) );
        } else if ( wp_cache_flush() ) {
            wp_die( esc_html__( 'Object cache flushed.', 'harborcache' ) );
        } else {
            wp_die( esc_html__( 'Object cache could not be flushed.', 'harborcache' ) );
        }
    }

    /**
     * Displays a redis cache pro admin notice
     *
     * @return void
     */
    public function pro_notice() {
        return;
    }

    /**
     * Displays a redis cache pro admin notice specifically for WooCommerce
     *
     * @return void
     */
    public function wc_pro_notice() {
        return;
    }

    /**
     * Registers all hooks associated with the shutdown hook
     *
     * @return void
     */
    public function register_shutdown_hooks() {
        if ( ! defined( 'WP_REDIS_DISABLE_COMMENT' ) || ! WP_REDIS_DISABLE_COMMENT ) {
            add_action( 'shutdown', [ $this, 'maybe_print_comment' ], 0 );
        }
    }

    /** Retained compatibility callback; enabling a cache never deletes database transients. */
    public function maybe_delete_transients( $should_delete ) { return; }

    /**
     * Displays the redis cache html comment
     *
     * @return void
     */
    public function maybe_print_comment() {
        /** @var \WP_Object_Cache $wp_object_cache */
        global $wp_object_cache;

        if (
            ( defined( 'WP_CLI' ) && WP_CLI ) ||
            ( defined( 'DOING_CRON' ) && DOING_CRON ) ||
            ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
            ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
            ( defined( 'JSON_REQUEST' ) && JSON_REQUEST ) ||
            ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) ||
            ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ||
            ( defined( 'WC_API_REQUEST' ) && WC_API_REQUEST )
        ) {
            return;
        }

        if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
            return;
        }

        if (
            ! isset( $wp_object_cache->cache, $wp_object_cache->diagnostics ) ||
            ! is_array( $wp_object_cache->cache )
        ) {
            return;
        }

        if ($this->incompatible_content_type()) {
            return;
        }

        $message = sprintf(
            'Performance optimized by HarborCache. Learn more: %s',
            'https://profiles.wordpress.org/shahidirfan100/'
        );

        if ( ! WP_DEBUG_DISPLAY ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            printf( "\n<!-- %s -->\n", $message );

            return;
        }

        $bytes = strlen( serialize( $wp_object_cache->cache ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

        $debug = sprintf(
            // translators: %1$d = number of objects. %2$s = human-readable size of cache. %3$s = name of the used client.
            __( 'Retrieved %1$d objects (%2$s) from Redis using %3$s.', 'harborcache' ),
            $wp_object_cache->cache_hits,
            function_exists( 'size_format' ) ? size_format( $bytes ) : "{$bytes} bytes",
            $wp_object_cache->diagnostics['client']
        );

        printf(
            "<!--\n%s\n\n%s\n-->\n",
            $message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            esc_html( $debug )
        );
    }

    /**
     * Whether incompatible content type headers were sent.
     *
     * @return bool
     */
    protected function incompatible_content_type() {
        $jsonContentType = static function ($headers) {
            foreach ($headers as $header => $value) {
                if (stripos((string) $header, 'content-type') === false) {
                    continue;
                }

                if (stripos((string) $value, '/json') === false) {
                    continue;
                }

                return true;
            }

            return false;
        };

        if (function_exists('headers_list')) {
            $headers = [];

            foreach (headers_list() as $header) {
                [$name, $value] = explode(':', $header);
                $headers[$name] = $value;
            }

            if ($jsonContentType($headers)) {
                return true;
            }
        }

        if (function_exists('apache_response_headers')) {
            if ($headers = apache_response_headers()) {
                return $jsonContentType($headers);
            }
        }

        return false;
    }

    /**
     * Initializes the WP filesystem API to be ready for use
     *
     * @param string $url    The URL to post the form to.
     * @param bool   $silent Whether to ask the user for credentials if necessary or not.
     * @return bool
     */
    public function initialize_filesystem( $url, $silent = false ) {
        if ( $silent ) {
            ob_start();
        }

        $credentials = request_filesystem_credentials( $url );

        if ( false === $credentials ) {
            if ( $silent ) {
                ob_end_clean();
            }

            return false;
        }

        if ( ! WP_Filesystem( $credentials ) ) {
            request_filesystem_credentials( $url );

            if ( $silent ) {
                ob_end_clean();
            }

            return false;
        }

        return true;
    }

    /**
     * Determines whether object cache file modifications are allowed.
     *
     * @return bool
     */
    function is_file_mod_allowed() {
        return wp_is_file_mod_allowed( 'object_cache_dropin' );
    }

    /**
     * Test if we can write in the WP_CONTENT_DIR and modify the `object-cache.php` drop-in
     *
     * @return true|WP_Error
     */
    public function test_filesystem_writing() {
        /** @var \WP_Filesystem_Base $wp_filesystem */
        global $wp_filesystem;

        if ( ! $this->is_file_mod_allowed() ) {
            return new WP_Error( 'disallowed', __( 'File modifications are not allowed.', 'harborcache' ) );
        }

        if ( ! $this->initialize_filesystem( '', true ) ) {
            return new WP_Error( 'fs', __( 'Could not initialize filesystem.', 'harborcache' ) );
        }

        $dropin_check = ! defined( 'WP_REDIS_DISABLE_DROPIN_CHECK' ) || ! WP_REDIS_DISABLE_DROPIN_CHECK;

        if ( ! $dropin_check ) {
            if ( ! $wp_filesystem->exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
                return true;
            }

            if ( ! $wp_filesystem->is_writable( WP_CONTENT_DIR . '/object-cache.php' ) ) {
                return new WP_Error( 'writable', __( 'Object cache drop-in is not writable.', 'harborcache' ) );
            }

            return true;
        }

        $cachefile = WP_REDIS_PLUGIN_PATH . '/includes/object-cache.php';
        $testfile = WP_CONTENT_DIR . '/object-cache.tmp';

        if ( ! $wp_filesystem->exists( $cachefile ) ) {
            return new WP_Error( 'exists', __( 'Object cache file doesn’t exist.', 'harborcache' ) );
        }

        if ( $wp_filesystem->exists( $testfile ) ) {
            if ( ! $wp_filesystem->delete( $testfile ) ) {
                return new WP_Error( 'delete', __( 'Test file exists, but couldn’t be deleted.', 'harborcache' ) );
            }
        }

        if ( ! $wp_filesystem->is_writable( WP_CONTENT_DIR ) ) {
            return new WP_Error( 'writable', __( 'Content directory is not writable.', 'harborcache' ) );
        }

        if ( ! $wp_filesystem->copy( $cachefile, $testfile, true, FS_CHMOD_FILE ) ) {
            return new WP_Error( 'copy', __( 'Failed to copy test file.', 'harborcache' ) );
        }

        if ( ! $wp_filesystem->exists( $testfile ) ) {
            return new WP_Error( 'exists', __( 'Copied test file doesn’t exist.', 'harborcache' ) );
        }

        $meta = get_file_data( $testfile, [ 'Version' => 'Version' ] );

        if ( $meta['Version'] !== WP_REDIS_VERSION ) {
            return new WP_Error( 'version', __( 'Couldn’t verify test file contents.', 'harborcache' ) );
        }

        if ( ! $wp_filesystem->delete( $testfile ) ) {
            return new WP_Error( 'delete', __( 'Copied test file couldn’t be deleted.', 'harborcache' ) );
        }

        return true;
    }

    /**
     * Calls the drop-in update method if necessary
     *
     * @return void
     */
    public function maybe_update_dropin() {
        if ( defined( 'WP_REDIS_DISABLE_DROPIN_AUTOUPDATE' ) && WP_REDIS_DISABLE_DROPIN_AUTOUPDATE ) {
            return;
        }

        if ( $this->object_cache_dropin_outdated() ) {
            add_action( 'shutdown', [ $this, 'update_dropin' ] );
        }
    }

    /**
     * Redirects to the plugin settings if the plugin was just activated
     *
     * @return void
     */
    public function maybe_redirect() {
        if ( ! get_transient( '_rediscache_activation_redirect' ) ) {
            return;
        }

        if ( ! $this->current_user_can_manage_redis() ) {
            return;
        }

        delete_transient( '_rediscache_activation_redirect' );

        wp_safe_redirect( network_admin_url( $this->page ) );
    }

    /**
     * Updates the `object-cache.php` drop-in
     *
     * @return void
     */
    public function update_dropin() {
        global $wp_filesystem;

        if ( ! $this->validate_object_cache_dropin() ) {
            return;
        }

        if ( $this->initialize_filesystem( '', true ) ) {
            $result = $wp_filesystem->copy(
                WP_REDIS_PLUGIN_PATH . '/includes/object-cache.php',
                WP_CONTENT_DIR . '/object-cache.php',
                true,
                FS_CHMOD_FILE
            );

            /**
             * Fires on cache update-dropin event
             *
             * @since 1.3.5
             * @param bool $result Whether the filesystem event (copy of the `object-cache.php` file) was successful.
             */
            do_action( 'redis_object_cache_update_dropin', $result );
        }
    }

    /**
     * Plugin activation hook
     *
     * @return void
     */
    public static function on_activation() {
        set_transient( '_rediscache_activation_redirect', 1, 30 );
    }

    /**
     * Plugin deactivation hook
     *
     * @param string $plugin Plugin basename.
     * @return void
     */
    public function on_deactivation( $plugin ) {
        \HarborCache\Console::deactivate( $plugin );
    }

    /**
     * Helper method to retrieve a nonced plugin action link
     *
     * @param string $action The action to be executed once the link is followed.
     * @return string
     */
    public function action_link( $action ) {
        if ( ! in_array( $action, $this->actions, true ) ) {
            return '';
        }

        return wp_nonce_url(
            network_admin_url( add_query_arg( 'action', $action, $this->page ) ),
            $action
        );
    }

    /**
     * Obscure `password` URL parameter.
     *
     * @param string $url
     * @return string
     */
    public function obscure_url_secrets( $url ) {
        return preg_replace(
            '/password=[^&]+/',
            'password=*****',
            (string) $url
        );
    }

    /**
     * The capability required to manage Redis.
     *
     * @return string
     */
    public function manage_redis_capability() {
        if ( defined( 'WP_REDIS_MANAGER_CAPABILITY' ) && WP_REDIS_MANAGER_CAPABILITY ) {
            return WP_REDIS_MANAGER_CAPABILITY;
        }

        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';

        /**
         * Filters the capability used to determine if a user can manage Redis.
         *
         * @since 2.6.0
         * @param string   $capability The default capability to determine if the user can manage cache.
         */
        return apply_filters( 'redis_cache_manager_capability', $capability );
    }

    /**
     * Does the current user have the capability to manage Redis?
     *
     * @return bool
     */
    public function current_user_can_manage_redis() {
        return current_user_can( $this->manage_redis_capability() );
    }

    /**
     * Prevent LiteSpeed Cache from overwriting the `object-cache.php` drop-in.
     *
     * @return void
     */
    public function litespeed_disable_objectcache() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['LSCWP_CTRL'], $_POST['LSCWP_NONCE'], $_POST['object'] ) ) {
            $_POST['object'] = '0';
        }
    }

    /**
     * Returns `true` if the plugin was installed by AccelerateWP from CloudLinux.
     *
     * @param bool $ignore_banner_constant
     * @return bool
     */
    public static function acceleratewp_install( $ignore_banner_constant = false ) {
        $path = defined( 'WP_REDIS_PATH' ) ? WP_REDIS_PATH : null;
        $scheme = defined( 'WP_REDIS_SCHEME' ) ? WP_REDIS_SCHEME : null;

        if ( $scheme === 'unix' && strpos( (string) $path, '.clwpos/redis.sock' ) !== false ) {
            if ( $ignore_banner_constant ) {
                return true;
            } else {
                return defined( 'WP_REDIS_DISABLE_BANNERS' ) && WP_REDIS_DISABLE_BANNERS;
            }
        }

        return false;
    }
}
