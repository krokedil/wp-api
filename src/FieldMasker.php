<?php
/**
 * Configured masking of the payloads a plugin knows the shape of.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi;

/**
 * Masks the fields a configuration names, matched case insensitively wherever the name
 * appears below the level it was declared on. The reserved 'keep' key turns a rule into an
 * allow list, masking every key it does not name.
 *
 * The sibling of KeyMasker: that one masks by key name anywhere, this one by a described
 * shape. No WordPress or WooCommerce function may be called from this class.
 */
class FieldMasker {

	/**
	 * The reserved rule key that turns a rule into an allow list.
	 */
	public const KEEP = 'keep';

	/**
	 * Mask a set of fields within a data array. A rule keeps matching below the level it
	 * was declared on, so a container nested in a list is treated like one at the root.
	 *
	 * @param array $data   The data to mask.
	 * @param array $fields The fields to mask.
	 * @return array
	 */
	public static function mask( $data, $fields ) {
		$rules = self::compile_rules( $fields );
		return self::mask_node( $data, $rules, $rules['keep'], 0 );
	}

	/**
	 * Merge extra masking configuration into a configuration, keeping both.
	 *
	 * @param array $base  The configured fields.
	 * @param array $extra The extra fields to merge in.
	 * @return array
	 */
	public static function merge_config( $base, $extra ) {
		foreach ( (array) $extra as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( ! in_array( $value, $base, true ) ) {
					$base[] = $value;
				}
			} elseif ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::merge_config( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Turn the nested configuration array into rules that can be matched by key name.
	 *
	 * @param array $fields The configured fields.
	 * @return array A rule set with the keys 'mask', 'children' and 'keep'.
	 */
	private static function compile_rules( $fields ) {
		$rules = array(
			'mask'     => array(),
			'children' => array(),
			'keep'     => null,
		);

		foreach ( (array) $fields as $key => $value ) {
			// A list entry names a field to mask.
			if ( is_int( $key ) ) {
				if ( is_string( $value ) ) {
					$rules['mask'][ strtolower( $value ) ] = true;
				}
				continue;
			}

			$name = strtolower( (string) $key );

			if ( self::KEEP === $name && is_array( $value ) ) {
				$rules['keep'] = array();
				foreach ( $value as $keep ) {
					$rules['keep'][ strtolower( (string) $keep ) ] = true;
				}
				continue;
			}

			if ( is_array( $value ) ) {
				$rules['children'][ $name ] = self::compile_rules( $value );
			} else {
				// A named field with anything but an array, such as 'attachment' => 'mask'.
				$rules['mask'][ $name ] = true;
			}
		}

		return $rules;
	}

	/**
	 * Walk one node of the data and mask what the rules in scope name.
	 *
	 * @param mixed      $data  The node.
	 * @param array      $scope The rules that are in scope here, which is every rule declared on this node or above it.
	 * @param array|null $keep  The allow list for this container, or null if it is not allow listed.
	 * @param int        $depth How deep we already are.
	 * @return mixed
	 */
	private static function mask_node( $data, $scope, $keep, $depth ) {
		if ( $depth > KeyMasker::MAX_DEPTH ) {
			return KeyMasker::REDACTED;
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			// A list entry has no name of its own, so it is treated as the container it sits in.
			if ( is_int( $key ) ) {
				$data[ $key ] = self::mask_node( $value, $scope, $keep, $depth + 1 );
				continue;
			}

			$name = strtolower( (string) $key );

			if ( isset( $scope['mask'][ $name ] ) || ( null !== $keep && ! isset( $keep[ $name ] ) ) ) {
				$data[ $key ] = KeyMasker::placeholder( $value );
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			$child = $scope['children'][ $name ] ?? null;
			if ( null === $child ) {
				$data[ $key ] = self::mask_node( $value, $scope, null, $depth + 1 );
				continue;
			}

			// Rules stay in scope as we descend, so a kept key is still described by
			// the rules declared beside it, in whichever order.
			$next = array(
				'mask'     => $child['mask'] + $scope['mask'],
				'children' => $child['children'] + $scope['children'],
			);

			$data[ $key ] = self::mask_node( $value, $next, $child['keep'], $depth + 1 );
		}

		return $data;
	}
}
