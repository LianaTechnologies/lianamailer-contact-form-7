<?php

namespace CF7_LianaMailer;

class LianaMailerPlugin {

	private $post_data;

	private static $lianaMailerConnection;
	private static $site_data = [];


	public function __construct() {
		self::$lianaMailerConnection = new LianaMailerConnection();
		self::addActions();
	}

	public function addActions() {
		add_action( 'admin_enqueue_scripts', [ $this, 'addLianaMailerPluginScripts' ], 10, 1 );
		add_action( 'wp_ajax_getSiteDataForCF7Settings', [ $this, 'getSiteDataForSettings'], 10, 1);

		// adds LianaMailer tab into admin view
		add_filter( 'wpcf7_editor_panels', [ $this, 'addLianaMailerPanel' ], 10, 1 );
		add_action( 'save_post_wpcf7_contact_form', [ $this, 'saveFormSettings' ], 10, 2 );

		// adds fields into public form
		add_action( 'wpcf7_contact_form', [ $this, 'addLianaMailerInputsToForm' ], 10, 1 );
		add_filter( 'wpcf7_form_elements', [ $this, 'forceAcceptance' ], 10, 1 );
		// on submit make a newsletter subscription
		add_action( 'wpcf7_submit', [ $this, 'doNewsletterSubscription' ], 10, 2 );
		// create tags on selectable fields for form
		add_action( 'admin_init', [ $this, 'addLianaMailerProperties' ], 10, 1);
	}

	/**
	 * Make newsletter subscription
	 */
	public function doNewsletterSubscription( $cf7_instance, $result ) {
		$submission = \WPCF7_Submission::get_instance();
		$isPluginEnabled = (bool)get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_enabled', true );
		// works only in public form and check if plugin is enablen on current form
		if( !$isPluginEnabled || !empty($submission->get_invalid_fields())) {
			return;
		}

		self::getLianaMailerSiteData($cf7_instance);
		if(empty(self::$site_data)) {
			return;
		}

		$list_id = get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_mailing_lists', true );
		$consent_id = (int)get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_site_consents', true );
		$consentData = [];


		$key = array_search($list_id, array_column(self::$site_data['lists'], 'id'));
		// if selected list is not found anymore from LianaMailer subscription page, do not allow subscription
		if($key === false) {
			$list_id = null;
		}

		$posted_data = false;
		$failure = false;
		$failure_reason = false;
		if( $submission ) {
			$posted_data = $submission->get_posted_data();
		}

		if(!empty($posted_data)) {
			try {

				$email = ( isset( $posted_data[ 'LM_email' ] ) ? sanitize_email( trim( $posted_data[ 'LM_email' ] ) ) : false );
				$sms = ( isset( $posted_data[ 'LM_sms' ] ) ? sanitize_text_field( trim( $posted_data[ 'LM_sms' ] ) ) : null );

				if(empty($list_id)) {
					throw new \Exception('No mailing lists set');
				}
				if(empty($email) && empty($sms)) {
					throw new \Exception('No email or SMS -field set');
				}

				$subscribeByEmail	= false;
				$subscribeBySMS 	= false;
				if($email) {
					$subscribeByEmail = true;
				}
				else if($sms) {
					$subscribeBySMS = true;
				}


				//if( $email && !empty( $email ) ) {
				if( $subscribeByEmail ||  $subscribeBySMS ) {
					$this->post_data = $posted_data;

					$customerSettings = self::$lianaMailerConnection->getMailerCustomer();
					// autoconfirm subscription if:
					// * LM site has "registration_needs_confirmation" disabled
					// * email set
					// * LM site has welcome mail set
					$autoConfirm = ((isset($customerSettings['registration_needs_confirmation']) && empty($customerSettings['registration_needs_confirmation'])) || !$email || !self::$site_data['welcome']);

					$properties = $this->filterRecipientProperties();
					self::$lianaMailerConnection->setProperties($properties);

					//$recipient = self::$lianaMailerConnection->getRecipientByEmail($email);
					if($subscribeByEmail) {
						$recipient = self::$lianaMailerConnection->getRecipientByEmail($email);
					}
					else {
						$recipient = self::$lianaMailerConnection->getRecipientBySMS($sms);
					}

					// if recipient found from LM and it not enabled and subscription had email set, re-enable it. Recipient only with SMS cannot be activated
					if (!is_null($recipient) && isset($recipient['recipient']['enabled']) && $recipient['recipient']['enabled'] === false && $email) {
						self::$lianaMailerConnection->reactivateRecipient($email, $autoConfirm);
					}
					self::$lianaMailerConnection->createAndJoinRecipient($email, $sms, $list_id, $autoConfirm);

					$consentKey = array_search($consent_id, array_column(self::$site_data['consents'], 'consent_id'));
					if($consentKey !== false) {
						$consentData = self::$site_data['consents'][$consentKey];
						//  Add consent to recipient
						self::$lianaMailerConnection->addRecipientConsent($consentData);
					}

					// if not existing recipient or recipient was not confirmed and site is using welcome -mail and LM account has double opt-in enabled and email address set
					if((!$recipient || !$recipient['recipient']['confirmed']) && self::$site_data['welcome'] && $customerSettings['registration_needs_confirmation'] && $email) {
						self::$lianaMailerConnection->sendWelcomeMail(self::$site_data['domain']);
					}
				}
			}
			catch(\Exception $e) {
				$failure_reason = $e->getMessage();
			}
		}

		if(!empty($failure_reason)) {
			error_log(__CLASS__.'::'.__FUNCTION__.' ERROR: '.$failure_reason);
		}
		return;
	}

