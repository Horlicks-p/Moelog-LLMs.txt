<?php
/**
 * Minimal standalone smoke test for the HTML-to-Markdown converter.
 *
 * Run with: php tests/converter-smoke.php
 */

define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( $text );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-html-to-markdown.php';

function moelog_assert_contains( $needle, $haystack, $message ) {
	if ( false === strpos( $haystack, $needle ) ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected to contain: {$needle}\n\n{$haystack}\n" );
		exit( 1 );
	}
}

function moelog_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$html = <<<'HTML'
<h2>中文標題</h2>
<p>foo_bar 與 2 * 3 應保留原樣。</p>
<p><code>a`b</code></p>
<pre><code class="language-php">echo "```";</code></pre>
<ul><li><p>第一段</p><p>第二段</p></li><li>正常項目</li></ul>
<blockquote><p>引用一</p><p>引用二</p></blockquote>
<table>
  <thead><tr><th>A|B</th><th>內容</th></tr></thead>
  <tbody><tr><td>第一行<br>第二行</td><td><table><tr><td>巢狀</td></tr></table></td></tr></tbody>
</table>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP" data-src="/images/真實 圖片.png" alt="圖[一]">
<script><p>不應遍歷的內容</p></script>
HTML;

libxml_use_internal_errors( false );
$converter = new MoeLog_HTML_To_Markdown();
$markdown  = $converter->convert( $html );

moelog_assert_contains( '## 中文標題', $markdown, 'UTF-8 heading should survive the full HTML wrapper.' );
moelog_assert_contains( 'foo_bar 與 2 * 3 應保留原樣。', $markdown, 'Body text should not be over-escaped.' );
moelog_assert_contains( '`` a`b ``', $markdown, 'Inline code should use a longer backtick delimiter.' );
moelog_assert_contains( "````php\necho \"```\";\n````", $markdown, 'Code fences should be longer than backticks in code.' );
moelog_assert_contains( "- 第一段\n  第二段\n- 正常項目", $markdown, 'Multiple paragraphs inside a list item should remain one item.' );
moelog_assert_contains( "> 引用一\n> \n> 引用二", $markdown, 'Blockquote paragraphs should contain only one blank quote line.' );
moelog_assert_same( false, false !== strpos( $markdown, "> \n> \n" ), 'Duplicate empty blockquote lines should be collapsed.' );
moelog_assert_contains( 'A\|B', $markdown, 'Table pipes should be escaped.' );
moelog_assert_contains( '第一行<br>第二行', $markdown, 'Table newlines should be normalized.' );
moelog_assert_contains( '![圖\[一\]](/images/真實%20圖片.png)', $markdown, 'Lazy image source and alt text should be normalized.' );
moelog_assert_same( false, false !== strpos( $markdown, '不應遍歷的內容' ), 'Skipped elements should not leak their descendants.' );
moelog_assert_same( 1, substr_count( $markdown, '| --- | --- |' ), 'Nested rows must not be added to the outer table.' );
moelog_assert_same( false, libxml_use_internal_errors(), 'libxml internal-error state should be restored.' );

libxml_use_internal_errors( true );
$converter->convert( '<p>second pass</p>' );
moelog_assert_same( true, libxml_use_internal_errors(), 'A previously enabled libxml error state should remain enabled.' );
libxml_use_internal_errors( false );

// --- URL 絕對化與站內連結改寫 ---

// 未注入 base URL 時 converter 仍可獨立使用：
// protocol-relative 網址本身已含 host，只補 scheme；站內絕對路徑則無從解析，維持原樣。
$standalone = new MoeLog_HTML_To_Markdown();
moelog_assert_contains(
	'![圖](https://cdn.example.com/a.jpg)',
	$standalone->convert( '<img src="//cdn.example.com/a.jpg" alt="圖">' ),
	'Protocol-relative URLs only need a scheme, so they are fixed even without a base URL.'
);
moelog_assert_contains(
	'![圖](/wp-content/uploads/b.jpg)',
	$standalone->convert( '<img src="/wp-content/uploads/b.jpg" alt="圖">' ),
	'Without a base URL a root-relative path cannot be resolved and must stay as-is.'
);

$known_posts    = array(
	'https://example.com/blog/real-post/' => true,
	'https://example.com/blog/category/x/' => false,
);
$resolver_calls = array();

$linked = new MoeLog_HTML_To_Markdown(
	'https://example.com/blog',
	function ( $url ) use ( $known_posts, &$resolver_calls ) {
		$resolver_calls[] = $url;
		return isset( $known_posts[ $url ] ) ? $known_posts[ $url ] : false;
	}
);

