<?php
/**
 * JSON encoding and secure download handling.
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports diagnostic results without writing them to a public file.
 */
final class ODSC_Exporter {
	/**
	 * Encodes a diagnostic payload.
	 *
	 * @param array<string, mixed> $payload Diagnostic payload.
	 * @return string
	 * @throws RuntimeException When JSON encoding fails.
	 */
	public function encode( $payload ) {
		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			throw new RuntimeException( 'Unable to encode diagnostic payload.' );
		}

		return $json . "\n";
	}

	/**
	 * Signs JSON for a same-page, no-storage download flow.
	 *
	 * @param string $json JSON payload.
	 * @return string
	 */
	public function sign( $json ) {
		return hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
	}

	/**
	 * Verifies a signed JSON payload.
	 *
	 * @param string $json      JSON payload.
	 * @param string $signature Submitted signature.
	 * @return bool
	 */
	public function verify( $json, $signature ) {
		return hash_equals( $this->sign( $json ), $signature );
	}

	/**
	 * Validates the top-level contract and exact result IDs.
	 *
	 * @param array<string, mixed> $payload Diagnostic payload.
	 * @return bool
	 */
	public function is_valid_payload( $payload ) {
		if (
			! isset( $payload['schema_version'], $payload['collector'], $payload['collected_at'], $payload['site'], $payload['results'] ) ||
			ODSC_SCHEMA_VERSION !== $payload['schema_version'] ||
			! is_array( $payload['results'] )
		) {
			return false;
		}

		$ids = array();
		foreach ( $payload['results'] as $result ) {
			if ( ! is_array( $result ) || ! isset( $result['id'] ) || ! is_string( $result['id'] ) ) {
				return false;
			}

			$ids[] = $result['id'];
		}

		return ODSC_Collector::expected_ids() === $ids;
	}

	/**
	 * Streams JSON directly to the authenticated browser.
	 *
	 * @param string $json JSON payload.
	 * @return void
	 */
	public function download( $json ) {
		$filename = 'od-site-check-' . current_time( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $json ) );

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Signed JSON download, not HTML.
		exit;
	}
}
