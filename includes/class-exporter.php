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
class ODSC_Exporter {
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
		$filename = $this->get_filename();

		nocache_headers();
		$this->send_download_headers(
			array(
				'Content-Type: application/json; charset=utf-8',
				'Content-Disposition: attachment; filename="' . $filename . '"',
				'X-Content-Type-Options: nosniff',
				'Content-Length: ' . strlen( $json ),
			)
		);

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Signed JSON download, not HTML.
		$this->terminate_download();
	}

	/**
	 * Sends the download-specific response headers.
	 *
	 * Kept separate from download() so integration tests can capture the exact
	 * response without weakening request authorization or signature checks.
	 *
	 * @param string[] $headers Header lines.
	 * @return void
	 */
	protected function send_download_headers( $headers ) {
		foreach ( $headers as $header ) {
			header( $header );
		}
	}

	/**
	 * Ends the request after the download body has been sent.
	 *
	 * @return void
	 */
	protected function terminate_download() {
		exit;
	}

	/**
	 * Builds a filename that identifies the diagnosed site.
	 *
	 * @return string
	 */
	public function get_filename() {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = strtolower( $host );

		if ( function_exists( 'idn_to_ascii' ) && '' !== $host ) {
			$ascii_host = idn_to_ascii( $host );
			if ( false !== $ascii_host ) {
				$host = $ascii_host;
			}
		}

		$host = preg_replace( '/[^a-z0-9.-]+/', '-', $host );
		$host = trim( (string) $host, '.-' );
		if ( '' === $host ) {
			$host = 'site';
		}

		return 'od-site-check-' . $host . '-' . current_time( 'Ymd-His' ) . '.json';
	}
}
