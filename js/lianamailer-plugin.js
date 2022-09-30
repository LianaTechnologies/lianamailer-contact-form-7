/**
 * LianaMailer / Contact Form 7 JavaScript functionality
 *
 * @package  LianaMailer
 * @author   Liana Technologies <websites@lianatech.com>
 * @license  GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * @link     https://www.lianatech.com
 */

jQuery( document ).ready(
	function($) {

		var $enableCb          = $( '#lianamailer-panel input[name=lianamailer_plugin_enabled]' );
		var $siteSelect        = $( '#lianamailer-panel select[name=lianamailer_plugin_account_sites]' );
		var $mailingListSelect = $( '#lianamailer-panel select[name=lianamailer_plugin_mailing_lists]' );
		var $consentSelect     = $( '#lianamailer-panel select[name=lianamailer_plugin_site_consents]' );
		var $tab               = $( 'li#lianamailer-panel-tab' );

		// If site selection not found there is propably problem with REST API credentials.
		if ( ! $siteSelect.length && $tab.length) {
			setTabError();
		}
		toggleLianaMailerPlugin();

		function toggleLianaMailerPlugin() {

			disableElement( $mailingListSelect );
			disableElement( $consentSelect );

			if ($enableCb.is( ':checked' )) {
				// if sites found from LianaMailer.
				if ($siteSelect.find( "option:gt(0)" ).length > 0) {
					enableElement( $siteSelect );
				}
				// if mailing lists found for the selected site.
				if ($mailingListSelect.find( "option:gt(0)" ).length > 0) {
					enableElement( $mailingListSelect );
				}
				// if consents found for the selected site, enable the select.
				if ($consentSelect.find( "option:gt(0)" ).length > 0) {
					enableElement( $consentSelect );
				}

				if ( ! $siteSelect.val()) {
					console.log( 'Site was not selected' );
					setError( $siteSelect );
				}
				if ( ! $mailingListSelect.val()) {
					setError( $mailingListSelect );
				}

			} else {
				disableElement( $siteSelect );
				disableElement( $mailingListSelect );
				disableElement( $consentSelect );
				unsetError( $siteSelect );
				unsetError( $mailingListSelect );
				unsetError( $consentSelect );
			}
		}

		$enableCb.change(
			function() {
				toggleLianaMailerPlugin();
			}
		);

		if ( ! $siteSelect.val()) {
			$mailingListSelect.addClass( 'disabled' );
			$consentSelect.addClass( 'disabled' );
		}
		$siteSelect.change(
			function() {
				var siteValue = $( this ).val();

				disableElement( $mailingListSelect );
				disableElement( $consentSelect );

				$mailingListSelect.find( "option:gt(0)" ).remove();
				$consentSelect.find( "option:gt(0)" ).remove();

				if ( ! siteValue) {
					setError( $siteSelect );
					setError( $mailingListSelect );
					$mailingListSelect.addClass( 'disabled' ).find( "option:gt(0)" ).remove();
					$consentSelect.addClass( 'disabled' ).find( "option:gt(0)" ).remove();
				} else {
					unsetError( $siteSelect );
					unsetError( $mailingListSelect );
					let params = {
						url: lianaMailerConnection.url,
						method: 'POST',
						dataType: 'json',
						data: {
							'action': 'getSiteDataForCF7Settings',
							'site': siteValue
						}
					};

					$.ajax( params ).done(
						function( data ) {

							disableElement( $mailingListSelect );
							disableElement( $consentSelect );

							var lists    = data.lists;
							var consents = data.consents;

							if (lists.length) {
								$mailingListSelect.find( "option:gt(0)" ).remove();
								var options = [];
								$.each(
									lists,
									function( index, listData ) {
										var opt   = document.createElement( 'option' );
										opt.value = listData.id;
										opt.text  = listData.name;
										options.push( opt );
									}
								);
								$mailingListSelect.append( options );
								enableElement( $mailingListSelect );
							}

							if (consents.length) {
								$consentSelect.find( "option:gt(0)" ).remove();
								var options = [];
								$.each(
									consents,
									function( index, consentData ) {
										var opt   = document.createElement( 'option' );
										opt.value = consentData.consent_id;
										opt.text  = consentData.name;
										options.push( opt );
									}
								);
								$consentSelect.append( options );
								enableElement( $consentSelect );
							}
						}
					);
				}
			}
		);

		$mailingListSelect.change(
			function() {
				var mailingListValue = $( this ).val();
				if ( ! mailingListValue) {
					setError( $mailingListSelect );
				} else {
					unsetError( $mailingListSelect );
				}
			}
		);

		function enableElement($elem) {
			if ( ! $elem.length) {
				return;
			}
			$elem.removeClass( 'disabled' ).prop( 'disabled', false );
		}
		function disableElement($elem) {
			if ( ! $elem.length) {
				return;
			}
			$elem.addClass( 'disabled' ).prop( 'disabled', true );
		}

		function setError($elem) {
			if ( ! $elem.length) {
				return;
			}
			setTabError();
			$elem.addClass( 'error' );
		}

		function unsetError($elem) {
			if ( ! $elem.length) {
				return;
			}
			unsetTabError();
			$elem.removeClass( 'error' );

		}

		function setTabError() {
			var $mark = $( '<span class="lianamailer-icon-in-circle" aria-hidden="true">!</span>' );
			if ( ! $tab.find( 'a span.lianamailer-icon-in-circle' ).length) {
				$tab.find( 'a' ).append( $mark );
			}
		}

		function unsetTabError() {
			if ($tab.find( 'a span.lianamailer-icon-in-circle' ).length) {
				$tab.find( 'a span.lianamailer-icon-in-circle' ).remove();
			}
		}
	}
);
