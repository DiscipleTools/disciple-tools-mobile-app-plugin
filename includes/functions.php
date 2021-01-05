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
        add_action( 'dt_get_site_notification_options', [ $this, "dt_get_site_notification_options" ], 10, 1 );
        add_action( 'send_notification_on_channels', [ $this, "send_notification_on_channels" ], 20, 4 );

        add_filter( 'dt_post_type_modules', [ $this, 'dt_post_type_modules' ], 1000, 1 );
    }

    public function dt_post_type_modules( $modules ){
        if ( isset( $modules["access_module"] ) && !empty( $modules["access_module"]["enabled"] ) ){
            $modules["access_module"]["locked"] = true;
        }
        if ( isset( $modules["dmm_module"] ) && !empty( $modules["dmm_module"]["enabled"] ) ){
            $modules["dmm_module"]["locked"] = true;
        }
        return $modules;
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
                $g["is_church"] = $this->is_church( $g["ID"] );
            }
            foreach ( $fields["child_groups"] as &$g ){
                $counts = $this->get_group_baptized( $g["ID"] );
                $g["baptized_member_count"] = $counts["baptized"];
                $g["member_count"] = $counts["member_count"];
                $g["is_church"] = $this->is_church( $g["ID"] );
            }
            foreach ( $fields["peer_groups"] as &$g ){
                $counts = $this->get_group_baptized( $g["ID"] );
                $g["baptized_member_count"] = $counts["baptized"];
                $g["member_count"] = $counts["member_count"];
                $g["is_church"] = $this->is_church( $g["ID"] );
            }
        }

        return $fields;
    }

    private function is_church( $post_id ){
        $health_metrics = get_post_meta( $post_id, 'health_metrics' );
        $group_type = get_post_meta( $post_id, 'group_type', true );
        return $group_type === "church" || in_array( 'church_commitment', $health_metrics );
    }


    public function include_fields_in_login_endpoint( $data, $user ){
        $data["locale"] = get_user_locale( $user->ID );
        return $data;
    }

    public function dt_update_user( $user, $fields ){
        if ( isset( $fields["add_push_token"] ) ) {
            $push_tokens = get_user_option( 'dt_push_tokens', $user->ID );
            if ( $push_tokens === false ){
                $push_tokens = [];
            }
            if ( isset( $fields["add_push_token"]["token"], $fields["add_push_token"]["device_id"] ) ){
                $push_tokens[$fields["add_push_token"]["device_id"]] = [
                    "token" => $fields["add_push_token"]["token"]
                ];
                $update = update_user_option( $user->ID, "dt_push_tokens", $push_tokens );
                if ( $update === false ){
                    throw new Exception( 'Something went wrong updating the push notification token', 500 );
                }
            } else {
                throw new Exception( 'Required "token" and "device_id" field', 400 );
            }
        }
        if ( isset( $fields["remove_push_token"] ) ) {
            $push_tokens = get_user_option( 'dt_push_tokens', $user->ID );
            if ( $push_tokens === false ){
                $push_tokens = [];
            }
            if ( isset( $fields["remove_push_token"]["device_id"] ) && isset( $push_tokens[$fields["remove_push_token"]["device_id"]] ) ) {
                unset( $push_tokens[$fields["remove_push_token"]["device_id"]] );
                update_user_option( $user->ID, "dt_push_tokens", $push_tokens );
            }
        }
    }

    public static function dt_get_site_notification_options( $notifications ){
        if ( !isset( $notifications["channels"]["push_notifications"] ) ) {
            $notifications["channels"]["push_notifications"] = [
                "label" => __( "Push Notifications", "disciple_tools" )
            ];
            foreach ( $notifications["types"] as $type_key => $type_value ){
                $notifications["types"][$type_key]["push_notifications"] = false;
            }
        }
        return $notifications;
    }

    /**
     * Called when any notification is sent from the theme
     */
    public static function send_notification_on_channels( $user_id, $notification, $notification_type, $already_sent = [] ) {
        $user = get_user_by( "ID", $user_id );
        //if the user is receiving notifications of this type
        if ( $user && dt_user_notification_is_enabled( $notification_type, 'push_notifications', null, $user_id ) ) {
            $message = Disciple_Tools_Notifications::get_notification_message_html( $notification, false );
            $push_tokens = get_user_option( 'dt_push_tokens', $user->ID ) ?? [];
            if ( !$push_tokens ){
                $push_tokens = [];
            }
            //make sure we don't have old format
            foreach ( $push_tokens as $device_id => $value ){
                if ( is_numeric( $device_id ) ){
                    unset( $push_tokens[$device_id] );
                    update_user_option( $user->ID, "dt_push_tokens", $push_tokens );
                }
            };
            if ( empty( $push_tokens ) ) {
                return;
            }
            $expo = \ExponentPhpSDK\Expo::normalSetup();
            $channel = $notification_type . $user->ID . time();
            foreach ( $push_tokens as $device_id => $value ){
                // Subscribe the recipient to the server
                if ( isset( $value["token"] ) ) {
                    try {
                        $expo->subscribe( $channel, $value["token"] );
                    } catch ( ExponentPhpSDK\Exceptions\ExpoRegistrarException $e ){
                        if ( $e->getCode() === 422 ){
                            unset( $push_tokens[$device_id] );
                            update_user_option( $user->ID, "dt_push_tokens", $push_tokens );
                        }
                        if ( $e->getMessage() === "Could not register the token provided for the interest, due to internal error." ){
                            dt_write_log( $e );
                            return;
                        }
                    } catch ( Exception $e ){
                        dt_write_log( $e );
                    }
                }
            }
            // Build the notification data
            $notification = [ 'body' => $message ];

            // Notify an interest with a notification
            try {
                $response = $expo->notify( $channel, $notification );
                if ( is_array( $response )){
                    foreach ( $response as $index => $sent ) {
                        if ( isset( $sent["status"], $sent["details"]["error"] ) && $sent["status"] === "error" && $sent["details"]["error"] === "DeviceNotRegistered" ){
                            foreach ( array_keys( $push_tokens ) as $key_index => $key ){
                                if ( $index === $key_index ){
                                    unset( $push_tokens[$key] );
                                    update_user_option( $user->ID, "dt_push_tokens", $push_tokens );
                                }
                            }
                        }
                    }
                }
            } catch (ExponentPhpSDK\Exceptions\ExpoException $e){
                dt_write_log( $e );
                //if the notification fails completely and the device is no longer registered, remove the token.
                if ( $e->getMessage() === "The recipient device is not registered with FCM." ){
                    update_user_option( $user->ID, "dt_push_tokens", [] );
                }
            } catch ( ExponentPhpSDK\Exceptions\UnexpectedResponseException $e ){
                dt_write_log( $e );
            } catch ( Exception $e ){
                dt_write_log( $e );
            }
        }
    }
}
