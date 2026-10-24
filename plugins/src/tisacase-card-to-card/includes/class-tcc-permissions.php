<?php
defined( 'ABSPATH' ) || exit;
/** Only the plugin's financial-review capability is managed here; never WordPress roles or order-edit rights. */
class TCC_Permissions {
    const CAP = 'tcc_review_payments';
    public static function available_roles() { return wp_roles()->roles; }
    public static function selected_roles() {
        $roles = self::available_roles();
        $selected = (array) get_option( 'tcc_reviewer_roles', array( 'administrator' ) );
        // Reflect an existing grant from a role editor instead of displaying it as unchecked.
        foreach ( $roles as $slug => $role ) { if ( ! empty( $role['capabilities'][self::CAP] ) ) { $selected[] = $slug; } }
        $selected = array_filter( $selected, function ( $slug ) use ( $roles ) { return is_string( $slug ) && isset( $roles[$slug] ); } );
        if ( isset( $roles['administrator'] ) ) { $selected[] = 'administrator'; }
        return array_values( array_unique( $selected ) );
    }
    public static function validate_roles( $input ) {
        if ( ! is_array( $input ) ) { throw new InvalidArgumentException( 'فهرست نقش‌های بررسی معتبر نیست؛ تنظیمات ذخیره نشد.' ); }
        $available = self::available_roles(); $roles = array();
        foreach ( $input as $slug ) {
            if ( ! is_string( $slug ) || ! isset( $available[$slug] ) ) { throw new InvalidArgumentException( 'یکی از نقش‌های بررسی معتبر نیست؛ تنظیمات ذخیره نشد.' ); }
            $roles[] = $slug;
        }
        if ( isset( $available['administrator'] ) ) { $roles[] = 'administrator'; }
        return array_values( array_unique( $roles ) );
    }
    public static function requested_roles( $post ) {
        // An already-open v1.0/1.1 settings page must not erase role grants on upgrade.
        if ( ! isset( $post['reviewer_roles_present'] ) ) { return self::selected_roles(); }
        if ( ! is_scalar( $post['reviewer_roles_present'] ) || '1' !== (string) $post['reviewer_roles_present'] ) { throw new InvalidArgumentException( 'فرم دسترسی معتبر نیست؛ صفحه را دوباره باز کنید.' ); }
        return self::validate_roles( isset( $post['reviewer_roles'] ) ? $post['reviewer_roles'] : array() );
    }
    public static function validate_users( $ids ) {
        $out = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        foreach ( $out as $id ) { if ( ! get_userdata( $id ) ) { throw new InvalidArgumentException( 'یکی از شناسه‌های بازبین معتبر نیست؛ تنظیمات ذخیره نشد.' ); } }
        return $out;
    }
    /** Called only after the settings controller's authorization, nonce and audit insert. */
    public static function assign( $roles, $reviewers ) {
        $roles = self::validate_roles( $roles ); $reviewers = self::validate_users( $reviewers );
        foreach ( self::available_roles() as $slug => $details ) {
            $role = get_role( $slug ); if ( ! $role ) { continue; }
            if ( in_array( $slug, $roles, true ) ) { $role->add_cap( self::CAP ); }
            elseif ( ! empty( $details['capabilities'][self::CAP] ) ) { $role->remove_cap( self::CAP ); }
        }
        foreach ( array_diff( (array) get_option( 'tcc_reviewers', array() ), $reviewers ) as $id ) { $user = get_userdata( $id ); if ( $user ) { $user->remove_cap( self::CAP ); } }
        foreach ( $reviewers as $id ) { get_userdata( $id )->add_cap( self::CAP ); }
        update_option( 'tcc_reviewer_roles', $roles, false );
        update_option( 'tcc_reviewers', $reviewers, false );
    }
    public static function restore() {
        foreach ( self::selected_roles() as $slug ) { $role = get_role( $slug ); if ( $role ) { $role->add_cap( self::CAP ); } }
        foreach ( (array) get_option( 'tcc_reviewers', array() ) as $id ) { $user = get_userdata( $id ); if ( $user ) { $user->add_cap( self::CAP ); } }
    }
    public static function uninstall() {
        // Keep assignments and all financial data, but remove this plugin's runtime capability.
        foreach ( self::available_roles() as $slug => $details ) { $role = get_role( $slug ); if ( $role ) { $role->remove_cap( self::CAP ); } }
        foreach ( (array) get_option( 'tcc_reviewers', array() ) as $id ) { $user = get_userdata( $id ); if ( $user ) { $user->remove_cap( self::CAP ); } }
    }
}
