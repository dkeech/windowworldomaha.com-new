<?php

namespace TrimarkDigital\Framework\Setup;

/**
 * Theme setup
 */
function setup() {
	// Make theme available for translation
	// Community translations can be found at https://github.com/roots/sage-translations
	load_theme_textdomain('trimark', get_template_directory() . '/lang');

	// Enable plugins to manage the document title
	// http://codex.wordpress.org/Function_Reference/add_theme_support#Title_Tag
	add_theme_support('title-tag');

	// Register wp_nav_menu() menus
	// http://codex.wordpress.org/Function_Reference/register_nav_menus
	register_nav_menus([
		'primary_navigation' => __('Primary Navigation', 'trimark'),
		'utility_navigation'  => __('Utility Navigation', 'trimark'),
		'footer_navigation'  => __('Footer Navigation', 'trimark'),
	]);

	// Enable post thumbnails
	// http://codex.wordpress.org/Post_Thumbnails
	// http://codex.wordpress.org/Function_Reference/set_post_thumbnail_size
	// http://codex.wordpress.org/Function_Reference/add_image_size
	add_theme_support('post-thumbnails');

	// Enable HTML5 markup support
	// http://codex.wordpress.org/Function_Reference/add_theme_support#HTML5
	add_theme_support('html5', ['caption', 'comment-form', 'comment-list', 'gallery', 'search-form']);

	// Head cleanup + Security
	remove_action('wp_head', 'wp_generator'); // WP version
	remove_action('wp_head', 'rsd_link'); // EditURI link
	remove_action('wp_head', 'wlwmanifest_link'); // windows live writer
}
add_action('after_setup_theme', __NAMESPACE__ . '\\setup');

/**
 * Move Yoast Metabox to Bottom.
 */
add_filter('wpseo_metabox_prio', function () {
	return 'low';
});

/**
 * Add sitemap to default WP robots.txt
 */
add_filter('robots_txt', function ($output, $public) {
	$site_url = parse_url(site_url());
	$output .= "Sitemap: {$site_url['scheme']}://{$site_url['host']}/sitemap_index.xml\n";
	return $output;
}, 99, 2);

/**
 * Read more link for excerpts.
 *
 * @param  string $more
 * @return string
 */
function excerpt_more($more) {
	return sprintf(
		'... <a href="%1$s" class="read-more-link">%2$s</a>',
		esc_url(get_permalink(get_the_ID())),
		sprintf(__('Continue reading %s', 'wpdocs'), '<span class="screen-reader-text">' . get_the_title(get_the_ID()) . '</span>')
	);
}
add_filter('excerpt_more', __NAMESPACE__ . '\\excerpt_more');


/**
 * Override native WP PHPMailer credentials with SendGrid API.
 *
 * @param  object $phpmailer
 * @return void
 */
function phpmailer_init($phpmailer) {
	// Don't configure SMTP on production/staging - we'll use API instead
	if (wp_get_environment_type() === 'production' || wp_get_environment_type() === 'staging') {
		// Prevent default PHPMailer processing
		// We're handling it via the wp_mail filter instead
		error_log('=== Using SendGrid API instead of SMTP ===');
	}
}
add_action('phpmailer_init', __NAMESPACE__ . '\\phpmailer_init', 999);

/**
 * Send email via SendGrid API instead of SMTP
 *
 * @param  array $args
 * @return bool
 */
function send_via_sendgrid_api($args) {
	// Only use API on production/staging
	if (wp_get_environment_type() !== 'production' && wp_get_environment_type() !== 'staging') {
		return $args; // Let default mail handler work
	}
	
	$api_key = getenv('SENDGRID_API_KEY');
	
	error_log('=== SENDING VIA SENDGRID API ===');
	error_log('To: ' . print_r($args['to'], true));
	error_log('Subject: ' . $args['subject']);
	
	// Format recipients
	$to_emails = is_array($args['to']) ? $args['to'] : explode(',', $args['to']);
	$to_formatted = array_map(function($email) {
		return ['email' => trim($email)];
	}, $to_emails);
	
	$data = [
		'personalizations' => [
			[
				'to' => $to_formatted
			]
		],
		'from' => ['email' => 'noreply@trimarkleads.com', 'name' => get_bloginfo('name')],
		'subject' => $args['subject'],
		'content' => [
			['type' => 'text/html', 'value' => $args['message']]
		]
	];
	
	$response = wp_remote_post('https://api.sendgrid.com/v3/mail/send', [
		'headers' => [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type' => 'application/json'
		],
		'body' => json_encode($data),
		'timeout' => 30
	]);
	
	if (is_wp_error($response)) {
		error_log('SendGrid API Error: ' . $response->get_error_message());
		return $args; // Return original args to let wp_mail try default method
	}
	
	$code = wp_remote_retrieve_response_code($response);
	$body = wp_remote_retrieve_body($response);
	
	error_log('SendGrid API Response Code: ' . $code);
	if ($code !== 202) {
		error_log('SendGrid API Response Body: ' . $body);
	}
	
	// If successful, we need to prevent wp_mail from actually sending
	// We do this by adding a filter that makes PHPMailer fail gracefully
	if ($code == 202) {
		error_log('=== EMAIL SENT SUCCESSFULLY VIA SENDGRID API ===');
		add_action('phpmailer_init', function($phpmailer) {
			$phpmailer->ClearAllRecipients(); // Prevent actual sending
		}, 9999);
	}
	
	return $args;
}
add_filter('wp_mail', __NAMESPACE__ . '\\send_via_sendgrid_api', 10, 1);
