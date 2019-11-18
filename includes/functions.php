<?php


class DT_Mobile_App_Plugin_Functions
{
    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    public function __construct() {
        add_filter( "dt_after_get_post_fields_filter", [ $this, "dt_filter_get_post" ], 1, 2 );
        add_filter( 'jwt_auth_token_before_dispatch', [ $this, "include_fields_in_login_endpoint" ], 10, 2 );
        add_action( 'dt_update_user', [ $this, 'dt_update_user' ], 10, 2 );
    }

    private function get_group_baptized( $group_id ){
        //get members who are baptized,
        //get group member count

        $member_count = get_post_meta( $group_id, "member_count", true );
        global $wpdb;
        $baptized = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) 
            FROM $wpdb->p2p as p2p
            INNER JOIN $wpdb->postmeta pm ON ( p2p.p2p_from = pm.post_id AND pm.meta_key = 'milestones' )
            WHERE p2p_type = 'contacts_to_groups' and p2p_to = %s
            AND pm.meta_value = 'milestone_baptized'
        ", $group_id ) );
        return [
            "member_count" => $member_count,
            "baptized" => $baptized
        ];
    }

    public function dt_filter_get_post( array $fields, string $post_type = "" ){
        if ( $post_type === "groups" ){
            $counts = $this->get_group_baptized( $fields["ID"] );
            $fields["baptized_member_count"] = $counts["baptized"];
            foreach ( $fields["parent_groups"] as &$g ){
                $counts = $this->get_group_baptized( $g["ID"] );
                $g["baptized_member_count"] = $counts["baptized"];
                $g["member_count"] = $counts["member_count"];
            }
            foreach ( $fields["child_groups"] as &$g ){
                $counts = $this->get_group_baptized( $g["ID"] );
                $g["baptized_member_count"] = $counts["baptized"];
                $g["member_count"] = $counts["member_count"];
            }
            foreach ( $fields["peer_groups"] as &$g ){
                $counts = $this->get_group_baptized( $g["ID"] );
                $g["baptized_member_count"] = $counts["baptized"];
                $g["member_count"] = $counts["member_count"];
            }
        }

        return $fields;
    }

    public function include_fields_in_login_endpoint( $data, $user ){
        $data["locale"] = get_user_locale( $user->ID );
        return $data;
    }

    public function dt_update_user( $user, $fields ){
        if ( isset( $fields["add_push_token"] ) ) {
            $push_tokens = get_user_option( $user->ID, 'dt_push_tokens' );
            if ( $push_tokens === false ){
                $push_tokens = [];
            }
            if ( !in_array( $push_tokens, $fields["add_push_token"] ) ) {
                $push_tokens[] = $fields["add_push_token"];
                $update = update_user_option( $user->ID, "dt_push_tokens", $push_tokens );
                if ( $update === false ){
                    throw new Exception( 'Something went wrong updating the push notification token', 500 );
                }
            }
        }
    }
}
