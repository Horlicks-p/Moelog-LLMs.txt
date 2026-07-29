<?php
/**
 * HTML 轉 Markdown 轉換器
 * 使用 DOMDocument 遞迴遍歷，將 WordPress 文章內容轉為乾淨的 Markdown。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MoeLog_HTML_To_Markdown {

	/**
	 * 網站根網址，用來把相對與 protocol-relative URL 轉成絕對網址。
	 *
	 * @var string
	 */
	private $base_url = '';

	/**
	 * base_url 的 scheme，供 protocol-relative URL 補齊使用。
	 *
	 * @var string
	 */
	private $base_scheme = 'https';

	/**
	 * 判斷站內網址是否有對應 `.md` 版本的 callback。
	 *
	 * 由呼叫端注入（需要 WordPress 查詢），converter 本身不依賴 WordPress，
	 * 未注入時所有連結維持原樣。
	 *
	 * @var callable|null
	 */
	private $internal_link_resolver = null;

	/**
	 * @param string        $base_url                網站根網址，通常是 home_url()。
	 * @param callable|null $internal_link_resolver  接受絕對網址、回傳 bool 的 callback。
	 */
	public function __construct( $base_url = '', $internal_link_resolver = null ) {
		$this->base_url = rtrim( (string) $base_url, '/' );

		if ( '' !== $this->base_url ) {
			$scheme = parse_url( $this->base_url, PHP_URL_SCHEME );
			if ( is_string( $scheme ) && '' !== $scheme ) {
				$this->base_scheme = strtolower( $scheme );
			}
		}

		if ( is_callable( $internal_link_resolver ) ) {
			$this->internal_link_resolver = $internal_link_resolver;
		}
	}

	/**
	 * 將 HTML 字串轉換為 Markdown。
	 *
	 * @param string $html
	 * @return string
	 */
	public function convert( $html ) {
		if ( empty( trim( $html ) ) ) {
			return '';
		}

		if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
			return wp_strip_all_tags( $html );
		}

		$dom = new DOMDocument();
		$previous_libxml_state = libxml_use_internal_errors( true );
		$root = null;

		try {
			$document = '<!DOCTYPE html><html><head>'
				. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
				. '<meta charset="UTF-8"></head><body>'
				. '<div id="moelog-llms-root">' . $html . '</div></body></html>';
			$loaded = $dom->loadHTML( $document, LIBXML_NONET );

			if ( $loaded ) {
				$xpath = new DOMXPath( $dom );
				$nodes = $xpath->query( '//div[@id="moelog-llms-root"]' );
				if ( $nodes && $nodes->length > 0 ) {
					$root = $nodes->item( 0 );
				}
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_libxml_state );
		}

		if ( ! $root ) {
			return wp_strip_all_tags( $html );
		}

		$markdown = $this->process_children( $root );

		// 清除多餘的連續空行（最多保留一個空行）
		$markdown = preg_replace( '/\n{3,}/', "\n\n", $markdown );

		return trim( $markdown );
	}

	/**
	 * 遍歷子節點並組合輸出。
	 */
	private function process_children( DOMNode $node ) {
		$output = '';
		foreach ( $node->childNodes as $child ) {
			$output .= $this->convert_node( $child );
		}
		return $output;
	}

	/**
	 * 將單一 DOM 節點轉換為 Markdown 字串。
	 */
	private function convert_node( DOMNode $node ) {
		// 純文字節點
		if ( $node->nodeType === XML_TEXT_NODE ) {
			return $node->nodeValue;
		}

		// 只處理元素節點
		if ( $node->nodeType !== XML_ELEMENT_NODE ) {
			return '';
		}

		$tag   = strtolower( $node->nodeName );
		$skip_tags = array(
			'script', 'style', 'nav', 'header', 'footer', 'aside', 'form',
			'input', 'button', 'select', 'textarea', 'iframe', 'noscript',
			'svg', 'canvas',
		);
		if ( in_array( $tag, $skip_tags, true ) ) {
			return '';
		}

		$inner = $this->process_children( $node );

		switch ( $tag ) {

			// 標題
			case 'h1': return "\n\n# " . $this->normalize_single_line( $inner ) . "\n\n";
			case 'h2': return "\n\n## " . $this->normalize_single_line( $inner ) . "\n\n";
			case 'h3': return "\n\n### " . $this->normalize_single_line( $inner ) . "\n\n";
			case 'h4': return "\n\n#### " . $this->normalize_single_line( $inner ) . "\n\n";
			case 'h5': return "\n\n##### " . $this->normalize_single_line( $inner ) . "\n\n";
			case 'h6': return "\n\n###### " . $this->normalize_single_line( $inner ) . "\n\n";

			// 段落與換行
			case 'p':  return "\n\n" . trim( $inner ) . "\n\n";
			case 'br': return "\n";
			case 'hr': return "\n\n---\n\n";

			// 強調
			case 'strong':
			case 'b':
				$text = trim( $inner );
				return $text ? '**' . $text . '**' : '';

			case 'em':
			case 'i':
				$text = trim( $inner );
				return $text ? '*' . $text . '*' : '';

			case 'del':
			case 's':
				$text = trim( $inner );
				return $text ? '~~' . $text . '~~' : '';

			// 行內程式碼
			case 'code':
				// 若父節點是 <pre>，讓 pre 處理
				if ( $node->parentNode && strtolower( $node->parentNode->nodeName ) === 'pre' ) {
					return $node->nodeValue;
				}
				return $this->format_inline_code( $node->nodeValue );

			// 程式碼區塊
			case 'pre':
				$lang      = '';
				$code_node = $node->getElementsByTagName( 'code' )->item( 0 );
				if ( $code_node ) {
					$class = $code_node->getAttribute( 'class' );
					if ( preg_match( '/language-(\w+)/', $class, $m ) ) {
						$lang = $m[1];
					}
					$code_content = $code_node->nodeValue;
				} else {
					$code_content = $node->nodeValue;
				}
				$code_content = str_replace( array( "\r\n", "\r" ), "\n", $code_content );
				$code_content = rtrim( $code_content, "\n" );
				$fence        = str_repeat( '`', max( 3, $this->longest_backtick_run( $code_content ) + 1 ) );
				return "\n\n" . $fence . $lang . "\n" . $code_content . "\n" . $fence . "\n\n";

			// 引用
			case 'blockquote':
				$lines      = explode( "\n", trim( $inner ) );
				$quoted     = array();
				$last_empty = false;
				foreach ( $lines as $line ) {
					$is_empty = '' === trim( $line );
					if ( $is_empty && $last_empty ) {
						continue;
					}
					$quoted[]  = '> ' . $line;
					$last_empty = $is_empty;
				}
				return "\n\n" . implode( "\n", $quoted ) . "\n\n";

			// 連結
			case 'a':
				$href = $this->absolutize_url( $node->getAttribute( 'href' ) );
				$href = $this->maybe_convert_internal_link( $href );
				$href = $this->escape_url_destination( $href );
				$text = trim( $inner );
				if ( empty( $text ) ) {
					return '';
				}
				if ( empty( $href ) || $href === '#' ) {
					return $text;
				}
				return '[' . $this->escape_link_label( $text ) . '](' . $href . ')';

			// 圖片
			case 'img':
				$src = $this->absolutize_url( $this->get_image_source( $node ) );
				$alt = $node->getAttribute( 'alt' );
				if ( empty( $src ) ) {
					return '';
				}
				return '![' . $this->escape_link_label( $alt ) . '](' . $this->escape_url_destination( $src ) . ')';

			// 無序清單
			case 'ul':
				return "\n\n" . $this->process_list( $node, false ) . "\n\n";

			// 有序清單
			case 'ol':
				return "\n\n" . $this->process_list( $node, true ) . "\n\n";

			// 清單項目（由 process_list 處理，此處備用）
			case 'li':
				return trim( $inner );

			// 表格
			case 'table':
				return "\n\n" . $this->process_table( $node ) . "\n\n";

			// 表格容器標籤，直接傳遞內容
			case 'thead':
			case 'tbody':
			case 'tfoot':
			case 'tr':
			case 'th':
			case 'td':
				return $inner;

			// div / section / article 等容器，傳遞內容
			case 'div':
			case 'section':
			case 'article':
			case 'main':
			case 'figure':
			case 'figcaption':
			case 'span':
			case 'sup':
			case 'sub':
				return $inner;

			default:
				return $inner;
		}
	}

	/**
	 * 處理 <ul> 或 <ol>，支援巢狀清單。
	 */
	private function process_list( DOMNode $node, $ordered, $depth = 0 ) {
		$items  = array();
		$index  = 1;
		$indent = str_repeat( '  ', $depth );

		foreach ( $node->childNodes as $child ) {
			if ( $child->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}
			$child_tag = strtolower( $child->nodeName );
			if ( $child_tag !== 'li' ) {
				continue;
			}

			// 處理 li 的內容，區分子清單
			$text       = '';
			$nested     = '';

			foreach ( $child->childNodes as $li_child ) {
				if ( $li_child->nodeType === XML_ELEMENT_NODE ) {
					$li_tag = strtolower( $li_child->nodeName );
					if ( $li_tag === 'ul' ) {
						$nested .= "\n" . $this->process_list( $li_child, false, $depth + 1 );
						continue;
					}
					if ( $li_tag === 'ol' ) {
						$nested .= "\n" . $this->process_list( $li_child, true, $depth + 1 );
						continue;
					}
				}
				$text .= $this->convert_node( $li_child );
			}

			$text = trim( preg_replace( '/\n{2,}/', "\n", $text ) );
			$text = str_replace( "\n", "\n" . $indent . '  ', $text );

			$prefix   = $ordered ? $index . '. ' : '- ';
			$items[]  = $indent . $prefix . $text . $nested;
			$index++;
		}

		return implode( "\n", $items );
	}

	/**
	 * 將 <table> 轉為 Markdown 表格格式。
	 */
	private function process_table( DOMNode $node ) {
		$rows = array();

		foreach ( $this->get_direct_table_rows( $node ) as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $child ) {
				if ( $child->nodeType !== XML_ELEMENT_NODE ) {
					continue;
				}
				$cell_tag = strtolower( $child->nodeName );
				if ( $cell_tag === 'th' || $cell_tag === 'td' ) {
					$cells[] = $this->escape_table_cell( $this->process_children( $child ) );
				}
			}
			if ( $cells ) {
				$rows[] = $cells;
			}
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$col_count = max( array_map( 'count', $rows ) );
		$output    = '';

		foreach ( $rows as $i => $cells ) {
			// 補齊欄位數
			while ( count( $cells ) < $col_count ) {
				$cells[] = '';
			}
			$output .= '| ' . implode( ' | ', $cells ) . " |\n";
			// 在第一行後插入分隔線
			if ( $i === 0 ) {
				$dividers = array_fill( 0, $col_count, '---' );
				$output  .= '| ' . implode( ' | ', $dividers ) . " |\n";
			}
		}

		return $output;
	}

	/**
	 * 只取得目前 table 的直接列，避免把巢狀 table 的 tr 一起算入。
	 */
	private function get_direct_table_rows( DOMNode $table ) {
		$rows = array();

		foreach ( $table->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );
			if ( 'tr' === $tag ) {
				$rows[] = $child;
				continue;
			}

			if ( ! in_array( $tag, array( 'thead', 'tbody', 'tfoot' ), true ) ) {
				continue;
			}

			foreach ( $child->childNodes as $row ) {
				if ( XML_ELEMENT_NODE === $row->nodeType && 'tr' === strtolower( $row->nodeName ) ) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}

	/**
	 * 將表格 cell 正規化成單行，並跳脫欄位分隔符號。
	 */
	private function escape_table_cell( $text ) {
		$text = trim( $text );
		$text = preg_replace( '/\s*[\r\n]+\s*/u', '<br>', $text );
		return str_replace( '|', '\\|', $text );
	}

	/**
	 * 將標題內容正規化成單行，避免內文換行產生新的 Markdown 結構。
	 */
	private function normalize_single_line( $text ) {
		return trim( preg_replace( '/\s*[\r\n]+\s*/u', ' ', $text ) );
	}

	/**
	 * 依內容中的反引號長度選擇安全的 inline code delimiter。
	 */
	private function format_inline_code( $text ) {
		$text  = str_replace( array( "\r\n", "\r", "\n" ), ' ', (string) $text );
		$fence = str_repeat( '`', max( 1, $this->longest_backtick_run( $text ) + 1 ) );

		if ( false !== strpos( $text, '`' ) || preg_match( '/^\s|\s$/u', $text ) ) {
			return $fence . ' ' . $text . ' ' . $fence;
		}

		return $fence . $text . $fence;
	}

	/**
	 * 回傳字串中最長的連續反引號數量。
	 */
	private function longest_backtick_run( $text ) {
		if ( ! preg_match_all( '/`+/', (string) $text, $matches ) ) {
			return 0;
		}

		$lengths = array_map( 'strlen', $matches[0] );
		return max( $lengths );
	}

	/**
	 * 跳脫 link label 與 image alt 中會關閉結構的字元。
	 */
	private function escape_link_label( $text ) {
		return str_replace( array( '\\', '[', ']' ), array( '\\\\', '\\[', '\\]' ), (string) $text );
	}

	/**
	 * 將相對與 protocol-relative URL 轉成絕對網址。
	 *
	 * Markdown 檔案會被單獨取用，脫離原本的 HTTP context，
	 * `//host/path` 沒有可繼承的 scheme，`/path` 也沒有可依據的網域。
	 */
	private function absolutize_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		// 已有 scheme（http:、mailto:、data: 等）或純錨點，維持原樣。
		if ( preg_match( '#^(?:[a-z][a-z0-9+.\-]*:|\#)#i', $url ) ) {
			return $url;
		}

		// protocol-relative：補上與網站相同的 scheme。
		if ( 0 === strpos( $url, '//' ) ) {
			return $this->base_scheme . ':' . $url;
		}

		// 站內絕對路徑：補上網站根網址。
		if ( 0 === strpos( $url, '/' ) && '' !== $this->base_url ) {
			return $this->base_url . $url;
		}

		// 其餘相對路徑需要以當前文章路徑為基準，這裡無從得知，維持原樣。
		return $url;
	}

	/**
	 * 站內文章／頁面連結改指向 `.md` 版本，讓 AI 沿著連結繼續取得 Markdown。
	 *
	 * 是否有對應 `.md` 一律交由注入的 resolver 判斷，避免把分類、標籤、
	 * 附件或首頁等沒有 `.md` 版本的網址轉成 404 連結。
	 */
	private function maybe_convert_internal_link( $url ) {
		if ( null === $this->internal_link_resolver || '' === $this->base_url || '' === $url ) {
			return $url;
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'] ) ) {
			return $url;
		}

		// 帶 query string 的網址（例如 ?p=123、?s=關鍵字）不轉換。
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			return $url;
		}

		$base_parts = parse_url( $this->base_url );
		if ( ! is_array( $base_parts ) || ! isset( $base_parts['host'] ) ) {
			return $url;
		}

		if ( strtolower( $parts['host'] ) !== strtolower( $base_parts['host'] ) ) {
			return $url;
		}

		$path = rtrim( $parts['path'], '/' );

		// 首頁與已經是 .md 的網址不處理。
		if ( '' === $path || 0 === substr_compare( $path, '.md', -3, 3, true ) ) {
			return $url;
		}

		// 明顯是靜態檔案的路徑先擋掉，省去一次查詢。
		if ( preg_match( '/\.(?:jpe?g|png|gif|webp|svg|ico|pdf|zip|mp[34]|css|js|xml|json|txt)$/i', $path ) ) {
			return $url;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		// 傳給 resolver 的網址不含錨點，避免干擾 url_to_postid() 的解析。
		if ( ! call_user_func( $this->internal_link_resolver, $origin . $parts['path'] ) ) {
			return $url;
		}

		$converted = $origin . $path . '.md';

		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$converted .= '#' . $parts['fragment'];
		}

		return $converted;
	}

	/**
	 * 將 URL 轉成安全的 Markdown link destination。
	 */
	private function escape_url_destination( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return str_replace(
			array( ' ', '(', ')', '<', '>' ),
			array( '%20', '%28', '%29', '%3C', '%3E' ),
			$url
		);
	}

	/**
	 * 取得 lazy-load 圖片的實際來源，略過常見透明 placeholder。
	 */
	private function get_image_source( DOMNode $node ) {
		$src = trim( $node->getAttribute( 'src' ) );
		if ( $this->is_placeholder_image( $src ) ) {
			$src = '';
		}

		foreach ( array( 'data-src', 'data-lazy-src' ) as $attribute ) {
			if ( '' === $src ) {
				$candidate = trim( $node->getAttribute( $attribute ) );
				if ( '' !== $candidate && ! $this->is_placeholder_image( $candidate ) ) {
					$src = $candidate;
				}
			}
		}

		return $src;
	}

	/**
	 * 判斷常見 1x1 或具名 placeholder 圖片。
	 */
	private function is_placeholder_image( $src ) {
		if ( '' === $src ) {
			return true;
		}

		if ( 0 === stripos( $src, 'data:image/gif;base64,R0lGODlhAQABA' ) ) {
			return true;
		}

		return (bool) preg_match( '/(?:transparent|spacer|placeholder|blank)[^\/]*\.(?:gif|png)(?:[?#].*)?$/i', $src );
	}
}
