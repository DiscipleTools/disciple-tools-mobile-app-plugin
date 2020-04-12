<?php
/**
 * Plugin Name: Disciple Tools - Mobile App Extension
 * Plugin URI: https://github.com/DiscipleTools/disciple-tools-mobile-app-plugin
 * Description: Disciple Tools - Mobile App Extension supports integration with the disciple tools mobile app
 * Version:  1.4
 * Author URI: https://github.com/DiscipleTools
 * GitHub Plugin URI: https://github.com/DiscipleTools/disciple-tools-mobile-app-plugin
 * Requires at least: 4.7.0
 * (Requires 4.7+ because of the integration of the REST API at 4.7 and the security requirements of this milestone version.)
 * Tested up to: 5.1
 *
 * @package Disciple_Tools
 * @link    https://github.com/DiscipleTools
 * @license GPL-3.0 or later
 *          https://www.gnu.org/licenses/gpl-3.0.html
 */


if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

$class_already_loaded = false;
if ( ! class_exists( 'Jwt_Auth' ) ) {
    require_once( 'libraries/wp-api-jwt-auth/jwt-auth.php' );
} else {
    $class_already_loaded = true;
}

$dt_mobile_app_required_dt_theme_version = '0.27.1';

/**
 * Gets the instance of the `DT_Mobile_App` class.
 *
 * @since  0.1
 * @access public
 * @return object|bool
 */
function dt_mobile_app() {
    global $dt_mobile_app_required_dt_theme_version;
    $wp_theme = wp_get_theme();
    $version = $wp_theme->version;
    /*
     * Check if the Disciple.Tools theme is loaded and is the latest required version
     */
    $is_theme_dt = strpos( $wp_theme->get_template(), "disciple-tools-theme" ) !== false || $wp_theme->name === "Disciple Tools";
    if ( $is_theme_dt && version_compare( $version, $dt_mobile_app_required_dt_theme_version, "<" ) ) {
        add_action( 'admin_notices', 'dt_mobile_app_hook_admin_notice' );
        add_action( 'wp_ajax_dismissed_notice_handler', 'dt_hook_ajax_notice_handler' );
        return false;
    }
    if ( !$is_theme_dt ){
        return false;
    }
    /**
     * Load useful function from the theme
     */
    if ( !defined( 'DT_FUNCTIONS_READY' ) ){
        require_once get_template_directory() . '/dt-core/global-functions.php';
    }
    return DT_Mobile_App::get_instance();
}
add_action( 'plugins_loaded', 'dt_mobile_app' );

/**
 * Singleton class for setting up the plugin.
 *
 * @since  0.1
 * @access public
 */
class DT_Mobile_App {

    /**
     * Declares public variables
     *
     * @since  0.1
     * @access public
     * @return object
     */
    public $token;
    public $version;
    private $context = "dt_mobile_app_plugin";
    public $dir_path = '';
    public $dir_uri = '';
    public $img_uri = '';
    public $includes_path;
    public $show_jwt_error = false;

    /**
     * Returns the instance.
     *
     * @since  0.1
     * @access public
     * @return object
     */
    public static function get_instance() {

        static $instance = null;

        if ( is_null( $instance ) ) {
            $instance = new dt_mobile_app();
            $instance->setup();
            $instance->includes();
            $instance->setup_actions();
        }
        return $instance;
    }

    /**
     * Constructor method.
     *
     * @since  0.1
     * @access private
     * @return void
     */
    private function __construct() {
        $this->namespace = $this->context . "/v1";
        add_action( 'rest_api_init', [ $this, 'add_api_routes' ] );
        add_action( 'admin_notices', [ $this, 'mobile_app_error' ] );
    }

    public function add_api_routes(){

        register_rest_route(
            $this->namespace, '/contacts', [
                "methods"  => "GET",
                "callback" => [ $this, 'get_contacts' ],
            ]
        );

        register_rest_route(
            $this->namespace, '/groups', [
                "methods"  => "GET",
                "callback" => [ $this, 'get_groups' ],
            ]
        );

        register_rest_route(
            $this->namespace, '/users', [
                "methods"  => "GET",
                "callback" => [ $this, 'get_users' ],
            ]
        );

        register_rest_route(
            $this->namespace, '/people-groups', [
                "methods"  => "GET",
                "callback" => [ $this, 'get_people_groups' ],
            ]
        );

        register_rest_route(
            $this->namespace, '/locations', [
                "methods"  => "GET",
                "callback" => [ $this, 'get_locations' ],
            ]
        );

    }

