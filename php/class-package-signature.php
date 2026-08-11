<?php

namespace WPElevator\Update_Client;

/**
 * Enforce the WordPress core package signature verification for
 * package downloads from known hosts using a known public key.
 *
 * @see verify_file_signature() and download_url() in WP core.
 */
class Package_Signature {

	/**
	 * Base64 encoded Ed25519 public key used for signing the packages.
	 */
	private string $public_key;

	/**
	 * Hostnames of the package downloads that require a valid signature.
	 *
	 * @var string[]
	 */
	private array $hosts = [];

	public function __construct( string $public_key ) {
		$this->public_key = trim( $public_key );
	}

	public function init(): void {
		// Enable the signature verification for the registered hosts.
		add_filter( 'wp_signature_hosts', [ $this, 'filter_enable_signature_hosts' ] );

		// Ensure that the signature verification is never skipped for the registered hosts.
		add_filter( 'wp_signature_softfail', [ $this, 'filter_disable_signature_softfail' ], 10, 2 );

		// Mark the public key as trusted during the signature verification.
		add_filter( 'wp_trusted_keys', [ $this, 'filter_extend_trusted_keys' ] );
	}

	/**
	 * If the current environment supports the package signature verification.
	 */
	public function can_verify(): bool {
		return function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	public function get_public_key(): string {
		return $this->public_key;
	}

	/**
	 * Require a valid package signature for all downloads from the host of this URL.
	 */
	public function enforce_for_url( string $url ): void {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! empty( $host ) && ! in_array( $host, $this->hosts, true ) ) {
			$this->hosts[] = $host;
		}
	}

	/**
	 * Add the registered hosts to the list of hosts that require signature verification.
	 *
	 * @param string[] $hosts List of hostnames.
	 * @return string[]
	 */
	public function filter_enable_signature_hosts( array $hosts ): array {
		if ( ! empty( $this->hosts ) ) {
			return array_unique( array_merge( $hosts, $this->hosts ) );
		}

		return $hosts;
	}

	/**
	 * Prevent installs with a missing or invalid signature for the registered hosts.
	 *
	 * @param bool   $allow_softfail If signature errors can be ignored.
	 * @param string $url The URL being downloaded.
	 * @return bool
	 */
	public function filter_disable_signature_softfail( $allow_softfail, $url ) {
		if ( in_array( wp_parse_url( (string) $url, PHP_URL_HOST ), $this->hosts, true ) ) {
			return false;
		}

		return $allow_softfail;
	}

	/**
	 * Add the known public key to the list of trusted signing keys.
	 *
	 * The key is exposed only while a package download from one of the registered
	 * hosts is pending verification during the current request to avoid it being
	 * trusted during the signature verification of unrelated packages.
	 *
	 * @param string[] $keys List of base64 encoded Ed25519 public keys.
	 * @return string[]
	 */
	public function filter_extend_trusted_keys( array $keys ): array {
		if ( ! empty( $this->hosts ) && ! in_array( $this->public_key, $keys, true ) ) {
			$keys[] = $this->public_key;
		}

		return $keys;
	}
}