	/**
	 * Filters properties which not found from LianaMailer site
	 */
	private function filterRecipientProperties() {

		$properties = $this->getLianaMailerProperties(false, self::$site_data['properties']);

		$props = [];
		foreach($properties as $property) {
			$propertyName = $property['name'];
			$propertyVisibleName = $property['visible_name'];

			// if Property value havent been posted, leave it as it is
			if( !isset( $this->post_data[ 'LM_' . $propertyName]) ) {
				continue;
			}
			// otherwise update it into LianaMailer
			$props[$propertyVisibleName] = sanitize_text_field( $this->post_data['LM_'.$propertyName] );
		}
		return $props;
	}

	/**
	 * Generates array of LianaMailer properties
	 */
	private function getLianaMailerProperties($core_fields = false, $properties = []) {
		$fields = [];
		$customerSettings = self::$lianaMailerConnection->getMailerCustomer();
		// if couldnt fetch customer settings we assume something is wrong with API or credentials
		if(empty($customerSettings)) {
			return [];
		}

		// append Email and SMS fields
		if($core_fields) {
			$fields[] = [
				'name'         => 'email',
				'visible_name' => 'email',
				'required'     => true,
				'type'         => 'text'
			];
			// Use SMS -field only if LianaMailer account has it enabled
			if(isset($customerSettings['sms']) && $customerSettings['sms'] == 1) {
				$fields[] = [
					'name'         => 'sms',
					'visible_name' => 'sms',
					'required'     => false,
					'type'         => 'text'
				];
			}
		}

		if( !empty( $properties ) ) {
			$properties = array_map( function( $field ){
				return [
					// replace some special characters because CF7 does support tag names only
					// in .../contact-form-7/includes/validation-functions.php:
					 // function wpcf7_is_name( $string ) {
					 // return preg_match( '/^[A-Za-z][-A-Za-z0-9_:.]*$/', $string );
					 // }
					'name'			=> str_replace(['ä','ö', 'å'],['a','o','o'],$field[ 'name' ]).'_'.$field['handle'],
					'handle'		=> $field['handle'],
					'visible_name'	=> $field[ 'name' ],
					'required'		=> $field[ 'required' ],
					'type'			=> $field[ 'type' ]
				];
			}, $properties );

			$fields = array_merge($fields, $properties);
		}

		return $fields;

	}

