<?php
/**
 * Minimal standalone smoke tests for core helpers and state restoration.
 *
 * Run with: php tests/core-smoke.php
 */

define( 'ABSPATH', __DIR__ );

class WP_Post {
	public $ID = 42;
	public $post_title = '測試文章';
	public $post_date = '2026-07-28 12:00:00';
	public $post_author = 1;
	public $post_type = 'post';
	public $post_status = 'publish';
	public $post_password = '';
	public $post_excerpt = '';
	public $post_content = '<p>content</p>';
}

class WP_Hook {
	public $callbacks = array();
}

class MoeLog_HTML_To_Markdown {
	public function convert( $html ) {
		return $html;
	}
}

$deleted_transient = null;

function delete_transient( $key ) {
	$GLOBALS['deleted_transient'] = $key;
}

function get_bloginfo( $field ) {
	return 'name' === $field ? '測試網站' : '網站描述';
}

function get_option( $key ) {
	return 'permalink_structure' === $key ? $GLOBALS['permalink_structure'] : '';
}

function get_posts( $arguments ) {
	$GLOBALS['get_posts_arguments'] = $arguments;
	return array( new WP_Post() );
}

function get_pages() {
	$public = new WP_Post();
	$public->ID = 43;
	$public->post_type = 'page';
	$public->post_title = '公開頁面';

	$protected = new WP_Post();
	$protected->ID = 44;
	$protected->post_type = 'page';
	$protected->post_title = '保護頁面';
	$protected->post_password = 'secret';

	return array( $public, $protected );
}

function mysql2date( $format, $date ) {
	return '2026-07-28';
}

function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( $path = '' ) {
	return 'https://example.com/blog' . $path;
}

