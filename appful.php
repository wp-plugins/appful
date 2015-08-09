<?php
/*
Plugin Name: appful
Plugin URI: https://appful.net/
Description: appful is the world's most remarkable & beautiful app service for Blogger and Online magazines. It is designed to create outstanding real native iOS Apps based on the content of your Wordpress site or YouTube channel. appful is surprisingly simple to use and not a single line of code is required.
Version: 1.0
Author: appful
Author URI: https://appful.net/
*/

$dir = appful_api_dir();

set_time_limit(0);
error_reporting(0);

@include_once "$dir/singletons/api.php";
@include_once "$dir/singletons/query.php";
@include_once "$dir/singletons/introspector.php";
@include_once "$dir/singletons/response.php";
@include_once "$dir/models/post.php";
@include_once "$dir/models/comment.php";
@include_once "$dir/models/category.php";
@include_once "$dir/models/tag.php";
@include_once "$dir/models/author.php";
@include_once "$dir/models/attachment.php";
@include_once "$dir/models/widget.php";

function appful_api_init() {
	global $appful_api;
	if (phpversion() < 5) {
		add_action('admin_notices', 'appful_api_php_version_warning');
		return;
	}
	if (!class_exists('Appful_API')) {
		add_action('admin_notices', 'appful_api_class_warning');
		return;
	}

	add_filter('rewrite_rules_array', 'appful_api_rewrites');

	$appful_api = new Appful_API();
}


function appful_api_php_version_warning() {
	echo "<div id=\"json-api-warning\" class=\"updated fade\"><p>appful benötigt PHP 5.0 oder höher.</p></div>";
}


function appful_api_class_warning() {
	echo "<div id=\"json-api-warning\" class=\"updated fade\"><p>Oops, Appful_API class not found. If you've defined a Appful_API_DIR constant, double check that the path is correct.</p></div>";
}


function appful_api_activation() {
	// Add the rewrite rule on activation
	global $wp_rewrite;
	add_filter('rewrite_rules_array', 'appful_api_rewrites');
	$wp_rewrite->flush_rules();

	$appful_api = new Appful_API();
	if(strlen(get_option("appful_blog_id")) > 0) {
		$appful_api->register();
	}
}


function appful_api_deactivation() {
	// Remove the rewrite rule on deactivation
	global $wp_rewrite;
	$wp_rewrite->flush_rules();

	global $appful_api;
	if(strlen(get_option("appful_blog_id")) > 0) {
		$appful_api->request("register", array("disable" => 1));
	}
}

function appful_api_uninstall() {
	global $appful_api;
	if(strlen(get_option("appful_blog_id")) > 0) {
		$appful_api->request("register", array("uninstall" => 1));
	}
}

function appful_api_rewrites($wp_rules) {
	$base = "appful-api";
	if (empty($base)) {
		return $wp_rules;
	}
	$appful_api_rules = array(
		"$base\$" => 'index.php?jsn=info',
		"$base/(.+)\$" => 'index.php?jsn=$matches[1]'
	);

	return array_merge($appful_api_rules, $wp_rules);
}


function appful_api_dir() {
	if (defined('Appful_API_DIR') && file_exists(Appful_API_DIR)) {
		return Appful_API_DIR;
	} else {
		return dirname(__FILE__);
	}
}


function appful_on_post($post_id, $push = FALSE) {
	global $appful_api;
	$appful_api->request("cache", array("post_id" => $post_id, "push" => $push?1:0));
}


function appful_on_post_transition($new_status, $old_status, $post) {
	if ($new_status != "auto-draft") {
		appful_on_post($post->ID, $new_status == "publish" && $old_status != "trash" && $old_status != "private" && $new_status != $old_status);
	}
}


function appful_on_comment($comment_id) {
	appful_on_post(get_comment($comment_id)->comment_post_ID, false);
}

function appful_admin_notice() {
	global $pagenow, $appful_api;
	if (get_option("appful_invalid_session") && ($pagenow != "options-general.php" && $_GET["page"] != "appful")) {
		echo '<div class="error">
             <p>'. $appful_api->localize("hint_not_connected") .' <a href="options-general.php?page=appful">'. $appful_api->localize("connect") .'</a></p>
         </div>';
	}
}

add_action('widgets_init',
     create_function('', 'return register_widget("Appful_Widget");')
);

//add_action('publish_post', 'on_post');
//add_action('wp_trash_post', 'on_post');
//add_action('untrash_post', 'appful_update_post');

add_action("comment_post", "appful_on_comment");
add_action("delete_comment", "appful_on_comment");
add_action("edit_comment", "appful_on_comment");
add_action("trash_comment", "appful_on_comment");
add_action('transition_post_status', 'appful_on_post_transition', 10, 3);

// Add initialization and activation hooks
add_action('init', 'appful_api_init');

register_activation_hook(__FILE__, 'appful_api_activation');
register_deactivation_hook(__FILE__, 'appful_api_deactivation');
register_uninstall_hook(__FILE__, "appful_api_uninstall");

add_action('admin_notices', 'appful_admin_notice');

?>