	/**
	 * Get Contact Form 7 instance by GET -parameter
	 */
	private function getCF7Instance() {
		$cf7_instance = null;
		if(isset($_GET['post']) && intval($_GET['post'])) {
			$cf7_instance = \WPCF7_ContactForm::get_instance($_GET['post']);
		}

		return $cf7_instance;
	}

	/**
	 * Create selectable tags on for form
	 * add_action( 'admin_init', [ $this, 'addLianaMailerProperties' ], 10, 1);
	 */
	public function addLianaMailerProperties() {

		$isPluginEnabled = false;
		$cf7_instance = $this->getCF7Instance();
		if($cf7_instance instanceof \WPCF7_ContactForm) {
			$isPluginEnabled = (bool)get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_enabled', true );
		}
		if( ! is_admin() || (isset($isPluginEnabled) && !$isPluginEnabled)) {
			return;
		}

		$cf7_instance = $this->getCF7Instance();
		self::getLianaMailerSiteData($cf7_instance);

		// if couldnt fetch site data we assume something is wrong with API, credentials or settings
		if(empty(self::$site_data)) {
			return;
		}

		$fields = $this->getLianaMailerProperties(true, self::$site_data['properties']);
		foreach( $fields as $field ) {
			$args = [
				'name' => 'LM_' . $field[ 'name' ],
				'title' => 'LM ' . $field[ 'visible_name' ],
				'element_id' => 'LM_' . $field[ 'name' ] . '_element',
				'callback' => [$this, 'renderTextFieldGenerator'],
				'options' => [ 'required' => $field[ 'required' ] ],
			];
			wpcf7_add_tag_generator( $args[ 'name' ], $args[ 'title' ], $args[ 'element_id' ], $args[ 'callback' ], $args[ 'options' ] );
		}
	}

	/**
	 * Callback for rendering custom LianaMailer field settings
	 */
	public function renderTextFieldGenerator($form, $args) {
		$type = 'text';
		if($args['id'] == 'LM_email') {
			$type = 'email';
		}
		$this->renderLianaMailerPropertyOptions($type, $args);
	}

