<?php

class Appful_API {

	function __construct() {
		$this->query = new Appful_API_Query();
		$this->introspector = new Appful_API_Introspector();
		$this->response = new Appful_API_Response();
		add_action('template_redirect', array(&$this, 'template_redirect'));
		add_action('admin_menu', array(&$this, 'admin_menu'));
		add_action('update_option_appful_api_base', array(&$this, 'flush_rewrite_rules'));
		add_action('pre_update_option_appful_api_controllers', array(&$this, 'update_controllers'));
		register_deactivation_hook( __FILE__, 'plugin_deactivate');
		add_action('post_submitbox_misc_actions', array(&$this, 'submitbox_actions'));
		add_action('save_post', array(&$this, 'save_postdata'));
		add_filter('post_row_actions', array(&$this, 'post_row_actions'), 10, 2);

		if (!get_option("appful_ip", false)) {
			$this->updateAllowedIPs();
		}

		if (time() - get_option("appful_register_last_refresh", 0) > get_option("appful_cache_register_interval", 60 * 60)) {
			$this->register();
		}

		if (time() - get_option("appful_cache_last_refresh", 0) > get_option("appful_cache_fill_interval", 24 * 60 * 60)) {
			$this->fill_cache();
		}

		if ($_REQUEST["appful_slider"] && $_REQUEST["post_id"] && $_REQUEST["nonce"]) {
			if (wp_verify_nonce($_REQUEST["nonce"], "appful-slider")) {
				$this->updateAppSlider($_REQUEST["post_id"], $_REQUEST["appful_slider"] == "true");
				$this->request("cache", array("post_id" => $_REQUEST["post_id"], "push" => 0));
			}
		}
	}


	function updateAllowedIPs() {
		$this->save_option("appful_ip", $this->response->encode_json($this->request("authorizedIPs", NULL)["payload"] ?: array(gethostbyname("appful.net"), gethostbyname("appful.io"), gethostbyname("appful.de"))));
	}


	function template_redirect() {
		// Check to see if there's an appropriate API controller + method
		$controller = strtolower($this->query->get_controller());
		if ($controller) {
			$controller_path = $this->controller_path($controller);
			if (file_exists($controller_path)) {
				require_once $controller_path;
			}
			$controller_class = $this->controller_class($controller);

			if (!class_exists($controller_class)) {
				$this->error("Unknown controller '$controller_class'.");
			}

			$this->controller = new $controller_class();
			$method = $this->query->get_method($controller);

			if ($method) {
				if (!in_array($this->getClientIP(), $this->response->decode_json(get_option("appful_ip")))) {
					$this->updateAllowedIPs();
				}

				if (!in_array($this->getClientIP(), $this->response->decode_json(get_option("appful_ip")))) {
					$this->error('Hostname not authorized.');
					die();
				}

				if ($_REQUEST["register"] == 1) {
					$this->response->respond($this->register());
				}

				if ($_REQUEST["fill"] == 1) {
					$_REQUEST["register"] == 1 ? $this->fill_cache() : $this->response->respond($this->fill_cache());
				}

				if ($_REQUEST["register"] == 1 || $_REQUEST["fill"] == 1) die();


				$this->response->setup();

				// Run action hooks for method
				do_action("appful_api-{$controller}-$method");

				// Error out if nothing is found
				if ($method == '404') {
					$this->error('Not found');
				}

				$result = $this->controller->$method();
				$this->response->respond($result);

				exit;
			}
		}
	}


	function submitbox_actions() {
		global $post;
		$value = $this->isAppSlider($post->ID);
?>
		<div class="misc-pub-section">
		<input type="checkbox" style="margin-right:10px;" name="show_in_main_appful_slider" <?php echo $value ? " checked":"" ?>><label>App-Slider auf der Startseite</label>
		</div>
		<?php
	}


	function save_postdata($post_id) {
		/* check if this is an autosave */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return false;

		/* check if the user can edit this page */
		if ( !current_user_can( 'edit_page', $postid ) ) return false;

		/* check if there's a post id and check if this is a post */
		/* make sure this is the same post type as above */
		if (empty($postid) || $_POST['post_type'] != 'post' ) return false;

		$this->updateAppSlider($post_id, isset($_POST['show_in_main_appful_slider']));
	}


	function updateAppSlider($post_id, $value) {
		if ($value) {
			wp_set_post_tags($post_id, 'app-slider', true);
		} else {
			foreach (wp_get_post_tags($post_id) as $tag) {
				if ($tag->name != "app-slider") $tags[] = $tag->name;
			}
			wp_set_post_tags($post_id, $tags, false);
		}
	}


