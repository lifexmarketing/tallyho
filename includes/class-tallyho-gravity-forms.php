<?php
/**
 * Gravity Forms integration.
 *
 * Gravity Forms shows a confirmation even to entries it marks as spam (Akismet,
 * reCAPTCHA v3 scoring, CleanTalk in some modes), so a bare client-side listener
 * would count spam. This class decides server-side, at confirmation time, whether
 * the entry is a real conversion, and only then hands the browser a marker or
 * a redirect cookie.
 *
 * @package Tallyho
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tallyho_Gravity_Forms {

	const SETTING_ENABLED = 'tallyhoEnabled';
	const SETTING_NAME    = 'tallyhoConversionName';

	public static function init(): void {
		add_filter( 'gform_confirmation', array( __CLASS__, 'filter_confirmation' ), 20, 4 );
		add_filter( 'gform_form_settings_fields', array( __CLASS__, 'form_settings_fields' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'missing_gravity_forms_notice' ) );
	}

	/**
	 * @param string|array $confirmation Confirmation markup, or an array with a 'redirect' key.
	 * @param array        $form
	 * @param array        $entry
	 * @param bool         $ajax
	 * @return string|array
	 */
	public static function filter_confirmation( $confirmation, $form, $entry, $ajax = false ) {
		if ( ! self::should_track( $form, $entry ) ) {
			return $confirmation;
		}

		$is_redirect = is_array( $confirmation ) && ! empty( $confirmation['redirect'] );

		if ( ! $is_redirect && ! is_string( $confirmation ) ) {
			return $confirmation;
		}

		$delivery = $is_redirect ? 'redirect' : ( $ajax ? 'ajax' : 'page' );

		$payload = Tallyho_Conversion::payload(
			'gravityforms',
			self::get( $form, 'id' ),
			self::get( $entry, 'id' ),
			(string) self::get( $form, 'title' ),
			(string) self::get( $form, self::SETTING_NAME ),
			$delivery
		);

		/**
		 * Filter the conversion payload before it is handed to the browser.
		 * Return a non-array to skip this conversion.
		 */
		$payload = apply_filters( 'tallyho_payload', $payload, $form, $entry );
		if ( ! is_array( $payload ) || '0' === ( $payload['entry_id'] ?? '0' ) ) {
			return $confirmation;
		}

		if ( $is_redirect ) {
			Tallyho_Conversion::set_cookie( $payload );
			return $confirmation;
		}

		return $confirmation . Tallyho_Conversion::marker_html( $payload );
	}

	/**
	 * Real entry, not spam, tracking enabled for the form, no privacy signal.
	 */
	public static function should_track( $form, $entry ): bool {
		$track = is_array( $form ) && is_array( $entry )
			&& ! empty( $entry['id'] )
			&& 'spam' !== self::get( $entry, 'status' )
			&& self::form_enabled( $form )
			&& ! Tallyho_Conversion::visitor_opted_out();

		/**
		 * Final say on whether this submission pushes a conversion.
		 */
		return (bool) apply_filters( 'tallyho_should_track', $track, $form, $entry );
	}

	/**
	 * Enabled unless the form's toggle has been explicitly switched off.
	 */
	public static function form_enabled( array $form ): bool {
		if ( ! array_key_exists( self::SETTING_ENABLED, $form ) ) {
			return true;
		}
		$value = $form[ self::SETTING_ENABLED ];
		return ! ( false === $value || 0 === $value || '0' === $value || '' === $value || 'false' === $value );
	}

	/**
	 * Adds a "LifeX Conversion Tracking" section to Form Settings (Gravity Forms 2.5+).
	 */
	public static function form_settings_fields( $fields, $form ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$fields['tallyho_conversions'] = array(
			'title'  => esc_html__( 'LifeX Conversion Tracking', 'tallyho' ),
			'fields' => array(
				array(
					'name'          => self::SETTING_ENABLED,
					'type'          => 'toggle',
					'label'         => esc_html__( 'Push a conversion event when this form is submitted', 'tallyho' ),
					'default_value' => true,
				),
				array(
					'name'        => self::SETTING_NAME,
					'type'        => 'text',
					'label'       => esc_html__( 'Conversion name', 'tallyho' ),
					'description' => esc_html__( 'Sent as conversion_name, for example estimate_form. Lowercase letters, numbers and underscores. Leave blank to let GTM map this form by its ID.', 'tallyho' ),
				),
			),
		);

		return $fields;
	}

	/**
	 * Array lookup that does not depend on Gravity Forms' rgar() being loaded.
	 */
	private static function get( $array, string $key ) {
		return ( is_array( $array ) && isset( $array[ $key ] ) ) ? $array[ $key ] : '';
	}

	public static function missing_gravity_forms_notice(): void {
		if ( class_exists( 'GFForms' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'plugins' !== $screen->id ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'Tallyho Form Tracking is active, but Gravity Forms is not. No conversions will be pushed until Gravity Forms is active.', 'tallyho' )
			. '</p></div>';
	}
}
