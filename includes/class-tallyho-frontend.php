<?php
/**
 * Front-end: prints the shared privacy helper (if nothing else has) and enqueues
 * the static listener. Nothing printed here is per-visitor, so it is cache-safe.
 *
 * @package Tallyho
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tallyho_Frontend {

	const HANDLE = 'tallyho';

	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'print_privacy_helper' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'exclude_from_delay' ) );
	}

	/**
	 * Same guarded client helper the LifeX Tracking Opt-Out Helper plugin prints.
	 * If that plugin (or anything else) already defined window.isTrackingOptOut,
	 * this does nothing.
	 */
	public static function print_privacy_helper(): void {
		echo "<script>(function(){if(typeof window.isTrackingOptOut==='function')return;"
			. "window.isTrackingOptOut=function isTrackingOptOut(){try{if(navigator.globalPrivacyControl===true)return true;"
			. "var d=[navigator.doNotTrack,window.doNotTrack,navigator.msDoNotTrack];"
			. "for(var i=0;i<d.length;i++){if(d[i]==='1'||d[i]===1||d[i]==='yes')return true;}return false;}"
			. "catch(e){return false;}};})();</script>\n";
	}

	public static function enqueue(): void {
		wp_enqueue_script(
			self::HANDLE,
			TALLYHO_URL . 'assets/js/tallyho.js',
			array(),
			TALLYHO_VERSION,
			true
		);
	}

	/**
	 * WP Rocket's "Delay JavaScript execution" would hold the listener until the
	 * visitor interacts, which can lose conversions on thank-you pages.
	 *
	 * @param array $exclusions
	 * @return array
	 */
	public static function exclude_from_delay( $exclusions ) {
		$exclusions   = is_array( $exclusions ) ? $exclusions : array();
		$exclusions[] = 'tallyho';
		$exclusions[] = 'isTrackingOptOut';
		return $exclusions;
	}
}