function get_permalink( $post_id ) {
	switch ( (int) $post_id ) {
		case 42:
			return 'https://example.com/blog/中文文章/';
		case 43:
			return 'https://example.com/blog/public-page/';
		case 44:
			return 'https://example.com/blog/protected-page/';
		default:
			return '';
	}
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function get_the_title( $post = null ) {
	return $post instanceof WP_Post ? $post->post_title : '測試文章';
}

function get_the_date() {
	return '2026-07-28';
}

function get_the_author_meta() {
	return '作者';
}

function get_the_category() {
	return false;
}

function get_the_tags() {
	return false;
}

function setup_postdata() {
	$GLOBALS['id'] = 42;
	$GLOBALS['pages'] = array( 'changed' );
}

function apply_filters( $tag, $value ) {
	if ( 'the_content' === $tag && ! empty( $GLOBALS['throw_on_the_content'] ) ) {
		throw new RuntimeException( 'Simulated third-party callback failure.' );
	}
	return $value;
}

function remove_filter( $tag, $function, $priority ) {
	global $wp_filter;
	foreach ( $wp_filter[ $tag ]->callbacks[ $priority ] as $key => $callback ) {
		if ( $callback['function'] === $function ) {
			unset( $wp_filter[ $tag ]->callbacks[ $priority ][ $key ] );
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-llms-txt.php';

$GLOBALS['permalink_structure'] = '/%postname%/';

function moelog_core_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function moelog_call_private( $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( 'MoeLog_LLMS_Txt', $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}
	return $reflection->invokeArgs( null, $arguments );
}

MoeLog_LLMS_Txt::flush_index_cache( 'option-old-value-must-be-ignored' );
moelog_core_assert(
	MoeLog_LLMS_Txt::TRANSIENT_KEY === $GLOBALS['deleted_transient'],
	'Cache invalidation callback must always delete the fixed plugin transient key.'
);

$encoded_slug_matches = moelog_call_private( 'permalink_matches_slug', array( 42, '%E4%B8%AD%E6%96%87%E6%96%87%E7%AB%A0' ) );
$utf8_slug_matches    = moelog_call_private( 'permalink_matches_slug', array( 42, '中文文章' ) );
moelog_core_assert( $encoded_slug_matches && $utf8_slug_matches, 'Encoded and UTF-8 Chinese slugs must normalize to the same canonical path.' );
moelog_core_assert(
	! moelog_call_private( 'permalink_matches_slug', array( 42, 'random/path/中文文章' ) ),
	'An unrelated path with the same final slug must not pass canonical validation.'
);

$escaped = moelog_call_private( 'escape_markdown_text', array( '標題 [測試] * &amp;' ) );
moelog_core_assert( '標題 \[測試\] \* &' === $escaped, 'Generated Markdown structure text must be escaped and entities decoded.' );
moelog_core_assert(
	'2026-07-28' === moelog_call_private( 'escape_markdown_text', array( '2026-07-28' ) ),
	'Dates must not be escaped.'
);
moelog_core_assert(
	false === strpos( moelog_call_private( 'escape_markdown_text', array( 'WordPress 5.8 (Gutenberg)' ) ), '\\' ),
	'Ordinary prose punctuation must not accumulate backslashes.'
);
moelog_core_assert(
	'C++ 與 Node.js 入門' === moelog_call_private( 'escape_markdown_text', array( 'C++ 與 Node.js 入門' ) ),
	'Plus signs and periods inside a structural line must remain readable.'
);
moelog_core_assert( '\\# 標題' === moelog_call_private( 'escape_markdown_text', array( '# 標題' ) ), 'A leading heading marker must be escaped.' );
moelog_core_assert( '1\\. 項目' === moelog_call_private( 'escape_markdown_text', array( '1. 項目' ) ), 'A leading ordered-list marker must be escaped.' );

$index = moelog_call_private( 'build_llms_txt' );
moelog_core_assert( false !== strpos( $index, '中文文章.md' ), 'Pretty permalink index should contain Markdown post links.' );
moelog_core_assert( false !== strpos( $index, 'public-page.md' ), 'Public pages should be listed.' );
moelog_core_assert( false === strpos( $index, 'protected-page' ), 'Password-protected pages must be excluded.' );
moelog_core_assert( false === strpos( $index, "\n> 本文件" ), 'Only the site summary should use the blockquote section.' );
moelog_core_assert( false !== strpos( $index, ': 2026-07-28' ), 'Index should include the date from the loaded post object.' );
moelog_core_assert( false === $GLOBALS['get_posts_arguments']['has_password'], 'Post query must exclude password-protected posts.' );
moelog_core_assert( false === $GLOBALS['get_posts_arguments']['update_post_meta_cache'], 'Index query must not prime post meta cache.' );
moelog_core_assert( false === $GLOBALS['get_posts_arguments']['update_post_term_cache'], 'Index query must not prime term cache.' );

$GLOBALS['permalink_structure'] = '';
$plain_index = moelog_call_private( 'build_llms_txt' );
moelog_core_assert( false === strpos( $plain_index, '中文文章.md' ), 'Plain Permalinks must not produce broken .md URLs.' );
moelog_core_assert( false !== strpos( $plain_index, '不提供 `.md` 版本' ), 'Plain Permalink fallback must be explained in the index.' );
$GLOBALS['permalink_structure'] = '/%postname%/';

$keep_callback  = 'keep_content';
$noise_callback = 'related_posts_output';
$wp_filter = array( 'the_content' => new WP_Hook() );
$wp_filter['the_content']->callbacks = array(
	10 => array(
		'keep' => array( 'function' => $keep_callback, 'accepted_args' => 1 ),
		'noise' => array( 'function' => $noise_callback, 'accepted_args' => 1 ),
	),
);
$original_callbacks = $wp_filter['the_content']->callbacks;

$GLOBALS['post']  = 'original-post';
$GLOBALS['id']    = 7;
$GLOBALS['pages'] = array( 'original-page' );
$GLOBALS['throw_on_the_content'] = true;

for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
	try {
		moelog_call_private( 'build_markdown', array( new WP_Post(), new MoeLog_HTML_To_Markdown() ) );
		moelog_core_assert( false, 'The simulated the_content exception should propagate.' );
	} catch ( RuntimeException $exception ) {
		moelog_core_assert( 'Simulated third-party callback failure.' === $exception->getMessage(), 'Unexpected exception was thrown.' );
	}

	moelog_core_assert( $original_callbacks === $wp_filter['the_content']->callbacks, 'the_content callbacks and ordering must be restored exactly after every request.' );
}

moelog_core_assert( 'original-post' === $GLOBALS['post'], 'Global post must be restored after an exception.' );
moelog_core_assert( 7 === $GLOBALS['id'], 'Postdata globals must be restored after an exception.' );
moelog_core_assert( array( 'original-page' ) === $GLOBALS['pages'], 'Multipage globals must be restored after an exception.' );

$GLOBALS['throw_on_the_content'] = false;
$prose_post = new WP_Post();
$prose_post->post_excerpt = '本文介紹 WordPress 5.8 的 block-editor (Gutenberg) 功能。';
$markdown = moelog_call_private( 'build_markdown', array( $prose_post, new MoeLog_HTML_To_Markdown() ) );
moelog_core_assert( false !== strpos( $markdown, '2026-07-28' ), 'Per-post Markdown must keep the date readable.' );
moelog_core_assert( false === strpos( $markdown, '2026\\-07\\-28' ), 'Per-post Markdown must not escape date hyphens.' );
moelog_core_assert( false !== strpos( $markdown, $prose_post->post_excerpt ), 'Excerpt prose must remain readable without excess escaping.' );

echo "Core smoke test passed.\n";
