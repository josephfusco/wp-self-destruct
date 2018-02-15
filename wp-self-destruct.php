<?php
/**
 * Plugin Name:    WP Self Destruct
 * Plugin URI:     http://github.com/josephfusco/wp-self-destruct
 * Description:    Wipe out your entire site with one click, including the database.
 * Version:        1.1.0
 * Author:         Joseph Fusco
 * Author URI:     https://josephfus.co
 * License:        GPLv2 or later
 * Text Domain:    wp-self-destruct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Self_Destruct {

	public function __construct() {
		$this->load_admin();
	}

	/**
	 * Load admin functionality.
	 *
	 * @since  1.1.0
	 */
	public function load_admin() {
		add_action( 'init', array( $this, 'plugin_init' ) );
	}

	/**
	 * Initialize wp-admin side of plugin.
	 *
	 * @since  1.1.0
	 */
	public function plugin_init() {
		if ( ! is_super_admin() ) {
			return;
		}

		add_action( 'admin_head', array( $this, 'action_javascript' ) );
		add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_meta_links' ), 10, 2 );
		add_action( 'wp_ajax_wpsd_action', array( $this, 'process_form' ) );
	}

	/**
	 * Add link to settings page.
	 *
	 * @since  1.1.0
	 */
	public function plugin_meta_links( $links, $file ) {
		$plugin = plugin_basename( __FILE__ );

		// Create link.
		if ( $file == $plugin ) {
			return array_merge(
				$links,
				array( '<a href="' . admin_url( 'tools.php?page=self-destruct' ) . '">Settings</a>' )
			);
		}

		return $links;
	}

	/**
	 * Register submenu page.
	 *
	 * @since  1.1.0
	 */
	public function register_menu_page() {
		add_submenu_page(
			'tools.php',
			'Self Destruct',
			'Self Destruct',
			'manage_options',
			'self-destruct',
			array( $this, 'submenu_page_cb' )
		);
	}

	/**
	 * Display a custom menu page.
	 *
	 * @since  1.1.0
	 */
	public function submenu_page_cb() {
		$code = $this->generate_code();
		?>
		<div class="wrap card">

			<h1>Self Destruct</h1>

			<form id="wpsd" action="" method="post" enctype="multipart/form-data">

				<p><strong style="color: tomato;">WARNING!</strong></p>

				<p>This will completely delete your database by running the following command:</p>

				<p><code><?php echo 'mysqladmin -u ' . DB_USER . ' -p ' . DB_PASSWORD . ' --force drop ' . DB_NAME; ?></code></p>

				<p>The following directory will also be deleted:</p>

				<p><code><?php echo ABSPATH; ?></code></p>

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
	 * @since  1.1.0
	 */
	public function action_javascript() {
		$ajax_nonce = wp_create_nonce( 'wpsd_ajax_nonce' );
		?>
		<script type="text/javascript">
		( function( $ ) {

			$( document ).ready( function( $ ) {

				var form                   = $( '#wpsd' );
				var confirm_input          = $( '#user_confirm_code' );
				var btn                    = $( '#destroy' );
				var generated_confirm_code = $( '#generated_confirm_code' ).val();

				form.submit( function( e ) {

					e.preventDefault();

					var user_confirm_code = $( '#user_confirm_code' ).val();

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
	 * @since  1.1.0
	 */
	public function process_form() {
		check_ajax_referer( 'wpsd_ajax_nonce', 'security' );

		if ( isset( $_POST['generated_confirm_code'] ) && ! empty( $_POST['generated_confirm_code'] ) ) {
			$generated_confirm_code = sanitize_text_field( wp_unslash( $_POST['generated_confirm_code'] ) );
		}
		if ( isset( $_POST['user_confirm_code'] ) && ! empty( $_POST['user_confirm_code'] ) ) {
			$user_confirm_code = sanitize_text_field( wp_unslash( $_POST['user_confirm_code'] ) );
		}

		// Check if confirmation code matches generated code
		if ( $user_confirm_code === $generated_confirm_code ) {

			// Force delete database.
			$this->cmd_rmdb();

			// Delete site.
			$this->cmd_rmdir( ABSPATH );

		} else {
			echo 'Confirmation code does not match';
		}

		exit();
	}

	/**
	 * Generate confirmation code.
	 *
	 * @since  1.1.0
	 */
	public function generate_code( $length = 5 ) {
		return substr( md5( time() ), 1, $length );
	}

	/**
	 * Recursively delete a directory and all of it's contents.
	 *
	 * Equivalent of `rm -r` on command-line. Deletes all files with
	 * `rmdir()` and `unlink()`, an E_WARNING level error will be generated on failure.
	 *
	 * @since  1.1.0
	 *
	 * @param string $dir Absolute path to directory to delete.
	 * @return bool Return a boolean: true on success, false on failure.
	 *
	 */
	public function cmd_rmdir( $path ) {
		if ( FALSE === file_exists( $path ) ) {
			return FALSE;
		}

		/** @var SplFileInfo[] $files */
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $fileinfo ) {
			if ( $fileinfo->isDir() ) {
				if ( FALSE === rmdir( $fileinfo->getRealPath() ) ) {
					return FALSE;
				}
			} else {
				if ( FALSE === unlink( $fileinfo->getRealPath() ) ) {
					return FALSE;
				}
			}
		}

		return rmdir( $path );
	}

	/**
	 * Delete the site's database.
	 *
	 * @since  1.1.0
	 */
	public function cmd_rmdb() {
		shell_exec( 'mysqladmin -u ' . DB_USER . ' -p ' . DB_PASSWORD . ' --force drop ' . DB_NAME );
	}

}

$wp_self_destruct = new WP_Self_Destruct();

if ( class_exists( 'WP_CLI' ) ) {

	/**
	 * Add WP CLI command for destroying site.
	 *
	 * @since  1.1.0
	 */
	$wpsd_destroy_command = function( $args, $assoc_args ) {
		$siteURL    = get_site_url();

		$successMsg = "Database & files for " . $siteURL . " have been deleted.";
		$warningMsg = "This is irreversible!";
		$confirmMsg = "Are you sure you want to delete " . $siteURL . "?";

		// If --yes flag found, immediately destroy site.
		if ( isset( $assoc_args['yes'] ) ) {

			// Delete db & files.
			WP_Self_Destruct::cmd_rmdb();
			WP_Self_Destruct::cmd_rmdir( ABSPATH );

			// Print success message.
			WP_CLI::success( $successMsg );

			return;
		}

		// Confirm with user if they want to proceed.
		WP_CLI::warning( $warningMsg );
		WP_CLI::confirm( $confirmMsg );

		// Delete db & files.
		WP_Self_Destruct::cmd_rmdb();
		WP_Self_Destruct::cmd_rmdir( ABSPATH );

		// Print success message.
		WP_CLI::success( $successMsg );
	};

	WP_CLI::add_command( 'destroy', $wpsd_destroy_command );

}
