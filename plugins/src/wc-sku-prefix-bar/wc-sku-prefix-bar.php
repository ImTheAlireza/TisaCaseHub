<?php
/**
 * Plugin Name: WooCommerce SKU Prefix Bar
 * Description: SKU prefix dashboard, next-SKU generator, duplicate checker and protected REST API for the Telegram bot.
 * Version: 1.5.3
 * Author: Arena
 * Text Domain: wc-sku-prefix-bar
 * Requires at least: 5.6
 * Requires PHP: 7.2
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_SKU_Prefix_Bar {
    const TRANSIENT = 'wcspb_latest_skus_v2';
    const LEGACY_TRANSIENT = 'wcspb_latest_skus';
    const BUILD_TRANSIENT = 'wcspb_sku_summary_build';
    const GENERATION_OPTION = 'wcspb_sku_summary_generation';
    const BUILD_LOCK_OPTION = 'wcspb_sku_summary_lock';
    const VER = '1.5.3';
    const TTL = HOUR_IN_SECONDS * 6;
    const BUILD_TTL = DAY_IN_SECONDS;
    const BATCH_SIZE = 500;
    const MAX_PREFIXES = 1000;
    private static $printed = false;
    private static $sku_changed_products = array();

    public static function init() {
        $self = new self();
        add_action( 'manage_posts_extra_tablenav', array( $self, 'render_tablenav' ), 5 );
        add_action( 'admin_enqueue_scripts', array( $self, 'assets' ) );
        add_action( 'wp_ajax_wcspb_refresh', array( $self, 'ajax_refresh' ) );
        add_action( 'woocommerce_product_options_sku', array( $self, 'render_generator' ) );
        add_action( 'wp_ajax_wcspb_next', array( $self, 'ajax_next' ) );
        add_action( 'wp_ajax_wcspb_summary', array( $self, 'ajax_summary' ) );
        add_action( 'wp_ajax_wcspb_check', array( $self, 'ajax_check' ) );
        add_action( 'rest_api_init', array( $self, 'register_rest_routes' ) );
        add_action( 'added_post_meta', array( __CLASS__, 'flush_after_sku_add' ), 10, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'flush_after_sku_update' ), 10, 4 );
        add_action( 'deleted_post_meta', array( __CLASS__, 'flush_after_sku_delete' ), 10, 4 );
        add_action( 'woocommerce_update_product', array( __CLASS__, 'flush_after_product_save_if_sku_changed' ), 10, 1 );
        add_action( 'woocommerce_new_product', array( __CLASS__, 'flush_after_product_save_if_sku_changed' ), 10, 1 );
        add_action( 'deleted_post', array( __CLASS__, 'flush_after_product_save_if_sku_changed' ), 10, 1 );
    }

    public static function flush() {
        $generation = self::new_generation();
        update_option( self::GENERATION_OPTION, $generation, false );
        delete_transient( self::TRANSIENT );
        delete_transient( self::LEGACY_TRANSIENT );
        delete_transient( self::BUILD_TRANSIENT );
    }

    private static function new_generation() {
        return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( '', true ) . mt_rand() );
    }

    private static function generation() {
        $generation = get_option( self::GENERATION_OPTION, '' );
        if ( is_string( $generation ) && '' !== $generation ) return $generation;
        $generation = self::new_generation();
        add_option( self::GENERATION_OPTION, $generation, '', false );
        $stored = get_option( self::GENERATION_OPTION, $generation );
        return is_string( $stored ) && '' !== $stored ? $stored : $generation;
    }

    private static function note_sku_change( $object_id ) {
        $object_id = (int) $object_id;
        self::$sku_changed_products[ $object_id ] = true;
        self::flush();
    }

    public static function flush_after_sku_add( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( '_sku' === $meta_key && '' !== (string) $meta_value ) self::note_sku_change( $object_id );
    }

    public static function flush_after_sku_update( $meta_id, $object_id, $meta_key, $meta_value ) {
        // This action fires only after WordPress actually updates metadata; unchanged product saves do not flush.
        if ( '_sku' === $meta_key ) self::note_sku_change( $object_id );
    }

    public static function flush_after_sku_delete( $meta_ids, $object_id, $meta_key, $meta_value ) {
        // Also catches SKU metadata removed during permanent product deletion.
        if ( '_sku' === $meta_key ) self::note_sku_change( $object_id );
    }

    public static function flush_after_product_save_if_sku_changed( $product_id ) {
        $product_id = (int) $product_id;
        if ( isset( self::$sku_changed_products[ $product_id ] ) ) {
            self::flush(); // WooCommerce may update its product lookup row after the postmeta action.
            unset( self::$sku_changed_products[ $product_id ] );
        }
    }

    private function is_products_screen() {
        if ( function_exists( 'get_current_screen' ) ) {
            $s = get_current_screen();
            if ( ! $s || 'edit' !== $s->base || 'product' !== $s->post_type ) return false;
        } else {
            global $pagenow;
            if ( 'edit.php' !== $pagenow || 'product' !== ( isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '' ) ) return false;
        }
        foreach ( array_keys( wp_unslash($_GET) ) as $key ) if ( 'post_type' !== $key ) return false;
        return true;
    }
    private function is_product_editor() {
        if ( function_exists( 'get_current_screen' ) ) {
            $s = get_current_screen();
            if ( $s && 'post' === $s->base && 'product' === $s->post_type ) return true;
        }
        global $pagenow, $post;
        if ( 'post-new.php' === $pagenow ) return 'product' === ( isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '' );
        return 'post.php' === $pagenow && $post && 'product' === get_post_type($post);
    }
    /** وابستگی به لایهٔ توکن هاب، اگر فعال بود — تا var(--tisa-*) ما بعد از آن کسکید بگیرد. */
    private function style_deps() {
        return wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array();
    }

    public function assets( $hook ) {
        if ( $this->is_product_editor() ) {
            wp_enqueue_style('wcspb', plugins_url('assets/bar.css',__FILE__), $this->style_deps(), self::VER);
            wp_enqueue_script('wcspb-editor',plugins_url('assets/editor.js',__FILE__),array('jquery'),self::VER,true);
            wp_localize_script('wcspb-editor','WCSPB_ED',array('ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wcspb'),'postId'=>get_the_ID() ?: 0,'i18n'=>array('checking'=>__('در حال بررسی…','wc-sku-prefix-bar'),'dupe'=>__('این SKU قبلاً استفاده شده:','wc-sku-prefix-bar'),'free'=>__('SKU آزاد است','wc-sku-prefix-bar'),'edit'=>__('ویرایش','wc-sku-prefix-bar'),'building'=>__('در حال تکمیل فهرست سری‌ها…','wc-sku-prefix-bar'),'buildFailed'=>__('ساخت فهرست SKU ناموفق بود؛ دوباره تلاش کنید.','wc-sku-prefix-bar'),'chooseSeries'=>__('— انتخاب سری —','wc-sku-prefix-bar'),'truncated'=>__('فهرست برای محدودماندن حافظه به ۱۰۰۰ سری محدود شده است.','wc-sku-prefix-bar'))));
            return;
        }
        if ( ! $this->is_products_screen() ) return;
        wp_enqueue_style('wcspb',plugins_url('assets/bar.css',__FILE__), $this->style_deps(), self::VER);
        wp_enqueue_script('wcspb',plugins_url('assets/bar.js',__FILE__),array('jquery'),self::VER,true);
        wp_localize_script('wcspb','WCSPB',array('ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wcspb')));
    }
    /** Return a capped prefix summary and advance at most one database page on cache misses. */
    public function get_latest_state() {
        $generation = self::generation();
        $cached = get_transient( self::TRANSIENT );
        if ( is_array( $cached ) && isset( $cached['generation'], $cached['items'] ) && $cached['generation'] === $generation ) {
            if ( self::generation() === $generation ) {
                return array( 'items' => $cached['items'], 'done' => true, 'processed' => isset( $cached['processed'] ) ? (int) $cached['processed'] : 0, 'truncated' => ! empty( $cached['truncated'] ) );
            }
        } elseif ( false !== $cached ) {
            delete_transient( self::TRANSIENT ); // Discard malformed snapshots rather than trusting unversioned data.
        }

        $lock = $this->acquire_build_lock();
        if ( false === $lock ) return $this->building_state( $generation );

        try {
            $generation = self::generation();
            $cached = get_transient( self::TRANSIENT );
            if ( is_array( $cached ) && isset( $cached['generation'], $cached['items'] ) && $cached['generation'] === $generation ) {
                return array( 'items' => $cached['items'], 'done' => true, 'processed' => isset( $cached['processed'] ) ? (int) $cached['processed'] : 0, 'truncated' => ! empty( $cached['truncated'] ) );
            }

            $state = get_transient( self::BUILD_TRANSIENT );
            if ( ! is_array( $state ) || ! isset( $state['generation'] ) || $state['generation'] !== $generation ) {
                $state = $this->new_build_state( $generation );
            }
            unset( $state['error'] );
            $this->advance_build( $state );

            // If a SKU changed while the query was running, never publish the old snapshot.
            if ( self::generation() !== $generation ) return $this->building_state( self::generation() );

            if ( ! empty( $state['done'] ) ) {
                $items = $this->format_summary( $state['best'] );
                $snapshot = array(
                    'generation' => $generation,
                    'items' => $items,
                    'processed' => (int) $state['processed'],
                    'truncated' => ! empty( $state['truncated'] ),
                );
                set_transient( self::TRANSIENT, $snapshot, self::TTL );
                delete_transient( self::BUILD_TRANSIENT );
                return array( 'items' => $items, 'done' => true, 'processed' => $snapshot['processed'], 'truncated' => $snapshot['truncated'] );
            }

            set_transient( self::BUILD_TRANSIENT, $state, self::BUILD_TTL );
            return array( 'items' => $this->format_summary( $state['best'] ), 'done' => false, 'processed' => (int) $state['processed'], 'truncated' => ! empty( $state['truncated'] ), 'error' => ! empty( $state['error'] ) );
        } finally {
            $this->release_build_lock( $lock );
        }
    }

    /** Compatibility wrapper. On a cold cache, callers receive a bounded partial summary. */
    public function get_latest() {
        $state = $this->get_latest_state();
        return $state['items'];
    }

    private function new_build_state( $generation ) {
        global $wpdb;
        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) );
        return array(
            'generation' => $generation,
            'source' => $exists === $lookup ? 'lookup' : 'postmeta',
            'cursor' => 0,
            'best' => array(),
            'processed' => 0,
            'truncated' => false,
            'done' => false,
        );
    }

    private function building_state( $generation ) {
        $state = get_transient( self::BUILD_TRANSIENT );
        if ( ! is_array( $state ) || ! isset( $state['generation'] ) || $state['generation'] !== $generation ) {
            return array( 'items' => array(), 'done' => false, 'processed' => 0, 'truncated' => false, 'error' => false );
        }
        return array( 'items' => $this->format_summary( $state['best'] ), 'done' => false, 'processed' => (int) $state['processed'], 'truncated' => ! empty( $state['truncated'] ), 'error' => ! empty( $state['error'] ) );
    }

    private function acquire_build_lock() {
        $token = self::new_generation();
        if ( add_option( self::BUILD_LOCK_OPTION, array( 'token' => $token, 'created' => time() ), '', false ) ) return $token;
        $existing = get_option( self::BUILD_LOCK_OPTION, array() );
        $stale = ! is_array( $existing ) || ! isset( $existing['created'] ) || time() - (int) $existing['created'] > 300;
        if ( $stale ) {
            delete_option( self::BUILD_LOCK_OPTION );
            if ( add_option( self::BUILD_LOCK_OPTION, array( 'token' => $token, 'created' => time() ), '', false ) ) return $token;
        }
        return false;
    }

    private function release_build_lock( $token ) {
        $lock = get_option( self::BUILD_LOCK_OPTION, array() );
        if ( is_array( $lock ) && isset( $lock['token'] ) && $lock['token'] === $token ) delete_option( self::BUILD_LOCK_OPTION );
    }

    /** A single call reads at most BATCH_SIZE SKU rows. */
    private function advance_build( &$state ) {
        global $wpdb;
        if ( ! empty( $state['done'] ) ) return;
        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        if ( 'lookup' === $state['source'] ) {
            $sql = $wpdb->prepare( "SELECT product_id, sku FROM {$lookup} WHERE product_id > %d AND sku <> '' ORDER BY product_id ASC LIMIT " . self::BATCH_SIZE, (int) $state['cursor'] );
        } else {
            $sql = $wpdb->prepare( "SELECT pm.meta_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_id > %d AND pm.meta_key='_sku' AND pm.meta_value<>'' AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') ORDER BY pm.meta_id ASC LIMIT " . self::BATCH_SIZE, (int) $state['cursor'] );
        }
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            $state['error'] = true;
            return; // Keep the build resumable after a temporary database error.
        }
        if ( ! $rows && 'lookup' === $state['source'] && 0 === (int) $state['cursor'] ) {
            $state['source'] = 'postmeta';
            $this->advance_build( $state );
            return;
        }
        if ( ! $rows ) {
            $state['done'] = true;
            return;
        }
        foreach ( $rows as $row ) {
            $id_key = 'lookup' === $state['source'] ? 'product_id' : 'meta_id';
            $sku_key = 'lookup' === $state['source'] ? 'sku' : 'meta_value';
            $state['cursor'] = max( (int) $state['cursor'], (int) $row[ $id_key ] );
            $state['processed']++;
            $this->record_sku( $state, isset( $row[ $sku_key ] ) ? $row[ $sku_key ] : '' );
        }
        if ( count( $rows ) < self::BATCH_SIZE ) $state['done'] = true;
    }

    private function record_sku( &$state, $sku ) {
        $sku = trim( (string) $sku );
        if ( ! preg_match( '/^([^0-9]*)([0-9]+)(.*)$/u', $sku, $matches ) ) return;
        $prefix = strtoupper( trim( $matches[1] ) );
        $number = (int) $matches[2];
        $padding = strlen( $matches[2] );
        if ( '' === preg_replace( '/[^A-Z]/', '', $prefix ) ) return;
        if ( ! isset( $state['best'][ $prefix ] ) && count( $state['best'] ) >= self::MAX_PREFIXES ) {
            $state['truncated'] = true;
            return;
        }
        if ( ! isset( $state['best'][ $prefix ] ) || $number > $state['best'][ $prefix ]['num'] || ( $number === $state['best'][ $prefix ]['num'] && $padding > $state['best'][ $prefix ]['pad'] ) ) {
            $state['best'][ $prefix ] = array( 'num' => $number, 'pad' => $padding, 'sku' => $sku );
        }
    }

    private function format_summary( $best ) {
        if ( ! is_array( $best ) ) return array();
        ksort( $best, SORT_NATURAL );
        $items = array();
        foreach ( $best as $prefix => $data ) {
            $items[] = array(
                'prefix' => $prefix,
                'label' => $prefix . str_pad( (string) $data['num'], $data['pad'], '0', STR_PAD_LEFT ),
                'next' => $prefix . str_pad( (string) ( $data['num'] + 1 ), $data['pad'], '0', STR_PAD_LEFT ),
                'sample' => $data['sku'],
            );
        }
        return $items;
    }

    public function render_tablenav( $which ) {
        if ( 'top' === $which ) $this->render_bar();
    }

    public function render_bar() {
        if ( self::$printed || ! $this->is_products_screen() || ! current_user_can( 'edit_products' ) ) return;
        self::$printed = true;
        $state = $this->get_latest_state();
        echo $this->bar_html( $state['items'], ! $state['done'], $state['processed'], $state['truncated'] );
    }

    private function bar_html( $items, $building = false, $processed = 0, $truncated = false ) {
        ob_start(); ?>
        <div class="wcspb-bar" id="wcspb-bar" data-building="<?php echo $building ? '1' : '0'; ?>" dir="rtl" role="toolbar" aria-label="شناسه‌ها" style="display: flex;visibility: visible;opacity: 1;">
            <span class="wcspb-sort-label"><?php esc_html_e( 'مرتب‌سازی بر اساس:', 'wc-sku-prefix-bar' ); ?></span>
            <span class="wcspb-list">
            <?php if ( empty( $items ) && ! $building ) : ?>
                <span class="wcspb-empty"><?php esc_html_e( 'شناسه‌ای پیدا نشد.', 'wc-sku-prefix-bar' ); ?></span>
            <?php else : foreach ( $items as $it ) : ?>
                <button type="button" class="wcspb-chip" data-prefix="<?php echo esc_attr( $it['prefix'] ); ?>" title="<?php echo esc_attr( sprintf( /* translators: %s: SKU prefix */ __( 'مرتب‌سازی محصولات بر اساس %s', 'wc-sku-prefix-bar' ), $it['prefix'] ) ); ?>"><?php echo esc_html( $it['prefix'] ); ?></button>
            <?php endforeach; endif; ?>
            </span>
            <span class="wcspb-tools">
                <button type="button" class="wcspb-refresh" id="wcspb-refresh" title="<?php esc_attr_e( 'شمارش دوبارهٔ سری‌ها', 'wc-sku-prefix-bar' ); ?>">⟳ <?php esc_html_e( 'تازه‌سازی', 'wc-sku-prefix-bar' ); ?></button>
                <?php if ( $building ) : ?><small class="wcspb-build-status" role="status"><?php echo esc_html( sprintf( __( 'در حال بررسی SKUها (%s)…', 'wc-sku-prefix-bar' ), number_format_i18n( $processed ) ) ); ?></small><?php elseif ( $truncated ) : ?><small class="wcspb-build-status" role="status"><?php esc_html_e( 'برای محدودماندن حافظه، فقط ۱۰۰۰ سری نخست نمایش داده می‌شود.', 'wc-sku-prefix-bar' ); ?></small><?php endif; ?>
                <small class="wcspb-credit"><?php esc_html_e( 'توسط علیرضا شعبان‌زاده', 'wc-sku-prefix-bar' ); ?></small>
            </span>
        </div>
        <?php return ob_get_clean();
    }

    public function ajax_refresh() {
        check_ajax_referer( 'wcspb', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) wp_send_json_error();
        self::flush();
        $state = $this->get_latest_state();
        wp_send_json_success( array( 'html' => $this->bar_html( $state['items'], ! $state['done'], $state['processed'], $state['truncated'] ), 'building' => ! $state['done'] ) );
    }

    public function ajax_summary() {
        check_ajax_referer( 'wcspb', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) wp_send_json_error();
        $state = $this->get_latest_state();
        $payload = array(
            'done' => $state['done'],
            'processed' => $state['processed'],
            'truncated' => $state['truncated'],
            'error' => ! empty( $state['error'] ),
        );
        $surface = isset( $_POST['surface'] ) ? sanitize_key( wp_unslash( $_POST['surface'] ) ) : '';
        if ( 'bar' === $surface ) {
            $payload['html'] = $this->bar_html( $state['items'], ! $state['done'], $state['processed'], $state['truncated'] );
        } elseif ( $state['done'] ) {
            $payload['items'] = $state['items'];
        }
        wp_send_json_success( $payload );
    }

    public function render_generator() {
        if ( ! current_user_can( 'edit_products' ) ) return;
        $state = $this->get_latest_state();
        if ( ! $state['items'] && $state['done'] ) return;
        ?>
        <p class="form-field wcspb-gen-field">
            <label for="wcspb-gen"><?php esc_html_e( 'SKU خودکار', 'wc-sku-prefix-bar' ); ?></label>
            <span class="wcspb-gen-wrap">
                <select id="wcspb-gen" class="wcspb-gen-select">
                    <option value=""><?php esc_html_e( '— انتخاب سری —', 'wc-sku-prefix-bar' ); ?></option>
                    <?php foreach ( $state['items'] as $item ) : ?><option value="<?php echo esc_attr( $item['next'] ); ?>"><?php echo esc_html( $item['label'] . '  →  ' . $item['next'] ); ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="button button-primary wcspb-gen-btn" id="wcspb-gen-btn"><?php esc_html_e( 'درج در فیلد SKU', 'wc-sku-prefix-bar' ); ?></button>
            </span>
            <span class="wcspb-gen-status" id="wcspb-gen-status" role="status" aria-live="polite"><?php if ( ! $state['done'] ) echo esc_html( sprintf( __( 'در حال ساخت فهرست سری‌ها… (%s)', 'wc-sku-prefix-bar' ), number_format_i18n( $state['processed'] ) ) ); ?></span>
        </p>
        <?php
    }

    public function ajax_next() {
        check_ajax_referer( 'wcspb', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) wp_send_json_error();
        $prefix = strtoupper( sanitize_text_field( wp_unslash( isset( $_POST['prefix'] ) ? $_POST['prefix'] : '' ) ) );
        $state = $this->get_latest_state();
        if ( ! $state['done'] && ! empty( $state['error'] ) ) wp_send_json_error( array( 'message' => 'ساخت فهرست SKU موقتاً با مشکل روبه‌رو شد؛ دوباره تلاش کنید.' ) );
        if ( ! $state['done'] ) wp_send_json_success( array( 'pending' => true ) );
        foreach ( $state['items'] as $item ) {
            if ( $item['prefix'] === $prefix ) wp_send_json_success( array( 'next' => $item['next'] ) );
        }
        wp_send_json_error( array( 'message' => 'سری SKU پیدا نشد.' ) );
    }

    public function ajax_check(){ check_ajax_referer('wcspb','nonce'); if(!current_user_can('edit_products'))wp_send_json_error(); global $wpdb; $sku=sanitize_text_field(wp_unslash($_POST['sku']??'')); $self=absint($_POST['post_id']??0); if(''===$sku)wp_send_json_success(array('dupe'=>false)); $row=$wpdb->get_row($wpdb->prepare("SELECT p.ID,p.post_title,p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_sku' AND pm.meta_value=%s AND p.ID<>%d AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') LIMIT 1",$sku,$self)); if(!$row)wp_send_json_success(array('dupe'=>false)); $id=(int)$row->ID; $title=$row->post_title; if('product_variation'===$row->post_type&&($parent=wp_get_post_parent_id($id))){$title=get_the_title($parent).' — '.__('variation','wc-sku-prefix-bar');$id=$parent;} wp_send_json_success(array('dupe'=>true,'title'=>$title?:('#'.$id),'link'=>get_edit_post_link($id,'raw'))); }

    /** REST endpoint consumed by the Telegram bot. WooCommerce consumer-key authentication maps to a WordPress user. */
    public function register_rest_routes(){ register_rest_route('wcspb/v1','/next-sku',array('methods'=>WP_REST_Server::READABLE,'permission_callback'=>function(){return current_user_can('edit_products');},'callback'=>function(WP_REST_Request $request){$prefix=strtoupper(preg_replace('/[^A-Z0-9_-]/i','',(string)$request->get_param('prefix')));if(''===$prefix)return new WP_Error('wcspb_missing_prefix','A SKU prefix is required',array('status'=>400));$state=$this->get_latest_state();if(!$state['done'])return new WP_Error('wcspb_summary_building','SKU summary is still being built. Retry shortly.',array('status'=>503,'retry_after'=>1));foreach($state['items'] as $item)if(strtoupper($item['prefix'])===$prefix)return rest_ensure_response(array('prefix'=>$prefix,'latest'=>$item['label'],'sku'=>$item['next']));return rest_ensure_response(array('prefix'=>$prefix,'latest'=>null,'sku'=>$prefix.'1'));},'args'=>array('prefix'=>array('required'=>true)))); }
}
add_action('plugins_loaded',array('WC_SKU_Prefix_Bar','init'));
register_deactivation_hook(__FILE__,array('WC_SKU_Prefix_Bar','flush'));
