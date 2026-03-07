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
	 * 將 HTML 字串轉換為 Markdown。
	 *
	 * @param string $html
	 * @return string
	 */
	public function convert( $html ) {
		if ( empty( trim( $html ) ) ) {
			return '';
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><div id="moelog-llms-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		$root = $dom->getElementById( 'moelog-llms-root' );
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
		$inner = $this->process_children( $node );

		switch ( $tag ) {

			// 標題
			case 'h1': return "\n\n# " . trim( $inner ) . "\n\n";
			case 'h2': return "\n\n## " . trim( $inner ) . "\n\n";
			case 'h3': return "\n\n### " . trim( $inner ) . "\n\n";
			case 'h4': return "\n\n#### " . trim( $inner ) . "\n\n";
			case 'h5': return "\n\n##### " . trim( $inner ) . "\n\n";
			case 'h6': return "\n\n###### " . trim( $inner ) . "\n\n";

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
				return '`' . $inner . '`';

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
				return "\n\n```" . $lang . "\n" . trim( $code_content ) . "\n```\n\n";

			// 引用
			case 'blockquote':
				$lines  = explode( "\n", trim( $inner ) );
				$quoted = array_map( function ( $line ) {
					return '> ' . $line;
				}, $lines );
				return "\n\n" . implode( "\n", $quoted ) . "\n\n";

			// 連結
			case 'a':
				$href = $node->getAttribute( 'href' );
				$text = trim( $inner );
				if ( empty( $text ) ) {
					return '';
				}
				if ( empty( $href ) || $href === '#' ) {
					return $text;
				}
				return '[' . $text . '](' . $href . ')';

			// 圖片
			case 'img':
				$src = $node->getAttribute( 'src' );
				$alt = $node->getAttribute( 'alt' );
				if ( empty( $src ) ) {
					return '';
				}
				return '![' . $alt . '](' . $src . ')';

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

			// 略過不需要的元素
			case 'script':
			case 'style':
			case 'nav':
			case 'header':
			case 'footer':
			case 'aside':
			case 'form':
			case 'input':
			case 'button':
			case 'select':
			case 'textarea':
			case 'iframe':
			case 'noscript':
			case 'svg':
			case 'canvas':
				return '';

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

			$prefix   = $ordered ? $index . '. ' : '- ';
			$items[]  = $indent . $prefix . trim( $text ) . $nested;
			$index++;
		}

		return implode( "\n", $items );
	}

	/**
	 * 將 <table> 轉為 Markdown 表格格式。
	 */
	private function process_table( DOMNode $node ) {
		$rows = array();

		$trs = $node->getElementsByTagName( 'tr' );
		foreach ( $trs as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $child ) {
				if ( $child->nodeType !== XML_ELEMENT_NODE ) {
					continue;
				}
				$cell_tag = strtolower( $child->nodeName );
				if ( $cell_tag === 'th' || $cell_tag === 'td' ) {
					$cells[] = trim( $this->process_children( $child ) );
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
}
