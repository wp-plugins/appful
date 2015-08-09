<?php

class Appful_API_Comment {

	var $id;      // Integer
	var $name;    // String
	var $url;     // String
	var $date;    // String
	var $content; // String
	var $parent;  // Integer
	var $avatar;
	var $author;  // Object (only if the user was registered & logged in)

	function Appful_API_Comment($wp_comment = null) {
		if ($wp_comment) {
			$this->import_wp_object($wp_comment);
		}
	}


	function import_wp_object($wp_comment) {
		global $appful_api;

		$date_format = $appful_api->query->date_format;
		$content = apply_filters('comment_text', $wp_comment->comment_content);

		$this->id = (int) $wp_comment->comment_ID;
		$this->name = $wp_comment->comment_author;
		$this->url = $wp_comment->comment_author_url;
		$this->date = date($date_format, strtotime($wp_comment->comment_date_gmt));
		$this->content = $content;
		$this->parent = (int) $wp_comment->comment_parent;
		$this->avatar = "http://www.gravatar.com/avatar/" . md5(strtolower(trim($wp_comment->comment_author_email)));
		//$this->raw = $wp_comment;

		if (!empty($wp_comment->user_id)) {
			$this->author = new Appful_API_Author($wp_comment->user_id);
		} else {
			unset($this->author);
		}
	}


	function handle_submission() {
		global $comment, $wpdb, $appful_api;
		add_action('comment_id_not_found', array(&$this, 'comment_id_not_found'));
		add_action('comment_closed', array(&$this, 'comment_closed'));
		add_action('comment_on_draft', array(&$this, 'comment_on_draft'));
		add_filter('comment_post_redirect', array(&$this, 'comment_post_redirect'));
		add_action('comment_flood_trigger', array(&$this, 'comment_flood'));
		add_action('comment_duplicate_trigger', array(&$this, 'comment_flood'));


		$data = array(
			'comment_post_ID' => $_REQUEST["post_id"],
			'comment_author' => $_REQUEST["name"],
			'comment_author_email' => $_REQUEST["email"],
			'comment_author_url' => $_REQUEST["url"],
			'comment_content' => $_REQUEST["content"],
			'comment_type' => '',
			'comment_parent' => $_REQUEST["parent"] ?: 0,
			'comment_author_IP' => $_REQUEST["ip"] ?: $appful_api->getClientIP(),
			'comment_agent' => $_REQUEST["user_agent"] ?: $_SERVER['HTTP_USER_AGENT'],
			'comment_date' => current_time('mysql'),
			'comment_approved' => get_option("comment_moderation") == 0 ? 1:0,
		);


		wp_allow_comment($data);
		wp_insert_comment($data);
		$appful_api->response->respond(array("status" => $data["comment_approved"] == 1 ? "ok" : "pending"));

	}


	function comment_id_not_found() {
		global $appful_api;
		$appful_api->error("Post ID '{$_REQUEST['post_id']}' not found.");
	}


	function comment_closed() {
		global $appful_api;
		$appful_api->error("Post is closed for comments.");
	}


	function comment_on_draft() {
		global $appful_api;
		$appful_api->error("You cannot comment on unpublished posts.");
	}


	function comment_flood() {
		global $appful_api;
		$appful_api->response->respond(array("status" => "flood"));
		die();
	}


	function comment_duplicate() {
		global $appful_api;
		$appful_api->response->respond(array("status" => "duplicate"));
		die();
	}


	function comment_post_redirect() {
		global $comment, $appful_api;
		$status = ($comment->comment_approved) ? 'ok' : 'pending';
		$new_comment = new Appful_API_Comment($comment);
		$appful_api->response->respond($new_comment, $status);
	}


}


?>