    /**
     * @param WP_REST_Request $request
     *
     * @return array|WP_Query
     */
    public function get_contacts( WP_REST_Request $request ) {
        //$contacts = DT_Posts::get_viewable_compact( 'contacts', $search );
        return $this->get_viewable( 'contacts' );
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return array|WP_Query
     */
    public function get_groups( WP_REST_Request $request ) {
        //$contacts = DT_Posts::get_viewable_compact( 'groups', $search );
        return $this->get_viewable( 'groups' );
    }

    /**
     * @param string $post_type
     *
     * @return bool
     */
    private function can_access( string $post_type ) {
        return current_user_can( "access_" . $post_type );
    }

    /**
     * @param string $post_type
     *
     * @return bool
     */
    public static function can_view_all( string $post_type ) {
        return current_user_can( "view_any_" . $post_type );
    }

    /**
     * @param string $post_type
     * @param int    $user_id
     *
     * @return array
     */
    public static function get_posts_shared_with_user( string $post_type, int $user_id, $search_for_post_name = '' ) {
        global $wpdb;
        $shares = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                FROM $wpdb->dt_share as shares
                INNER JOIN $wpdb->posts as posts
                WHERE user_id = %d
                AND posts.post_title LIKE %s
                AND shares.post_id = posts.ID
                AND posts.post_type = %s
                AND posts.post_status = 'publish'",
                $user_id,
                "%$search_for_post_name%",
                $post_type
            ),
            OBJECT
        );
        return $shares;
    }

    /**
     * Get viewable in compact form
     *
     * @param string $post_type
     *
     * @return array|WP_Error|WP_Query
     */
    private function get_viewable( string $post_type ) {
        if ( !$this->can_access( $post_type ) ) {
            return new WP_Error( __FUNCTION__, sprintf( "You do not have access to these %s", $post_type ), [ 'status' => 403 ] );
        }
        global $wpdb;
        $current_user = wp_get_current_user();
        $compact = [];
        //$search_string = esc_sql( sanitize_text_field( $search_string ) );
        $shared_with_user = [];
        $users_interacted_with =[];

        $send_quick_results = false;
        //find the most recent posts interacted with by the user
        $posts = $wpdb->get_results( $wpdb->prepare( "
            SELECT *, statusReport.meta_value as overall_status, pm.meta_value as corresponds_to_user
            FROM $wpdb->posts p
            LEFT JOIN $wpdb->postmeta statusReport ON ( statusReport.post_id = p.ID AND statusReport.meta_key = 'overall_status')
            LEFT JOIN $wpdb->postmeta pm ON ( pm.post_id = p.ID AND pm.meta_key = 'corresponds_to_user' )
            WHERE p.post_type = %s AND (p.post_status = 'publish' OR p.post_status = 'private')
        ", $current_user->ID, $post_type ), OBJECT );
        if ( !empty( $posts ) ){
            $send_quick_results = true;
        }
        if ( !$send_quick_results ){
            if ( !$this->can_view_all( $post_type ) ) {
                //@todo better way to get the contact records for users my contacts are shared with
                $shared_with_user = $this->get_posts_shared_with_user( $post_type, $current_user->ID );
                $query_args['meta_key'] = 'assigned_to';
                $query_args['meta_value'] = "user-" . $current_user->ID;
                $posts = $wpdb->get_results( $wpdb->prepare( "
                    SELECT *, statusReport.meta_value as overall_status, pm.meta_value as corresponds_to_user 
                    FROM $wpdb->posts
                    INNER JOIN $wpdb->postmeta as assigned_to ON ( $wpdb->posts.ID = assigned_to.post_id AND assigned_to.meta_key = 'assigned_to')
                    LEFT JOIN $wpdb->postmeta statusReport ON ( statusReport.post_id = $wpdb->posts.ID AND statusReport.meta_key = 'overall_status')
                    LEFT JOIN $wpdb->postmeta pm ON ( pm.post_id = $wpdb->posts.ID AND pm.meta_key = 'corresponds_to_user' )
                    WHERE assigned_to.meta_value = %s
                    AND $wpdb->posts.post_type = %s AND ($wpdb->posts.post_status = 'publish' OR $wpdb->posts.post_status = 'private')
                ", "user-". $current_user->ID, $post_type), OBJECT );
            } else {
                $posts = $wpdb->get_results( $wpdb->prepare( "
                    SELECT ID, post_title, pm.meta_value as corresponds_to_user, statusReport.meta_value as overall_status
                    FROM $wpdb->posts p
                    LEFT JOIN $wpdb->postmeta pm ON ( pm.post_id = p.ID AND pm.meta_key = 'corresponds_to_user' )
                    LEFT JOIN $wpdb->postmeta statusReport ON ( statusReport.post_id = p.ID AND statusReport.meta_key = 'overall_status')
                    WHERE p.ID IN ( SELECT ID FROM $wpdb->posts )
                    AND p.post_type = %s AND (p.post_status = 'publish' OR p.post_status = 'private')
                ", $post_type), OBJECT );
            }
        }
        if ( is_wp_error( $posts ) ) {
            return $posts;
        }

        $post_ids = array_map(
            function( $post ) {
                return $post->ID;
            },
            $posts
        );
        if ( $post_type === 'contacts' && !$this->can_view_all( $post_type ) && sizeof( $posts ) < 30 ) {
            //$users_interacted_with = Disciple_Tools_Users::get_assignable_users_compact( $search_string );
            $users_interacted_with = $this->get_assignable_users();
            foreach ( $users_interacted_with as $user ) {
                $post_id = $this->get_contact_for_user( $user["ID"] );
                if ( $post_id ){
                    if ( !in_array( $post_id, $post_ids ) ) {
                        $compact[] = [
                            "ID" => $post_id,
                            "name" => $user["name"],
                            "user" => true
                        ];
                    }
                }
            }
        }
        foreach ( $shared_with_user as $shared ) {
            if ( !in_array( $shared->ID, $post_ids ) ) {
                $compact[] = [
                    "ID" => $shared->ID,
                    "name" => $shared->post_title
                ];
            }
        }
        foreach ( $posts as $post ) {
            $compact[] = [
                "ID" => $post->ID,
                "name" => $post->post_title,
                "user" => $post->corresponds_to_user >= 1,
                "status" => $post->overall_status
            ];
        }

        return [
            "total" => sizeof( $compact ),
            "posts" => $compact
        ];
    }

    /**
     * @param \WP_REST_Request $request
     *
     * @return array|\WP_Error
     */
    public function get_users( WP_REST_Request $request ) {
        //$users = Disciple_Tools_Users::get_assignable_users_compact( $search, $get_all );
        return $this->get_assignable_users();
    }

    /**
     * Get viewable in compact form
     *
     * @param string $post_type
     *
     * @return array|WP_Error|WP_Query
     */
    private function get_assignable_users() {
        if ( !current_user_can( "access_contacts" ) ) {
            return new WP_Error( __FUNCTION__, __( "No permissions to assign" ), [ 'status' => 403 ] );
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $users = [];
        $update_needed = [];
        if ( !user_can( get_current_user_id(), 'view_any_contacts' ) ){
            // users that are shared posts that are shared with me
            $users_ids = $wpdb->get_results( $wpdb->prepare("
                SELECT user_id
                FROM $wpdb->dt_share
                WHERE post_id IN (
                      SELECT post_id
                      FROM $wpdb->dt_share
                      WHERE user_id = %1\$s
                )
                GROUP BY user_id
                ",
                $user_id
            ), ARRAY_N );

            $dispatchers = $wpdb->get_results("
                SELECT user_id FROM $wpdb->usermeta 
                WHERE meta_key = '{$wpdb->prefix}capabilities' 
                AND meta_value LIKE '%dispatcher%'
            ");

            $assure_unique = [];
            foreach ( $dispatchers as $index ){
                $id = $index->user_id;
                if ( $id && !in_array( $id, $assure_unique )){
                    $assure_unique[] = $id;
                    $users[] = get_user_by( "ID", $id );
                }
            }
            foreach ( $users_ids as $index ){
                $id = $index[0];
                if ( $id && !in_array( $id, $assure_unique )){
                    $assure_unique[] = $id;
                    $users[] = get_user_by( "ID", $id );
                }
            }
        } else {

            $search_string = esc_attr( $search_string );
            $user_query = new WP_User_Query( array( 'blog_id' => 0 ) );
            $users = $user_query->get_results();

            //used cached updated needed data if available
            //@todo refresh the cache if not available
            $dispatcher_user_data = get_transient( 'dispatcher_user_data' );
            if ( $dispatcher_user_data ){
                foreach ( maybe_unserialize( $dispatcher_user_data ) as $uid => $val ){
                    $update_needed['user-' . $uid] = $val["number_update"];
                }
            } else {
                $ids = [];
                foreach ( $users as $a ){
                    $ids[] = 'user-' . $a->ID;
                }
                $user_ids = dt_array_to_sql( $ids );
                //phpcs:disable
                $update_needed_result = $wpdb->get_results("
                    SELECT pm.meta_value, COUNT(update_needed.post_id) as count
                    FROM $wpdb->postmeta pm
                    INNER JOIN $wpdb->postmeta as update_needed on (update_needed.post_id = pm.post_id and update_needed.meta_key = 'requires_update' and update_needed.meta_value = '1' )
                    WHERE pm.meta_key = 'assigned_to' and pm.meta_value IN ( $user_ids )
                    GROUP BY pm.meta_value
                ", ARRAY_A );
                //phpcs:enable
                foreach ( $update_needed_result as $up ){
                    $update_needed[$up["meta_value"]] = $up["count"];
                }
            }
        }
        $list = [];

        $workload_status_options = dt_get_site_custom_lists()["user_workload_status"] ?? [];

        foreach ( $users as $user ) {
            if ( user_can( $user, "access_contacts" ) ) {
                $u = [
                    "name" => $user->display_name,
                    "ID"   => $user->ID,
                    "avatar" => get_avatar_url( $user->ID, [ 'size' => '16' ] ),
                    "contact_id" => $this->get_contact_for_user( $user->ID )
                ];
                //extra information for the dispatcher
                if ( current_user_can( 'view_any_contacts' )) { // && !$get_all ){
                    $workload_status = get_user_option( 'workload_status', $user->ID );
                    if ( $workload_status && isset( $workload_status_options[ $workload_status ]["color"] ) ) {
                        $u['status_color'] = $workload_status_options[$workload_status]["color"];
                    }
                    $u["update_needed"] = $update_needed['user-' . $user->ID] ?? 0;
                }
                $list[] = $u;
            }
        }

        function asc_meth( $a, $b ) {
            $a["name"] = strtolower( $a["name"] );
            $b["name"] = strtolower( $b["name"] );
            return strcmp( $a["name"], $b["name"] );
        }

        usort( $list, "asc_meth" );
        return $list;
    }

    /**
     * @param $user->ID
     *
     * @return $contacts->post->ID|null
     */
    private static function get_contact_for_user( $user_id ){
        if ( !current_user_can( "access_contacts" )){
            return new WP_Error( 'no_permission', __( "Insufficient permissions" ), [ 'status' => 403 ] );
        }
        $contact_id = get_user_option( "corresponds_to_contact", $user_id );
        if ( !empty( $contact_id )){
            return $contact_id;
        }
        $args = [
            'post_type'  => 'contacts',
            'relation'   => 'AND',
            'meta_query' => [
                [
                    'key' => "corresponds_to_user",
                    "value" => $user_id
                ],
                [
                    'key' => "type",
                    "value" => "user"
                ],
            ],
        ];
        $contacts = new WP_Query( $args );
        if ( isset( $contacts->post->ID ) ){
            update_user_option( $user_id, "corresponds_to_contact", $contacts->post->ID );
            return $contacts->post->ID;
        } else {
            return null;
        }
    }

    /**
     * @param \WP_REST_Request $request
     *
     * @return array|WP_Error
     */
    public function get_people_groups( WP_REST_Request $request ) {
        if ( !current_user_can( "access_contacts" )){
            return new WP_Error( __FUNCTION__, "You do not have permission for this", [ 'status' => 403 ] );
        }
        //$people_groups = Disciple_Tools_people_groups::get_people_groups_compact( $search );
        global $wpdb;
        $locale = get_user_locale();
        $query_args = [
            'post_type' => 'peoplegroups',
            'nopaging'  => true,
        ];
        $query = new WP_Query( $query_args );
        $list = [];
        foreach ( $query->posts as $post ) {
            $translation = get_post_meta( $post->ID, $locale, true );
            if ($translation !== "") {
                $label = $translation;
            } else {
                $label = $post->post_title;
            }

            $list[] = [
            "ID" => $post->ID,
            "name" => $post->post_title,
            "label" => $label
            ];
        }
        $meta_query_args = [
            'post_type' => 'peoplegroups',
            'nopaging'  => true,
            'meta_query' => array(
                array(
                    'key' => $locale
                )
            ),
        ];

        $meta_query = new WP_Query( $meta_query_args );
        foreach ( $meta_query->posts as $post ) {
            $translation = get_post_meta( $post->ID, $locale, true );
            if ($translation !== "") {
                $label = $translation;
            } else {
                $label = $post->post_title;
            }
            $list[] = [
            "ID" => $post->ID,
            "name" => $post->post_title,
            "label" => $label
            ];
        }

        $total_found_posts = $query->found_posts + $meta_query->found_posts;

        return [
        "total" => $total_found_posts,
        "posts" => $list
        ];
    }

    /**
     * @param \WP_REST_Request $request
     *
     * @return array|WP_Error
     */
    public function get_locations( WP_REST_Request $request ) {
        if ( !user_can( get_current_user_id(), 'manage_dt' ) ) {
            return new WP_Error( __METHOD__, "Unable to retrieve the data.", array( 'status' => 400 ) );
        }
        global $wpdb;
        // phpcs:disable
        // WordPress.WP.PreparedSQL.NotPrepared
        $results = $wpdb->get_results("
                            SELECT grid_id, alt_name
                            FROM $wpdb->dt_location_grid
                        ", ARRAY_A );
        // phpcs:enable
        $prepared = [];
        foreach ( $results as $row ){
            $prepared[$row["grid_id"]] = $row["alt_name"];
        }
        return [
            'location_grid' => $prepared, //$location_grid,
            'total' => count($results)
        ];
    }

    public function mobile_app_error() {
        if ( $this->show_jwt_error ){
            $class = 'notice notice-error';
            $message = __( 'For the mobile app to work, please remove this plugin: JWT Authentication for WP-API ', 'sample-text-domain' );
            printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
        }
    }
    /**
     * Loads files needed by the plugin.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    private function includes() {
        global $class_already_loaded;
        if ( $class_already_loaded ){
            $this->show_jwt_error = true;

        }
        require_once( 'includes/admin/admin-menu-and-tabs.php' );
        require_once plugin_dir_path( __FILE__ ) . '/vendor/autoload.php';
        require_once( 'includes/functions.php' );
        new DT_Mobile_App_Plugin_Functions();
    }



    /**
     * Sets up globals.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    private function setup() {

        // Main plugin directory path and URI.
        $this->dir_path     = trailingslashit( plugin_dir_path( __FILE__ ) );
        $this->dir_uri      = trailingslashit( plugin_dir_url( __FILE__ ) );

        // Plugin directory paths.
        $this->includes_path      = trailingslashit( $this->dir_path . 'includes' );

        // Plugin directory URIs.
        $this->img_uri      = trailingslashit( $this->dir_uri . 'img' );

        // Admin and settings variables
        $this->token             = 'dt_mobile_app';
        $this->version             = '1.4';

        // sample rest api class
        require_once( 'includes/rest-api.php' );
        DT_Mobile_App_Endpoints::instance();
    }

    /**
     * Sets up main plugin actions and filters.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    private function setup_actions() {

        if ( is_admin() ) {
            // Check for plugin updates
            if ( !class_exists( 'Puc_v4_Factory' ) ) {
                require( get_template_directory() . '/dt-core/libraries/plugin-update-checker/plugin-update-checker.php' );
            }
            /**
             * Below is the publicly hosted .json file that carries the version information. This file can be hosted
             * anywhere as long as it is publicly accessible. You can download the version file listed below and use it as
             * a template.
             * Also, see the instructions for version updating to understand the steps involved.
             * @see https://github.com/DiscipleTools/disciple-tools-version-control/wiki/How-to-Update-the-Starter-Plugin
             */
            Puc_v4_Factory::buildUpdateChecker(
                'https://raw.githubusercontent.com/DiscipleTools/disciple-tools-version-control/master/disciple-tools-mobile-app-plugin-version-control.json',
                __FILE__,
                'disciple-tools-mobile-app-plugin'
            );
        }

        // Internationalize the text strings used.
        add_action( 'plugins_loaded', array( $this, 'i18n' ), 2 );
    }

    /**
     * Method that runs only when the plugin is activated.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    public static function activation() {

        // Confirm 'Administrator' has 'manage_dt' privilege. This is key in 'remote' configuration when
        // Disciple Tools theme is not installed, otherwise this will already have been installed by the Disciple Tools Theme
        $role = get_role( 'administrator' );
        if ( !empty( $role ) ) {
            $role->add_cap( 'manage_dt' ); // gives access to dt plugin options
        }

    }

    /**
     * Method that runs only when the plugin is deactivated.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    public static function deactivation() {
        delete_option( 'dismissed-dt-starter' );
    }

    /**
     * Loads the translation files.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    public function i18n() {
        load_plugin_textdomain( 'dt_mobile_app', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ). 'languages' );
    }

    /**
     * Magic method to output a string if trying to use the object as a string.
     *
     * @since  0.1
     * @access public
     * @return string
     */
    public function __toString() {
        return 'dt_mobile_app';
    }

    /**
     * Magic method to keep the object from being cloned.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    public function __clone() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Whoah, partner!', 'dt_mobile_app' ), '0.1' );
    }

    /**
     * Magic method to keep the object from being unserialized.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Whoah, partner!', 'dt_mobile_app' ), '0.1' );
    }

    /**
     * Magic method to prevent a fatal error when calling a method that doesn't exist.
     *
     * @since  0.1
     * @access public
     * @return null
     */
    public function __call( $method = '', $args = array() ) {
        // @codingStandardsIgnoreLine
        _doing_it_wrong( "dt_mobile_app::{$method}", esc_html__( 'Method does not exist.', 'dt_mobile_app' ), '0.1' );
        unset( $method, $args );
        return null;
    }
}
// end main plugin class

// Register activation hook.
register_activation_hook( __FILE__, [ 'DT_Mobile_App', 'activation' ] );
register_deactivation_hook( __FILE__, [ 'DT_Mobile_App', 'deactivation' ] );


function dt_mobile_app_hook_admin_notice() {
    global $dt_mobile_app_required_dt_theme_version;
    $wp_theme = wp_get_theme();
    $current_version = $wp_theme->version;
    $message = __( "'Disciple Tools - Mobile App Extension' plugin requires 'Disciple Tools' theme to work. Please activate 'Disciple Tools' theme or make sure it is latest version.", "dt_mobile" );
    if ( $wp_theme->get_template() === "disciple-tools-theme" ){
        $message .= sprintf( esc_html__( 'Current Disciple Tools version: %1$s, required version: %2$s', 'dt_mobile' ), esc_html( $current_version ), esc_html( $dt_mobile_app_required_dt_theme_version ) );
    }
    // Check if it's been dismissed...
    if ( ! get_option( 'dismissed-dt-mobile-app', false ) ) { ?>
        <div class="notice notice-error notice-dt-mobile-app is-dismissible" data-notice="dt-mobile-app">
            <p><?php echo esc_html( $message );?></p>
        </div>
        <script>
            jQuery(function($) {
                $( document ).on( 'click', '.notice-dt-mobile-app .notice-dismiss', function () {
                    $.ajax( ajaxurl, {
                        type: 'POST',
                        data: {
                            action: 'dismissed_notice_handler',
                            type: 'dt-mobile-app',
                            security: '<?php echo esc_html( wp_create_nonce( 'wp_rest_dismiss' ) ) ?>'
                        }
                    })
                });
            });
        </script>
    <?php }
}
/**
 * AJAX handler to store the state of dismissible notices.
 */
if ( !function_exists( "dt_hook_ajax_notice_handler" )){
    function dt_hook_ajax_notice_handler(){
        check_ajax_referer( 'wp_rest_dismiss', 'security' );
        if ( isset( $_POST["type"] ) ){
            $type = sanitize_text_field( wp_unslash( $_POST["type"] ) );
            update_option( 'dismissed-' . $type, true );
        }
    }
}

add_action( "plugins_loaded", function(){
    /** Setup key for JWT authentication */
    if ( !defined( 'JWT_AUTH_SECRET_KEY' ) ) {
        if ( get_option( "my_jwt_key" ) ) {
            define( 'JWT_AUTH_SECRET_KEY', get_option( "my_jwt_key" ) );
        } else {
            $iv = password_hash( random_bytes( 16 ), PASSWORD_DEFAULT );
            update_option( 'my_jwt_key', $iv );
            define( 'JWT_AUTH_SECRET_KEY', $iv );
        }
    }
});
