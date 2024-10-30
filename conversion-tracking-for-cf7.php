<?php
/*
Plugin Name: Conversion Tracking for Contact Form 7
Description: Adds tracking info to all contact form 7 outgoing emails by using the [tracking-info] shortcode. The tracking info includes the Form Page URL, Original Referrer, Landing Page, User IP, Browser. 
Version: 1.3.3
Author: Inbound Horizons
Author URI: https://www.inboundhorizons.com/
Requires at least: 3.3
Requires PHP: 5.4
*/


$CTCF7_COOKIE = 'ctcf7_cookie';


add_filter('wpcf7_mail_components', 'CTCF7_AddTrackingToEmail');
function CTCF7_AddTrackingToEmail($array) {
	global $CTCF7_COOKIE;

	$lineBreak = PHP_EOL;	// Default PHP line break for plaintext emails. 
	if (wpautop($array['body']) == $array['body']) { // The email is of HTML type...
		$lineBreak = "<br/>"; // Use an HTML line break
	}
	
	$trackingInfo = $lineBreak . $lineBreak . "-- Tracking Info --" . $lineBreak;
	$trackingInfo .= "The user filled the form on: " . sanitize_text_field($_SERVER['HTTP_REFERER']) . $lineBreak;





	
	if (isset($_COOKIE[$CTCF7_COOKIE])) {	// If the session cookie is set...
	
	
		$session_key = sanitize_text_field($_COOKIE[$CTCF7_COOKIE]);	// Sanitize the cookie before using it
		$session_record = CTCF7_GetSessionRecord($session_key);
		
		if (is_array($session_record) && (isset($session_record['session_value']))) {	// If the record was actually set...
			
			$session = unserialize($session_record['session_value']);
			

		
			if (isset($session['OriginalRef'])) {
				$trackingInfo .= "The user came to your website from: " . sanitize_text_field($session['OriginalRef']) . $lineBreak;
			}
			
			if (isset($session['LandingPage'])) {
				$trackingInfo .= "The user's landing page on your website: " . sanitize_text_field($session['LandingPage']) . $lineBreak;
			}
		}
		
	
	}
	
	

	if (isset($_SERVER["REMOTE_ADDR"])) {
		$trackingInfo .= "User's IP: " . sanitize_text_field($_SERVER["REMOTE_ADDR"]) . $lineBreak;
	}
	
	if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
		$trackingInfo .= "User's Proxy Server IP: " . sanitize_text_field($_SERVER["HTTP_X_FORWARDED_FOR"]) . $lineBreak;
	}

	if (isset($_SERVER["HTTP_USER_AGENT"])) {
		$trackingInfo .= "User's browser is: " . sanitize_text_field($_SERVER["HTTP_USER_AGENT"]) . $lineBreak;
	}

	$array['body'] = str_replace('[tracking-info]', $trackingInfo, $array['body']);

    return $array;
}


add_action('init', 'CTCF7_SetLandingInfo');
function CTCF7_SetLandingInfo() {
	global $CTCF7_COOKIE;

	if (!isset($_COOKIE[$CTCF7_COOKIE])) {
		
		// Get the values to save
			$original_ref = isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field($_SERVER['HTTP_REFERER']) : ''; 
			$landing_page = "http://" . sanitize_text_field($_SERVER["SERVER_NAME"]) . sanitize_text_field($_SERVER["REQUEST_URI"]); 
		
		
		// Package the values into an array
			$session = array();
			$session['OriginalRef'] = $original_ref;
			$session['LandingPage'] = $landing_page;
		
		
		// Save the values
			$session_key = CTCF7_InsertSession($session);
		
		
		// Set the session cookie with the DB session key
			setcookie($CTCF7_COOKIE, $session_key, time() + (21600), "/"); // 21600 = 6 hours
			$_COOKIE[$CTCF7_COOKIE] = $session_key;
		
	}
	
}



register_activation_hook(__FILE__, 'CTCF7_CreateSessionTable');
function CTCF7_CreateSessionTable() {
	// https://codex.wordpress.org/Creating_Tables_with_Plugins
	global $wpdb;

	$table = 'track_sessions';
	$table_name = $wpdb->prefix . $table;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "
		CREATE TABLE ".$table_name." (
			session_key char(32) NOT NULL,
			session_value LONGTEXT NOT NULL,
			session_expiry BIGINT(20) UNSIGNED NOT NULL,
			PRIMARY KEY (session_key)
		) $charset_collate;
	";
	

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}





function CTCF7_InsertSession($value = array(), $expires = 86400) {	// (86400 = 1 day)
	global $wpdb;
	
	
	CTCF7_CleanupSessionTable();	// Clean up the table by removing old records
	
	// Get a unique session key that is not in the DB
	$key = '';
	$duplicate_key = true;
	while ($duplicate_key) {
		$key = CTCF7_GenerateSessionKey();
		$record = CTCF7_GetSessionRecord($key);
		
		if (!is_array($record) || empty($record)) {
			$duplicate_key = false;
		}
	}
	
	
	
	
	$table = 'track_sessions';
	$table_name = $wpdb->prefix . $table;
	
	$wpdb->insert($table_name, 
		array(
			'session_key' => $key,
			'session_value' => serialize($value),		// Serialize the data
			'session_expiry' => (time() + $expires),	// Set the expiration time as right NOW + expiration seconds
		),
		array(
			'%s',
			'%s',
			'%d',
		)
	);
	
	return ($key);
}

function CTCF7_GetSessionRecord($key) {
	global $wpdb;
	
	$table = 'track_sessions';
	$table_name = $wpdb->prefix . $table;
	
	$record = $wpdb->get_row(
		$wpdb->prepare("SELECT * FROM ".$table_name." WHERE session_key = %s", $key),
		ARRAY_A
	);
	
	return ($record);
}

function CTCF7_GenerateSessionKey($count = 32) {
	$chars = array_merge(range('A', 'Z'), range('a', 'z'), range(0, 9));	// Get an array of all letters and numbers
	
	$key = '';
	for ($i = 0; $i < $count; $i++) {
		$key .= $chars[array_rand($chars)];
	}
	
	return ($key);
}

function CTCF7_CleanupSessionTable() {
	global $wpdb;
	
	$table = 'track_sessions';
	$table_name = $wpdb->prefix . $table;
	
	// Delete all records that have expired
	$current_time = time();
	$sql = $wpdb->prepare("DELETE FROM ".$table_name." WHERE session_expiry < %d", $current_time);
	$wpdb->query($sql);
	
}








