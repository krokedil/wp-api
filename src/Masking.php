<?php
/**
 * The WordPress aware entry point to the log masking.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi;

/**
 * Masks log data unless the krokedil_wp_api_mask_log_data filter turned it off. Use this over
 * FieldMasker and KeyMasker, which stay free of WordPress, so the filter covers your data too.
 */
class Masking {

	/**
	 * Whether log data should be masked. There is deliberately no setting for this, only a
	 * filter, so masking can be turned off while debugging but never by accident. Only a strict
	 * false disables it, and a filter that throws leaves it on.
	 *
	 * @param string $slug The plugin slug the log is written for.
	 * @return bool
	 */
	public static function is_enabled( $slug ) {
		try {
			/**
			 * Filters whether log data is masked before it is written. Return false to log
			 * everything in the clear. Only meant for debugging, never leave it on in production.
			 *
			 * @param bool   $enabled Whether masking is enabled. Default true.
			 * @param string $slug    The plugin slug the log is written for, to target a single plugin.
			 * @return bool
			 */
			return false !== apply_filters( 'krokedil_wp_api_mask_log_data', true, $slug );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Mask the fields a configuration names, see FieldMasker::mask().
	 *
	 * @param mixed  $data   The data to mask.
	 * @param array  $fields The fields to mask.
	 * @param string $slug   The plugin slug the log is written for.
	 * @return mixed The masked data, the data as it was if masking is off, or the failure marker.
	 */
	public static function mask_fields( $data, $fields, $slug ) {
		if ( ! self::is_enabled( $slug ) ) {
			return $data;
		}

		try {
			return FieldMasker::mask( $data, $fields );
		} catch ( \Throwable $e ) {
			return KeyMasker::FAILED;
		}
	}

	/**
	 * Mask by key name and value shape, see KeyMasker::mask().
	 *
	 * @param mixed  $data The data to mask.
	 * @param string $slug The plugin slug the log is written for.
	 * @return mixed The masked data, the data as it was if masking is off, or the failure marker.
	 */
	public static function mask_keys( $data, $slug ) {
		if ( ! self::is_enabled( $slug ) ) {
			return $data;
		}

		try {
			return KeyMasker::mask( $data );
		} catch ( \Throwable $e ) {
			return KeyMasker::FAILED;
		}
	}
}
