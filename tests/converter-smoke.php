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

echo "Converter smoke test passed.\n";
