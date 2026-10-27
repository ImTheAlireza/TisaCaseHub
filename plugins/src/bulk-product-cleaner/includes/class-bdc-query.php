<?php
/**
 * Query helpers: counting, listing, filtering, image resolution.
 *
 * @package BulkProductCleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BDC_Query
 */
class BDC_Query {

	/**
	 * Post statuses this plugin is allowed to operate on.
	 *
	 * @return string[]
	 */
	public static function allowed_statuses() {
		$statuses = array( 'draft', 'pending', 'private', 'auto-draft', 'trash' );

		/**
		 * Filters the statuses the cleaner may target.
		 *
		 * @param string[] $statuses Status slugs.
		 */
		return array_values( array_unique( (array) apply_filters( 'bdc_allowed_statuses', $statuses ) ) );
	}

	/**
	 * Sanitizes a raw filter array coming from a request.
	 *
	 * @param array $raw Raw input.
	 * @return array Normalized filter set.
	 */
	public static function sanitize_filters( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		$statuses = array();
		if ( isset( $raw['status'] ) ) {
			$candidates = is_array( $raw['status'] ) ? $raw['status'] : explode( ',', (string) $raw['status'] );
			foreach ( $candidates as $status ) {
				$status = sanitize_key( (string) $status );
				if ( in_array( $status, self::allowed_statuses(), true ) ) {
					$statuses[] = $status;
				}
			}
		}
		if ( empty( $statuses ) ) {
			$statuses = array( 'draft' );
		}

		$images = isset( $raw['images'] ) ? sanitize_key( (string) $raw['images'] ) : 'any';
		if ( ! in_array( $images, array( 'any', 'with', 'without' ), true ) ) {
			$images = 'any';
		}

		$ptype = isset( $raw['ptype'] ) ? sanitize_key( (string) $raw['ptype'] ) : 'any';
		if ( ! in_array( $ptype, array( 'any', 'simple', 'variable', 'grouped', 'external' ), true ) ) {
			$ptype = 'any';
		}

		return array(
			'status'      => array_values( array_unique( $statuses ) ),
			'search'      => isset( $raw['search'] ) ? sanitize_text_field( (string) $raw['search'] ) : '',
			'older_than'  => isset( $raw['older_than'] ) ? max( 0, (int) $raw['older_than'] ) : 0,
			'date_from'   => self::sanitize_date( isset( $raw['date_from'] ) ? $raw['date_from'] : '' ),
			'date_to'     => self::sanitize_date( isset( $raw['date_to'] ) ? $raw['date_to'] : '' ),
			'cat'         => isset( $raw['cat'] ) ? max( 0, (int) $raw['cat'] ) : 0,
			'author'      => isset( $raw['author'] ) ? max( 0, (int) $raw['author'] ) : 0,
			'images'      => $images,
			'ptype'       => $ptype,
			'no_price'    => ! empty( $raw['no_price'] ) ? 1 : 0,
			'orderby'     => self::sanitize_orderby( isset( $raw['orderby'] ) ? $raw['orderby'] : '' ),
			'order'       => ( isset( $raw['order'] ) && 'ASC' === strtoupper( (string) $raw['order'] ) ) ? 'ASC' : 'DESC',
		);
	}

