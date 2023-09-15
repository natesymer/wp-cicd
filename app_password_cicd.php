<?php

// curl -X POST -H "Authorization: Basic YWRtaW46RmppeiBuODBMIEhsTjAgcDhMSyBNZ2xsIFQxQ1c=" http://localhost:8000/__upload_theme"

function get_app_pw_user() {
	$username = $_SERVER['PHP_AUTH_USER'];
	$app_pw = $_SERVER['PHP_AUTH_PW'];

	$user = wp_authenticate_application_password(null, $username, $app_pw);
	if ($user instanceof WP_User) {
		return $user;
	}

	return false;
}

add_filter('wp_is_application_passwords_available', '__return_true');
add_filter('application_password_is_api_request', '__return_true');

define("THE_TOKEN", "TOKEN");

function hsh($token) {
	if (function_exists('hash')) {
		return hash('sha256', $token);
	} else {
		return sha1($token);
	}
}

add_filter('get_user_metadata', function($check, $user_id, $meta_key) {
	if ($meta_key === 'session_tokens') {
		$u = get_app_pw_user();
		if ($u && $u->ID === $user_id) {
			$v = [hsh(THE_TOKEN) => PHP_INT_MAX];
			return [$v];
		}
	}

	return $check;
}, 10, 3);

// This is the first hook where wp_validate_auth_cookie is defined.
add_filter('plugins_loaded', function() {
	$u = get_app_pw_user();
	if ($u) {
		$_COOKIE[AUTH_COOKIE] = wp_generate_auth_cookie($u->ID, PHP_INT_MAX, 'auth', THE_TOKEN);
		$_COOKIE[SECURE_AUTH_COOKIE] = wp_generate_auth_cookie($u->ID, PHP_INT_MAX, 'secure_auth', THE_TOKEN);
		$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($u->ID, PHP_INT_MAX, 'logged_in', THE_TOKEN);
	}
});

function deploy_theme() {
	if (!current_user_can('upload_themes')) {
		wp_die( __( 'Sorry, you are not allowed to install themes on this site.' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// from wp-admin/includes/util.php
	// rewritten to avoid calling ob_* and flush()
	function show_message($message) {
		if (is_wp_error( $message ) ) {
			if ($message->get_error_data() && is_string( $message->get_error_data() ) ) {
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
