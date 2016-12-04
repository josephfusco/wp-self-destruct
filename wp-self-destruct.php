<?php
/**
 * Plugin Name:    WP Self Destruct
 * Plugin URI:     http://github.com/josephfusco/wp-self-destruct
 * Description:    Wipe out your entire site with one click, including the database.
 * Version:        1.0.0
 * Author:         Joseph Fusco
 * Author URI:     http://josephfus.co
 * License:        GPLv2 or later
 * Text Domain:    wp-self-destruct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize plugin.
 *
 * @since  1.0.0
 */
function wpsd_init() {
	if ( ! is_super_admin() ) {
		return;
	}

	add_action( 'admin_head', 'wpsd_action_javascript' );
	add_action( 'admin_menu', 'wpsd_register_menu_page' );
}
add_action( 'init', 'wpsd_init' );

/**
 * Add link to settings page.
 *
 * @since  1.0.0
 */
function wpsd_plugin_meta_links( $links, $file ) {
	$plugin = plugin_basename( __FILE__ );

	// create link
	if ( $file == $plugin ) {
		return array_merge(
			$links,
			array( '<a href="' . admin_url( 'tools.php?page=self-destruct' ) . '">Settings</a>' )
		);
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'wpsd_plugin_meta_links', 10, 2 );

/**
 * Register submenu page.
 *
 * @since  1.0.0
 */
function wpsd_register_menu_page() {
	add_submenu_page(
		'tools.php',
		'Self Destruct',
		'Self Destruct',
		'manage_options',
		'self-destruct',
		'wpsd_submenu_page'
	);
}

/**
 * Display a custom menu page.
 *
 * @since  1.0.0
 */
function wpsd_submenu_page() {
	$code = wpsd_generate_code();
	?>
	<div class="wrap card">
		<h1>Self Destruct</h1>

		<form id="wpsd" action="" method="post" enctype="multipart/form-data">
			<p><strong style="color: tomato;">WARNING</strong> This will completely delete your site and database by running the following commands:</p>
			<p><code><?php echo 'mysqladmin -u ' . DB_USER . ' -p ' . DB_PASSWORD . ' --force drop ' . DB_NAME; ?></code></p>
			<p><code><?php echo 'rm -rf ' . ABSPATH; ?></code></p>
			<hr>
			<p>
				Enter the security code into the text box to confirm:
				<strong><?php echo esc_html( $code ); ?></strong>
			</p>
			<p>
				<input type="hidden" name="generated_confirm_code" id="generated_confirm_code" value="<?php echo esc_html( $code ); ?>">
				<input type="text" name="user_confirm_code" id="user_confirm_code" value="" placeholder="*****" maxlength="5">
			</p>
			<p>
				<input type="submit" name="destroy" id="destroy" class="button button-primary button-red" value="Destroy Site" disabled>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Embed JS.
 *
 * @since  1.0.0
 */
function wpsd_action_javascript() {
	$ajax_nonce = wp_create_nonce( 'wpsd_ajax_nonce' );
	?>
	<script type="text/javascript">
	(function($) {

		$(document).ready(function($) {

			var form                   = $("#wpsd");
			var confirm_input          = $('#user_confirm_code');
			var btn                    = $('#destroy');
			var generated_confirm_code = $('#generated_confirm_code').val();

			form.submit(function(e) {

				e.preventDefault();

				var user_confirm_code = $('#user_confirm_code').val();

				var data = {
					action: 'wpsd_action',
					generated_confirm_code: generated_confirm_code,
					user_confirm_code: user_confirm_code,
					security: '<?php echo $ajax_nonce; ?>',
				};

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: data,
					success: function( response ) {
						location.reload();
					}
				})
			});

			// If confirmation codes match, remove disabled attribute
			confirm_input.on( 'change keyup paste', function() {
				btn.prop( 'disabled', $( this ).val() !== generated_confirm_code );
			});
		});
	})( jQuery );
	</script>
	<?php
}

/**
 * Process form data.
 *
 * @since  1.0.0
 */
function wpsd_process() {
	check_ajax_referer( 'wpsd_ajax_nonce', 'security' );

	global $wpdb;

	if ( isset( $_POST['generated_confirm_code'] ) && ! empty( $_POST['generated_confirm_code'] ) ) {
		$generated_confirm_code = sanitize_text_field( wp_unslash( $_POST['generated_confirm_code'] ) );
	}
	if ( isset( $_POST['user_confirm_code'] ) && ! empty( $_POST['user_confirm_code'] ) ) {
		$user_confirm_code = sanitize_text_field( wp_unslash( $_POST['user_confirm_code'] ) );
	}

	// Check if confirmation code matches generated code
	if ( $user_confirm_code === $generated_confirm_code ) {

		// Force delete database
		shell_exec( 'mysqladmin -u ' . DB_USER . ' -p ' . DB_PASSWORD . ' --force drop ' . DB_NAME );

		// Delete site
		exec( 'rm -rf ' . ABSPATH );

	} else {
		echo 'Confirmation code does not match';
	}

	exit();
}
add_action( 'wp_ajax_wpsd_action', 'wpsd_process' );

/**
 * Generate confirmation code.
 *
 * @since  1.0.0
 */
function wpsd_generate_code( $length = 5 ) {
	return substr( md5( time() ), 1, $length );
}
