<?php
/**
 * 核心邏輯：Rewrite Rules、llms.txt 生成、Markdown 頁面服務。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MoeLog_LLMS_Txt {
	const TRANSIENT_KEY = 'moelog_llms_txt_index_v1';
	const CACHE_TTL     = 1800;

	/**
	 * 新增 Rewrite Rules。
	 * - /llms.txt        → 網站索引
	 * - /{slug}.md       → 文章/頁面的 Markdown 版本
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?moelog_llms_txt=1', 'top' );
		add_rewrite_rule( '^(.+)\.md$', 'index.php?moelog_llms_md=$matches[1]', 'top' );
	}

	/**
	 * 註冊自訂 Query Vars。
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'moelog_llms_txt';
		$vars[] = 'moelog_llms_md';
		return $vars;
	}

	/**
	 * 攔截請求並分派處理。
	 */
	public static function handle_request() {
		if ( get_query_var( 'moelog_llms_txt' ) ) {
			self::validate_request_method();
			self::serve_llms_txt( self::is_head_request() );
		}

		$md_slug = get_query_var( 'moelog_llms_md', false );
		if ( false !== $md_slug && '' !== (string) $md_slug ) {
			self::validate_request_method();
			self::serve_markdown( (string) $md_slug, self::is_head_request() );
		}
	}

	/**
	 * 清除 /llms.txt 索引快取。
	 *
	 * 此 callback 故意不接收 hook 傳入的參數，避免把 post ID 或 option 舊值
	 * 誤當成 transient key。
	 */
	public static function flush_index_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * 只允許公開讀取端點使用 GET 或 HEAD。
	 */
	private static function validate_request_method() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		status_header( 405 );
		header( 'Allow: GET, HEAD' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		nocache_headers();
		echo "405 Method Not Allowed\n";
		exit;
	}

	/**
	 * 判斷目前是否為 HEAD 請求。
	 */
	private static function is_head_request() {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] );
	}

	/**
	 * 輸出 /llms.txt 索引檔案。
	 */
	private static function serve_llms_txt( $head_only = false ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=' . self::CACHE_TTL );

		if ( $head_only ) {
			exit;
		}

		$output = get_transient( self::TRANSIENT_KEY );
		if ( false === $output ) {
			$output = self::build_llms_txt();
			set_transient( self::TRANSIENT_KEY, $output, self::CACHE_TTL );
		}

		echo $output;
		exit;
	}

	/**
	 * 建立 /llms.txt 索引內容。
	 */
	private static function build_llms_txt() {

		$site_name   = get_bloginfo( 'name' );
		$site_desc   = get_bloginfo( 'description' );
		$pretty_urls = '' !== (string) get_option( 'permalink_structure' );

		$output = '# ' . self::escape_markdown_text( $site_name ) . "\n\n";

		if ( $site_desc ) {
			$output .= '> ' . self::escape_markdown_text( $site_desc ) . "\n\n";
		}

		$output .= "本文件依循 llms.txt 提案格式，提供適合 AI 語言模型閱讀的網站內容索引。\n\n";
		if ( $pretty_urls ) {
			$output .= "下列連結提供文章與頁面的 Markdown 純文字版本。\n\n";
		} else {
			$output .= "本站使用 Plain Permalinks，因此下列連結指向原始 HTML 頁面，不提供 `.md` 版本。\n\n";
		}

		// 最新文章
		$posts = get_posts( array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => -1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		if ( $posts ) {
			$output .= "## 文章\n\n";
			foreach ( $posts as $post ) {
				$permalink = get_permalink( $post->ID );
				$url       = $pretty_urls ? self::to_md_url( $permalink ) : $permalink;
				$title     = self::escape_markdown_link_text( get_the_title( $post ) );
				$date      = mysql2date( 'Y-m-d', $post->post_date );
				$output   .= '- [' . $title . '](' . self::escape_markdown_url( $url ) . '): ' . $date . "\n";
			}
			$output .= "\n";
		}


		// 頁面
		$pages = get_pages( array(
			'post_status' => 'publish',
			'sort_column' => 'menu_order',
			'sort_order'  => 'ASC',
		) );

		if ( $pages ) {
			$page_lines = '';
			foreach ( $pages as $page ) {
				if ( ! empty( $page->post_password ) ) {
					continue;
				}
				$permalink = get_permalink( $page->ID );
				$url       = $pretty_urls ? self::to_md_url( $permalink ) : $permalink;
				$title     = self::escape_markdown_link_text( get_the_title( $page ) );
				$page_lines .= '- [' . $title . '](' . self::escape_markdown_url( $url ) . ")\n";
			}
			if ( '' !== $page_lines ) {
				$output .= "## 頁面\n\n" . $page_lines . "\n";
			}
		}

		return $output;
	}

	/**
	 * 輸出文章/頁面的 Markdown 版本。
	 *
	 * @param string $slug  Rewrite 捕獲的路徑（不含 .md）
	 */
	private static function serve_markdown( $slug, $head_only = false ) {
		$post_id = self::resolve_post_id( $slug );

		if ( ! $post_id ) {
			self::serve_not_found( $head_only );
		}

		$post = get_post( $post_id );
		if (
			! $post ||
			'publish' !== $post->post_status ||
			! in_array( $post->post_type, array( 'post', 'page' ), true ) ||
			! empty( $post->post_password )
		) {
			self::serve_not_found( $head_only );
		}

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=3600' );

		if ( $head_only ) {
			exit;
		}

		// Plain Permalinks 下沒有可用的 .md 端點，此時不改寫站內連結。
		$pretty_urls = '' !== (string) get_option( 'permalink_structure' );
		$converter   = new MoeLog_HTML_To_Markdown(
			home_url(),
			$pretty_urls ? array( __CLASS__, 'internal_url_has_markdown' ) : null
		);
		$output = self::build_markdown( $post, $converter );

		echo $output;
		exit;
	}

	/**
	 * 判斷站內網址是否對應到有 `.md` 版本的公開文章或頁面。
	 *
	 * 供轉換器改寫正文中的站內連結使用。分類、標籤、附件、草稿與
	 * 密碼保護內容都會回傳 false，避免產生指向 404 的 `.md` 連結。
	 */
	public static function internal_url_has_markdown( $url ) {
		static $cache = array();

		$url = (string) $url;
		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		$result  = false;
		$post_id = url_to_postid( $url );

		if ( $post_id ) {
			$post   = get_post( $post_id );
			$result = $post
				&& 'publish' === $post->post_status
				&& in_array( $post->post_type, array( 'post', 'page' ), true )
				&& empty( $post->post_password );
		}

		$cache[ $url ] = $result;

		return $result;
	}

	/**
	 * 輸出一致的 404 回應。
	 */
	private static function serve_not_found( $head_only = false ) {
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		nocache_headers();

		if ( ! $head_only ) {
			echo "404 Not Found\n\n找不到對應的文章或頁面。";
		}
		exit;
	}

	/**
	 * 組合完整的 Markdown 輸出。
	 */
	private static function build_markdown( WP_Post $post, MoeLog_HTML_To_Markdown $converter ) {
		$output = '# ' . self::escape_markdown_text( get_the_title( $post ) ) . "\n\n";

		// 後設資料
		$date   = get_the_date( 'Y-m-d', $post->ID );
		$author = get_the_author_meta( 'display_name', $post->post_author );
		$output .= '> **發佈日期：** ' . $date . '　**作者：** ' . self::normalize_plain_text( $author ) . "\n\n";

		// 分類與標籤（僅文章）
		if ( 'post' === $post->post_type ) {
			$categories = get_the_category( $post->ID );
			if ( $categories ) {
				$cat_names = array_map( array( __CLASS__, 'normalize_plain_text' ), wp_list_pluck( $categories, 'name' ) );
				$output   .= '**分類：** ' . implode( '、', $cat_names ) . "\n\n";
			}

			$tags = get_the_tags( $post->ID );
			if ( $tags ) {
				$tag_names = array_map( array( __CLASS__, 'normalize_plain_text' ), wp_list_pluck( $tags, 'name' ) );
				$output   .= '**標籤：** ' . implode( '、', $tag_names ) . "\n\n";
			}
		}

		// 摘要（若有）
		if ( $post->post_excerpt ) {
			$excerpt = self::normalize_plain_text( $post->post_excerpt );
			$output .= '**摘要：** ' . $excerpt . "\n\n";
		}

		$output .= "---\n\n";

		// 主要內容：套用 the_content 過濾器前，先移除常見的噪音 filter
		// （廣告注入、相關文章、社群分享按鈕等），用完再還原。
		$hook_snapshot   = self::suspend_noise_filters();
		$global_snapshot = self::snapshot_post_globals();

		try {
			$GLOBALS['post'] = $post;
			setup_postdata( $post );
			$content = apply_filters( 'the_content', $post->post_content );
		} finally {
			self::restore_noise_filters( $hook_snapshot );
			self::restore_post_globals( $global_snapshot );
		}

		$output .= $converter->convert( $content );

		// 來源連結
		$output .= "\n\n---\n\n";
		$output .= '**來源：** ' . self::escape_markdown_url( get_permalink( $post->ID ) ) . "\n";

		return $output;
	}

	/**
	 * 根據 slug 路徑解析對應的 Post ID。
	 * 嘗試多種方式以支援不同的 Permalink 結構。
	 */
	private static function resolve_post_id( $slug ) {
		$slug       = ltrim( rawurldecode( (string) $slug ), '/' );
		$home_url   = untrailingslashit( home_url() );
		$candidates = array();

		// 方法 1：直接用 url_to_postid（帶尾部斜線）
		$post_id = url_to_postid( $home_url . '/' . $slug . '/' );
		if ( $post_id ) {
			$candidates[] = $post_id;
		}

		// 方法 2：不帶尾部斜線
		$post_id = url_to_postid( $home_url . '/' . $slug );
		if ( $post_id ) {
			$candidates[] = $post_id;
		}

		// 方法 3：先用完整路徑查詢（巢狀頁面用）
		$post = get_page_by_path( $slug, OBJECT, array( 'post', 'page' ) );
		if ( $post ) {
			$candidates[] = $post->ID;
		}

		// 方法 4：最後才用最末段 slug 作為寬鬆 fallback。
		$parts     = explode( '/', $slug );
		$post_name = end( $parts );

		$post = get_page_by_path( $post_name, OBJECT, array( 'post', 'page' ) );
		if ( $post ) {
			$candidates[] = $post->ID;
		}

		foreach ( array_unique( array_map( 'intval', $candidates ) ) as $candidate_id ) {
			if ( self::permalink_matches_slug( $candidate_id, $slug ) ) {
				return $candidate_id;
			}
		}

		return 0;
	}

	/**
	 * 用 canonical permalink path 驗證解析結果，避免只靠最後一段 slug 誤中。
	 */
	private static function permalink_matches_slug( $post_id, $slug ) {
		$expected = self::normalize_url_path( get_permalink( $post_id ) );
		$actual   = self::normalize_url_path( home_url( '/' . ltrim( $slug, '/' ) ) );

		return '' !== $expected && $expected === $actual;
	}

	/**
	 * 正規化 URL path；兩側都先解碼，以支援中文及百分比編碼 slug。
	 */
	private static function normalize_url_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}

		$path = rawurldecode( $path );
		$path = preg_replace( '#/+#', '/', '/' . ltrim( $path, '/' ) );

		return untrailingslashit( $path );
	}

	/**
	 * 暫時移除已知會注入噪音的 the_content filter。
	 * 回傳原始 WP_Hook snapshot，供 restore_noise_filters() 精確還原。
	 */
	private static function suspend_noise_filters() {
		global $wp_filter;

		// 常見注入廣告、相關文章、社群按鈕的 callback 關鍵字
		$noise_patterns = array(
			'inject_ads',
			'related_posts',
			'share_buttons',
			'social_share',
			'yarpp', // Yet Another Related Posts Plugin
		);
		$noise_patterns = apply_filters( 'moelog_llms_noise_patterns', $noise_patterns );

		if ( ! isset( $wp_filter['the_content'] ) || ! $wp_filter['the_content'] instanceof WP_Hook ) {
			return null;
		}

		$hook_snapshot = clone $wp_filter['the_content'];
		$to_remove     = array();

		// 先收集，完成遍歷後才移除，避免邊走訪邊改 callbacks。
		foreach ( $wp_filter['the_content']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$func_name = self::callback_name( $callback['function'] );

				foreach ( $noise_patterns as $pattern ) {
					if ( is_string( $pattern ) && '' !== $pattern && false !== stripos( $func_name, $pattern ) ) {
						$to_remove[] = array(
							'function'      => $callback['function'],
							'priority'      => $priority,
						);
						break;
					}
				}
			}
		}

		foreach ( $to_remove as $filter ) {
			remove_filter( 'the_content', $filter['function'], $filter['priority'] );
		}

		return $hook_snapshot;
	}

	/**
	 * 完整還原 suspend_noise_filters() 前的 WP_Hook，包含 callback 順序。
	 *
	 * 這是刻意的全量回捲：產生 Markdown 後即結束請求，因此優先保證
	 * 此端點不會永久改變既有 callback、priority 或執行順序。
	 */
	private static function restore_noise_filters( $hook_snapshot ) {
		global $wp_filter;

		if ( $hook_snapshot instanceof WP_Hook ) {
			$wp_filter['the_content'] = $hook_snapshot;
		}
	}

	/**
	 * 將 callable 轉成可比對的名稱。
	 */
	private static function callback_name( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $owner . '::' . $callback[1];
		}

		if ( is_object( $callback ) ) {
			return get_class( $callback );
		}

		return '';
	}

	/**
	 * 保存 setup_postdata() 可能修改的 globals，供 finally 精確還原。
	 */
	private static function snapshot_post_globals() {
		$keys = array( 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
		$snapshot = array();

		foreach ( $keys as $key ) {
			$snapshot[ $key ] = array(
				'exists' => array_key_exists( $key, $GLOBALS ),
				'value'  => array_key_exists( $key, $GLOBALS ) ? $GLOBALS[ $key ] : null,
			);
		}

		return $snapshot;
	}

	/**
	 * 還原 snapshot_post_globals() 保存的 globals。
	 */
	private static function restore_post_globals( array $snapshot ) {
		foreach ( $snapshot as $key => $state ) {
			if ( $state['exists'] ) {
				$GLOBALS[ $key ] = $state['value'];
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
	}

	/**
	 * 清理由網站資料產生的 Markdown 結構文字。
	 *
	 * 正文 DOM 文字節點不使用此方法，避免過度跳脫一般內容。
	 */
	private static function escape_markdown_text( $text ) {
		$text = self::normalize_plain_text( $text );
		$text = str_replace(
			array( '\\', '`', '*', '_', '[', ']' ),
			array( '\\\\', '\\`', '\\*', '\\_', '\\[', '\\]' ),
			$text
		);

		return preg_replace_callback(
			'/^(\s*)([#>\-+]|\d+\.)/u',
			function ( $matches ) {
				if ( preg_match( '/^\d+\.$/', $matches[2] ) ) {
					return $matches[1] . substr( $matches[2], 0, -1 ) . '\\.';
				}
				return $matches[1] . '\\' . $matches[2];
			},
			$text
		);
	}

	/**
	 * 將散文或資料欄位轉成無 HTML 的單行文字，不增加 Markdown 反斜線。
	 */
	private static function normalize_plain_text( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return (string) preg_replace( '/\s*[\r\n]+\s*/u', ' ', $text );
	}

	/**
	 * 跳脫 Markdown link label；保留 label 內既有的強調格式。
	 */
	private static function escape_markdown_link_text( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s*[\r\n]+\s*/u', ' ', $text );
		return str_replace( array( '\\', '[', ']' ), array( '\\\\', '\\[', '\\]' ), $text );
	}

	/**
	 * 將 URL 轉成安全的 Markdown link destination。
	 */
	private static function escape_markdown_url( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return str_replace(
			array( ' ', '(', ')', '<', '>' ),
			array( '%20', '%28', '%29', '%3C', '%3E' ),
			$url
		);
	}

	/**
	 * 將 Permalink 轉為 .md URL。
	 * 例：https://example.com/my-post/  →  https://example.com/my-post.md
	 */
	private static function to_md_url( $permalink ) {
		return untrailingslashit( $permalink ) . '.md';
	}

	/**
	 * 在 <head> 加入 llms.txt 的 <link> 標籤，方便 AI 爬蟲自動發現。
	 */
	public static function add_head_link() {
		$url = home_url( '/llms.txt' );
		echo '<link rel="llms-txt" type="text/plain" href="' . esc_url( $url ) . '" />' . "\n";
	}

	/**
	 * 在 robots.txt 末尾加入 llms.txt 的位置提示。
	 */
	public static function append_robots_txt( $output, $public ) {
		if ( $public ) {
			$output .= "\n# LLMs.txt: " . esc_url_raw( home_url( '/llms.txt' ) ) . "\n";
		}
		return $output;
	}
}
