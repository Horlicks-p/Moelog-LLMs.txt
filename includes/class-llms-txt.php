<?php
/**
 * 核心邏輯：Rewrite Rules、llms.txt 生成、Markdown 頁面服務。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MoeLog_LLMS_Txt {

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
			self::serve_llms_txt();
		}

		$md_slug = get_query_var( 'moelog_llms_md' );
		if ( $md_slug ) {
			self::serve_markdown( $md_slug );
		}
	}

	/**
	 * 輸出 /llms.txt 索引檔案。
	 */
	private static function serve_llms_txt() {
		header( 'Content-Type: text/plain; charset=utf-8' );
		// 禁止快取（內容隨時更新）
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );

		$site_name = get_bloginfo( 'name' );
		$site_desc = get_bloginfo( 'description' );

		$output  = "# {$site_name}\n\n";

		if ( $site_desc ) {
			$output .= "> {$site_desc}\n\n";
		}

		$output .= "> 本文件遵循 llms.txt 規範，提供適合 AI 語言模型閱讀的網站內容索引。\n";
		$output .= "> 在各連結網址後加上 `.md` 即可取得該頁面的 Markdown 純文字版本。\n\n";

		// 最新文章
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		if ( $posts ) {
			$output .= "## 文章\n\n";
			foreach ( $posts as $post ) {
				$permalink = get_permalink( $post->ID );
				$md_url    = self::to_md_url( $permalink );
				$title     = $post->post_title;
				$date      = get_the_date( 'Y-m-d', $post->ID );
				$output   .= "- [{$title}]({$md_url}): {$date}\n";
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
			$output .= "## 頁面\n\n";
			foreach ( $pages as $page ) {
				$permalink = get_permalink( $page->ID );
				$md_url    = self::to_md_url( $permalink );
				$title     = $page->post_title;
				$output   .= "- [{$title}]({$md_url})\n";
			}
			$output .= "\n";
		}

		echo $output;
		exit;
	}

	/**
	 * 輸出文章/頁面的 Markdown 版本。
	 *
	 * @param string $slug  Rewrite 捕獲的路徑（不含 .md）
	 */
	private static function serve_markdown( $slug ) {
		$post_id = self::resolve_post_id( $slug );

		if ( ! $post_id ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "404 Not Found\n\n找不到對應的文章或頁面。";
			exit;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "404 Not Found\n\n找不到對應的文章或頁面。";
			exit;
		}

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=3600' );

		$converter = new MoeLog_HTML_To_Markdown();
		$output    = self::build_markdown( $post, $converter );

		echo $output;
		exit;
	}

	/**
	 * 組合完整的 Markdown 輸出。
	 */
	private static function build_markdown( WP_Post $post, MoeLog_HTML_To_Markdown $converter ) {
		$output = '# ' . $post->post_title . "\n\n";

		// 後設資料
		$date   = get_the_date( 'Y-m-d', $post->ID );
		$author = get_the_author_meta( 'display_name', $post->post_author );
		$output .= "> **發佈日期：** {$date}　**作者：** {$author}\n\n";

		// 分類與標籤（僅文章）
		if ( $post->post_type === 'post' ) {
			$categories = get_the_category( $post->ID );
			if ( $categories ) {
				$cat_names = wp_list_pluck( $categories, 'name' );
				$output   .= '**分類：** ' . implode( '、', $cat_names ) . "\n\n";
			}

			$tags = get_the_tags( $post->ID );
			if ( $tags ) {
				$tag_names = wp_list_pluck( $tags, 'name' );
				$output   .= '**標籤：** ' . implode( '、', $tag_names ) . "\n\n";
			}
		}

		// 摘要（若有）
		if ( $post->post_excerpt ) {
			$excerpt = wp_strip_all_tags( $post->post_excerpt );
			$output .= "**摘要：** {$excerpt}\n\n";
		}

		$output .= "---\n\n";

		// 主要內容：套用 the_content 過濾器前，先移除常見的噪音 filter
		// （廣告注入、相關文章、社群分享按鈕等），用完再還原。
		$removed = self::suspend_noise_filters();

		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$content = apply_filters( 'the_content', $post->post_content );
		wp_reset_postdata();

		self::restore_noise_filters( $removed );

		$output .= $converter->convert( $content );

		// 來源連結
		$output .= "\n\n---\n\n";
		$output .= '**來源：** ' . get_permalink( $post->ID ) . "\n";

		return $output;
	}

	/**
	 * 根據 slug 路徑解析對應的 Post ID。
	 * 嘗試多種方式以支援不同的 Permalink 結構。
	 */
	private static function resolve_post_id( $slug ) {
		$slug      = ltrim( $slug, '/' );
		$home_url  = untrailingslashit( home_url() );

		// 方法 1：直接用 url_to_postid（帶尾部斜線）
		$post_id = url_to_postid( $home_url . '/' . $slug . '/' );
		if ( $post_id ) {
			return $post_id;
		}

		// 方法 2：不帶尾部斜線
		$post_id = url_to_postid( $home_url . '/' . $slug );
		if ( $post_id ) {
			return $post_id;
		}

		// 方法 3：用最後一段 slug 查詢 post_name
		$parts     = explode( '/', $slug );
		$post_name = end( $parts );

		$post = get_page_by_path( $post_name, OBJECT, array( 'post', 'page' ) );
		if ( $post ) {
			return $post->ID;
		}

		// 方法 4：全路徑查詢（巢狀頁面用）
		$post = get_page_by_path( $slug, OBJECT, array( 'post', 'page' ) );
		if ( $post ) {
			return $post->ID;
		}

		return 0;
	}

	/**
	 * 暫時移除已知會注入噪音的 the_content filter。
	 * 回傳被移除的 filter 清單，供 restore_noise_filters() 還原。
	 */
	private static function suspend_noise_filters() {
		global $wp_filter;

		// 常見注入廣告、相關文章、社群按鈕的 callback 關鍵字
		$noise_patterns = array(
			'inject_ads',
			'related_posts',
			'share_buttons',
			'social_share',
			'yarpp',       // Yet Another Related Posts Plugin
			'wpp_',        // WP-PostViews 等
			'jetpack',
		);

		$removed = array();

		if ( ! isset( $wp_filter['the_content'] ) ) {
			return $removed;
		}

		foreach ( $wp_filter['the_content']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $key => $callback ) {
				$func_name = '';
				if ( is_string( $callback['function'] ) ) {
					$func_name = $callback['function'];
				} elseif ( is_array( $callback['function'] ) ) {
					$func_name = is_object( $callback['function'][0] )
						? get_class( $callback['function'][0] ) . '::' . $callback['function'][1]
						: implode( '::', $callback['function'] );
				}

				foreach ( $noise_patterns as $pattern ) {
					if ( stripos( $func_name, $pattern ) !== false ) {
						$removed[] = array(
							'function'      => $callback['function'],
							'priority'      => $priority,
							'accepted_args' => $callback['accepted_args'],
						);
						remove_filter( 'the_content', $callback['function'], $priority );
						break;
					}
				}
			}
		}

		return $removed;
	}

	/**
	 * 還原被 suspend_noise_filters() 移除的 filter。
	 */
	private static function restore_noise_filters( array $removed ) {
		foreach ( $removed as $filter ) {
			add_filter( 'the_content', $filter['function'], $filter['priority'], $filter['accepted_args'] );
		}
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
			$output .= "\n# LLMs.txt (AI-friendly content index)\n";
			$output .= 'LLMs-txt: ' . home_url( '/llms.txt' ) . "\n";
		}
		return $output;
	}
}