	function isAppSlider($post_id) {
		$tags = wp_get_post_tags($post_id);
		$value = false;
		foreach ($tags as $tag) {
			if ($tag->name == "app-slider") {
				$value = true;
				break;
			}
		}
		return $value;
	}


	function post_row_actions($actions, $post) {
		$value = $this->isAppSlider($post->ID);
		$actions['edit_badges'] = "<a href='" . admin_url("edit.php?appful_slider=". ($value ? "false":"true") ."&post_id=". $post->ID . "&nonce=". wp_create_nonce('appful-slider')) . "'>" . ($value ? "-":"+") . ' App-Slider' . "</a>";
		return $actions;
	}


	function get_contents($url, $params) {
		global $http_code;
		if (in_array('curl', get_loaded_extensions())) { //curl installed, use curl
			$postData = '';
			//create name value pairs seperated by &
			foreach ($params as $k => $v) {
				$postData .= $k . '='.$v.'&';
			}

			$postData = trim($postData, '&');

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_POST, count($params));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
			curl_setopt($ch, CURLOPT_TIMEOUT, 4);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

			$output = curl_exec($ch);
			curl_close($ch);
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			return $output;
		} else if (ini_get('allow_url_fopen')) {
				$context = stream_context_create(array('http' => array('header' => 'Connection: close\r\n', 'method'  => 'POST', 'header'  => 'Content-type: application/x-www-form-urlencoded', 'content' => http_build_query($params))));
				$result = file_get_contents($url, false, $context);
				foreach ($http_response_header as $header) {
					if ( preg_match( "#HTTP/[0-9\.]+\s+([0-9]+)#", $header, $out)) {
						$http_code = intval($out[1]);
					}
				}
				return $result;
			} else {
			wp_die($this->localize("fopen_error"));
		}
	}


	function request($location, $params) {
		global $http_code;
		if ((strlen(get_option("appful_blog_id")) > 0 && strlen(get_option("appful_session_id")) > 0) || $location == "register") {
			$params["blog_id"] = get_option("appful_blog_id");
			$params["session_id"] = get_option("appful_session_id");
			$params["lang"] = $this->locale();
			$response = $this->get_contents("http://api.appful.net/v1/plugin/". $location . ".php", $params);
			$response = $this->response->decode_json($response) ?: $response;
			if ($http_code == -35) {
				$this->save_option("appful_session_id", "");
				$this->save_option("appful_invalid_session", "1");
			}
			return $response;
		}
	}


	function fill_cache() {
		if (strlen(get_option("appful_session_id")) > 0) {
			global $wpdb;
			$posts = $wpdb->get_results("SELECT id,post_modified_gmt FROM `". $wpdb->posts ."` WHERE `post_status` = 'publish' AND `post_type` = 'post' ORDER BY `post_date` DESC", ARRAY_A);
			$allPosts = array();
			foreach ($posts as $post)
				$allPosts[] = array("id" => (int)$post["id"], "modified" => strtotime($post["post_modified_gmt"]));

			$this->save_option("appful_cache_last_refresh", time());
			if (isset($_REQUEST["output"])) {
				$this->response->respond(array("posts" => $allPosts));
				exit;
			} else {
				return $this->request("cache", array("posts" => $this->response->encode_json($allPosts)));
			}
		} else if ($_REQUEST["fill"] == 1) {
				$this->error("Not logged in.");
			}
	}


	function admin_menu() {
		//add_options_page('appful connect', 'appful connect', 'manage_options', 'appful', array(&$this, 'admin_options'));
		add_menu_page('appful', 'appful', 'manage_options', 'appful', array(&$this, 'admin_options'), "dashicons-groups");
	}


	function localize($key) {
		$locale = explode("_", get_locale())[0];

		$strings["de"] = array(
			"username" => "Benutzername",
			"password" => "Passwort",
			"message_connected" => "Dieser Blog ist erfolgreich bei appful mit dem Benutzer USER verbunden.",
			"message_cache_prefix" => "Der Cache ist",
			"message_cache_ok" => "aktuell",
			"message_cache_filling" => "wird befüllt",
			"hint_not_connected" => "Dieser Blog ist nicht mehr mit appful verbunden!",
			"connect" => "Verbinden",
			"disconnect" => "Trennen",
			"select_app" => "App auswählen",
			"select" => "auswählen",
			"description" => "Beschreibung",
			"size_small" => "Klein",
			"size_large" => "Groß",
			"size" => "Größe",
			"error_no_published_app" => "Du hast leider noch keine veröffentlichte App. Das Widget wird angezeigt, sobald du deine erste App veröffentlichst.",
			"fopen_error" => "Bitte aktivieren Sie allow_url_fopen in den php-Einstellungen (php.ini) oder installieren Sie cURL."
		);

		$strings["en"] = array(
			"username" => "Username",
			"password" => "Password",
			"message_connected" => "This blog successfully connected with appful (Username: USER).",
			"message_cache_prefix" => "The cache is",
			"message_cache_ok" => "up to date",
			"message_cache_filling" => "being filled",
			"hint_not_connected" => "This blog is no longer connected with appful!",
			"connect" => "Connect",
			"disconnect" => "Disconnect",
			"select_app" => "Select App",
			"select" => "Select",
			"description" => "Description",
			"size_small" => "Small",
			"size_large" => "Large",
			"size" => "Size",
			"error_no_published_app" => "You do not have any published app. The widget will be displayed as soon as you publish your first app.",
			"fopen_error" => "Please enable allow_url_fopen in your php-configuration (php.ini) or install cURL."
		);

		if (!in_array($locale, array_keys($strings))) {
			$locale = "en";
		}

		return $strings[$locale][$key] ?: $key;
	}


	function locale() {
		$locale = explode("_", get_locale())[0];
		if (!in_array($locale, array("de", "en"))) return "en";
		return $locale;
	}


	function admin_options() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}

		$request = $this->register();