	/**
	 * Validates a Y-m-d date string.
	 *
	 * @param mixed $value Raw value.
	 * @return string Empty string when invalid.
	 */
	private static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$dt = DateTime::createFromFormat( 'Y-m-d', $value );
		if ( $dt && $dt->format( 'Y-m-d' ) === $value ) {
			return $value;
		}
		return '';
	}

	/**
	 * Whitelists the ORDER BY column.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_orderby( $value ) {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'date', 'title', 'id' );
		return in_array( $value, $allowed, true ) ? $value : 'date';
	}

	/**
	 * Builds the shared FROM + WHERE fragment for the product query.
	 *
	 * Every dynamic value is bound through $wpdb->prepare().
	 *
	 * @param array $filters Sanitized filters.
	 * @return array{sql:string,args:array} SQL fragment and bind arguments.
	 */
	private static function build_where( array $filters ) {
		global $wpdb;

		$joins = array();
		$where = array( "p.post_type = 'product'" );
		$args  = array();

		// Status (always at least one, always whitelisted).
		$status_ph = implode( ', ', array_fill( 0, count( $filters['status'] ), '%s' ) );
		$where[]   = "p.post_status IN ( {$status_ph} )";
		foreach ( $filters['status'] as $status ) {
			$args[] = $status;
		}

		// Free-text search across title and SKU.
		if ( '' !== $filters['search'] ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$joins['sku'] = "LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'";
			$where[] = '( p.post_title LIKE %s OR sku.meta_value LIKE %s )';
			$args[]  = $like;
			$args[]  = $like;
		}

		// Older than N days.
		if ( $filters['older_than'] > 0 ) {
			$where[] = 'p.post_modified_gmt < %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', time() - ( $filters['older_than'] * DAY_IN_SECONDS ) );
		}

		// Explicit date range (inclusive).
		if ( '' !== $filters['date_from'] ) {
			$where[] = 'p.post_date >= %s';
			$args[]  = $filters['date_from'] . ' 00:00:00';
		}
		if ( '' !== $filters['date_to'] ) {
			$where[] = 'p.post_date <= %s';
			$args[]  = $filters['date_to'] . ' 23:59:59';
		}

		// Author.
		if ( $filters['author'] > 0 ) {
			$where[] = 'p.post_author = %d';
			$args[]  = $filters['author'];
		}

		// Category.
		if ( $filters['cat'] > 0 ) {
			$joins['tr'] = "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID";
			$joins['tt'] = "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'";
			$where[]     = 'tt.term_id = %d';
			$args[]      = $filters['cat'];
		}

		// Featured image presence.
		if ( 'with' === $filters['images'] || 'without' === $filters['images'] ) {
			$joins['thumb'] = "LEFT JOIN {$wpdb->postmeta} th ON th.post_id = p.ID AND th.meta_key = '_thumbnail_id'";
			$joins['gal']   = "LEFT JOIN {$wpdb->postmeta} gl ON gl.post_id = p.ID AND gl.meta_key = '_product_image_gallery'";
			if ( 'with' === $filters['images'] ) {
				$where[] = "( ( th.meta_value IS NOT NULL AND th.meta_value <> '' AND th.meta_value <> '0' ) OR ( gl.meta_value IS NOT NULL AND gl.meta_value <> '' ) )";
			} else {
				$where[] = "( ( th.meta_value IS NULL OR th.meta_value = '' OR th.meta_value = '0' ) AND ( gl.meta_value IS NULL OR gl.meta_value = '' ) )";
			}
		}

		// Product type via taxonomy.
		if ( 'any' !== $filters['ptype'] ) {
			$joins['trp'] = "INNER JOIN {$wpdb->term_relationships} trp ON trp.object_id = p.ID";
			$joins['ttp'] = "INNER JOIN {$wpdb->term_taxonomy} ttp ON ttp.term_taxonomy_id = trp.term_taxonomy_id AND ttp.taxonomy = 'product_type'";
			$joins['tp']  = "INNER JOIN {$wpdb->terms} tp ON tp.term_id = ttp.term_id";
			$where[]      = 'tp.slug = %s';
			$args[]       = $filters['ptype'];
		}

		// Missing price.
		if ( $filters['no_price'] ) {
			$joins['price'] = "LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = '_price'";
			$where[]        = "( pr.meta_value IS NULL OR pr.meta_value = '' )";
		}

		$sql = "FROM {$wpdb->posts} p " . implode( ' ', $joins ) . ' WHERE ' . implode( ' AND ', $where );

		return array(
			'sql'  => $sql,
			'args' => $args,
		);
	}

	/**
	 * Counts matching products.
	 *
	 * @param array $filters Sanitized filters.
	 * @return int
	 */
	public static function count( array $filters ) {
		global $wpdb;

		$parts = self::build_where( $filters );
		$sql   = 'SELECT COUNT( DISTINCT p.ID ) ' . $parts['sql'];

		if ( ! empty( $parts['args'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $parts['args'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Returns a page of matching products.
	 *
	 * @param array $filters  Sanitized filters.
	 * @param int   $page     1-based page number.
	 * @param int   $per_page Rows per page.
	 * @return array<int,object>
	 */
	public static function get_page( array $filters, $page, $per_page ) {
		global $wpdb;

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 200, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$order_map = array(
			'date'  => 'p.post_date',
			'title' => 'p.post_title',
			'id'    => 'p.ID',
		);
		$orderby   = $order_map[ $filters['orderby'] ];
		$order     = $filters['order']; // Already constrained to ASC|DESC.

		$parts = self::build_where( $filters );
		$sql   = 'SELECT DISTINCT p.ID, p.post_title, p.post_date, p.post_status, p.post_author '
			. $parts['sql']
			. " ORDER BY {$orderby} {$order}, p.ID DESC LIMIT %d OFFSET %d";

		$args   = $parts['args'];
		$args[] = $per_page;
		$args[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $sql );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns a chunk of matching product IDs (used by "select all matching").
	 *
	 * @param array $filters Sanitized filters.
	 * @param int   $limit   Maximum rows.
	 * @param int   $after_id Exclusive product-ID cursor.
	 * @return int[]
	 */
	public static function get_ids( array $filters, $limit, $after_id = 0 ) {
		return self::get_ids_after( $filters, $limit, $after_id );
	}

	/**
	 * Returns the next ID chunk after a product ID (keyset pagination).
	 *
	 * @param array $filters  Sanitized filters.
	 * @param int   $limit    Maximum rows.
	 * @param int   $after_id Exclusive ID cursor.
	 * @return int[]
	 */
	public static function get_ids_after( array $filters, $limit, $after_id = 0 ) {
		global $wpdb;

		$limit   = max( 1, min( 5000, (int) $limit ) );
		$after_id = max( 0, (int) $after_id );
		$parts   = self::build_where( $filters );
		$sql     = 'SELECT DISTINCT p.ID ' . $parts['sql'] . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d';
		$args    = $parts['args'];
		$args[]  = $after_id;
		$args[]  = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'absint', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Counts distinct images attached to matching products.
	 *
	 * Featured-image counting runs in SQL. Gallery metadata is scanned in small
	 * keyset pages with the existing 20,000-row safety ceiling; only unique IDs
	 * from those rows are retained to preserve distinct-image semantics.
	 *
	 * @param array $filters Sanitized filters.
	 * @return int
	 */
	public static function count_images( array $filters ) {
		global $wpdb;

		$parts = self::build_where( $filters );

		// Subquery over matching product IDs: keeps everything inside MySQL.
		$inner = 'SELECT DISTINCT p.ID ' . $parts['sql'];

		$sql = "SELECT COUNT( DISTINCT pm.meta_value )
			FROM {$wpdb->postmeta} pm
			WHERE pm.meta_key = '_thumbnail_id'
			  AND pm.meta_value REGEXP '^[0-9]+$'
			  AND pm.meta_value <> '0'
			  AND pm.post_id IN ( {$inner} )";

		if ( ! empty( $parts['args'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $parts['args'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$featured = (int) $wpdb->get_var( $sql );

		// Gallery CSV metadata stays capped at 20,000 rows, but is fetched in
		// 2,000-row keyset pages to reduce PHP peak memory in the admin screen.
		$gallery_ids = array();
		$after_meta_id = 0;
		$gallery_rows_read = 0;
		$gallery_row_limit = 20000;
		$chunk_size = 2000;
		do {
			$chunk_size = min( 2000, $gallery_row_limit - $gallery_rows_read );
			$gallery_sql = "SELECT pm.meta_id, pm.meta_value
				FROM {$wpdb->postmeta} pm
				WHERE pm.meta_key = '_product_image_gallery'
				  AND pm.meta_value <> ''
				  AND pm.post_id IN ( {$inner} )
				  AND pm.meta_id > %d
				ORDER BY pm.meta_id ASC
				LIMIT %d";
			$args = $parts['args'];
			$args[] = $after_meta_id;
			$args[] = $chunk_size;
			if ( ! empty( $args ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$gallery_sql = $wpdb->prepare( $gallery_sql, $args );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results( $gallery_sql, ARRAY_A );
			foreach ( $rows as $row ) {
				$after_meta_id = (int) $row['meta_id'];
				foreach ( explode( ',', (string) $row['meta_value'] ) as $piece ) {
					$id = (int) trim( $piece );
					if ( $id > 0 ) {
						$gallery_ids[ $id ] = true;
					}
				}
			}
			$page_count = count( $rows );
			$gallery_rows_read += $page_count;
			unset( $rows );
		} while ( $page_count === $chunk_size && $gallery_rows_read < $gallery_row_limit );

		return $featured + count( $gallery_ids );
	}

	/**
	 * Resolves every unique attachment ID belonging to a product.
	 *
	 * Handles featured image, gallery, variation images, and whitespace/duplicates.
	 *
	 * @param int $product_id Product ID.
	 * @return int[] Unique, positive attachment IDs.
	 */
	public static function collect_image_ids( $product_id ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return array();
		}

		$ids = array();

		$thumb = (int) get_post_meta( $product_id, '_thumbnail_id', true );
		if ( $thumb > 0 ) {
			$ids[ $thumb ] = true;
		}

		$gallery = (string) get_post_meta( $product_id, '_product_image_gallery', true );
		if ( '' !== $gallery ) {
			foreach ( explode( ',', $gallery ) as $piece ) {
				$id = (int) trim( $piece );
				if ( $id > 0 ) {
					$ids[ $id ] = true;
				}
			}
		}

		// Variation featured images.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$variation_thumbs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} v ON v.ID = pm.post_id
				 WHERE v.post_parent = %d
				   AND v.post_type = 'product_variation'
				   AND pm.meta_key = '_thumbnail_id'",
				$product_id
			)
		);

		foreach ( (array) $variation_thumbs as $value ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}

		$result = array_map( 'intval', array_keys( $ids ) );

		/**
		 * Filters the attachment IDs resolved for a product.
		 *
		 * @param int[] $result     Attachment IDs.
		 * @param int   $product_id Product ID.
		 */
		return (array) apply_filters( 'bdc_product_image_ids', $result, $product_id );
	}

	/**
	 * Warms the meta cache for a batch of posts so per-row lookups cost nothing.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return void
	 */
	public static function prime_meta( array $post_ids ) {
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids );
		}
	}

	/**
	 * Determines whether an attachment is referenced by anything other than $exclude_id.
	 *
	 * Checks: other featured images, other galleries, non-excluded post_parent,
	 * variations of other products, and site logo / custom header usage.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param int[] $exclude_ids   Product IDs being deleted (their references don't count).
	 * @return bool
	 */
	public static function is_attachment_shared( $attachment_id, array $exclude_ids ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return true; // Fail safe: never delete on bad input.
		}

		$exclude_ids = array_values( array_filter( array_map( 'absint', $exclude_ids ) ) );
		if ( empty( $exclude_ids ) ) {
			$exclude_ids = array( 0 );
		}
		$excl_ph = implode( ', ', array_fill( 0, count( $exclude_ids ), '%d' ) );

		// 1+2) Referenced as a featured image, or inside another gallery.
		// Both live in postmeta, so a single indexed pass answers them.
		// EXISTS short-circuits on the first hit instead of counting rows.
		$like = '%,' . $attachment_id . ',%';
		$args = array_merge( array( $attachment_id ), $exclude_ids, array( $like ), $exclude_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta}
					WHERE meta_key = '_thumbnail_id'
					  AND CAST( meta_value AS UNSIGNED ) = %d
					  AND post_id NOT IN ( {$excl_ph} )
					LIMIT 1
				) OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta}
					WHERE meta_key = '_product_image_gallery'
					  AND CONCAT( ',', REPLACE( meta_value, ' ', '' ), ',' ) LIKE %s
					  AND post_id NOT IN ( {$excl_ph} )
					LIMIT 1
				)",
				$args
			)
		);

		if ( $found > 0 ) {
			return true;
		}

		// 3) Attached to a different, surviving parent post.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$parent = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d", $attachment_id )
		);
		if ( $parent > 0 && ! in_array( $parent, $exclude_ids, true ) ) {
			// A variation parent that is itself being deleted is fine.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$grandparent = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'product_variation'",
					$parent
				)
			);
			if ( 0 === $grandparent || ! in_array( $grandparent, $exclude_ids, true ) ) {
				return true;
			}
		}

		// 4) Theme usage: site icon, custom logo, header image.
		// These are identical for every image in a batch, so resolve once.
		static $theme_ids = null;

		if ( null === $theme_ids ) {
			$theme_ids = array_filter(
				array(
					(int) get_option( 'site_icon' ),
					(int) get_option( 'site_logo' ),
					(int) get_theme_mod( 'custom_logo' ),
				)
			);
		}

		if ( in_array( $attachment_id, $theme_ids, true ) ) {
			return true;
		}

		/**
		 * Filters whether an attachment is considered shared (and therefore preserved).
		 *
		 * @param bool  $shared        Result so far.
		 * @param int   $attachment_id Attachment ID.
		 * @param int[] $exclude_ids   Products being deleted.
		 */
		return (bool) apply_filters( 'bdc_is_attachment_shared', false, $attachment_id, $exclude_ids );
	}

	/**
	 * Sums the on-disk size of an attachment including all generated sizes.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Bytes.
	 */
	public static function attachment_disk_size( $attachment_id ) {
		$files = self::attachment_files( $attachment_id );
		$bytes = 0;

		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false !== $size ) {
					$bytes += (int) $size;
				}
			}
		}

		return $bytes;
	}

	/**
	 * Lists every physical file belonging to an attachment.
	 *
	 * Uses WordPress metadata (never filename guessing), and includes
	 * -scaled originals plus WebP/AVIF sidecars produced by optimizer plugins.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[] Absolute file paths (may include non-existent paths' filtered out).
	 */
	public static function attachment_files( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$main          = get_attached_file( $attachment_id );

		if ( ! $main ) {
			return array();
		}

		$dir   = dirname( $main );
		$paths = array( $main );
		$meta  = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$paths[] = $dir . '/' . wp_basename( (string) $size['file'] );
					}
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$paths[] = $dir . '/' . wp_basename( (string) $meta['original_image'] );
			}
		}

		// Optimizer sidecars.
		$sidecars = array();
		foreach ( $paths as $path ) {
			foreach ( array( '.webp', '.avif' ) as $ext ) {
				$sidecars[] = $path . $ext;
			}
		}

		$all = array_values( array_unique( array_merge( $paths, $sidecars ) ) );

		return array_values(
			array_filter(
				$all,
				static function ( $path ) {
					return is_file( $path );
				}
			)
		);
	}

	/**
	 * Formats a byte count for display.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );
		if ( $bytes < 1024 ) {
			/* translators: %s: number of bytes. */
			return sprintf( __( '%s بایت', 'bulk-product-cleaner' ), number_format_i18n( $bytes ) );
		}
		return size_format( $bytes, 2 );
	}
}
