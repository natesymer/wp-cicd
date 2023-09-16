<?php
/*
 * Plugin Name: WP-CI/CD
 * Description: Securely exposes an endpoint to allow for installing themes via POST. Authentication is handled via basic auth and WP application passwords.
 * Requires PHP: 7.0
 * Author: Nate Symer
 * Author URI: https://symer.io/
 */

(function() {

function get_app_pw_user() {
	$username = $_SERVER['PHP_AUTH_USER'];
	$app_pw = $_SERVER['PHP_AUTH_PW'];

	if ($username && $app_pw && username_exists($username)) {
		$user = wp_authenticate_application_password(null, $username, $app_pw);
		if ($user instanceof WP_User) {
			return $user;
		}
	}

	return false;
}

add_filter('wp_is_application_passwords_available', '__return_true');
add_filter('application_password_is_api_request', '__return_true');

function deploy_theme() {
	$u = get_app_pw_user();
	if ($u) {
		wp_set_current_user($u->ID);
	}

	if (!current_user_can('upload_themes')) {
		header("Content-Type: application/json");
		echo json_encode([
			'errors' => [
				[
					'code_human_readable' => __('Sorry, you are not allowed to install themes on this site.'),
					'code' => 'not_permitted',
					'reason' => "You don't have the permissions to upload themes (upload_themes)"
				]
			],
			'logs' => []
		]);

		die();
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// A new implementation of the function
	// in wp-admin/includes/util.php
	function show_message($message) {
		if (is_wp_error($message)) {
			if ($message->get_error_data() && is_string($message->get_error_data())) {
				$message = $message->get_error_message() . ': ' . $message->get_error_data();
			} else {
				$message = $message->get_error_message();
			}
		}

		echo "<p>$message</p>\n";
	}


	$file_upload = new File_Upload_Upgrader('themezip', 'package');

	ob_start();
	$upgrader = new Theme_Upgrader();
	$upgrader->install($file_upload->package, [
		'overwrite_package' => true
	]);

	$result = $upgrader->skin->result;

	if ($result || is_wp_error($result)) {
		$file_upload->cleanup();
	}

	$content = ob_get_contents();
	ob_end_clean();

	$content = html_entity_decode(strip_tags($content));	

	function to_str($err) {
		$errs = [];
		foreach ($err->errors as $key => $_) {
			$str = $err->get_error_message($key);
			$d = $err->get_error_data($key);
			$errs[] = [
				'code_human_readable' => $str,
				'code' => $key,
				'reason' => $d
			];
		}
		return $errs;
	}

	header("Content-Type: application/json");
	echo json_encode([
		'errors' => is_wp_error($result) ? to_str($result) : null,
		'logs' => array_filter(explode("\n", $content))
	]);
}

add_filter('init', function() {
	add_rewrite_rule('__cicd__/deploy_theme/?$', 'index.php?theme_upload=true', 'top');
});

add_filter('query_vars', function ($query_vars) {
	$query_vars[] = 'theme_upload';
	return $query_vars;
});

add_filter('parse_query', function($q) {
	if ($q->query_vars['theme_upload']) {
		deploy_theme();
		die();
	}
});

})();
