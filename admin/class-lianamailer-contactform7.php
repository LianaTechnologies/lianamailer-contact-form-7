<?php
/**
 * LianaMailer Contact Form 7 admin panel
 *
 * PHP Version 7.4
 *
 * @package  LianaMailer
 * @author   Liana Technologies <websites@lianatech.com>
 * @license  GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * @link     https://www.lianatech.com
 */

namespace CF7_LianaMailer;

/**
 * LianaMailer / Contact Form 7 options panel class
 *
 * @package  LianaMailer
 * @author   Liana Technologies <websites@lianatech.com>
 * @license  GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * @link     https://www.lianatech.com
 */
class LianaMailer_ContactForm7 {

	/**
	 * REST API options to save
	 *
	 * @var lianamailer_contactform7_options array
	 */
	private $lianamailer_contactform7_options = array(
		'lianamailer_userid'     => '',
		'lianamailer_secret_key' => '',
		'lianamailer_realm'      => '',
		'lianamailer_url'        => '',
	);


	/**
	 * Constructor
	 */
	public function __construct() {
		add_action(
			'admin_menu',
			array( $this, 'liana_mailer_contact_form7_add_plugin_page' )
		);

		add_action(
			'admin_init',
			array( $this, 'liana_mailer_contact_form_7_page_init' )
		);
	}

	/**
	 * Add an admin page
	 *
	 * @return void
	 */
	public function liana_mailer_contact_form7_add_plugin_page():void {
		global $admin_page_hooks;

		// Only create the top level menu if it doesn't exist (via another plugin).
		if ( ! isset( $admin_page_hooks['lianamailer'] ) ) {
			add_menu_page(
				'LianaMailer',
				'LianaMailer',
				'manage_options',
				'lianamailer',
				array( $this, 'liana_mailer_contact_form_7_create_admin_page' ),
				'dashicons-admin-settings',
				65
			);
		}
		add_submenu_page(
			'lianamailer',
			'Contact Form 7',
			'Contact Form 7',
			'manage_options',
			'lianamailercontactform7',
			array( $this, 'liana_mailer_contact_form_7_create_admin_page' )
		);

		// Remove the duplicate of the top level menu item from the sub menu
		// to make things pretty.
		remove_submenu_page( 'lianamailer', 'lianamailer' );

	}


