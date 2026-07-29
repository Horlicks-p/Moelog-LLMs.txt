<?php
/**
 * Plugin Name: Moelog LLMs.txt
 * Description: 為 AI 機器人提供 /llms.txt 索引與各文章/頁面的 Markdown 純文字版本（在 URL 後加 .md）。
 * Version:     1.2.0
 * Author:      和製ホーリックス
 * License:     GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOELOG_LLMS_DIR', plugin_dir_path( __FILE__ ) );

require_once MOELOG_LLMS_DIR . 'includes/class-html-to-markdown.php';
require_once MOELOG_LLMS_DIR . 'includes/class-llms-txt.php';

add_action( 'init', array( 'MoeLog_LLMS_Txt', 'add_rewrite_rules' ) );
add_filter( 'query_vars', array( 'MoeLog_LLMS_Txt', 'add_query_vars' ) );
add_action( 'template_redirect', array( 'MoeLog_LLMS_Txt', 'handle_request' ) );
add_action( 'wp_head', array( 'MoeLog_LLMS_Txt', 'add_head_link' ) );
add_filter( 'robots_txt', array( 'MoeLog_LLMS_Txt', 'append_robots_txt' ), 10, 2 );

// 任何可能改變索引內容或連結的事件，都清除同一個固定 transient。
add_action( 'save_post', array( 'MoeLog_LLMS_Txt', 'flush_index_cache' ) );
add_action( 'deleted_post', array( 'MoeLog_LLMS_Txt', 'flush_index_cache' ) );
add_action( 'trashed_post', array( 'MoeLog_LLMS_Txt', 'flush_index_cache' ) );
add_action( 'untrashed_post', array( 'MoeLog_LLMS_Txt', 'flush_index_cache' ) );
add_action( 'transition_post_status', array( 'MoeLog_LLMS_Txt', 'flush_index_cache' ) );
add_action( 'update_option_blogname', array( 'MoeLog_LLMS_Txt', 'flush_index_cache' ) );
add_action( 'update_option_blogdescription', array( 'MoeLog_LLMS_Txt', 'flush_index_cache' ) );
add_action( 'update_option_permalink_structure', array( 'MoeLog_LLMS_Txt', 'flush_index_cache' ) );

register_activation_hook( __FILE__, 'moelog_llms_activate' );
register_deactivation_hook( __FILE__, 'moelog_llms_deactivate' );

function moelog_llms_activate() {
	MoeLog_LLMS_Txt::add_rewrite_rules();
	MoeLog_LLMS_Txt::flush_index_cache();
	flush_rewrite_rules();
}

function moelog_llms_deactivate() {
	MoeLog_LLMS_Txt::flush_index_cache();
	flush_rewrite_rules();
}