?>
			<link href="<?php echo plugins_url("assets/css/admin.css", dirname(__FILE__)) ?>" rel="stylesheet">
			<style>
				#wpwrap {
					background-image:url("<?php echo plugins_url(); ?>/appful/assets/img/background.png");
				}
				.al-wrap #logo {
					background:url("<?php echo plugins_url(); ?>/appful/assets/img/appful-logo.png") no-repeat center center;
					background-size:contain;
				}
			</style>

			<div class="al-wrap">
				<div id="logo">
				</div>
	            <div class="connect-box">
			        <form action="admin.php?page=appful" method="post">
						<?php wp_nonce_field('update-options'); ?>
						<?php if (!get_option("appful_session_id") || $request["status"] == "error" || !$request) { ?>
						      <div class="form-title"><?php echo $this->localize("username") ?>:</div>
						      <input type="text" size="24" name="user" value="<?php echo get_option("appful_user") ?>" />
						      <div class="form-title"><?php echo $this->localize("password") ?>:</div>
						      <input type="password" size="24" name="password" />
						      <?php if (strlen($request["error"]) > 0) { ?><div class="errormessage"><?php echo $request["error"] ?></div><?php } ?>
							  <input type="submit" value="<?php echo $this->localize("connect") ?>" />
							  <?php } else { ?>
							  <p>
							  	<?php echo str_replace("USER", get_option("appful_user"), $this->localize("message_connected")) ?>
							  </p>
							  <p><?php echo $this->localize("message_cache_prefix") ?> <?php if (!$request["payload"]["cache"]["fill"]) {
				?><?php echo $this->localize("message_cache_ok") ?><?php } else {
				?> <?php echo $this->localize("message_cache_filling") ?>... (<?php echo round((int)$request["payload"]["cache"]["fill"]["cached"]/(int)$request["payload"]["cache"]["fill"]["total"]*100, 2) ?>%)<?php } ?>.</p>
								<input type="hidden" name="unlink" value="1" />
								<input type="submit" value="<?php echo $this->localize("disconnect") ?>" />
								<?php
		}