$url_html = <<<'HTML'
<img src="//cdn.example.com/a.jpg" alt="CDN">
<img src="/wp-content/uploads/b.jpg" alt="root-relative 圖">
<img src="https://other.example.org/c.jpg" alt="外部圖">
<p><a href="https://example.com/blog/real-post/">站內文章</a></p>
<p><a href="/blog/real-post/">root-relative 站內文章</a></p>
<p><a href="/real-post/">安裝目錄外的同名路徑</a></p>
<p><a href="/other-app/page/">同網域的其他應用程式</a></p>
<p><a href="https://example.com/blog/real-post/#section">帶錨點</a></p>
<p><a href="https://example.com/blog/">網站首頁</a></p>
<p><a href="https://example.com/blog/category/x/">分類頁</a></p>
<p><a href="https://example.com/blog/real-post/?utm=1">帶 query</a></p>
<p><a href="https://example.com/blog/files/doc.pdf">PDF</a></p>
<p><a href="https://example.com/blog/already.md">已是 md</a></p>
<p><a href="https://external.example.net/page/">外部連結</a></p>
<p><a href="mailto:someone@example.com">Email</a></p>
HTML;

$url_markdown = $linked->convert( $url_html );

moelog_assert_contains( '![CDN](https://cdn.example.com/a.jpg)', $url_markdown, 'Protocol-relative image URLs must gain the site scheme.' );
moelog_assert_contains( '![外部圖](https://other.example.org/c.jpg)', $url_markdown, 'External absolute URLs must stay untouched.' );
moelog_assert_contains( '[站內文章](https://example.com/blog/real-post.md)', $url_markdown, 'Internal post links should point at the .md version.' );
moelog_assert_contains( '[帶錨點](https://example.com/blog/real-post.md#section)', $url_markdown, 'Fragments must survive the .md rewrite.' );
moelog_assert_contains( '[分類頁](https://example.com/blog/category/x/)', $url_markdown, 'URLs without a .md counterpart must not be rewritten.' );
moelog_assert_contains( '[帶 query](https://example.com/blog/real-post/?utm=1)', $url_markdown, 'URLs carrying a query string must not be rewritten.' );
moelog_assert_contains( '[PDF](https://example.com/blog/files/doc.pdf)', $url_markdown, 'Static files must not be rewritten.' );
moelog_assert_contains( '[已是 md](https://example.com/blog/already.md)', $url_markdown, 'Already-.md URLs must not gain a second suffix.' );
moelog_assert_contains( '[外部連結](https://external.example.net/page/)', $url_markdown, 'External links must not be rewritten.' );
moelog_assert_contains( '[Email](mailto:someone@example.com)', $url_markdown, 'mailto: links must be left alone.' );
moelog_assert_contains( '[網站首頁](https://example.com/blog/)', $url_markdown, 'The site home URL has no .md counterpart.' );

// root-relative URL 依 URL 語意屬於 origin，不能接到含子目錄的 base URL 後面。
moelog_assert_contains(
	'![root-relative 圖](https://example.com/wp-content/uploads/b.jpg)',
	$url_markdown,
	'Root-relative URLs resolve against the origin, not against the WordPress subdirectory.'
);
moelog_assert_contains(
	'[root-relative 站內文章](https://example.com/blog/real-post.md)',
	$url_markdown,
	'A root-relative path inside the install directory still resolves to the same post.'
);
moelog_assert_contains(
	'[安裝目錄外的同名路徑](https://example.com/real-post/)',
	$url_markdown,
	'/real-post/ is outside /blog and must not be rewritten into the blog post.'
);
moelog_assert_contains(
	'[同網域的其他應用程式](https://example.com/other-app/page/)',
	$url_markdown,
	'Same-host URLs outside the WordPress install must stay untouched.'
);

foreach ( array(
	'https://example.com/blog/files/doc.pdf' => 'Static file paths must be filtered out before any lookup happens.',
	'https://external.example.net/page/'     => 'External hosts must never reach the resolver.',
	'https://example.com/real-post/'         => 'Paths outside the install directory must not trigger a lookup.',
	'https://example.com/other-app/page/'    => 'Other apps on the same host must not trigger a lookup.',
) as $never_resolved => $message ) {
	moelog_assert_same( false, in_array( $never_resolved, $resolver_calls, true ), $message );
}

echo "Converter smoke test passed.\n";
