<?php
/**
 * LianaMailer Contact Form 7 admin panel
 *
 * PHP Version 7.4
 *
 * @category Components
 * @package  WordPress
 * @author   Liana Technologies <websites@lianatech.com>
 * @license  GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * @link     https://www.lianatech.com
 */

/**
 * LianaMailer / Contact Form 7 options panel class
 *
 * @category Components
 * @package  WordPress
 * @author   Liana Technologies <websites@lianatech.com>
 * @license  GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * @link     https://www.lianatech.com
 */

namespace CF7_LianaMailer;

class LianaMailerContactForm7 {

	private $lianamailer_contactform7_options = [
		'lianamailer_userid' => '',
		'lianamailer_secret_key' => '',
		'lianamailer_realm' => '',
		'lianamailer_url' => ''
	];


    /**
     * Constructor
     */
    public function __construct() {
        add_action(
            'admin_menu',
            [ $this, 'lianaMailerContactForm7AddPluginPage' ]
        );

		add_action(
            'admin_init',
            [ $this, 'lianaMailerContactForm7PageInit' ]
        );
    }

    /**
     * Add an admin page
     *
     * @return null
     */
    public function lianaMailerContactForm7AddPluginPage() {
        global $admin_page_hooks;

        // Only create the top level menu if it doesn't exist (via another plugin)
        if (!isset($admin_page_hooks['lianamailer'])) {
            add_menu_page(
                'LianaMailer', // page_title
                'LianaMailer', // menu_title
                'manage_options', // capability
                'lianamailer', // menu_slug
				[$this, 'lianaMailerContactForm7CreateAdminPage' ],
                'dashicons-admin-settings', // icon_url
                65 // position
            );
        }
        add_submenu_page(
            'lianamailer',
            'Contact Form 7',
            'Contact Form 7',
            'manage_options',
            'lianamailercontactform7',
            [ $this, 'lianaMailerContactForm7CreateAdminPage' ],
        );

        // Remove the duplicate of the top level menu item from the sub menu
        // to make things pretty.
        remove_submenu_page('lianamailer', 'lianamailer');

    }


    /**
     * Construct an admin page
     *
     * @return null
     */
    public function lianaMailerContactForm7CreateAdminPage() {
		$this->lianamailer_contactform7_options = get_option('lianamailer_contactform7_options');
		?>

		<div class="wrap">
		<?php
		// LianaMailer API Settings
		?>
			<h2>LianaMailer API Options for Contact Form 7</h2>
		<?php settings_errors(); ?>
		<form method="post" action="options.php">
			<?php
			settings_fields('lianamailer_contactform7_option_group');
			do_settings_sections('lianamailer_contactform7_admin');
			submit_button();
			?>
		</form>
		</div>
        <?php
    }

    /**
     * Init a Contact Form 7 admin page
     *
     * @return null
     */
	public function lianaMailerContactForm7PageInit() {

		$page = 'lianamailer_contactform7_admin';
		$section = 'lianamailer_contactform7_section';

		// LianaMailer
		register_setting(
            'lianamailer_contactform7_option_group', // option_group
            'lianamailer_contactform7_options', // option_name
            [
                $this,
                'lianaMailerContactForm7Sanitize'
            ] // sanitize_callback
        );

		add_settings_section(
            $section, // id
            '', // empty section title text
            [ $this, 'lianMailerContactForm7SectionInfo' ], // callback
            $page // page
        );

		$inputs = [
			// API UserID
			[
				'name' => 'lianamailer_contactform7_userid',
				'title' => 'LianaMailer API UserID',
				'callback' => [ $this, 'lianaMailerContactForm7UserIDCallback' ],
				'page' => $page,
				'section' => $section
			],
			// API Secret key
			[
				'name' => 'lianamailer_contactform7_secret',
				'title' => 'LianaMailer API Secret key',
				'callback' => [ $this, 'lianaMailerContactForm7SecretKeyCallback' ],
				'page' => $page,
				'section' => $section
			],
			// API URL
			[
				'name' => 'lianamailer_contactform7_url',
				'title' => 'LianaMailer API URL',
				'callback' => [ $this, 'lianaMailerContactForm7UrlCallback' ],
				'page' => $page,
				'section' => $section,
			],
			// API Realm
			[
				'name' => 'lianamailer_contactform7_realm',
				'title' => 'LianaMailer API Realm',
				'callback' => [ $this, 'lianaMailerContactForm7RealmCallback' ],
				'page' => $page,
				'section' => $section,
			],
			// Status check
			[
				'name' => 'lianamailer_contactform7_status_check',
				'title' => 'LianaMailer Connection Check',
				'callback' => [ $this, 'lianaMailerContactForm7ConnectionCheckCallback' ],
				'page' => $page,
				'section' => $section
			]
		];

		$this->addInputs($inputs);

	}

