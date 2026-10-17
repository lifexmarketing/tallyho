<?php
/**
 * Form-plugin-agnostic pieces: how a conversion is described to the browser.
 *
 * Two delivery paths carry the same fields:
 * - A hidden marker element appended to an on-page confirmation (AJAX and non-AJAX).
 * - A short-lived first-party cookie set on the submission response when the
 *   confirmation redirects to another page.
 *
 * Both are written only on the submission response, which page caches do not
 * store, so no per-visitor data ever lands in cached HTML.
 *
 * @package Tallyho
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tallyho_Conversion {

	const COOKIE_NAME    = 'tallyho_conv';
	const COOKIE_TTL     = 120;
	const COOKIE_VERSION = 'v1';
	const TITLE_MAX      = 100;

	/**
	 * Normalize a conversion name to a valid GA4 event name: lowercase letters,
	 * digits and underscores, starting with a letter, at most 40 characters.
	 * Returns '' when nothing valid remains.
	 */
	public static function sanitize_event_name( $name ): string {
		$name = strtolower( trim( (string) $name ) );
		$name = preg_replace( '/[^a-z0-9_]+/', '_', $name );
		$name = trim( (string) $name, '_' );
		$name = substr( $name, 0, 40 );
		$name = rtrim( $name, '_' );
		return preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ? $name : '';
	}

	/**
	 * Build the payload shared by both delivery paths.
	 *
	 * @return array{source:string,site:string,form_id:string,entry_id:string,form_title:string,conversion_name:string,delivery:string}
	 */
	public static function payload( string $source, $form_id, $entry_id, string $form_title, string $conversion_name, string $delivery ): array {
		$title = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $form_title ) ) );
		if ( function_exists( 'mb_substr' ) ) {
			$title = mb_substr( $title, 0, self::TITLE_MAX );
		} else {
			$title = substr( $title, 0, self::TITLE_MAX );
		}

		return array(
			'source'          => preg_replace( '/[^a-z0-9_]/', '', strtolower( $source ) ),
			'site'            => self::site_key(),
			'form_id'         => (string) absint( $form_id ),
			'entry_id'        => (string) absint( $entry_id ),
			'form_title'      => $title,
			'conversion_name' => self::sanitize_event_name( $conversion_name ),
			'delivery'        => in_array( $delivery, array( 'ajax', 'page', 'redirect' ), true ) ? $delivery : 'page',
		);
	}

	/**
	 * Short, stable key for this site, so event IDs stay unique when one Meta
	 * pixel or GA4 property is shared across several sites. Derived from the
	 * site's host name; filterable for sites that move between domains.
	 */
	public static function site_key(): string {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$key  = (string) apply_filters( 'tallyho_site_key', substr( md5( strtolower( $host ) ), 0, 6 ) );
		$key  = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', $key ) );
		return substr( $key, 0, 12 );
	}

	/**
	 * Hidden marker element read by assets/js/tallyho.js.
	 */
	public static function marker_html( array $p ): string {
		return sprintf(
			'<span class="tallyho-conversion" hidden aria-hidden="true" data-source="%s" data-site="%s" data-form-id="%s" data-entry-id="%s" data-form-title="%s" data-conversion-name="%s" data-delivery="%s"></span>',
			esc_attr( $p['source'] ),
			esc_attr( $p['site'] ),
			esc_attr( $p['form_id'] ),
			esc_attr( $p['entry_id'] ),
			esc_attr( $p['form_title'] ),
			esc_attr( $p['conversion_name'] ),
			esc_attr( $p['delivery'] )
		);
	}

	/**
	 * Cookie value: v1|source|site|form_id|entry_id|conversion_name|form_title, each
	 * part rawurlencoded so the value only contains cookie-safe characters.
	 */
	public static function cookie_value( array $p ): string {
		return implode(
			'|',
			array(
				self::COOKIE_VERSION,
				rawurlencode( $p['source'] ),
				rawurlencode( $p['site'] ),
				rawurlencode( $p['form_id'] ),
				rawurlencode( $p['entry_id'] ),
				rawurlencode( $p['conversion_name'] ),
				rawurlencode( $p['form_title'] ),
			)
		);
	}

	/**
	 * Set the redirect cookie on the current (submission) response.
	 * Readable by JS on purpose: the static listener reads and deletes it.
	 */
	public static function set_cookie( array $p ): bool {
		if ( headers_sent() ) {
			return false;
		}

		return setrawcookie(
			self::COOKIE_NAME,
			self::cookie_value( $p ),
			array(
				'expires'  => time() + self::COOKIE_TTL,
				'path'     => '/',
				'domain'   => ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Whether the submitting visitor sent a privacy signal. The submission
	 * request is never page-cached, so the server-side check is valid here.
	 */
	public static function visitor_opted_out(): bool {
		return function_exists( '\\LifeX\\Privacy\\isTrackingOptOut' ) && \LifeX\Privacy\isTrackingOptOut();
	}
}
