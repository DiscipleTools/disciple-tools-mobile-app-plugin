<?php
/**
 * Rest API example class
 */


class DT_Mobile_App_Endpoints
{
    public $permissions = [ 'access_contacts' ];

    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    public function __construct() {

        add_action( 'rest_api_init', [ $this, 'add_api_routes' ] );
    }

    public function has_permission(){
        $pass = false;
        foreach ( $this->permissions as $permission ){
            if ( current_user_can( $permission ) ){
                $pass = true;
            }
        }
        return $pass;
    }


    public function add_api_routes(){
        $namespace = 'dt-mobile-app/v1';

        register_rest_route(
            $namespace, 'location-data', [
                "methods" => "GET",
                "callback" => [ $this, 'get_location_data' ]
            ]
        );

        register_rest_route(
            $namespace, 'locations', [
                "methods" => "GET",
                "callback" => [ $this, 'get_locations' ],
            ]
        );
    }


    public function get_location_data() {
        global $wpdb;
        if ( !$this->has_permission() ) {
            return new WP_Error( __FUNCTION__, "You do not have permission for this", [ 'status' => 403 ] );
        }
        $max_date = $wpdb->get_var( "
            SELECT MAX(g.modification_date)
            FROM $wpdb->dt_location_grid g 
        " );
        return [
            "last_modified_date" => $max_date
        ];
    }

    public function get_locations( WP_REST_Request $request ) {
        if ( !$this->has_permission() ) {
            return new WP_Error( __FUNCTION__, "You do not have permission for this", [ 'status' => 403 ] );
        }
        global $wpdb;
        $results = $wpdb->get_results( "
           SELECT grid_id, alt_name
           FROM $wpdb->dt_location_grid
       ", ARRAY_A );

        $prepared = [];
        foreach ( $results as $row ) {
            $prepared[$row["grid_id"]] = $row["alt_name"];
        }
        $max_date = $wpdb->get_var( "
            SELECT MAX(g.modification_date)
            FROM $wpdb->dt_location_grid g 
        " );
        return [
            "last_modified_date" => $max_date,
            'location_grid' => $prepared, //$location_grid,
            'total' => count( $results )
        ];
    }
}
