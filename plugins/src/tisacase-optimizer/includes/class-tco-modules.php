<?php
/**
 * رجیستری ماژول‌های مرکز بهینه‌سازی تیساکیس.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Modules' ) ) {

	/**
	 * نگهداری و بازیابی ماژول‌های ثبت‌شده.
	 */
	final class TisaCase_Optimizer_Modules {

		/** @var array<string,TisaCase_Optimizer_Module_Interface>|null */
		private static $modules = null;

		/**
		 * همهٔ ماژول‌های فعال به ترتیب نمایش در نوار ناوبری.
		 *
		 * @return array<string,TisaCase_Optimizer_Module_Interface>
		 */
		public static function all() {
			if ( null !== self::$modules ) {
				return self::$modules;
			}

			$defaults = array(
				new TisaCase_Optimizer_Module_Images(),
				new TisaCase_Optimizer_Module_Vault(),
				new TisaCase_Optimizer_Module_Database(),
				new TisaCase_Optimizer_Module_Frontend(),
				new TisaCase_Optimizer_Module_System(),
			);

			/**
			 * فیلتر افزودن یا ویرایش ماژول‌های مرکز بهینه‌سازی تیساکیس.
			 *
			 * @param array<int,TisaCase_Optimizer_Module_Interface> $defaults فهرست ماژول‌ها.
			 */
			$registered = apply_filters( 'tisacase_optimizer_modules', $defaults );

			self::$modules = array();
			foreach ( (array) $registered as $module ) {
				if ( $module instanceof TisaCase_Optimizer_Module_Interface ) {
					self::$modules[ $module->id() ] = $module;
				}
			}

			return self::$modules;
		}

		/**
		 * دریافت یک ماژول با شناسه.
		 *
		 * @param string $id شناسهٔ ماژول.
		 * @return TisaCase_Optimizer_Module_Interface|null
		 */
		public static function get( $id ) {
			$all = self::all();
			$id  = sanitize_key( (string) $id );
			return isset( $all[ $id ] ) ? $all[ $id ] : null;
		}
	}
}
