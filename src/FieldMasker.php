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
				continue;
			}

			// Names match case insensitively, so 'HEADERS' merges into an existing 'headers'.
			$key = self::find_key( $base, $key );

			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::merge_config( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Find the key already in a configuration that matches a name case insensitively.
	 *
	 * @param array  $config The configuration.
	 * @param string $name   The name to look for.
	 * @return string The existing key, or the name itself if none matches.
	 */
	private static function find_key( $config, $name ) {
		$lower = strtolower( (string) $name );
		foreach ( array_keys( $config ) as $key ) {
			if ( ! is_int( $key ) && strtolower( (string) $key ) === $lower ) {
				return $key;
			}
		}

		return $name;
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
				$child                      = self::compile_rules( $value );
				$rules['children'][ $name ] = isset( $rules['children'][ $name ] )
					? self::merge_rules( $rules['children'][ $name ], $child )
					: $child;
			} else {
				// A named field with anything but an array, such as 'attachment' => 'mask'.
				$rules['mask'][ $name ] = true;
			}
		}

		return $rules;
	}

	/**
	 * Combine two rule sets so that everything either one names stays in force. Children
	 * with the same name are merged the same way, and two allow lists keep what either names.
	 *
	 * @param array $a One rule set.
	 * @param array $b The other rule set.
	 * @return array
	 */
	private static function merge_rules( $a, $b ) {
		$children = $a['children'];
		foreach ( $b['children'] as $name => $child ) {
			$children[ $name ] = isset( $children[ $name ] ) ? self::merge_rules( $children[ $name ], $child ) : $child;
		}

		$a_keep = $a['keep'] ?? null;
		$b_keep = $b['keep'] ?? null;
		if ( null === $a_keep || null === $b_keep ) {
			$keep = $a_keep ?? $b_keep;
		} else {
			$keep = $a_keep + $b_keep;
		}

		return array(
			'mask'     => $a['mask'] + $b['mask'],
			'children' => $children,
			'keep'     => $keep,
		);
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

			$child = $scope['children'][ $name ] ?? null;
			if ( null === $child ) {
				$data[ $key ] = is_array( $value ) ? self::mask_node( $value, $scope, null, $depth + 1 ) : $value;
				continue;
			}

			// A configured container that is not one cannot be walked, so it is masked whole
			// rather than left in the clear when the provider changes shape.
			if ( ! is_array( $value ) ) {
				$data[ $key ] = KeyMasker::placeholder( $value );
				continue;
			}

			// Rules stay in scope as we descend, so a kept key is still described by the
			// rules declared beside it, in whichever order. A child with the same name as one
			// already in scope combines with it rather than replacing it.
			$next = self::merge_rules(
				$child,
				array(
					'mask'     => $scope['mask'],
					'children' => $scope['children'],
				)
			);

			$data[ $key ] = self::mask_node( $value, $next, $child['keep'], $depth + 1 );
		}

		return $data;
	}
}
