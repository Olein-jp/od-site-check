<?php
/**
 * Allow-list based diagnostic result normalization.
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes collector output to the public schema.
 */
final class ODSC_Sanitizer {
	/**
	 * Allowed result statuses.
	 *
	 * @var string[]
	 */
	private const STATUSES = array(
		'collected',
		'partial',
		'manual_required',
		'unavailable',
		'unsupported',
		'error',
	);

	/**
	 * Builds one result record with only schema-approved fields.
	 *
	 * @param string                    $id         Diagnostic item ID.
	 * @param string                    $status     Collection status.
	 * @param string|null               $source     Data source identifier.
	 * @param array<string, mixed>|null $value Diagnostic value.
	 * @param string|null               $note       Human-readable note.
	 * @param string|null               $error_code Stable error code.
	 * @return array<string, mixed>
	 */
	public static function result( $id, $status, $source, $value, $note = null, $error_code = null ) {
		$normalized_id = is_string( $id ) && preg_match( '/^(WP|OPS)-[0-9]{2}$/', $id ) ? $id : '';

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status     = 'error';
			$value      = null;
			$error_code = 'invalid_status';
		}

		return array(
			'id'         => $normalized_id,
			'status'     => $status,
			'source'     => is_string( $source ) ? sanitize_key( $source ) : null,
			'value'      => is_array( $value ) ? self::normalize_value( $value ) : null,
			'note'       => is_string( $note ) ? self::normalize_string( $note ) : null,
			'error_code' => is_string( $error_code ) ? sanitize_key( $error_code ) : null,
		);
	}

	/**
	 * Normalizes a nested allow-listed value.
	 *
	 * @param array<mixed> $value Input value.
	 * @return array<mixed>
	 */
	private static function normalize_value( $value ) {
		$normalized = array();

		foreach ( $value as $key => $item ) {
			$normalized_key = is_int( $key ) ? $key : sanitize_key( $key );

			if ( is_array( $item ) ) {
				$normalized[ $normalized_key ] = self::normalize_value( $item );
			} elseif ( is_string( $item ) ) {
				$normalized[ $normalized_key ] = self::normalize_string( $item );
			} elseif ( is_int( $item ) || is_float( $item ) || is_bool( $item ) || null === $item ) {
				$normalized[ $normalized_key ] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Removes invalid UTF-8 without applying HTML-oriented transformations.
	 *
	 * @param string $value Input string.
	 * @return string
	 */
	private static function normalize_string( $value ) {
		return wp_check_invalid_utf8( $value, true );
	}
}
