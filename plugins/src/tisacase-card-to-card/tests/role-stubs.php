<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
/** Small WordPress role/user doubles. These are not a WordPress capability-system integration test. */
class TCC_Test_Roles {
    public $roles;
    public function __construct() {
        $this->roles = array(
            'administrator' => array( 'name' => 'مدیر سایت', 'capabilities' => array( 'read' => true, 'manage_options' => true, 'edit_shop_orders' => true, 'tcc_review_payments' => true ) ),
            'shop_manager' => array( 'name' => 'مدیر فروشگاه', 'capabilities' => array( 'read' => true, 'manage_woocommerce' => true, 'edit_shop_orders' => true ) ),
            'editor' => array( 'name' => 'ویرایشگر', 'capabilities' => array( 'read' => true, 'edit_posts' => true ) ),
            'customer' => array( 'name' => 'مشتری', 'capabilities' => array( 'read' => true ) ),
            'subscriber' => array( 'name' => 'مشترک', 'capabilities' => array( 'read' => true ) ),
            'finance' => array( 'name' => 'مسئول مالی سفارشی', 'capabilities' => array( 'read' => true, 'edit_shop_orders' => true ) ),
        );
    }
}
class TCC_Test_Role {
    public $capabilities;
    public function __construct( $slug ) { $this->capabilities =& wp_roles()->roles[$slug]['capabilities']; }
    public function add_cap( $cap, $grant = true ) { $this->capabilities[$cap] = $grant; }
    public function remove_cap( $cap ) { unset( $this->capabilities[$cap] ); }
}
function wp_roles() { if ( empty( $GLOBALS['test_roles'] ) ) { $GLOBALS['test_roles'] = new TCC_Test_Roles(); } return $GLOBALS['test_roles']; }
function get_role( $slug ) { return isset( wp_roles()->roles[$slug] ) ? new TCC_Test_Role( $slug ) : null; }
function translate_user_role( $name ) { return $name; }
class TCC_Test_User {
    public $ID; public $display_name = 'Test reviewer'; public $roles; public $caps = array();
    public function __construct( $id, $roles = array() ) { $this->ID = $id; $this->roles = $roles; }
    public function add_cap( $cap, $grant = true ) { $this->caps[$cap] = $grant; }
    public function remove_cap( $cap ) { unset( $this->caps[$cap] ); }
    public function has_cap( $cap ) {
        $caps = array(); foreach ( $this->roles as $slug ) { $role = get_role( $slug ); if ( $role ) { $caps = array_merge( $caps, $role->capabilities ); } }
        $caps = array_merge( $caps, $this->caps ); return ! empty( $caps[$cap] );
    }
}
function tcc_test_user( $id ) {
    if ( isset( $GLOBALS['test_users'][$id] ) ) { return $GLOBALS['test_users'][$id]; }
    if ( ! $id || ! empty( $GLOBALS['strict_test_users'] ) ) { return false; }
    $GLOBALS['test_users'][$id] = new TCC_Test_User( (int) $id ); return $GLOBALS['test_users'][$id];
}
function tcc_test_current_user_can( $cap, ...$args ) {
    $user = get_userdata( get_current_user_id() ); if ( ! $user ) { return false; }
    if ( 'edit_shop_order' === $cap ) {
        if ( in_array( (int) ( $args[0] ?? 0 ), $GLOBALS['denied_test_orders'] ?? array(), true ) ) { return false; }
        $cap = 'edit_shop_orders';
    }
    return $user->has_cap( $cap );
}