?>
					</form>
				</div>
				<div class="advice-box">
					<a href="<?php echo admin_url("widgets.php") ?>">
				        <img src="<?php echo plugins_url("assets/img/widget-advice-". $this->locale() . ".jpg", dirname(__FILE__)) ?>" height="199">
				    </a>
				</div>

			</div>
		<?php
	}


	function register() {
		global $http_code;
		if (strlen(get_option("appful_session_id")) > 0 || (!empty($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], "update-options") && isset($_POST["user"]) && isset($_POST["password"]))) {
			$params = array("siteurl" => get_option("home", rtrim(get_option("siteurl"), "/")));
			$shouldUnlink = isset($_POST["unlink"]) && !empty($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], "update-options");
			if ($shouldUnlink) $params["unlink"] = 1;
			if (isset($_POST["user"])) $params = array_merge(array("username" => $_POST["user"], "password" => $_POST["password"]), $params);

			$response = $this->request("register", $params);
			if ($response["status"] == "ok") {
				if ($response["payload"]["session_id"]) $this->save_option("appful_session_id", $response["payload"]["session_id"]);
				if ($response["payload"]["blog"]) $this->save_option("appful_blog_id", $response["payload"]["blog"]["id"]);
				if ($http_code == 201) $this->fill_cache();
				$this->save_option("appful_blog_infos", $this->response->encode_json($response["payload"]["blog"]));
				if ($response["payload"]["user"]) $this->save_option("appful_user", $response["payload"]["user"]);
				$this->save_option("appful_invalid_session", "");
				if ($response["payload"]["cache"]["fill_interval"]) $this->save_option("appful_cache_fill_interval", $response["payload"]["cache"]["fill_interval"]);
				if ($response["payload"]["cache"]["register_interval"]) $this->save_option("appful_cache_register_interval", $response["payload"]["cache"]["register_interval"]);
				$this->save_option("appful_widget_apps", $this->response->encode_json($response["payload"]["widget"]["apps"]));
				$this->save_option("appful_widget_branding", $this->response->encode_json($response["payload"]["widget"]["branding"]));
			}

			if ($shouldUnlink) {
				delete_option("appful_session_id");
			}

			$this->save_option("appful_register_last_refresh", time());
			return $response;
		}
	}


	function get_method_url($controller, $method, $options = '') {
		$url = get_bloginfo('url');
		$base = "appful-api";
		$permalink_structure = get_option('permalink_structure', '');
		if (!empty($options) && is_array($options)) {
			$args = array();
			foreach ($options as $key => $value) {
				$args[] = urlencode($key) . '=' . urlencode($value);
			}
			$args = implode('&', $args);
		} else {
			$args = $options;
		}
		if ($controller != 'core') {
			$method = "$controller/$method";
		}
		if (!empty($base) && !empty($permalink_structure)) {
			if (!empty($args)) {
				$args = "?$args";
			}
			return "$url/$base/$method/$args";
		} else {
			return "$url?jsn=$method&$args";
		}
	}


	function save_option($id, $value) {
		$option_exists = (get_option($id, null) !== null);
		if (strlen($value) > 0) {
			if ($option_exists) {
				update_option($id, $value);
			} else {
				add_option($id, $value);
			}
		} else {
			delete_option($id);
		}
	}


	function get_controllers() {
		$controllers = array();
		$dir = appful_api_dir();
		$dh = opendir("$dir/controllers");
		while ($file = readdir($dh)) {
			if (preg_match('/(.+)\.php$/', $file, $matches)) {
				$controllers[] = $matches[1];
			}
		}
		$controllers = apply_filters('appful_api_controllers', $controllers);
		return array_map('strtolower', $controllers);
	}


	function controller_is_active($controller) {
		return true;
	}


	function update_controllers($controllers) {
		if (is_array($controllers)) {
			return implode(',', $controllers);
		} else {
			return $controllers;
		}
	}


	function controller_info($controller) {
		$path = $this->controller_path($controller);
		$class = $this->controller_class($controller);
		$response = array(
			'name' => $controller,
			'description' => '(No description available)',
			'methods' => array()
		);
		if (file_exists($path)) {
			$source = file_get_contents($path);
			if (preg_match('/^\s*Controller name:(.+)$/im', $source, $matches)) {
				$response['name'] = trim($matches[1]);
			}
			if (preg_match('/^\s*Controller description:(.+)$/im', $source, $matches)) {
				$response['description'] = trim($matches[1]);
			}
			if (preg_match('/^\s*Controller URI:(.+)$/im', $source, $matches)) {
				$response['docs'] = trim($matches[1]);
			}
			if (!class_exists($class)) {
				require_once $path;
			}
			$response['methods'] = get_class_methods($class);
			return $response;
		} else if (is_admin()) {
				return "Cannot find controller class '$class' (filtered path: $path).";
			} else {
			$this->error("Unknown controller '$controller'.");
		}
		return $response;
	}


	function controller_class($controller) {
		return "appful_api_{$controller}_controller";
	}


	function controller_path($controller) {
		$dir = appful_api_dir();
		$controller_class = $this->controller_class($controller);
		return apply_filters("{$controller_class}_path", "$dir/controllers/$controller.php");
	}


	function get_nonce_id($controller, $method) {
		$controller = strtolower($controller);
		$method = strtolower($method);
		return "appful_api-$controller-$method";
	}


	function flush_rewrite_rules() {
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
	}


	function error($message = 'Unknown error', $status = 'error') {
		$this->response->respond(array(
				'error' => $message
			), $status);
	}


	function include_value($key) {
		return $this->response->is_value_included($key);
	}


	function getClientIP() {
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return $ip;
	}


}


?>