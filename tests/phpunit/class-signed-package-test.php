<?php

use WPElevator\Update_Client\Signed_Package;

class Signed_Package_Test extends WP_UnitTestCase {

	const PUBLIC_KEY = 'APBHggGnhrL1VxZ71NI3ElbWTpdEOljwnPh+gkDKxRc=';

	const PACKAGE_URL = 'https://updates.example.com/wp-json/update-pilot/v1/download/example/example-plugin';

	const PACKAGE_CONTENTS = 'example-plugin-package-contents';

	private function fake_package_download( ?string $signature ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $signature ) {
				if ( self::PACKAGE_URL !== $url ) {
					return $pre;
				}

				file_put_contents( $args['filename'], self::PACKAGE_CONTENTS );

				return [
					'response' => [
						'code' => 200,
					],
					'headers' => $signature ? [ 'X-Content-Signature' => $signature ] : [],
				];
			},
			10,
			3
		);
	}

	private function sign_package( string $secret_key ): string {
		// Sign the raw SHA-384 digest of the package as expected by verify_file_signature().
		return base64_encode(
			sodium_crypto_sign_detached(
				hash( 'sha384', self::PACKAGE_CONTENTS, true ),
				$secret_key
			)
		);
	}

	private function download_package( string $public_key ): string {
		return ( new Signed_Package( $public_key ) )->download( self::PACKAGE_URL );
	}

	public function test_download_returns_package_signed_with_the_known_key() {
		$this->skip_without_sodium();

		$keypair = sodium_crypto_sign_keypair();

		$this->fake_package_download( $this->sign_package( sodium_crypto_sign_secretkey( $keypair ) ) );

		$file = $this->download_package( base64_encode( sodium_crypto_sign_publickey( $keypair ) ) );

		$this->assertSame(
			self::PACKAGE_CONTENTS,
			file_get_contents( $file ),
			'The verified package file is handed over to WP core for installation'
		);

		$this->assertNotContains(
			base64_encode( sodium_crypto_sign_publickey( $keypair ) ),
			apply_filters( 'wp_trusted_keys', [] ),
			'The public key is not left trusted after the package has been verified'
		);

		$this->assertNotContains(
			'updates.example.com',
			apply_filters( 'wp_signature_hosts', [] ),
			'The package host does not require verification once the download is done'
		);

		$this->unlink( $file );
	}

	public function test_download_rejects_package_signed_with_another_key() {
		$this->skip_without_sodium();

		$this->fake_package_download( $this->sign_package( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );

		$this->expectException( RuntimeException::class );

		$this->download_package( base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) ) );
	}

	public function test_download_rejects_package_without_a_signature() {
		$this->skip_without_sodium();

		$this->fake_package_download( null );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/no signature was found/' );

		$this->download_package( self::PUBLIC_KEY );
	}

	public function test_download_rejects_unsigned_package_even_when_softfail_is_forced() {
		$this->skip_without_sodium();

		// Other code cannot re-enable the soft-fail for our packages.
		add_filter( 'wp_signature_softfail', '__return_true', PHP_INT_MAX );

		$this->fake_package_download( null );

		$this->expectException( RuntimeException::class );

		$this->download_package( self::PUBLIC_KEY );
	}

	public function test_download_still_verifies_when_signature_hosts_are_cleared() {
		$this->skip_without_sodium();

		// Other code cannot drop our package host from the hosts requiring verification.
		add_filter( 'wp_signature_hosts', '__return_empty_array', PHP_INT_MAX );

		$this->fake_package_download( null );

		// A missing signature rather than a skipped check proves the package was still verified.
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/no signature was found/' );

		$this->download_package( self::PUBLIC_KEY );
	}

	private function skip_without_sodium(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'The PHP Sodium extension is not available.' );
		}
	}
}
