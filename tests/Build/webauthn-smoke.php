<?php
/** Test real protocol fixtures against the built, namespaced dependency tree. */
declare(strict_types=1);

require __DIR__ . '/bootstrap-smoke.php';
if ( ! is_file( $swsl_build . '/vendor-prefixed/licenses/lbuchs-webauthn/LICENSE' ) ) {
	throw new RuntimeException( 'Dependency license notice is missing.' );
}
$fixture = json_decode( file_get_contents( dirname( __DIR__ ) . '/Fixtures/WebAuthn/chromium.json' ), true, 512, JSON_THROW_ON_ERROR );
$decode = static fn( string $value ): string => SW\Securelogin\Modules\Passkeys\Base64Url::decode( $value, 16384 );
$service = new SW\Securelogin\Modules\Passkeys\WebAuthnService( new SW\Securelogin\Modules\Passkeys\RelyingParty( $fixture['origin'], $fixture['rpId'] ) );
$verified = $service->verify( $fixture['clientDataJSON'], $fixture['attestationObject'], $fixture['rawId'], $decode( $fixture['challenge'] ) );
$assertion = $service->assertion( $fixture['assertion'] + array( 'rawId' => $fixture['rawId'], 'userHandle' => $fixture['handle'] ), array( 'credential_id' => $verified->id, 'user_handle' => $decode( $fixture['handle'] ), 'public_key' => $verified->public_key, 'backup_eligible' => $verified->backup_eligible ), $decode( $fixture['challenge'] ) );
$library = new SW\Securelogin\Dependencies\lbuchs\WebAuthn\WebAuthn( 'Test', $fixture['rpId'], array( 'none' ), true );
$args = array( $decode( $fixture['assertion']['clientDataJSON'] ), $decode( $fixture['assertion']['authenticatorData'] ), $decode( $fixture['assertion']['signature'] ), $verified->public_key, $decode( $fixture['challenge'] ), null, true, true );
if ( true !== $library->processGet( ...$args ) ) {
	throw new RuntimeException( 'Valid Chromium signature failed after scoping.' );
}
$args[2][0] = chr( ord( $args[2][0] ) ^ 1 );
try {
	$library->processGet( ...$args );
	throw new RuntimeException( 'Modified signature was accepted.' );
} catch ( SW\Securelogin\Dependencies\lbuchs\WebAuthn\WebAuthnException $expected ) {
	// Expected rejection by the actual cryptographic verifier.
}
// The other plugin's original dependency must not alter our scoped static settings.
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
new lbuchs\WebAuthn\WebAuthn( 'Foreign plugin', $fixture['rpId'], array( 'none' ), false );
$options = $service->options( $decode( $fixture['handle'] ), 'test', 'Test', array() );
$json = json_decode( json_encode( $options, JSON_THROW_ON_ERROR ), true );
if ( $decode( $json['publicKey']['challenge'] ) !== $service->challenge() ) {
	throw new RuntimeException( 'Foreign dependency affected binary serialization.' );
}
echo "Scoped WebAuthn registration, signature rejection and serialization tests passed.\n";
