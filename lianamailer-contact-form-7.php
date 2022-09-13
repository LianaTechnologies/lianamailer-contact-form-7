<?php
/**
 * Plugin Name:       LianaMailer - Contact Form 7
 * Plugin URI:        https://www.lianatech.com/solutions/websites
 * Description:       LianaMailer for Contact Form 7.
 * Version:           1.0.51
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Liana Technologies
 * Author URI:        https://www.lianatech.com
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * Text Domain:       lianamailer
 * Domain Path:       /languages
 *
 * PHP Version 7.4
 *
 * @category Components
 * @package  WordPress
 * @author   Liana Technologies <websites@lianatech.com>
 * @author   Timo Pohjanvirta <timo.pohjanvirta@lianatech.com>
 * @license  https://www.gnu.org/licenses/gpl-3.0-standalone.html GPL-3.0-or-later
 * @link     https://www.lianatech.com
 */

namespace CF7_LianaMailer;

add_action( 'plugins_loaded', '\CF7_LianaMailer\load_plugin', 10, 0 );

function load_plugin() {
	// if Contact Form 7 is installed (and active?)
	if ( defined( 'WPCF7_VERSION' ) ) {

		// TODO: Autoloader?
		require_once dirname(__FILE__) . '/includes/Mailer/Rest.php';
		require_once dirname(__FILE__) . '/includes/Mailer/LianaMailerConnection.php';

		// Plugin for Contact Form 7 to add tab for setting mailer settings
		require_once dirname(__FILE__) . '/includes/LianaMailerPlugin.php';

		try {
			$lmPlugin = new LianaMailerPlugin();
		} catch( \Exception $e ) {
			$error_messages[] = 'Error: ' . $e->getMessage();
		}


		/**
		 * Include admin menu & panel code
		 */
		require_once dirname(__FILE__) . '/admin/lianamailer-admin.php';
	}
}