	/**
	 * Prints custom LianaMailer field settings HTML
	 */
	public function renderLianaMailerPropertyOptions($type, $args) {
		?>
		<div class="control-box">
			<fieldset>
				<legend>LianaMailer form element</legend>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="tag-generator-panel-<?php echo $type; ?>-name">Name</label></th>
							<td><input name="name" class="tg-name oneline" id="tag-generator-panel-<?php echo $type; ?>-name" type="text" value="<?php echo esc_attr( $args[ 'id' ] ); ?>" readonly></td>
						</tr>
						<tr>
							<th scope="row">Field type</th>
							<td>
								<fieldset>
									<legend class="screen-reader-text">Field type</legend>
									<label><input name="required" type="checkbox" <?php echo ( $args[ 'required' ] === true ? ' checked="checked"' : '' ) ?>> Required field</label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="tag-generator-panel-<?php echo $type; ?>-values">Default value</label></th>
							<td><input name="values" class="oneline" id="tag-generator-panel-<?php echo $type; ?>-values" type="text"><br>
							<label><input name="placeholder" class="option" type="checkbox"> Use this text as the placeholder of the field</label></td>
						</tr>
						<tr>
							<th scope="row"><label for="tag-generator-panel-<?php echo $type; ?>-id">Id attribute</label></th>
							<td><input name="id" class="idvalue oneline option" id="tag-generator-panel-<?php echo $type; ?>-id" type="text"></td>
						</tr>
						<tr>
							<th scope="row"><label for="tag-generator-panel-<?php echo $type; ?>-class">Class attribute</label></th>
							<td><input name="class" class="classvalue oneline option" id="tag-generator-panel-<?php echo $type; ?>-class" type="text"></td>
						</tr>
					</tbody>
				</table>
			</fieldset>
		</div>
		<div class="insert-box">
			<input name="<?php echo $type; ?>" class="tag code" readonly="readonly" onfocus="this.select()" type="text">
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
	public function getSiteDataForSettings() {

		$accountSites = self::$lianaMailerConnection->getAccountSites();
		$selectedSite = $_POST['site'];

		$data = [];
		foreach($accountSites as &$site) {
			if($site['domain'] == $selectedSite) {
				$data['lists'] = $site['lists'];
				$data['consents'] = (self::$lianaMailerConnection->getSiteConsents($site['domain']) ?? []);
				break;
			}
		}

		echo json_encode($data);
		wp_die();
	}

	/**
	 * Enqueue plugin CSS and JS
	 * add_action( 'admin_enqueue_scripts', [ $this, 'addLianaMailerPluginScripts' ], 10, 1 );
	 */
	public function addLianaMailerPluginScripts() {
		wp_enqueue_style('lianamailer-contact-form-7-admin-css', dirname( plugin_dir_url( __FILE__ ) ).'/css/admin.css');

		$js_vars = [
			'url' => admin_url( 'admin-ajax.php' )
		];
		wp_register_script('lianamailer-plugin',  dirname( plugin_dir_url( __FILE__ ) ) . '/js/lianamailer-plugin.js', [ 'jquery' ], false, false );
		wp_localize_script('lianamailer-plugin', 'lianaMailerConnection', $js_vars );
		wp_enqueue_script('lianamailer-plugin');
	}

	/**
	 * Adds LianaMailer tab into admin view
	 * add_filter( 'wpcf7_editor_panels', [ $this, 'addLianaMailerPanel' ], 10, 1 );
	 */
	public function addLianaMailerPanel( $panels ) {
		$panels[ 'lianamailer-panel' ] = [
			'title'    => 'LianaMailer-integration',
			'callback' => [ $this, 'renderLianaMailerPanel' ]
		];
		return $panels;
	}

	/**
	 * Prints settings for LianaMailer tab
	 */
	public function renderLianaMailerPanel( $post ) {

		self::getLianaMailerSiteData($post);

		// Getting all sites from LianaMailer
		$accountSites = self::$lianaMailerConnection->getAccountSites();

		// if LianaMailer sites could not fetch or theres no any, print error message
		if(empty($accountSites)) {
			$html = '<p class="error">Could not find any LianaMailer sites. Ensure <a href="'.$_SERVER['PHP_SELF'].'?page=lianamailercontactform7" target="_blank">API settings</a> are propertly set and LianaMailer account has at least one subscription site.</p>';
			echo $html;
			return;
		}

		$isPluginEnabled = (bool)get_post_meta( $post->id(), 'lianamailer_plugin_enabled', true );
		$selectedSite = get_post_meta( $post->id(), 'lianamailer_plugin_account_sites', true );
		$selectedList = get_post_meta( $post->id(), 'lianamailer_plugin_mailing_lists', true );
		$selectedConsent = get_post_meta( $post->id(), 'lianamailer_plugin_site_consents', true );

		$html = '';
		$html .= $this->printEnableCheckbox($isPluginEnabled);
		$html .= '<div class="lianaMailerPluginSettings">';
			$html .= $this->printSiteSelection($accountSites, $selectedSite);
			$html .= $this->printMailingListSelection($selectedList);
			$html .= $this->printConsentSelection($selectedConsent);
		$html .= '</div>';


		echo $html;
	}

	/**
	 * Print plugin enable checkbox for settings page
	 */
	private function printEnableCheckbox($isPluginEnabled) {

		$html = '<label>';
			$html .= '<input type="checkbox" name="lianamailer_plugin_enabled"'.($isPluginEnabled ? ' checked="checked"' : '').'> Enable LianaMailer -integration on this form';
		$html .= '</label>';

		return $html;
	}

	/**
	 * Print site selection for settings page
	 */
	private function printSiteSelection($sites, $selectedSite) {

		$html = '<h3>Choose LianaMailer site</h3>';
		$html .= '<select name="lianamailer_plugin_account_sites">';
			$html .= '<option value="">Choose</option>';
		foreach($sites as $site) {
			$html .= '<option value="'.$site['domain'].'"'.($site['domain'] == $selectedSite ? ' selected="selected"' : '').'>'.$site['domain'].'</option>';
		}
		$html .= '</select>';

		return $html;
	}

	/**
	 * Print mailing list selection for settings page
	 */
	private function printMailingListSelection($selectedList) {

		$mailingLists = [];
		if(isset(self::$site_data['lists'])) {
			$mailingLists = self::$site_data['lists'];
		}
		$disabled = empty($mailingLists);

		$html = '<h3>Choose mailing list</h3>';
		$html .= '<select name="lianamailer_plugin_mailing_lists"'.($disabled ? ' class="disabled"' : '').'>';
			$html .= '<option value="">Choose</option>';
			foreach($mailingLists as $list) {
				$html .= '<option value="'.$list['id'].'"'.($list['id'] == $selectedList ? ' selected="selected"' : '').'>'.$list['name'].'</option>';
			}
		$html .= '</select>';

		return $html;
	}

	/**
	 * Print consent selection for settings page
	 */
	private function printConsentSelection($selectedConsent) {
		$consents = [];
		if(isset(self::$site_data['consents'])) {
			$consents = self::$site_data['consents'];
		}
		$disabled = empty($consents);

		$html = '<h3>Choose consent</h3>';
		$html .= '<select name="lianamailer_plugin_site_consents"'.($disabled ? ' class="disabled"' : '').'>';
			$html .= '<option value="">Choose</option>';
			foreach($consents as $consent) {
				$html .= '<option value="'.$consent['consent_id'].'"'.($consent['consent_id'] == $selectedConsent ? ' selected="selected"' : '').'>'.$consent['name'].'</option>';
			}
		$html .= '</select>';

		return $html;
	}

	/**
	 * Get selected LianaMailer site data:
	 * domain, welcome, properties, lists and consents
	 */
	private static function getLianaMailerSiteData($cf7_instance = null) {

		if(!empty(self::$site_data)) {
			return;
		}

		if(is_null($cf7_instance)) {
			return;
		}

		// Getting all sites from LianaMailer
		$accountSites = self::$lianaMailerConnection->getAccountSites();

		if(empty($accountSites)) {
			return;
		}

		// Getting all properties from LianaMailer
		$lianaMailerProperties = self::$lianaMailerConnection->getLianaMailerProperties();
		$selectedSite = get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_account_sites', true );

		// if site is not selected
		if(!$selectedSite) {
			return;
		}

		$siteData = [];
		foreach($accountSites as &$site) {
			if($site['domain'] == $selectedSite) {
				$properties = [];
				$siteConsents = (self::$lianaMailerConnection->getSiteConsents($site['domain']) ?? []);

				$siteData['domain'] = $site['domain'];
				$siteData['welcome'] = $site['welcome'];
				foreach($site['properties'] as &$prop) {
					// Add required and type -attributes because getAccountSites() -endpoint doesnt return these
					// https://rest.lianamailer.com/docs/#tag/Sites/paths/~1v1~1sites/post
					$key = array_search($prop['handle'], array_column($lianaMailerProperties, 'handle'));
					if($key !== false) {
						$prop['required'] = $lianaMailerProperties[$key]['required'];
						$prop['type'] = $lianaMailerProperties[$key]['type'];
					}
				}
				$siteData['properties'] = $site['properties'];
				$siteData['lists'] = $site['lists'];
				$siteData['consents'] = $siteConsents;
				self::$site_data = $siteData;
			}
		}
	}

	public function addLianaMailerInputsToForm( $cf7_instance ) {

		$isPluginEnabled = (bool)get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_enabled', true );
		// works only in public form and check if plugin is enablen on current form
		if( is_admin() || !$isPluginEnabled) {
			return;
		}

		self::getLianaMailerSiteData($cf7_instance);
		if(!isset(self::$site_data['consents'])) {
			return;
		}

		$selectedConsent = get_post_meta( $cf7_instance->id(), 'lianamailer_plugin_site_consents', true );

		$props = [];
		$consentText = '';
		$settings_str = $cf7_instance->__get( 'additional_settings' );
		$form_str = $cf7_instance->prop( 'form' );
		if( $form_str && !empty( $form_str ) ) {

			$consentKey = array_search($selectedConsent, array_column(self::$site_data['consents'], 'consent_id'));
			if($consentKey !== false) {
				// Use consent description primarily fallback to consent name
				$consentText = (self::$site_data['consents'][$consentKey]['description'] ? self::$site_data['consents'][$consentKey]['description'] : self::$site_data['consents'][$consentKey]['name']);
			}

			if($selectedConsent && $consentText) {
				$allowed_tags = [
					'a' => [
						'href'   => [],
						'title'  => [],
						'target' => [],
						'class'  => [],
						'id'     => [],
					],
					'span' => [
						'style'  => [],
						'class'  => [],
					]
				];
				$allowed_protocols = [
					'http',
					'https',
					'mailto'
				];

				// Add checkbox input for the form just before submit-button. ref: https://contactform7.com/acceptance-checkbox/
				$form_str = substr_replace( $form_str, '[acceptance lianamailer_consent]' . wp_kses( $consentText, $allowed_tags, $allowed_protocols) . '[/acceptance]'.PHP_EOL.PHP_EOL, strpos( $form_str, '[submit' ), 0 );
			}
		}

		if( strpos( $settings_str, 'acceptance_as_validation' ) === false ) {
			if( !empty( $settings_str ) ) {
				$settings_str .= PHP_EOL;
			}
			$settings_str .= 'acceptance_as_validation: on';
		}

		$props['additional_settings'] = $settings_str;
		$props['form'] = $form_str;
		$cf7_instance->set_properties( $props );
	}

	public function forceAcceptance( $form_html ) {
		$html = str_replace( 'name="lianamailer_consent"', 'name="lianamailer_consent" required=""', $form_html );
		return $html;
	}


	public function saveFormSettings($post_id, $post) {
		if( $post->post_type != 'wpcf7_contact_form' ) {
			return;
		}
		// Plugin enabled / disabled
		if(isset($_POST['lianamailer_plugin_enabled'])) {
			update_post_meta( $post_id, 'lianamailer_plugin_enabled', boolval( $_POST['lianamailer_plugin_enabled'] ) );
		}
		else {
			delete_post_meta( $post_id, 'lianamailer_plugin_enabled' );
		}
		// Site
		if( isset( $_POST['lianamailer_plugin_account_sites'] ) ) {
			update_post_meta( $post_id, 'lianamailer_plugin_account_sites', wp_filter_post_kses( $_POST['lianamailer_plugin_account_sites'] ) );
		}
		// Mailing list
		if(isset($_POST['lianamailer_plugin_mailing_lists'])) {
			update_post_meta( $post_id, 'lianamailer_plugin_mailing_lists', intval( $_POST['lianamailer_plugin_mailing_lists'] ) );
		}
		// Consent
		if(isset($_POST['lianamailer_plugin_site_consents'])) {
			update_post_meta( $post_id, 'lianamailer_plugin_site_consents', intval( $_POST['lianamailer_plugin_site_consents'] ) );
		}
	}
}
?>