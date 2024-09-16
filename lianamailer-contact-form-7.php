<?php
/**
 * Plugin Name:       LianaMailer for Contact Form 7
 * Description:       LianaMailer for Contact Form 7.
 * Version:           1.0.60
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Liana Technologies Oy
 * Author URI:        https://www.lianatech.com
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * Text Domain:       lianamailer-contact-form-7
 * Domain Path:       /languages
 *
 * PHP Version 7.4
 *
 * @package  LianaMailer
 * @license  https://www.gnu.org/licenses/gpl-3.0-standalone.html GPL-3.0-or-later
 * @link     https://www.lianatech.com
 */

namespace CF7_LianaMailer;

define( 'LMCF7_VERSION', '1.0.60' );

add_action( 'plugins_loaded', '\CF7_LianaMailer\load_plugin', 10, 0 );

/**
 * Loads LianaMailer - Contact Form 7 plugin
 *
 * @return void
 */
function load_plugin():void {
	// if Contact Form 7 is installed (and active?).
	if ( defined( 'WPCF7_VERSION' ) ) {

		require_once dirname( __FILE__ ) . '/includes/Mailer/class-rest.php';
		require_once dirname( __FILE__ ) . '/includes/Mailer/class-lianamailerconnection.php';

		// Plugin for Contact Form 7 to add tab for setting mailer settings.
		require_once dirname( __FILE__ ) . '/includes/class-lianamailerplugin.php';

		try {
			$lm_plugin = new LianaMailerPlugin();
		} catch ( \Exception $e ) {
			$error_messages[] = 'Error: ' . $e->getMessage();
		}

		/**
		 * Include admin menu & panel code
		 */
		require_once dirname( __FILE__ ) . '/admin/class-lianamailer-contactform7.php';
	}
}
