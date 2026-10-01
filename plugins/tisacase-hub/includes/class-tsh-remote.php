<?php
/**
 * دریافت زیپ افزونه از مخزن — با آینه و بدون گیر کردن به بلاک HTTP وردپرس.
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TSH_Remote' ) ) {

	/**
	 * دانلود امن زیپ از GitHub / jsDelivr.
	 */
	final class TSH_Remote {

		/**
		 * میزبان‌هایی که هاب اجازهٔ درخواست به آن‌ها را می‌دهد.
		 *
		 * @return array<int,string>
		 */
		public static function hosts() {
			return array(
				'github.com',
				'www.github.com',
				'api.github.com',
				'raw.githubusercontent.com',
				'objects.githubusercontent.com',
				'camo.githubusercontent.com',
				'media.githubusercontent.com',
				'cdn.jsdelivr.net',
				'fastly.jsdelivr.net',
				'gcore.jsdelivr.net',
				'data.jsdelivr.net',
				'cdn.jsdelivr.com',
			);
		}

		/**
		 * @param string $url آدرس.
		 * @return bool
		 */
		public static function host_ok( $url ) {
			$u = strtolower( (string) $url );
			if ( ! preg_match( '#^https://#', $u ) ) {
				return false;
			}
			return (bool) preg_match( '#github\.com|githubusercontent\.com|jsdelivr\.(net|com)#', $u );
		}

		/**
		 * فیلترهای وردپرس تا download_url / wp_safe_remote_get میزبان گیت‌هاب را رد نکنند.
		 *
		 * @return void
		 */
		public static function allow() {
			add_filter( 'http_request_host_is_external', array( __CLASS__, 'host_is_external' ), 10, 2 );
			add_filter( 'http_request_args', array( __CLASS__, 'request_args' ), 10, 2 );
		}

		/**
		 * @param bool   $allow اجازهٔ فعلی.
		 * @param string $host  میزبان.
		 * @return bool
		 */
		public static function host_is_external( $allow, $host ) {
			if ( in_array( strtolower( (string) $host ), self::hosts(), true ) ) {
				return true;
			}
			return $allow;
		}

		/**
		 * @param array  $args آرگومان درخواست.
		 * @param string $url  آدرس.
		 * @return array
		 */
		public static function request_args( $args, $url ) {
			$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
			if ( ! in_array( strtolower( $host ), self::hosts(), true ) ) {
				return $args;
			}
			$args['timeout']     = max( 30, isset( $args['timeout'] ) ? (int) $args['timeout'] : 90 );
			$args['redirection'] = 5;
			$args['sslverify']   = true;
			$ua                  = 'TisaCase-Hub/' . ( defined( 'TSH_VERSION' ) ? TSH_VERSION : '1' ) . '; ' . home_url( '/' );
			if ( empty( $args['user-agent'] ) ) {
				$args['user-agent'] = $ua;
			}
			if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
				$args['headers'] = array();
			}
			if ( 0 === strpos( strtolower( $host ), 'api.github.com' ) ) {
				$args['headers']['Accept'] = 'application/vnd.github+json';
			} elseif ( empty( $args['headers']['Accept'] ) ) {
				$args['headers']['Accept'] = 'application/zip,application/octet-stream,*/*';
			}
			return $args;
		}

		/**
		 * فهرست آدرس‌های یک آیتم: تنظیمات هاب، سپس GitHub، سپس jsDelivr.
		 *
		 * @param array $item آیتم registry.
		 * @return array<int,string>
		 */
		public static function mirrors( $item ) {
			$dir = isset( $item['dir'] ) ? (string) $item['dir'] : '';
			if ( '' === $dir ) {
				return array();
			}
			$file   = $dir . '.zip';
			$repo   = self::repo();
			$branch = self::branch();
			$enc    = rawurlencode( $branch );
			$urls   = array(
				'https://raw.githubusercontent.com/' . $repo . '/refs/heads/' . $branch . '/plugins/dist/' . $file,
				'https://github.com/' . $repo . '/raw/' . $enc . '/plugins/dist/' . $file,
			);
			if ( false === strpos( $branch, '/' ) ) {
				$urls[] = 'https://cdn.jsdelivr.net/gh/' . $repo . '@' . $branch . '/plugins/dist/' . $file;
			}
			if ( class_exists( 'TSH_Registry' ) ) {
				$primary = TSH_Registry::zip_url( $item );
				if ( $primary && false === strpos( $primary, 'jsdelivr.net' ) ) {
					array_unshift( $urls, $primary );
				}
			}
			$out = array();
			foreach ( $urls as $url ) {
				$url = trim( (string) $url );
				if ( $url && preg_match( '#^https://#i', $url ) && ! in_array( $url, $out, true ) ) {
					$out[] = $url;
				}
			}
			return $out;
		}

		/**
		 * زیپ را از اولین آدرس سالم می‌گیرد. مسیر فایل موقت یا WP_Error.
		 *
		 * @param array|string $urls یک یا چند آدرس.
		 * @return string|\WP_Error
		 */
		public static function download_zip( $urls ) {
			self::allow();
			require_once ABSPATH . 'wp-admin/includes/file.php';

			$urls = is_array( $urls ) ? $urls : array( $urls );
			$last = null;
			foreach ( $urls as $url ) {
				$url = trim( (string) $url );
				if ( ! $url || ! preg_match( '#^https://#i', $url ) ) {
					continue;
				}
				if ( ! self::host_ok( $url ) ) {
					continue;
				}
				$got = self::fetch_one( $url );
				if ( ! is_wp_error( $got ) && is_string( $got ) && is_readable( $got ) && filesize( $got ) > 8 ) {
					return $got;
				}
				$last = is_wp_error( $got ) ? $got : new WP_Error( 'tsh_empty_zip', __( 'فایل زیپ خالی رسید.', 'tisacase-hub' ) );
			}
			if ( $last instanceof WP_Error ) {
				return $last;
			}
			return new WP_Error( 'tsh_no_zip', __( 'آدرس زیپی برای این افزونه نیست.', 'tisacase-hub' ) );
		}

		/**
		 * یک آدرس: download_url، بعد wp_remote_get، بعد cURL، بعد file_get_contents.
		 *
		 * @param string $url آدرس.
		 * @return string|\WP_Error مسیر موقت.
		 */
		private static function fetch_one( $url ) {
			$direct = self::fetch_direct( $url );
			if ( ! is_wp_error( $direct ) ) {
				return $direct;
			}

			$blocked = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL;
			if ( $blocked ) {
				return $direct;
			}

			if ( function_exists( 'download_url' ) ) {
				$tmp = download_url( $url, 90 );
				if ( ! is_wp_error( $tmp ) ) {
					return $tmp;
				}
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 90,
					'redirection' => 5,
					'sslverify'   => true,
					'headers'     => self::headers_for( $url ),
				)
			);
			return self::store_body( $response, $url );
		}

		/**
		 * @param string $url آدرس.
		 * @return array<string,string>
		 */
		private static function headers_for( $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$ua   = 'TisaCase-Hub/' . ( defined( 'TSH_VERSION' ) ? TSH_VERSION : '1' );
			if ( false !== strpos( $host, 'api.github.com' ) ) {
				return array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => $ua,
				);
			}
			return array(
				'Accept'     => 'application/zip,application/octet-stream,*/*',
				'User-Agent' => $ua,
			);
		}

		/**
		 * @param mixed  $response پاسخ wp_remote_*.
		 * @param string $url      آدرس.
		 * @return string|\WP_Error
		 */
		private static function store_body( $response, $url ) {
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$err_body = (string) wp_remote_retrieve_body( $response );
			if ( in_array( $code, array( 403, 429 ), true ) && false !== stripos( $err_body, 'rate limit' ) ) {
				return new WP_Error( 'tsh_rate_limit', __( 'سقف درخواست API GitHub پر شده است (در هاست‌های اشتراکی رایج است از IP مشترک)؛ آینه jsDelivr امتحان شد. کمی صبر کنید و دوباره بزنید.', 'tisacase-hub' ) );
			}
			return new WP_Error( 'tsh_http', sprintf( /* translators: %d: status */ __( 'پاسخ HTTP %d از مخزن.', 'tisacase-hub' ), $code ) );
		}
			$body = wp_remote_retrieve_body( $response );
			if ( ! is_string( $body ) || strlen( $body ) < 2 ) {
				return new WP_Error( 'tsh_empty_zip', __( 'فایل زیپ خالی رسید.', 'tisacase-hub' ) );
			}
			$tmp = wp_tempnam( 'tsh-dl' );
			if ( ! $tmp ) {
				return new WP_Error( 'tsh_temp', __( 'فایل موقت ساخته نشد.', 'tisacase-hub' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $tmp, $body ) ) {
				return new WP_Error( 'tsh_temp', __( 'نوشتن فایل موقت ممکن نشد.', 'tisacase-hub' ) );
			}
			return $tmp;
		}

		/**
		 * وقتی هستهٔ وردپرس HTTP را بلوکه کرده، مستقیم می‌گیریم.
		 *
		 * @param string $url آدرس.
		 * @return string|\WP_Error
		 */
		private static function fetch_direct( $url ) {
			if ( ! self::host_ok( $url ) ) {
				return new WP_Error( 'tsh_host', __( 'میزبان مجاز نیست.', 'tisacase-hub' ) );
			}

			$body = '';
			$hdrs = self::headers_for( $url );
			$hdr_lines = array();
			foreach ( $hdrs as $k => $v ) {
				$hdr_lines[] = $k . ': ' . $v;
			}
			if ( function_exists( 'curl_init' ) ) {
				$ch = curl_init( $url );
				if ( $ch ) {
					$opts = array(
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_FOLLOWLOCATION => true,
						CURLOPT_MAXREDIRS      => 5,
						CURLOPT_TIMEOUT        => 90,
						CURLOPT_SSL_VERIFYPEER => true,
						CURLOPT_USERAGENT      => $hdrs['User-Agent'],
						CURLOPT_HTTPHEADER     => $hdr_lines,
					);
					curl_setopt_array( $ch, $opts );
					$got  = curl_exec( $ch );
					$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
					$cerr = curl_error( $ch );
					if ( ( ! is_string( $got ) || $code < 200 || $code >= 300 ) && $cerr && false !== stripos( $cerr, 'ssl' ) ) {
						$opts[ CURLOPT_SSL_VERIFYPEER ] = false;
						$opts[ CURLOPT_SSL_VERIFYHOST ] = 0;
						curl_setopt_array( $ch, $opts );
						$got  = curl_exec( $ch );
						$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
					}
					curl_close( $ch );
					if ( is_string( $got ) && $got && $code >= 200 && $code < 300 ) {
						$body = $got;
					}
				}
			}

			if ( '' === $body && ini_get( 'allow_url_fopen' ) ) {
				$ctx  = stream_context_create(
					array(
						'http' => array(
							'timeout'    => 90,
							'follow_location' => 1,
							'header'     => "Accept: application/zip,application/octet-stream,*/*\r\nUser-Agent: TisaCase-Hub\r\n",
						),
						'ssl'  => array(
							'verify_peer'      => true,
							'verify_peer_name' => true,
						),
					)
				);
				$got = @file_get_contents( $url, false, $ctx ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_string( $got ) && strlen( $got ) > 64 ) {
					$body = $got;
				}
			}

			if ( strlen( $body ) < 2 ) {
				return new WP_Error( 'tsh_empty_zip', __( 'دانلود مستقیم از مخزن ممکن نشد.', 'tisacase-hub' ) );
			}
			$tmp = wp_tempnam( 'tsh-dl' );
			if ( ! $tmp ) {
				return new WP_Error( 'tsh_temp', __( 'فایل موقت ساخته نشد.', 'tisacase-hub' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $tmp, $body );
			return $tmp;
		}

		public static function repo() {
			$repo = 'ImTheAlireza/TisaCaseHub';
			if ( class_exists( 'TSH_UI' ) ) {
				$raw = trim( (string) TSH_UI::setting( 'repo', $repo ) );
				if ( $raw && preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $raw ) ) {
					$repo = $raw;
				}
			}
			return $repo;
		}

		public static function branch() {
			$branch = 'main';
			if ( class_exists( 'TSH_UI' ) ) {
				$raw = trim( (string) TSH_UI::setting( 'branch', $branch ) );
				$raw = preg_replace( '#[^A-Za-z0-9._/-]#', '', $raw );
				if ( $raw ) {
					$branch = $raw;
				}
			}
			return $branch;
		}

		/**
		 * لینک گیت‌هاب → owner/repo + شاخه.
		 * نمونه: https://github.com/ImTheAlireza/TisaCaseHub/tree/arena/foo
		 *
		 * @param string $raw ورودی کاربر.
		 * @return array|\WP_Error {repo, branch}
		 */
		public static function parse_github_url( $raw ) {
			$raw = is_string( $raw ) ? $raw : '';
			$raw = wp_strip_all_tags( $raw );
			$raw = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
			$stripped = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $raw );
			if ( is_string( $stripped ) ) {
				$raw = $stripped;
			}
			$raw = preg_replace( '/[[:cntrl:]]+/', '', $raw );
			if ( preg_match( '#https?://(?:www\.)?github\.com/[^\s<>"\']+#i', $raw, $hit ) ) {
				$raw = rtrim( $hit[0], " \t.,);]" );
			} elseif ( preg_match( '#github\.com/[^\s<>"\']+#i', $raw, $hit ) ) {
				$raw = 'https://' . rtrim( $hit[0], " \t.,);]" );
			}
			$raw = str_replace( array( '%2F', '%2f' ), '/', $raw );
			$raw = rawurldecode( $raw );
			$raw = trim( $raw, " \t\n\r\"'<>" );
			if ( '' === $raw ) {
				return new WP_Error( 'tsh_url', __( 'لینک خالی است.', 'tisacase-hub' ) );
			}
			if ( preg_match( '#([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)(?:\.git)?#', $raw, $ow ) && false === stripos( $raw, 'github.com' ) && ! preg_match( '#^https?://#i', $raw ) ) {
				return array( 'repo' => $ow[1] . '/' . $ow[2], 'branch' => 'main' );
			}
			if ( preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $raw ) ) {
				return array( 'repo' => $raw, 'branch' => 'main' );
			}
			$raw = preg_replace( '#^git@github\.com:#i', 'https://github.com/', $raw );
			if ( ! preg_match( '#(?:https?://)?(?:www\.)?github\.com[/:]([^/\s]+)/([^/\s?#]+)#i', $raw, $m ) ) {
				return new WP_Error( 'tsh_url', __( 'این لینک گیت‌هاب نیست. مثل https://github.com/owner/repo بچسبانید.', 'tisacase-hub' ) );
			}
			$repo   = $m[1] . '/' . preg_replace( '/\.git$/', '', $m[2] );
			$branch = 'main';
			if ( preg_match( '#/(?:tree|blob|raw)/([^?#]+)#', $raw, $b ) ) {
				$branch = trim( $b[1], '/' );
			}
			$branch = preg_replace( '#[^A-Za-z0-9._/-]#', '', (string) $branch );
			if ( ! $branch ) {
				$branch = 'main';
			}
			return array( 'repo' => $repo, 'branch' => $branch );
		}

		/**
		 * تست: فهرست plugins/dist روی آن مخزن/شاخه.
		 *
		 * @param string $repo   owner/name.
		 * @param string $branch شاخه.
		 * @return array|\WP_Error {count, files}
		 */
		public static function bundled_catalog() {
			$file = TSH_DIR . 'includes/catalog.json';
			if ( ! is_readable( $file ) ) {
				return new WP_Error( 'tsh_bundle', __( 'فهرست همراه هاب پیدا نشد.', 'tisacase-hub' ) );
			}
			$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data = json_decode( (string) $raw, true );
			if ( ! is_array( $data ) || empty( $data['items'] ) ) {
				return new WP_Error( 'tsh_bundle', __( 'فهرست همراه هاب نامعتبر است.', 'tisacase-hub' ) );
			}
			$data['at']     = time();
			$data['source'] = 'bundle';
			return $data;
		}

		public static function test_connection( $repo, $branch ) {
			self::allow();
			$enc   = rawurlencode( $branch );
			$slash = str_replace( '/', '%2F', $branch );
			$body  = self::get_text(
				array(
					'https://api.github.com/repos/' . $repo . '/contents/plugins/dist?ref=' . $enc,
					'https://data.jsdelivr.com/v1/packages/gh/' . $repo . '@' . $enc . '/flat',
					'https://cdn.jsdelivr.net/gh/' . $repo . '@' . $slash . '/plugins/dist/tisacase-hub.zip',
				)
			);
			if ( is_wp_error( $body ) ) {
				$pack = self::bundled_catalog();
				if ( is_wp_error( $pack ) ) {
					return $body;
				}
				return array(
					'count'  => count( $pack['items'] ),
					'files'  => array_keys( $pack['items'] ),
					'remote' => false,
					'source' => 'bundle',
				);
			}
			$data = json_decode( $body, true );
			if ( isset( $data['message'] ) && ! isset( $data[0] ) && empty( $data['files'] ) ) {
				return new WP_Error( 'tsh_github', (string) $data['message'] );
			}
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'tsh_github', __( 'پاسخ مخزن نامعتبر بود.', 'tisacase-hub' ) );
			}
			$files = array();
			foreach ( $data as $row ) {
				if ( is_array( $row ) && ! empty( $row['name'] ) && preg_match( '/\.zip$/', (string) $row['name'] ) && false === strpos( (string) $row['name'], '/' ) ) {
					$files[] = (string) $row['name'];
				}
			}
			if ( isset( $data['files'] ) && is_array( $data['files'] ) ) {
				foreach ( $data['files'] as $row ) {
					$n = isset( $row['name'] ) ? (string) $row['name'] : '';
					if ( preg_match( '#/plugins/dist/([^/]+\.zip)$#', $n, $m ) ) {
						$files[] = $m[1];
					}
				}
			}
			$files = array_values( array_unique( $files ) );
			if ( empty( $files ) && is_string( $body ) && ( 0 === strpos( $body, 'PK' ) || false !== strpos( $body, 'Plugin Name:' ) ) ) {
				$files[] = 'tisacase-hub.zip';
			}
			return array(
				'count'  => count( $files ),
				'files'  => $files,
				'remote' => true,
				'source' => 'github',
			);
		}

		/**
		 * ذخیرهٔ اتصال در تنظیمات هاب.
		 *
		 * @param string $repo   owner/name.
		 * @param string $branch شاخه.
		 * @return void
		 */
		public static function save_connection( $repo, $branch ) {
			$saved = get_option( TSH_OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			$saved['repo']   = $repo;
			$saved['branch'] = $branch;
			update_option( TSH_OPTION, $saved, false );
			if ( class_exists( 'TSH_UI' ) ) {
				TSH_UI::flush();
			}
		}

		public static function get_text( $urls ) {
			self::allow();
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$urls = is_array( $urls ) ? $urls : array( $urls );
			$last = null;
			foreach ( $urls as $url ) {
				$url = trim( (string) $url );
				if ( ! $url || ! preg_match( '#^https://#i', $url ) ) {
					continue;
				}
				$got = self::fetch_one( $url );
				if ( ! is_wp_error( $got ) && is_string( $got ) && is_readable( $got ) ) {
					$body = (string) file_get_contents( $got ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					wp_delete_file( $got );
					if ( '' !== $body ) {
						return $body;
					}
				}
				$last = is_wp_error( $got ) ? $got : new WP_Error( 'tsh_empty', __( 'پاسخ خالی از مخزن.', 'tisacase-hub' ) );
			}
			return $last instanceof WP_Error ? $last : new WP_Error( 'tsh_empty', __( 'پاسخ خالی از مخزن.', 'tisacase-hub' ) );
		}

	public static function sync_catalog() {
		self::allow();
		$repo   = self::repo();
		$branch = self::branch();
		$enc  = rawurlencode( $branch );
		$api  = 'https://api.github.com/repos/' . $repo . '/contents/plugins/dist?ref=' . $enc;
		$body = self::get_text(
			array(
				$api,
				'https://data.jsdelivr.com/v1/packages/gh/' . $repo . '@' . $enc . '/flat',
			)
		);

		$names = array();
		if ( is_wp_error( $body ) ) {
			// آفلاین (نه GitHub و نه آینه پاسخ داد): فهرست باندل‌شده با خود هاب.
			$pack = self::bundled_catalog();
			if ( is_wp_error( $pack ) ) {
				return $body;
			}
			$pack['repo']   = $repo;
			$pack['branch'] = $branch;
			update_option( 'tisacase_hub_catalog', $pack, false );
			return $pack;
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'tsh_catalog', __( 'فهرست مخزن خوانده نشد.', 'tisacase-hub' ) );
		}

		if ( isset( $data['files'] ) && is_array( $data['files'] ) ) {
			// ساختار jsDelivr: فایل‌ها زیر «files» با نامِ شلش‌دار.
			foreach ( $data['files'] as $f ) {
				if ( is_array( $f ) && ! empty( $f['name'] ) ) {
					$names[] = ltrim( (string) $f['name'], '/' );
				}
			}
		} else {
			// ساختار GitHub API: لیست رکوردها.
			if ( isset( $data['message'] ) && ! isset( $data[0] ) ) {
				return new WP_Error( 'tsh_catalog', (string) $data['message'] );
			}
			foreach ( $data as $row ) {
				if ( ! is_array( $row ) || empty( $row['name'] ) ) {
					continue;
				}
				if ( ( isset( $row['type'] ) ? $row['type'] : 'file' ) !== 'file' ) {
					continue;
				}
				$names[] = (string) $row['name'];
			}
		}

		$items = array();
		foreach ( $names as $name ) {
			if ( ! preg_match( '/^([a-zA-Z0-9._-]+)\.zip$/', (string) $name, $m ) ) {
				continue;
			}
			$dir = $m[1];
			if ( 'tisacase-hub' === $dir ) {
				continue;
			}
			$meta = self::plugin_meta( $repo, $branch, $dir );
			$key  = sanitize_key( isset( $meta['key'] ) ? $meta['key'] : $dir );
			$items[ $key ] = array(
				'title'  => $meta['title'],
				'desc'   => $meta['desc'],
				'group'  => $meta['group'],
				'icon'   => $meta['icon'],
				'dir'    => $dir,
				'cap'    => $meta['cap'],
				'pages'  => $meta['pages'],
				'source' => 'github',
			);
		}

		// محافظ: فهرست خالی هرگز کاتالوگِ فعلی را پاک نمی‌کند.
		if ( empty( $items ) ) {
			return new WP_Error( 'tsh_catalog', __( 'هیچ فایل زیپ در فهرست مخزن پیدا نشد؛ فهرست قبلی حفظ شد.', 'tisacase-hub' ) );
		}

		$pack = array(
			'repo'   => $repo,
			'branch' => $branch,
			'at'     => time(),
			'items'  => $items,
		);
		update_option( 'tisacase_hub_catalog', $pack, false );
		return $pack;
	}

		public static function catalog() {
			$pack = get_option( 'tisacase_hub_catalog', array() );
			return is_array( $pack ) ? $pack : array();
		}

	private static function plugin_meta( $repo, $branch, $dir ) {
		$out = array(
			'title' => $dir,
			'desc'  => '',
			'group' => 'products',
			'icon'  => 'plug',
			'cap'   => 'manage_woocommerce',
			'pages' => array(),
			'key'   => sanitize_key( $dir ),
		);
		$branch_enc = rawurlencode( $branch );

		// مسیر سریع: آدرسِ کنوانسیونالِ فایل اصلی — بدون مصرف از limit API.
		// (شاخه در مسیر به‌صورت خام می‌رود — همان فرمت download_url خود GitHub؛ در query-string آرم‌کد می‌شود.)
		$raw_url = 'https://raw.githubusercontent.com/' . $repo . '/' . $branch . '/plugins/src/' . rawurlencode( $dir ) . '/' . rawurlencode( $dir ) . '.php';
		$php     = self::get_text( array( $raw_url ) );

		// فالبک: فهرست‌گیری پوشه از API (برای ساختارهای غیرکنوانسیونال).
		if ( is_wp_error( $php ) || false === strpos( (string) $php, 'Plugin Name:' ) ) {
			$php = null;
			$list_url  = 'https://api.github.com/repos/' . $repo . '/contents/plugins/src/' . rawurlencode( $dir ) . '?ref=' . $branch_enc;
			$list_body = self::get_text( array( $list_url ) );
			if ( ! is_wp_error( $list_body ) ) {
				$decoded = json_decode( $list_body, true );
				$files   = array();
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $row ) {
						if ( ! empty( $row['name'] ) && ! empty( $row['download_url'] ) && preg_match( '/\.php$/', (string) $row['name'] ) ) {
							$files[] = (string) $row['download_url'];
						}
					}
				}
				foreach ( $files as $file_url ) {
					$candidate = self::get_text( array( $file_url ) );
					if ( ! is_wp_error( $candidate ) && false !== strpos( (string) $candidate, 'Plugin Name:' ) ) {
						$php = $candidate;
						break;
					}
				}
			}
		}

		if ( is_string( $php ) && false !== strpos( $php, 'Plugin Name:' ) ) {
			$name = self::header_value( $php, 'Plugin Name' );
			$desc = self::header_value( $php, 'Description' );
			$hub  = self::header_value( $php, 'TisaCase Hub' );
			if ( $name ) { $out['title'] = $name; }
			if ( $desc ) { $out['desc'] = wp_strip_all_tags( $desc ); }
			if ( $hub && class_exists( 'TSH_Registry' ) ) {
				$parsed = TSH_Registry::parse_header( $hub );
				if ( ! empty( $parsed['title'] ) ) { $out['title'] = $parsed['title']; }
				if ( ! empty( $parsed['desc'] ) ) { $out['desc'] = $parsed['desc']; }
				if ( ! empty( $parsed['group'] ) ) { $out['group'] = $parsed['group']; }
				if ( ! empty( $parsed['icon'] ) ) { $out['icon'] = $parsed['icon']; }
				if ( ! empty( $parsed['cap'] ) ) { $out['cap'] = $parsed['cap']; }
				if ( ! empty( $parsed['key'] ) ) { $out['key'] = sanitize_key( $parsed['key'] ); }
				if ( ! empty( $parsed['page'] ) ) {
					$out['pages'][] = array(
						'label'  => __( 'باز کردن', 'tisacase-hub' ),
						'path'   => $parsed['page'],
						'screen' => isset( $parsed['screen'] ) ? $parsed['screen'] : '',
						'parent' => isset( $parsed['parent'] ) ? $parsed['parent'] : '',
						'slug'   => isset( $parsed['slug'] ) ? $parsed['slug'] : '',
					);
				}
			}
		}
		return $out;
	}

		private static function header_value( $php, $key ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . ':[ \t]*(.+)$/mi', $php, $m ) ) {
				return trim( $m[1] );
			}
			return '';
		}
	}
}
