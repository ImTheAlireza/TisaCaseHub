<?php
/**
 * لایهٔ ذخیره‌سازی: جدول اجراها، جدول لاگ تغییرات، انتخاب/شمارش SQL-پایهٔ محصولات هدف.
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCP_DB' ) ) {

	final class TCP_DB {

		const RUN_TABLE = 'tcp_runs';
		const LOG_TABLE = 'tcp_log';

		/** یکنواخت‌کردن کلیدهای متای محصول برای سادگی. */
		const META_REGULAR = '_regular_price';
		const META_SALE    = '_sale_price';

		/** زمان به‌ثانیه برای mark stale. */
		public static function now() {
			return current_time( 'mysql' );
		}

		public static function table_runs() {
			global $wpdb;
			return $wpdb->prefix . self::RUN_TABLE;
		}

		public static function table_log() {
			global $wpdb;
			return $wpdb->prefix . self::LOG_TABLE;
		}

		/* -----------------------------------------------------------------
		 * نصب / ارتقا
		 * --------------------------------------------------------------- */

		public static function activate() {
			self::install();
		}

		public static function maybe_install() {
			$installed = get_option( 'tcp_db_version', '' );
			if ( $installed !== TCP_Settings::DB_VERSION ) {
				self::install();
				update_option( 'tcp_db_version', TCP_Settings::DB_VERSION, false );
			}
		}

		public static function install() {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$collate = $wpdb->get_charset_collate();
			$runs    = self::table_runs();
			$log     = self::table_log();

			$sql_runs = "CREATE TABLE {$runs} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				type VARCHAR(20) NOT NULL DEFAULT 'bulk',
				status VARCHAR(20) NOT NULL DEFAULT 'queued',
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				operation VARCHAR(60) NOT NULL DEFAULT '',
				value DECIMAL(18,6) NULL,
				args_hash CHAR(64) NOT NULL DEFAULT '',
				args LONGTEXT NULL,
				page INT UNSIGNED NOT NULL DEFAULT 0,
				total_pages INT UNSIGNED NOT NULL DEFAULT 0,
				total_parents BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				count_updated BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				count_skipped BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				count_errors BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				parent_run_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				last_error VARCHAR(255) NOT NULL DEFAULT '',
				created_at DATETIME NULL,
				updated_at DATETIME NULL,
				finished_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY type (type),
				KEY user_id (user_id),
				KEY parent_run_id (parent_run_id),
				KEY updated_at (updated_at)
			) {$collate};";

			$sql_log = "CREATE TABLE {$log} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				run_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				parent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				object_type VARCHAR(20) NOT NULL DEFAULT '',
				before_value VARCHAR(64) NOT NULL DEFAULT '',
				after_value VARCHAR(64) NOT NULL DEFAULT '',
				created_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY run_id (run_id),
				KEY object_id (object_id),
				KEY parent_id (parent_id)
			) {$collate};";

			dbDelta( $sql_runs );
			dbDelta( $sql_log );
		}

		public static function cron_cleanup() {
			$days = absint( TCP_Settings::setting( 'retention_days' ) );
			if ( $days < 1 ) {
				return;
			}
			global $wpdb;
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}tisacase_bpm_log WHERE created_at < %s", $cutoff ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}tisacase_bpm_runs WHERE created_at < %s AND status NOT IN ('running','queued')", $cutoff ) );
		}

		/* -----------------------------------------------------------------
		 * اجراها (runs)
		 * --------------------------------------------------------------- */

		/**
		 * @param array $fields ستون‌های قابل‌نوشتن.
		 * @return int|0 شناسهٔ سطر جدید.
		 */
		public static function create_run( $fields ) {
			global $wpdb;
			$defaults = array(
				'type' => 'bulk', 'status' => 'running', 'user_id' => 0, 'operation' => '',
				'value' => null, 'args_hash' => '', 'args' => null, 'page' => 0,
				'total_pages' => 0, 'total_parents' => 0,
				'count_updated' => 0, 'count_skipped' => 0, 'count_errors' => 0,
				'parent_run_id' => 0, 'last_error' => '', 'created_at' => null,
				'updated_at' => null, 'finished_at' => null,
			);
			$fields    = wp_parse_args( $fields, $defaults );
			$fields['created_at'] = $fields['created_at'] ? $fields['created_at'] : self::now();
			$fields['updated_at'] = $fields['updated_at'] ? $fields['updated_at'] : $fields['created_at'];
			$fields['value']      = null === $fields['value'] ? null : number_format( (float) $fields['value'], 6, '.', '' );

			$ok = $wpdb->insert(
				self::table_runs(),
				array(
					'type' => $fields['type'], 'status' => $fields['status'], 'user_id' => absint( $fields['user_id'] ),
					'operation' => $fields['operation'], 'value' => $fields['value'], 'args_hash' => $fields['args_hash'],
					'args' => $fields['args'], 'page' => absint( $fields['page'] ),
					'total_pages' => absint( $fields['total_pages'] ), 'total_parents' => absint( $fields['total_parents'] ),
					'count_updated' => absint( $fields['count_updated'] ), 'count_skipped' => absint( $fields['count_skipped'] ),
					'count_errors' => absint( $fields['count_errors'] ), 'parent_run_id' => absint( $fields['parent_run_id'] ),
					'last_error' => mb_substr( $fields['last_error'], 0, 255 ), 'created_at' => $fields['created_at'],
					'updated_at' => $fields['updated_at'], 'finished_at' => $fields['finished_at'],
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
			return $ok ? (int) $wpdb->insert_id : 0;
		}

		public static function update_run( $run_id, $fields ) {
			global $wpdb;
			$set = array();
			$fmt = array();
			$allowed = array(
				'status', 'operation', 'args', 'page', 'total_pages', 'total_parents', 'count_updated',
				'count_skipped', 'count_errors', 'parent_run_id', 'last_error', 'updated_at', 'finished_at',
			);
			foreach ( $allowed as $key ) {
				if ( ! array_key_exists( $key, $fields ) ) {
					continue;
				}
				$set[ $key ] = $fields[ $key ];
				$fmt[]       = is_int( $fields[ $key ] ) ? '%d' : '%s';
			}
			if ( empty( $set ) ) {
				return false;
			}
			return false !== $wpdb->update( self::table_runs(), $set, array( 'id' => absint( $run_id ) ), $fmt, array( '%d' ) );
		}

		public static function get_run( $run_id ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_runs() . ' WHERE id = %d', absint( $run_id ) ), ARRAY_A );
			if ( ! $row ) {
				return null;
			}
			$row['value'] = null === $row['value'] ? null : (float) $row['value'];
			return $row;
		}

		public static function delete_run( $run_id ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_runs() . ' WHERE id = %d', absint( $run_id ) ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_log() . ' WHERE run_id = %d', absint( $run_id ) ) );
		}

		/**
		 * آخرین اجراهای ثبت‌شده برای صفحهٔ «گزارش اجراها».
		 */
		public static function list_runs( $limit = 100 ) {
			global $wpdb;
			$limit = max( 1, min( 500, absint( $limit ) ) );
			$rows  = $wpdb->get_results( 'SELECT * FROM ' . self::table_runs() . " ORDER BY id DESC LIMIT {$limit}", ARRAY_A );
			$users = array();
			foreach ( (array) $rows as $i => $r ) {
				$uid = absint( $r['user_id'] );
				if ( $uid && ! isset( $users[ $uid ] ) ) {
					$u = get_userdata( $uid );
					$users[ $uid ] = $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : (string) $uid;
				}
				$rows[ $i ]['user_label'] = isset( $users[ $uid ] ) ? $users[ $uid ] : '—';
				$rows[ $i ]['value']      = null === $r['value'] ? null : (float) $r['value'];
			}
			return $rows;
		}

		/**
		 * آیا اجرای فعال (running) دیگری غیر از $except_id هست که تازه باشد؟
		 * اجراهای قدیمی‌تر از lock_minutes را interrupted می‌کند.
		 *
		 * @return array{active:?array, stale:int} سطر فعالِ مزاحم اگر تازه باشد.
		 */
		public static function busy_slot( $except_id = 0 ) {
			global $wpdb;
			$except = absint( $except_id );
			$lock   = TCP_Settings::lock_minutes();
			$table  = self::table_runs();

			// اجراهای running که مدت‌ها به‌روز نشده‌اند → interrupted (امکان ادامه/شروع دوباره).
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $lock * MINUTE_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='interrupted' WHERE status='running' AND updated_at < %s AND id <> %d", $cutoff, $except ) );

			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'running' AND id <> %d ORDER BY id DESC LIMIT 1",
				$except
			), ARRAY_A );

			return array(
				'active' => $row ? $row : null,
				'stale'  => 0,
			);
		}

		public static function count_queued() {
			global $wpdb;
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_runs() . " WHERE type='scheduled' AND status='queued'" );
		}

		public static function claim_queued_run() {
			global $wpdb;
			$table = self::table_runs();
			$id    = $wpdb->get_var( "SELECT id FROM {$table} WHERE type='scheduled' AND status='queued' ORDER BY id ASC LIMIT 1" );
			if ( ! $id ) {
				return null;
			}
			$now = self::now();
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET status='running', updated_at=%s WHERE id=%d AND status='queued'",
				$now,
				$id
			) );
			// اگر تیک دیگری همین لحظه این اجرا را گرفته باشد، ادامه نده.
			if ( 1 !== (int) $wpdb->rows_affected ) {
				return null;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND status='running'", absint( $id ) ), ARRAY_A );
			return $row ? $row : null;
		}

		/* -----------------------------------------------------------------
		 * لاگ تغییرات
		 * --------------------------------------------------------------- */

		public static function insert_log( $run_id, $rows ) {
			if ( empty( $rows ) || ! TCP_Settings::logging_enabled() ) {
				return;
			}
			global $wpdb;
			$table = self::table_log();
			$run_id = absint( $run_id );
			$now    = self::now();
			$values = array();
			foreach ( (array) $rows as $r ) {
				$values[] = $wpdb->prepare(
					'( %d, %d, %d, %s, %s, %s, %s )',
					$run_id,
					absint( isset( $r['parent_id'] ) ? $r['parent_id'] : 0 ),
					absint( isset( $r['object_id'] ) ? $r['object_id'] : 0 ),
					isset( $r['object_type'] ) ? $r['object_type'] : '',
					isset( $r['before'] ) ? (string) $r['before'] : '',
					isset( $r['after'] ) ? (string) $r['after'] : '',
					$now
				);
			}
			if ( empty( $values ) ) {
				return;
			}
			$wpdb->query( 'INSERT INTO ' . $table . ' (run_id,parent_id,object_id,object_type,before_value,after_value,created_at) VALUES ' . implode( ',', $values ) );
		}

		public static function count_log( $run_id ) {
			global $wpdb;
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_log() . ' WHERE run_id=%d', absint( $run_id ) ) );
		}

		public static function get_log_page( $run_id, $offset, $limit ) {
			global $wpdb;
			return (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . self::table_log() . ' WHERE run_id=%d ORDER BY id ASC LIMIT %d OFFSET %d', absint( $run_id ), absint( $limit ), absint( $offset ) ),
				ARRAY_A
			);
		}

		/* -----------------------------------------------------------------
		 * انتخاب محصولات هدف + فیلترها (SQL-پایه برای مقیاس‌پذیری)
		 * --------------------------------------------------------------- */

		/**
		 * فهرست شناسهٔ محصولات مادر هدف با اعمال همهٔ فیلترها.
		 * برای target=products ترتیب انتخاب کاربر و برای category ترتیب ID صعودی.
		 */
		public static function selection_parent_ids( $args ) {
			$ids = array();
			if ( 'products' === $args['target_type'] ) {
				$ids = array_values( array_filter( array_map( 'absint', (array) $args['product_ids'] ) ) );
				// فیلتر وضعیت و نوع محصول روی انتخاب مستقیم هم اعمال می‌شود (سازگار با مسیر دسته‌بندی).
				$ids = self::filter_statuses( $ids, self::statuses( $args ) );
				$ids = self::filter_types( $ids, (array) $args['filters']['types'] );
			} else {
				$cat_ids = array_values( array_filter( array_map( 'absint', (array) $args['category_ids'] ) ) );
				if ( empty( $cat_ids ) ) {
					return array();
				}
				$tax_query = array(
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => $cat_ids,
						'include_children' => ! empty( $args['include_children'] ),
						'operator'         => 'IN',
					),
				);
				$type_filter = self::type_tax_query( $args );
				if ( $type_filter ) {
					$tax_query[] = $type_filter;
				}
				$q = new WP_Query( array(
					'post_type'              => 'product',
					'post_status'            => self::statuses( $args ),
					'fields'                 => 'ids',
					'posts_per_page'         => -1,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => $tax_query,
				) );
				$ids = array_map( 'absint', $q->posts );
				sort( $ids, SORT_NUMERIC );
			}

			if ( empty( $ids ) ) {
				return array();
			}

			return self::apply_presence_filters( $ids, $args['operation'], $args['filters'] );
		}

		private static function statuses( $args ) {
			$list = (array) ( isset( $args['filters']['statuses'] ) ? $args['filters']['statuses'] : array() );
			if ( empty( $list ) ) {
				return array( 'publish', 'private', 'draft', 'pending' );
			}
			$allowed = array( 'publish', 'private', 'draft', 'pending', 'future' );
			return array_values( array_intersect( $allowed, $list ) );
		}

		private static function type_tax_query( $args ) {
			$types = array_values( array_filter( array_map( 'sanitize_key', (array) $args['filters']['types'] ) ) );
			if ( empty( $types ) ) {
				return null;
			}
			return array(
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => $types,
				'operator' => 'IN',
			);
		}

		/** محدودکردن به نوع‌های محصول انتخاب‌شده؛ محصول بدون برچسب نوع = ساده در نظر گرفته می‌شود. */
		private static function filter_types( $ids, $types ) {
			if ( empty( $ids ) || empty( $types ) ) {
				return $ids;
			}
			$allowed = array_flip( array_values( array_filter( array_map( 'sanitize_key', $types ) ) ) );
			$map     = self::product_type_map( $ids );
			$out     = array();
			foreach ( $ids as $id ) {
				$t = isset( $map[ $id ] ) ? $map[ $id ] : 'simple';
				if ( isset( $allowed[ $t ] ) ) {
					$out[] = $id;
				}
			}
			return $out;
		}

		private static function filter_statuses( $ids, $statuses ) {
			if ( empty( $ids ) ) {
				return array();
			}
			global $wpdb;
			$ids_str = implode( ',', $ids );
			$in      = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";
			$rows    = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$ids_str}) AND post_type='product' AND post_status IN ({$in})"
			);
			$out = array_map( 'absint', $rows );
			// ترتیب ورودی حفظ شود.
			$lookup = array_flip( $out );
			$ordered = array();
			foreach ( $ids as $id ) {
				if ( isset( $lookup[ $id ] ) ) {
					$ordered[] = $id;
				}
			}
			return $ordered;
		}

		/**
		 * اعمال فیلترهای حضور متا در سطح محصول مادر:
		 * only_sale / only_wholesale / بازهٔ قیمت عادی — روی خود مادر یا هر وری‌شن.
		 */
		private static function apply_presence_filters( $ids, $operation, $filters ) {
			if ( empty( $ids ) ) {
				return array();
			}
			$filters = (array) $filters;
			$out     = $ids;

			if ( ! empty( $filters['only_sale'] ) ) {
				$out = self::ids_having_meta_value( $out, self::META_SALE, 'nonempty' );
			}
			if ( ! empty( $filters['only_wholesale'] ) ) {
				$out = self::ids_having_meta_value( $out, TCP_WHOLESALE_META, 'positive' );
			}
			$min = isset( $filters['price_min'] ) && '' !== $filters['price_min'] && null !== $filters['price_min'] ? (float) $filters['price_min'] : null;
			$max = isset( $filters['price_max'] ) && '' !== $filters['price_max'] && null !== $filters['price_max'] ? (float) $filters['price_max'] : null;
			if ( null !== $min || null !== $max ) {
				$out = self::ids_in_price_range( $out, $min, $max );
			}
			return array_values( array_unique( array_map( 'absint', $out ) ) );
		}

		private static function ids_having_meta_value( $ids, $meta_key, $kind ) {
			if ( empty( $ids ) ) {
				return array();
			}
			global $wpdb;
			$meta_key = esc_sql( $meta_key );
			$ids_str  = implode( ',', $ids );
			$pred     = ( 'positive' === $kind )
				? "pm.meta_value <> '' AND CAST(pm.meta_value AS DECIMAL(20,4)) > 0"
				: "pm.meta_value <> ''";
			$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				WHERE p.ID IN ({$ids_str})
				AND (
					EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = '{$meta_key}' AND {$pred} )
					OR EXISTS (
						SELECT 1 FROM {$wpdb->posts} v
						INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = v.ID
						WHERE v.post_type = 'product_variation'
						  AND v.post_status NOT IN ('trash','auto-draft')
						  AND v.post_parent = p.ID
						  AND pm.meta_key = '{$meta_key}' AND {$pred}
					)
				)";
			$found = array_flip( array_map( 'absint', $wpdb->get_col( $sql ) ) );
			$out   = array();
			foreach ( $ids as $id ) {
				if ( isset( $found[ $id ] ) ) {
					$out[] = $id;
				}
			}
			return $out;
		}

		private static function ids_in_price_range( $ids, $min, $max ) {
			if ( empty( $ids ) ) {
				return array();
			}
			global $wpdb;
			$ids_str = implode( ',', $ids );
			$lo      = null !== $min ? (float) $min : 0;
			$hi      = null !== $max ? (float) $max : 1e14;
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				WHERE p.ID IN ({$ids_str})
				AND (
					EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} pm
						WHERE pm.post_id = p.ID AND pm.meta_key = %s
						  AND pm.meta_value <> ''
						  AND CAST(pm.meta_value AS DECIMAL(20,4)) BETWEEN %f AND %f
					)
					OR EXISTS (
						SELECT 1 FROM {$wpdb->posts} v
						INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = v.ID
						WHERE v.post_type = 'product_variation'
						  AND v.post_status NOT IN ('trash','auto-draft')
						  AND v.post_parent = p.ID
						  AND pm.meta_key = %s
						  AND pm.meta_value <> ''
						  AND CAST(pm.meta_value AS DECIMAL(20,4)) BETWEEN %f AND %f
					)
				)",
				self::META_REGULAR, $lo, $hi, self::META_REGULAR, $lo, $hi
			);
			$found = array_flip( array_map( 'absint', $wpdb->get_col( $sql ) ) );
			$out   = array();
			foreach ( $ids as $id ) {
				if ( isset( $found[ $id ] ) ) {
					$out[] = $id;
				}
			}
			return $out;
		}

		/**
		 * شمارش SQL-پایهٔ اشیاء قیمت واجد شرایط + برداشتن نمونه‌ها بدون ساخت WC_Product برای همه.
		 * خروجی: array('eligible'=>int,'samples'=>array(object_id => array(parent,type)))
		 */
		public static function analyze_targets( $parent_ids, $operation, $sample_limit ) {
			$result  = array( 'eligible' => 0, 'samples' => array() );
			$chunks  = array_chunk( array_values( array_unique( array_map( 'absint', $parent_ids ) ) ), 1000 );
			$is_wholesale = 0 === strpos( (string) $operation, 'wholesale_' );
			$is_regular   = 0 === strpos( (string) $operation, 'regular_' );
			$is_sale      = 0 === strpos( (string) $operation, 'sale_' );

			foreach ( $chunks as $chunk ) {
				$types = self::product_type_map( $chunk );
				$vars  = self::variation_map( $chunk );

				// متا باید هم برای والدها و هم برای وریشن‌ها خوانده شود.
				$all_ids = $chunk;
				foreach ( $vars as $children ) {
					foreach ( (array) $children as $vid ) {
						$all_ids[] = $vid;
					}
				}
				$all_ids = array_values( array_unique( array_map( 'absint', $all_ids ) ) );
				$meta    = self::meta_map( $all_ids, self::needed_keys( $operation ) );

				foreach ( $chunk as $parent_id ) {
					$type = isset( $types[ $parent_id ] ) ? $types[ $parent_id ] : 'simple';
					if ( 'grouped' === $type ) {
						continue; // عملیات روی محصول گروهی رد می‌شود.
					}
					$objects = array();
					if ( 'variable' === $type ) {
						$objects = isset( $vars[ $parent_id ] ) ? $vars[ $parent_id ] : array();
					} else {
						$objects = array( $parent_id );
					}
					foreach ( $objects as $oid ) {
						if ( ! self::is_eligible( $oid, $parent_id, $operation, $meta, $is_wholesale, $is_regular, $is_sale ) ) {
							continue;
						}
						$result['eligible']++;
						if ( count( $result['samples'] ) < $sample_limit ) {
							$result['samples'][ (int) $oid ] = array( 'parent_id' => (int) $parent_id, 'type' => $type );
						}
					}
				}
			}
			return $result;
		}

		private static function needed_keys( $operation ) {
			if ( 0 === strpos( (string) $operation, 'wholesale_' ) ) {
				return array( TCP_WHOLESALE_META );
			}
			if ( 0 === strpos( (string) $operation, 'regular_' ) ) {
				return array( self::META_REGULAR );
			}
			// sale_*
			return array( self::META_REGULAR, self::META_SALE );
		}

		private static function is_eligible( $oid, $parent_id, $operation, &$meta, $is_wholesale, $is_regular, $is_sale ) {
			// oid برای محصول ساده همان والد است و برای متغیر، وریشن است؛ متا همیشه از خود شیء خوانده می‌شود.
			$v = isset( $meta[ $oid ] ) ? $meta[ $oid ] : array();
			if ( $is_wholesale ) {
				$raw = isset( $v[ TCP_WHOLESALE_META ] ) ? $v[ TCP_WHOLESALE_META ] : '';
				return '' !== $raw && (float) $raw > 0;
			}
			if ( $is_regular ) {
				if ( 'regular_set' === $operation ) {
					return true;
				}
				$reg = isset( $v[ self::META_REGULAR ] ) ? $v[ self::META_REGULAR ] : '';
				return '' !== $reg;
			}
			if ( 'sale_remove' === $operation ) {
				$sale = isset( $v[ self::META_SALE ] ) ? $v[ self::META_SALE ] : '';
				return '' !== $sale;
			}
			// sale_set / sale_discount_percent به قیمت عادی نیاز دارند.
			$reg = isset( $v[ self::META_REGULAR ] ) ? $v[ self::META_REGULAR ] : '';
			return '' !== $reg;
		}

		/** نگاشت محصول مادر → نوع (متا تاکسونومی product_type). */
		private static function product_type_map( $ids ) {
			global $wpdb;
			if ( empty( $ids ) ) {
				return array();
			}
			$ids_str = implode( ',', $ids );
			$rows = $wpdb->get_results(
				"SELECT tr.object_id AS pid, t.slug AS type
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE tt.taxonomy = 'product_type' AND tr.object_id IN ({$ids_str})",
				ARRAY_A
			);
			$map = array();
			foreach ( (array) $rows as $r ) {
				$map[ absint( $r['pid'] ) ] = $r['type'];
			}
			return $map;
		}

		/** نگاشت محصول مادر → شناسهٔ وریشن‌ها. */
		private static function variation_map( $ids ) {
			global $wpdb;
			if ( empty( $ids ) ) {
				return array();
			}
			$ids_str = implode( ',', $ids );
			$rows = $wpdb->get_results(
				"SELECT ID AS vid, post_parent AS pid FROM {$wpdb->posts}
				WHERE post_type = 'product_variation'
				  AND post_status NOT IN ('trash','auto-draft')
				  AND post_parent IN ({$ids_str})
				ORDER BY ID ASC",
				ARRAY_A
			);
			$map = array();
			foreach ( (array) $rows as $r ) {
				$map[ absint( $r['pid'] ) ][] = absint( $r['vid'] );
			}
			return $map;
		}

		/** نگاشت object_id → کلیدهای متای خواسته‌شده. */
		private static function meta_map( $ids, $keys ) {
			global $wpdb;
			$ids_str = implode( ',', $ids );
			$keys_str = implode( "','", array_map( 'esc_sql', $keys ) );
			$rows = $wpdb->get_results(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE meta_key IN ('{$keys_str}') AND post_id IN ({$ids_str})",
				ARRAY_A
			);
			$map = array();
			foreach ( (array) $rows as $r ) {
				$pid = absint( $r['post_id'] );
				if ( ! isset( $map[ $pid ] ) ) {
					$map[ $pid ] = array();
				}
				$map[ $pid ][ $r['meta_key'] ] = $r['meta_value'];
			}
			return $map;
		}

		/** نگاشت شناسهٔ شیء → نام محصول برای نمونه/خروجی. */
		public static function title_map( $object_ids ) {
			global $wpdb;
			$out = array();
			if ( empty( $object_ids ) ) {
				return $out;
			}
			$chunks = array_chunk( $object_ids, 500 );
			foreach ( $chunks as $chunk ) {
				$ids_str = implode( ',', array_map( 'absint', $chunk ) );
				$rows = $wpdb->get_results(
					"SELECT ID, post_title, post_type, post_parent FROM {$wpdb->posts}
					WHERE ID IN ({$ids_str})",
					ARRAY_A
				);
				foreach ( (array) $rows as $r ) {
					$out[ absint( $r['ID'] ) ] = array(
						'title'  => $r['post_title'],
						'type'   => $r['post_type'],
						'parent' => absint( $r['post_parent'] ),
					);
				}
			}
			return $out;
		}

		/** نگاشت شناسهٔ شیء → SKU. */
		public static function sku_map( $object_ids ) {
			global $wpdb;
			$out = array();
			if ( empty( $object_ids ) ) {
				return $out;
			}
			$chunks = array_chunk( array_map( 'absint', $object_ids ), 500 );
			foreach ( $chunks as $chunk ) {
				$ids_str = implode( ',', $chunk );
				$rows = $wpdb->get_results(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta}
					WHERE meta_key = '_sku' AND post_id IN ({$ids_str})",
					ARRAY_A
				);
				foreach ( (array) $rows as $r ) {
					$out[ absint( $r['post_id'] ) ] = $r['meta_value'];
				}
			}
			return $out;
		}
	}
}