	/**
	 * Construct an admin page
	 *
	 * @return void
	 */
	public function liana_mailer_contact_form_7_create_admin_page():void {
		$this->lianamailer_contactform7_options = get_option( 'lianamailer_contactform7_options' );
		?>

		<div class="wrap">
		<?php
		// LianaMailer API Settings.
		?>
			<h2>LianaMailer API Options for Contact Form 7</h2>
		<?php settings_errors(); ?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'lianamailer_contactform7_option_group' );
			do_settings_sections( 'lianamailer_contactform7_admin' );
			submit_button();
			?>
		</form>
		</div>
		<?php
	}

	/**
	 * Init a Contact Form 7 admin page
	 *
	 * @return void
	 */
	public function liana_mailer_contact_form_7_page_init():void {

		$page    = 'lianamailer_contactform7_admin';
		$section = 'lianamailer_contactform7_section';

		register_setting(
			'lianamailer_contactform7_option_group',
			'lianamailer_contactform7_options',
			array(
				$this,
				'liana_mailer_contact_form_7_sanitize',
			)
		);

		add_settings_section(
			$section,
			'',
			array( $this, 'liana_mailer_contact_form_7_section_info' ),
			$page
		);

		$inputs = array(
			// API UserID.
			array(
				'name'     => 'lianamailer_contactform7_userid',
				'title'    => 'LianaMailer API UserID',
				'callback' => array( $this, 'liana_mailer_contact_form_7_user_id_callback' ),
				'page'     => $page,
				'section'  => $section,
			),
			// API Secret key.
			array(
				'name'     => 'lianamailer_contactform7_secret',
				'title'    => 'LianaMailer API Secret key',
				'callback' => array( $this, 'liana_mailer_contact_form_7_secret_key_callback' ),
				'page'     => $page,
				'section'  => $section,
			),
			// API URL.
			array(
				'name'     => 'lianamailer_contactform7_url',
				'title'    => 'LianaMailer API URL',
				'callback' => array( $this, 'liana_mailer_contact_form_7_url_callback' ),
				'page'     => $page,
				'section'  => $section,
			),
			// API Realm.
			array(
				'name'     => 'lianamailer_contactform7_realm',
				'title'    => 'LianaMailer API Realm',
				'callback' => array( $this, 'liana_mailer_contact_form_7_realm_callback' ),
				'page'     => $page,
				'section'  => $section,
			),
			// Status check.
			array(
				'name'     => 'lianamailer_contactform7_status_check',
				'title'    => 'LianaMailer Connection Check',
				'callback' => array( $this, 'liana_mailer_contact_form_7_connection_check_callback' ),
				'page'     => $page,
				'section'  => $section,
			),
		);

		$this->add_inputs( $inputs );

	}

	/**
	 * Adds setting inputs for admin view
	 *
	 * @param array $inputs - Array of inputs.
	 *
	 * @return void
	 */
	private function add_inputs( $inputs ):void {
		if ( empty( $inputs ) ) {
			return;
		}

		foreach ( $inputs as $input ) {
			try {
				add_settings_field(
					$input['name'],
					$input['title'],
					$input['callback'],
					$input['page'],
					$input['section'],
					( ! empty( $input['options'] ) ? $input['options'] : null )
				);
			} catch ( \Exception $e ) {
				$this->error_messages[] = 'Oops, something went wrong: ' . $e->getMessage();
			}
		}
	}

	/**
	 * Basic input sanitization function
	 *
	 * @param string $input array to be sanitized.
	 *
	 * @return array
	 */
	public function liana_mailer_contact_form_7_sanitize( $input ) {
		$sanitary_values = array();

		// LianaMailer inputs.
		if ( isset( $input['lianamailer_userid'] ) ) {
			$sanitary_values['lianamailer_userid']
				= sanitize_text_field( $input['lianamailer_userid'] );
		}
		if ( isset( $input['lianamailer_secret_key'] ) ) {
			$sanitary_values['lianamailer_secret_key']
				= sanitize_text_field( $input['lianamailer_secret_key'] );
		}
		if ( isset( $input['lianamailer_url'] ) ) {
			$sanitary_values['lianamailer_url']
				= sanitize_text_field( $input['lianamailer_url'] );
		}
		if ( isset( $input['lianamailer_realm'] ) ) {
			$sanitary_values['lianamailer_realm']
				= sanitize_text_field( $input['lianamailer_realm'] );
		}
		return $sanitary_values;
	}

	/**
	 * Empty section info
	 *
	 * @return void
	 */
	public function liana_mailer_contact_form_7_section_info():void {
		// Intentionally empty section here.
		// Could be used to generate info text.
	}

	/**
	 * LianaMailer API URL
	 *
	 * @return void
	 */
	public function liana_mailer_contact_form_7_url_callback():void {

		printf(
			'<input class="regular-text" type="text" '
			. 'name="lianamailer_contactform7_options[lianamailer_url]" '
			. 'id="lianamailer_url" value="%s">',
			isset( $this->lianamailer_contactform7_options['lianamailer_url'] ) ? esc_attr( $this->lianamailer_contactform7_options['lianamailer_url'] ) : ''
		);
	}
	/**
	 * LianaMailer API Realm
	 *
	 * @return void
	 */
	public function liana_mailer_contact_form_7_realm_callback():void {

		printf(
			'<input class="regular-text" type="text" '
			. 'name="lianamailer_contactform7_options[lianamailer_realm]" '
			. 'id="lianamailer_realm" value="%s">',
			isset( $this->lianamailer_contactform7_options['lianamailer_realm'] ) ? esc_attr( $this->lianamailer_contactform7_options['lianamailer_realm'] ) : ''
		);
	}

	/**
	 * LianaMailer Status check
	 *
	 * @return void
	 */
	public function liana_mailer_contact_form_7_connection_check_callback():void {

		$return = '💥Fail';

		if ( ! empty( $this->lianamailer_contactform7_options['lianamailer_userid'] ) || ! empty( $this->lianamailer_contactform7_options['lianamailer_secret_key'] ) || ! empty( $this->lianamailer_contactform7_options['lianamailer_realm'] ) ) {
			$rest = new Rest(
				$this->lianamailer_contactform7_options['lianamailer_userid'],
				$this->lianamailer_contactform7_options['lianamailer_secret_key'],
				$this->lianamailer_contactform7_options['lianamailer_realm'],
				$this->lianamailer_contactform7_options['lianamailer_url']
			);

			$status = $rest->get_status();
			if ( $status ) {
				$return = '💚 OK';
			}
		}

		echo esc_html( $return );

	}

	/**
	 * LianaMailer UserID
	 *
	 * @return void
	 */
	public function liana_mailer_contact_form_7_user_id_callback():void {
		printf(
			'<input class="regular-text" type="text" '
			. 'name="lianamailer_contactform7_options[lianamailer_userid]" '
			. 'id="lianamailer_userid" value="%s">',
			isset( $this->lianamailer_contactform7_options['lianamailer_userid'] ) ? esc_attr( $this->lianamailer_contactform7_options['lianamailer_userid'] ) : ''
		);
	}

	/**
	 * LianaMailer UserID
	 *
	 * @return void
	 */
	public function liana_mailer_contact_form_7_secret_key_callback():void {
		printf(
			'<input class="regular-text" type="text" '
			. 'name="lianamailer_contactform7_options[lianamailer_secret_key]" '
			. 'id="lianamailer_secret_key" value="%s">',
			isset( $this->lianamailer_contactform7_options['lianamailer_secret_key'] ) ? esc_attr( $this->lianamailer_contactform7_options['lianamailer_secret_key'] ) : ''
		);
	}
}
if ( is_admin() ) {
	$liana_mailer_contact_form_7 = new LianaMailer_ContactForm7();
}