	private function addInputs($inputs) {
		if(empty($inputs))
			return;

		foreach($inputs as $input) {
			try {
				add_settings_field(
					$input['name'], // id
					$input['title'], // title
					$input['callback'], // callback
					$input['page'], // page
					$input['section'], // section
					(!empty($input['options']) ? $input['options'] : null)
				);
			}
			catch (\Exception $e) {
				$this->error_messages[] = 'Oops, something went wrong: '.$e->getMessage();
			}
		}
	}

    /**
     * Basic input sanitization function
     *
     * @param string $input String to be sanitized.
     *
     * @return null
     */
    public function lianaMailerContactForm7Sanitize($input) {
        $sanitary_values = [];

		// for LianaMailer inputs
		if (isset($input['lianamailer_userid'])) {
            $sanitary_values['lianamailer_userid']
                = sanitize_text_field($input['lianamailer_userid']);
        }
		if (isset($input['lianamailer_secret_key'])) {
            $sanitary_values['lianamailer_secret_key']
                = sanitize_text_field($input['lianamailer_secret_key']);
        }
		if (isset($input['lianamailer_url'])) {
            $sanitary_values['lianamailer_url']
                = sanitize_text_field($input['lianamailer_url']);
        }
		if (isset($input['lianamailer_realm'])) {
            $sanitary_values['lianamailer_realm']
                = sanitize_text_field($input['lianamailer_realm']);
        }
        return $sanitary_values;
    }

    /**
     * Empty section info
     *
     * @return null
     */
    public function lianMailerContactForm7SectionInfo($arg) {
        // Intentionally empty section here.
        // Could be used to generate info text.
    }

	/**
     * LianaMailer API URL
     *
     * @return null
     */
    public function lianaMailerContactForm7UrlCallback() {

		printf(
            '<input class="regular-text" type="text" '
            .'name="lianamailer_contactform7_options[lianamailer_url]" '
            .'id="lianamailer_url" value="%s">',
			isset($this->lianamailer_contactform7_options['lianamailer_url']) ? esc_attr($this->lianamailer_contactform7_options['lianamailer_url']) : ''
        );
    }
	/**
     * LianaMailer API Realm
     *
     * @return null
     */
    public function lianaMailerContactForm7RealmCallback() {
		// https://app.lianamailer.com
		printf(
            '<input class="regular-text" type="text" '
            .'name="lianamailer_contactform7_options[lianamailer_realm]" '
            .'id="lianamailer_realm" value="%s">',
			isset($this->lianamailer_contactform7_options['lianamailer_realm']) ? esc_attr($this->lianamailer_contactform7_options['lianamailer_realm']) : ''
        );
    }

	/**
     * LianaMailer Status check
     *
     * @return null
     */
    public function lianaMailerContactForm7ConnectionCheckCallback() {

		$return = '💥Fail';

		if(!empty($this->lianamailer_contactform7_options['lianamailer_userid']) || !empty($this->lianamailer_contactform7_options['lianamailer_secret_key']) || !empty($this->lianamailer_contactform7_options['lianamailer_realm'])) {
			$rest = new Rest(
				$this->lianamailer_contactform7_options['lianamailer_userid'],		// userid
				$this->lianamailer_contactform7_options['lianamailer_secret_key'],	// user secret
				$this->lianamailer_contactform7_options['lianamailer_realm'],		// realm eg. "EUR"
				$this->lianamailer_contactform7_options['lianamailer_url']			// https://rest.lianamailer.com
			);

			$status = $rest->getStatus();
			if($status) {
				$return = '💚 OK';
			}
		}

		echo $return;

    }

	/**
     * LianaMailer UserID
     *
     * @return null
     */
    public function lianaMailerContactForm7UserIDCallback() {
        printf(
            '<input class="regular-text" type="text" '
            .'name="lianamailer_contactform7_options[lianamailer_userid]" '
            .'id="lianamailer_userid" value="%s">',
            isset($this->lianamailer_contactform7_options['lianamailer_userid']) ? esc_attr($this->lianamailer_contactform7_options['lianamailer_userid']) : ''
        );
    }

		/**
     * LianaMailer UserID
     *
     * @return null
     */
    public function lianaMailerContactForm7SecretKeyCallback() {
        printf(
            '<input class="regular-text" type="text" '
            .'name="lianamailer_contactform7_options[lianamailer_secret_key]" '
            .'id="lianamailer_secret_key" value="%s">',
			isset($this->lianamailer_contactform7_options['lianamailer_secret_key']) ? esc_attr($this->lianamailer_contactform7_options['lianamailer_secret_key']) : ''
        );
    }
}
if (is_admin()) {
    $lianaMailerContactForm7 = new LianaMailerContactForm7();
}

