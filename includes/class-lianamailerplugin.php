<?php
/**
 * LianaMailer - Contact Form 7 plugin
 *
 * PHP Version 7.4
 *
 * @package  LianaMailer
 * @license  https://www.gnu.org/licenses/gpl-3.0-standalone.html GPL-3.0-or-later
 * @link     https://www.lianatech.com
 */

namespace CF7_LianaMailer;

/**
 * LianaMailer - Contact Form 7 plugin class
 *
 * PHP Version 7.4
 *
 * @package  LianaMailer
 * @license  https://www.gnu.org/licenses/gpl-3.0-standalone.html GPL-3.0-or-later
 * @link     https://www.lianatech.com
 */
class LianaMailerPlugin {

	/**
	 * Posted data
	 *
	 * @var post_data array
	 */
	private $post_data;

	/**
	 * LianaMailer connection object
	 *
	 * @var lianamailer_connection object
	 */
	private static $lianamailer_connection;

	/**
	 * Site data fetched from LianaMailer
	 *
	 * @var site_data array
	 */
	private static $site_data = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		self::$lianamailer_connection = new LianaMailerConnection();
		self::add_actions();
	}

	/**
	 * Adds actions for the plugin
	 *
	 * @return void
	 */
	public function add_actions():void {
		add_action( 'admin_enqueue_scripts', array( $this, 'add_liana_mailer_plugin_scripts' ), 10, 1 );
		add_action( 'wp_ajax_getSiteDataForCF7Settings', array( $this, 'get_site_data_for_settings' ), 10, 1 );

		// adds LianaMailer tab into admin view.
		add_filter( 'wpcf7_editor_panels', array( $this, 'add_liana_mailer_panel' ), 10, 1 );
		add_action( 'save_post_wpcf7_contact_form', array( $this, 'save_form_settings' ), 10, 2 );

		// adds fields into public form.
		add_action( 'wpcf7_contact_form', array( $this, 'add_liana_mailer_inputs_to_form' ), 10, 1 );
		add_filter( 'wpcf7_form_elements', array( $this, 'force_acceptance' ), 10, 1 );
		// on submit make a newsletter subscription.
		add_action( 'wpcf7_submit', array( $this, 'do_newsletter_subscription' ), 10, 2 );
		// create tags on selectable fields for form.
		add_action( 'admin_init', array( $this, 'add_liana_mailer_properties' ), 10, 1 );
	}

	/**
	 * Make newsletter subscription
	 *
	 * @param object $cf7_instance Contact Form 7 instance.
	 * @param array  $result Result of submission.
	 * @throws \Exception If submission failed.
	 *
	 * @return void
	 */
	public function do_newsletter_subscription( $cf7_instance, $result ):void {

		$submission        = \WPCF7_Submission::get_instance();
		$is_plugin_enabled = (bool) get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_enabled', true );
		// works only in public form and check if plugin is enablen on current form.
		if ( ! $is_plugin_enabled || ! empty( $submission->get_invalid_fields() ) ) {
			return;
		}

		self::get_liana_mailer_site_data( $cf7_instance );
		if ( empty( self::$site_data ) ) {
			return;
		}

		$list_id      = get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_mailing_lists', true );
		$consent_id   = (int) get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_site_consents', true );
		$consent_data = array();

		$key = array_search( intval( $list_id ), array_column( self::$site_data['lists'], 'id' ), true );
		// if selected list is not found anymore from LianaMailer subscription page, do not allow subscription.
		if ( false === $key ) {
			$list_id = null;
		}

		$posted_data    = false;
		$failure        = false;
		$failure_reason = false;
		if ( $submission ) {
			$posted_data = $submission->get_posted_data();
		}

		if ( ! empty( $posted_data ) ) {
			try {

				$email = ( isset( $posted_data['LM_email'] ) ? sanitize_email( trim( $posted_data['LM_email'] ) ) : false );
				$sms   = ( isset( $posted_data['LM_sms'] ) ? sanitize_text_field( trim( $posted_data['LM_sms'] ) ) : null );

				if ( empty( $list_id ) ) {
					throw new \Exception( 'No mailing lists set' );
				}
				if ( empty( $email ) && empty( $sms ) ) {
					throw new \Exception( 'No email or SMS -field set' );
				}

				$subscribe_by_email = false;
				$subscribe_by_sms   = false;
				if ( $email ) {
					$subscribe_by_email = true;
				} elseif ( $sms ) {
					$subscribe_by_sms = true;
				}

				if ( $subscribe_by_email || $subscribe_by_sms ) {
					$this->post_data = $posted_data;

					$customer_settings = self::$lianamailer_connection->get_lianamailer_customer();
					/**
					 * Autoconfirm subscription if:
					 * LM site has "registration_needs_confirmation" disabled
					 * email set
					 * LM site has welcome mail set
					 */
					$auto_confirm = ( ( isset( $customer_settings['registration_needs_confirmation'] ) && empty( $customer_settings['registration_needs_confirmation'] ) ) || ! $email || ! self::$site_data['welcome'] );

					$properties = $this->filter_recipient_properties();
					self::$lianamailer_connection->set_properties( $properties );

					if ( $subscribe_by_email ) {
						$recipient = self::$lianamailer_connection->get_recipient_by_email( $email );
					} else {
						$recipient = self::$lianamailer_connection->get_recipient_by_sms( $sms );
					}

					// if recipient found from LM and it not enabled and subscription had email set, re-enable it. Recipient only with SMS cannot be activated.
					if ( ! is_null( $recipient ) && isset( $recipient['recipient']['enabled'] ) && false === $recipient['recipient']['enabled'] && $email ) {
						self::$lianamailer_connection->reactivate_recipient( $email, $auto_confirm );
					}
					self::$lianamailer_connection->create_and_join_recipient( $recipient, $email, $sms, $list_id, $auto_confirm );

					$consent_key = array_search( $consent_id, array_column( self::$site_data['consents'], 'consent_id' ), true );
					if ( false !== $consent_key ) {
						$consent_data = self::$site_data['consents'][ $consent_key ];
						// Add consent to recipient.
						self::$lianamailer_connection->add_recipient_consent( $consent_data );
					}

					// send welcome mail if:
					// not existing recipient OR recipient was not previously enabled OR registration needs confirmation is enabled
					// and site is using welcome -mail and LM account has double opt-in enabled and email address set.
					if ( ( ! $recipient || ! $recipient['recipient']['enabled'] || $customer_settings['registration_needs_confirmation'] ) && self::$site_data['welcome'] && $email ) {
						self::$lianamailer_connection->send_welcome_mail( self::$site_data['domain'] );
					}
				}
			} catch ( \Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
					// phpcs:disable WordPress.PHP.DevelopmentFunctions
					$failure_reason = $e->getMessage();
					error_log( 'Failure: ' . $failure_reason );
					// phpcs:enable
				}
			}
		}
	}

	/**
	 * Filters properties which not found from LianaMailer site
	 */
	private function filter_recipient_properties() {

		$properties = $this->get_lianamailer_properties( false, self::$site_data['properties'] );

		$props = array();
		foreach ( $properties as $property ) {
			$property_name         = $property['name'];
			$property_visible_name = $property['visible_name'];

			// if Property value havent been posted, leave it as it is.
			if ( ! isset( $this->post_data[ 'LM_' . $property_name ] ) ) {
				continue;
			}

			// Checkboxes, choices and selects are in array, implode selected values as string.
			if ( is_array( $this->post_data[ 'LM_' . $property_name ] ) ) {
				$this->post_data[ 'LM_' . $property_name ] = implode( ', ', $this->post_data[ 'LM_' . $property_name ] );
			}

			// Otherwise update it into LianaMailer.
			$props[ $property_visible_name ] = sanitize_text_field( $this->post_data[ 'LM_' . $property_name ] );
		}
		return $props;
	}

	/**
	 * Generates array of LianaMailer properties
	 *
	 * @param boolean $core_fields Should we fetch LianaMailer core fields also. Defaults to false.
	 * @param array   $properties LianaMailer site property data as array.
	 */
	private function get_lianamailer_properties( $core_fields = false, $properties = array() ) {
		$fields            = array();
		$customer_settings = self::$lianamailer_connection->get_lianamailer_customer();
		// If could not fetch customer settings we assume something is wrong with API or credentials.
		if ( empty( $customer_settings ) ) {
			return array();
		}

		// Append Email and SMS fields.
		if ( $core_fields ) {
			$fields[] = array(
				'name'         => 'email',
				'visible_name' => 'email',
				'required'     => true,
				'type'         => 'text',
			);
			// Use SMS -field only if LianaMailer account has it enabled.
			if ( isset( $customer_settings['sms'] ) && '1' === $customer_settings['sms'] ) {
				$fields[] = array(
					'name'         => 'sms',
					'visible_name' => 'sms',
					'required'     => false,
					'type'         => 'text',
				);
			}
		}

		if ( ! empty( $properties ) ) {
			$properties = array_map(
				function( $field ) {
					return array(
						/**
						 * Replace some special characters because CF7 does support tag names only
						 * in .../contact-form-7/includes/validation-functions.php:
						 * function wpcf7_is_name( $string ) {
						 *   return preg_match( '/^[A-Za-z][-A-Za-z0-9_:.]*$/', $string );
						 * }
						 */
						'name'         => str_replace( array( 'ä', 'ö', 'å', ' ' ), array( 'a', 'o', 'o', '_' ), $field['name'] ) . '_' . $field['handle'],
						'handle'       => $field['handle'],
						'visible_name' => $field['name'],
						'required'     => ( $field['required'] ?? false ),
						'type'         => ( $field['type'] ?? 'text' ),
					);
				},
				$properties
			);

			$fields = array_merge( $fields, $properties );
		}

		return $fields;

	}

	/**
	 * Get Contact Form 7 instance by GET -parameter
	 */
	private function get_cf7_instance() {
		$cf7_instance = null;
		if ( isset( $_GET['post'] ) && intval( wp_unslash( $_GET['post'] ) ) ) {
			$post_id = sanitize_text_field( wp_unslash( $_GET['post'] ) );
			$nonce   = sanitize_key( wp_create_nonce( __FUNCTION__ . '-' . $post_id ) );
			if ( wp_verify_nonce( $nonce, 'get_cf7_instance-' . $post_id ) ) {
				$cf7_instance = \WPCF7_ContactForm::get_instance( intval( wp_unslash( $_GET['post'] ) ) );
			}
		}

		return $cf7_instance;
	}

	/**
	 * Create selectable tags on for form
	 * add_action( 'admin_init', [ $this, 'add_liana_mailer_properties' ], 10, 1);
	 */
	public function add_liana_mailer_properties() {

		$is_plugin_enabled = false;
		$cf7_instance      = $this->get_cf7_instance();
		if ( $cf7_instance instanceof \WPCF7_ContactForm ) {
			$is_plugin_enabled = (bool) get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_enabled', true );
		}
		if ( ! is_admin() || ( isset( $is_plugin_enabled ) && ! $is_plugin_enabled ) ) {
			return;
		}

		$cf7_instance = $this->get_cf7_instance();
		self::get_liana_mailer_site_data( $cf7_instance );

		// If couldnt fetch site data we assume something is wrong with API, credentials or settings.
		if ( empty( self::$site_data ) ) {
			return;
		}

		$fields = $this->get_lianamailer_properties( true, self::$site_data['properties'] );
		foreach ( $fields as $field ) {
			$args = array(
				'name'       => 'LM_' . $field['name'],
				'title'      => 'LM ' . $field['visible_name'],
				'element_id' => 'LM_' . $field['name'] . '_element',
				'callback'   => array( $this, 'render_text_field_generator' ),
				'options'    => array( 'required' => $field['required'] ),
			);
			wpcf7_add_tag_generator( $args['name'], $args['title'], $args['element_id'], $args['callback'], $args['options'] );
		}
	}

	/**
	 * Callback for rendering custom LianaMailer field settings
	 *
	 * @param object $form Form object.
	 * @param array  $args Field arguments.
	 */
	public function render_text_field_generator( $form, $args ) {
		$type = 'text';
		if ( 'LM_email' === $args['id'] ) {
			$type = 'email';
		}
		$this->render_liana_mailer_property_options( $type, $args );
	}

	/**
	 * Prints custom LianaMailer field settings HTML
	 *
	 * @param string $type Field type.
	 * @param array  $args Field arguments.
	 */
	public function render_liana_mailer_property_options( $type, $args ) {
		?>
		<div class="control-box">
			<fieldset>
				<legend>LianaMailer form element</legend>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="tag-generator-panel-<?php echo esc_attr( $type ); ?>-name">Name</label></th>
							<td><input name="name" class="tg-name oneline" id="tag-generator-panel-<?php echo esc_attr( $type ); ?>-name" type="text" value="<?php echo esc_attr( $args['id'] ); ?>" readonly></td>
						</tr>
						<tr>
							<th scope="row">Field type</th>
							<td>
								<fieldset>
									<legend class="screen-reader-text">Field type</legend>
									<label><input name="required" type="checkbox" <?php echo ( true === $args['required'] ? ' checked="checked"' : '' ); ?>> Required field</label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="tag-generator-panel-<?php echo esc_attr( $type ); ?>-values">Default value</label></th>
							<td><input name="values" class="oneline" id="tag-generator-panel-<?php echo esc_attr( $type ); ?>-values" type="text"><br>
							<label><input name="placeholder" class="option" type="checkbox"> Use this text as the placeholder of the field</label></td>
						</tr>
						<tr>
							<th scope="row"><label for="tag-generator-panel-<?php echo esc_attr( $type ); ?>-id">Id attribute</label></th>
							<td><input name="id" class="idvalue oneline option" id="tag-generator-panel-<?php echo esc_attr( $type ); ?>-id" type="text"></td>
						</tr>
						<tr>
							<th scope="row"><label for="tag-generator-panel-<?php echo esc_attr( $type ); ?>-class">Class attribute</label></th>
							<td><input name="class" class="classvalue oneline option" id="tag-generator-panel-<?php echo esc_attr( $type ); ?>-class" type="text"></td>
						</tr>
					</tbody>
				</table>
			</fieldset>
		</div>
		<div class="insert-box">
			<input name="<?php echo esc_attr( $type ); ?>" class="tag code" readonly="readonly" onfocus="this.select()" type="text">
			<div class="submitbox">
				<input class="button button-primary insert-tag" value="Add element" type="button">
			</div>
			<br class="clear">
		</div>

		<?php
	}


	/**
	 * AJAX callback for fetching lists and consents for specific LianaMailer site
	 */
	public function get_site_data_for_settings() {

		if ( ! isset( $_POST['site'] ) || ! sanitize_text_field( wp_unslash( $_POST['site'] ) ) ) {
			wp_die();
		}

		$site   = sanitize_text_field( wp_unslash( $_POST['site'] ) );
		$action = __FUNCTION__ . '-' . $site;
		$nonce  = sanitize_key( wp_create_nonce( $action ) );
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die();
		}

		$account_sites = self::$lianamailer_connection->get_account_sites();
		$selected_site = sanitize_text_field( wp_unslash( $_POST['site'] ) );

		$data = array();
		foreach ( $account_sites as &$site ) {
			if ( $site['domain'] === $selected_site ) {
				$data['lists']    = $site['lists'];
				$data['consents'] = ( self::$lianamailer_connection->get_site_consents( $site['domain'] ) ?? array() );
				break;
			}
		}

		echo wp_json_encode( $data );
		wp_die();
	}

	/**
	 * Enqueue plugin CSS and JS
	 * add_action( 'admin_enqueue_scripts', [ $this, 'add_liana_mailer_plugin_scripts' ], 10, 1 );
	 */
	public function add_liana_mailer_plugin_scripts() {
		wp_enqueue_style( 'lianamailer-contact-form-7-admin-css', dirname( plugin_dir_url( __FILE__ ) ) . '/css/admin.css', array(), LMCF7_VERSION );

		$js_vars = array(
			'url' => admin_url( 'admin-ajax.php' ),
		);
		wp_register_script( 'lianamailer-plugin', dirname( plugin_dir_url( __FILE__ ) ) . '/js/lianamailer-plugin.js', array( 'jquery' ), LMCF7_VERSION, true );
		wp_localize_script( 'lianamailer-plugin', 'lianaMailerConnection', $js_vars );
		wp_enqueue_script( 'lianamailer-plugin' );
	}

	/**
	 * Adds LianaMailer tab into admin view
	 * add_filter( 'wpcf7_editor_panels', [ $this, 'add_liana_mailer_panel' ], 10, 1 );
	 *
	 * @param array $panels CF7 panels as array.
	 */
	public function add_liana_mailer_panel( $panels ) {
		$panels['lianamailer-panel'] = array(
			'title'    => 'LianaMailer-integration',
			'callback' => array( $this, 'render_liana_mailer_panel' ),
		);
		return $panels;
	}

	/**
	 * Prints settings for LianaMailer tab
	 *
	 * @param object $cf7_instance CF7 instance.
	 */
	public function render_liana_mailer_panel( $cf7_instance ) {

		self::get_liana_mailer_site_data( $cf7_instance );

		// Getting all sites from LianaMailer.
		$account_sites = self::$lianamailer_connection->get_account_sites();
		$allowed_html  = wp_kses_allowed_html( 'post' );

		// if LianaMailer sites could not fetch or theres no any, print error message.
		if ( empty( $account_sites ) ) {
			$html = '<p class="error">Could not find any LianaMailer sites. Ensure <a href="' . ( isset( $_SERVER['PHP_SELF'] ) ? sanitize_text_field( wp_unslash( $_SERVER['PHP_SELF'] ) ) : '' ) . '?page=lianamailercontactform7" target="_blank">API settings</a> are propertly set and LianaMailer account has at least one subscription site.</p>';
			echo wp_kses( $html, $allowed_html );
			return;
		}

		$is_plugin_enabled = (bool) get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_enabled', true );
		$selected_site     = get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_account_sites', true );
		$selected_list     = (int) get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_mailing_lists', true );
		$selected_consent  = (int) get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_site_consents', true );

		$html      = '';
		$html     .= $this->print_enable_checkbox( $is_plugin_enabled );
		$html     .= '<div class="lianaMailerPluginSettings">';
			$html .= $this->print_site_selection( $account_sites, $selected_site );
			$html .= $this->print_mailing_list_selection( $selected_list );
			$html .= $this->print_consent_selection( $selected_consent );
		$html     .= '</div>';

		$custom_allowed = array();

		$custom_allowed['input'] = array(
			'class'   => array(),
			'id'      => array(),
			'name'    => array(),
			'value'   => array(),
			'type'    => array(),
			'checked' => array(),
		);

		$custom_allowed['select'] = array(
			'class'    => array(),
			'id'       => array(),
			'name'     => array(),
			'value'    => array(),
			'type'     => array(),
			'disabled' => array(),
		);

		$custom_allowed['option'] = array(
			'selected' => array( 'selected' ),
			'class'    => array(),
			'value'    => array(),
		);

		$allowed_html = array_merge( $allowed_html, $custom_allowed );
		echo wp_kses( $html, $allowed_html );
	}

	/**
	 * Print plugin enable checkbox for settings page
	 *
	 * @param boolean $is_plugin_enabled true if enabled in settings.
	 */
	private function print_enable_checkbox( $is_plugin_enabled ) {

		$html      = '<label>';
			$html .= '<input type="checkbox" name="lianamailer_plugin_enabled"' . ( $is_plugin_enabled ? ' checked="checked"' : '' ) . '> Enable LianaMailer -integration on this form';
		$html     .= '</label>';

		return $html;
	}

	/**
	 * Print site selection for settings page
	 *
	 * @param array  $sites fetched sites from LianaMailer account.
	 * @param string $selected_site selected site domain.
	 */
	private function print_site_selection( $sites, $selected_site ) {
		$html = '<h3>Choose LianaMailer site</h3>';
		$html .= '<select name="lianamailer_plugin_account_sites">';
		$html .= '<option value="">Choose</option>';
		foreach ( $sites as $site ) {
			if ( $site['redirect'] || $site['replaced_by'] ) {
				continue;
			}
			$html .= '<option value="' . $site['domain'] . '"' . ( $site['domain'] === $selected_site ? ' selected="selected"' : '' ) . '>' . $site['domain'] . '</option>';
		}
		$html .= '</select>';

		return $html;
	}

	/**
	 * Print mailing list selection for settings page
	 *
	 * @param string $selected_list selected list id.
	 */
	private function print_mailing_list_selection( $selected_list ) {

		$mailing_lists = array();
		if ( isset( self::$site_data['lists'] ) ) {
			$mailing_lists = self::$site_data['lists'];
		}
		$disabled = empty( $mailing_lists );

		$html      = '<h3>Choose mailing list</h3>';
		$html     .= '<select name="lianamailer_plugin_mailing_lists"' . ( $disabled ? ' class="disabled"' : '' ) . '>';
			$html .= '<option value="">Choose</option>';
		foreach ( $mailing_lists as $list ) {
			$html .= '<option value="' . $list['id'] . '"' . ( $list['id'] === $selected_list ? ' selected="selected"' : '' ) . '>' . $list['name'] . '</option>';
		}
		$html .= '</select>';

		return $html;
	}

	/**
	 * Print consent selection for settings page
	 *
	 * @param string $selected_consent selected consent id.
	 */
	private function print_consent_selection( $selected_consent ) {
		$consents = array();
		if ( isset( self::$site_data['consents'] ) ) {
			$consents = self::$site_data['consents'];
		}
		$disabled = empty( $consents );

		$html      = '<h3>Choose consent</h3>';
		$html     .= '<select name="lianamailer_plugin_site_consents"' . ( $disabled ? ' class="disabled"' : '' ) . '>';
			$html .= '<option value="">Choose</option>';
		foreach ( $consents as $consent ) {
			$html .= '<option value="' . $consent['consent_id'] . '"' . ( $consent['consent_id'] === $selected_consent ? ' selected="selected"' : '' ) . '>' . $consent['name'] . '</option>';
		}
		$html .= '</select>';

		return $html;
	}

	/**
	 * Get selected LianaMailer site data:
	 * domain, welcome, properties, lists and consents
	 *
	 * @param object $cf7_instance CF7 instance.
	 */
	private static function get_liana_mailer_site_data( $cf7_instance = null ) {

		if ( ! empty( self::$site_data ) ) {
			return;
		}

		if ( is_null( $cf7_instance ) ) {
			return;
		}

		// Getting all sites from LianaMailer.
		$account_sites = self::$lianamailer_connection->get_account_sites();

		if ( empty( $account_sites ) ) {
			return;
		}

		// Getting all properties from LianaMailer.
		$liana_mailer_properties = self::$lianamailer_connection->get_lianamailer_properties();
		$selected_site           = get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_account_sites', true );

		// if site is not selected.
		if ( ! $selected_site ) {
			return;
		}

		$site_data = array();
		foreach ( $account_sites as &$site ) {
			if ( $site['domain'] === $selected_site ) {
				$properties    = array();
				$site_consents = ( self::$lianamailer_connection->get_site_consents( $site['domain'] ) ?? array() );

				$site_data['domain']  = $site['domain'];
				$site_data['welcome'] = $site['welcome'];
				foreach ( $site['properties'] as &$prop ) {
					/**
					 * Add required and type -attributes because get_account_sites() -endpoint doesnt return these.
					 * https://rest.lianamailer.com/docs/#tag/Sites/paths/~1v1~1sites/post
					 */
					$key = array_search( $prop['handle'], array_column( $liana_mailer_properties, 'handle' ), true );
					if ( false !== $key ) {
						$prop['required'] = $liana_mailer_properties[ $key ]['required'];
						$prop['type']     = $liana_mailer_properties[ $key ]['type'];
					}
				}
				$site_data['properties'] = $site['properties'];
				$site_data['lists']      = $site['lists'];
				$site_data['consents']   = $site_consents;
				self::$site_data         = $site_data;
			}
		}
	}

	/**
	 * Adds LianaMailer inputs into public form
	 *
	 * @param object $cf7_instance CF7 instance.
	 */
	public function add_liana_mailer_inputs_to_form( $cf7_instance ) {

		$is_plugin_enabled = (bool) get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_enabled', true );
		// Works only in public form and check if plugin is enablen on current form.
		if ( is_admin() || ! $is_plugin_enabled ) {
			return;
		}

		self::get_liana_mailer_site_data( $cf7_instance );
		if ( ! isset( self::$site_data['consents'] ) ) {
			return;
		}

		$selected_consent = get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_site_consents', true );

		$props        = array();
		$consent_text = '';
		$settings_str = $cf7_instance->__get( 'additional_settings' );
		$form_str     = $cf7_instance->prop( 'form' );
		if ( $form_str && ! empty( $form_str ) ) {

			$consent_key = array_search( intval( $selected_consent ), array_column( self::$site_data['consents'], 'consent_id' ), true );
			if ( false !== $consent_key ) {
				// Use consent description primarily fallback to consent name.
				$consent_text = ( self::$site_data['consents'][ $consent_key ]['description'] ? self::$site_data['consents'][ $consent_key ]['description'] : self::$site_data['consents'][ $consent_key ]['name'] );
			}

			if ( $selected_consent && $consent_text ) {
				$allowed_tags      = array(
					'a'    => array(
						'href'   => array(),
						'title'  => array(),
						'target' => array(),
						'class'  => array(),
						'id'     => array(),
					),
					'span' => array(
						'style' => array(),
						'class' => array(),
					),
				);
				$allowed_protocols = array(
					'http',
					'https',
					'mailto',
				);

				// Add checkbox input for the form just before submit-button. ref: https://contactform7.com/acceptance-checkbox/.
				$form_str = substr_replace( $form_str, '[acceptance lianamailer_consent]' . wp_kses( $consent_text, $allowed_tags, $allowed_protocols ) . '[/acceptance]' . PHP_EOL . PHP_EOL, strpos( $form_str, '[submit' ), 0 );
			}
		}

		if ( strpos( $settings_str, 'acceptance_as_validation' ) === false ) {
			if ( ! empty( $settings_str ) ) {
				$settings_str .= PHP_EOL;
			}
			$settings_str .= 'acceptance_as_validation: on';
		}

		$props['additional_settings'] = $settings_str;
		$props['form']                = $form_str;
		$cf7_instance->set_properties( $props );
	}

	/**
	 * Forcing consent checkbox to be required
	 *
	 * @param string $form_html Form HTML.
	 */
	public function force_acceptance( $form_html ) {
		$html = str_replace( 'name="lianamailer_consent"', 'name="lianamailer_consent" required=""', $form_html );
		return $html;
	}

	/**
	 * Saving LianaMailer form settings
	 *
	 * @param int    $post_id Form id.
	 * @param object $post Form object.
	 */
	public function save_form_settings( $post_id, $post ) {

		if ( 'wpcf7_contact_form' !== $post->post_type ) {
			return;
		}

		$setting = sanitize_text_field( wp_unslash( $post_id ) );
		$action  = __FUNCTION__ . '-' . $setting;
		$nonce   = sanitize_key( wp_create_nonce( $action ) );
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return;
		}

		// Plugin enabled / disabled.
		if ( isset( $_POST['lianamailer_plugin_enabled'] ) ) {
			update_post_meta( $post_id, 'lianamailer_plugin_enabled', boolval( $_POST['lianamailer_plugin_enabled'] ) );
		} else {
			delete_post_meta( $post_id, 'lianamailer_plugin_enabled' );
		}
		// Site.
		if ( isset( $_POST['lianamailer_plugin_account_sites'] ) ) {
			update_post_meta( $post_id, 'lianamailer_plugin_account_sites', sanitize_text_field( wp_unslash( $_POST['lianamailer_plugin_account_sites'] ) ) );
		}
		// Mailing list.
		if ( isset( $_POST['lianamailer_plugin_mailing_lists'] ) ) {
			update_post_meta( $post_id, 'lianamailer_plugin_mailing_lists', intval( $_POST['lianamailer_plugin_mailing_lists'] ) );
		}
		// Consent.
		if ( isset( $_POST['lianamailer_plugin_site_consents'] ) ) {
			update_post_meta( $post_id, 'lianamailer_plugin_site_consents', intval( $_POST['lianamailer_plugin_site_consents'] ) );
		}
	}
}
?>
