<?php
/**
 * Test-only PSR-18 decorator that rejects ambiguous JSON before the candidate reads it.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Spikes\Oidc;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Narrow raw-JSON guard. It is not a JWT parser: it never verifies signatures, times,
 * issuer, audience values or nonce values. It only refuses what PHP's decoder would hide
 * (duplicate object names, object-shaped audiences) or what makes the candidate emit
 * diagnostics (non-string nonce/azp/sub). Everything else is passed on unchanged.
 */
final class StrictResponseGuard implements ClientInterface {
	private const MAX_BYTES = 262144;

	/** Wrapped transport.
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $inner;

	/** Factory to give the candidate a fresh, readable body.
	 *
	 * @var StreamFactoryInterface
	 */
	private StreamFactoryInterface $streams;

	/** Construct the guard.
	 *
	 * @param ClientInterface        $inner Real transport.
	 * @param StreamFactoryInterface $streams Body factory.
	 */
	public function __construct( ClientInterface $inner, StreamFactoryInterface $streams ) {
		$this->inner   = $inner;
		$this->streams = $streams;
	}

	/** Send the request and inspect JSON responses.
	 *
	 * @param RequestInterface $request Outgoing request.
	 * @return ResponseInterface Response with an equivalent body.
	 * @throws AmbiguousResponse For an ambiguous JSON response.
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$response = $this->inner->sendRequest( $request );
		$body     = (string) $response->getBody();
		if ( strlen( $body ) > self::MAX_BYTES ) {
			throw new AmbiguousResponse( 'OIDC response guard rejected the response.' );
		}
		$data = json_decode( $body, true );
		if ( is_array( $data ) ) {
			$this->inspect( $body, $data );
		}
		return $response->withBody( $this->streams->createStream( $body ) );
	}

	/** Inspect a syntactically valid JSON document.
	 *
	 * @param string       $raw Raw document.
	 * @param array<mixed> $data Decoded document.
	 * @throws AmbiguousResponse When ambiguous.
	 */
	private function inspect( string $raw, array $data ): void {
		self::scan( $raw );
		if ( isset( $data['id_token'] ) ) {
			$this->inspectToken( $data['id_token'] );
		}
		if ( isset( $data['keys'] ) && is_array( $data['keys'] ) ) {
			$seen = array();
			foreach ( $data['keys'] as $key ) {
				if ( ! is_array( $key ) || ! isset( $key['kid'] ) || ! is_string( $key['kid'] ) ) {
					continue;
				}
				if ( isset( $seen[ $key['kid'] ] ) ) {
					throw new AmbiguousResponse( 'OIDC response guard rejected the response.' );
				}
				$seen[ $key['kid'] ] = true;
			}
		}
	}

	/** Inspect the raw header and payload of a compact token.
	 *
	 * @param mixed $token Value of the id_token member.
	 * @throws AmbiguousResponse When ambiguous or not a compact token.
	 */
	private function inspectToken( mixed $token ): void {
		$parts = is_string( $token ) ? explode( '.', $token ) : array();
		if ( 3 !== count( $parts ) ) {
			throw new AmbiguousResponse( 'OIDC response guard rejected the response.' );
		}
		$header  = self::decode( $parts[0] );
		$payload = self::decode( $parts[1] );
		self::scan( $header );
		$kinds = self::scan( $payload );
		foreach ( array( 'nonce', 'azp', 'sub' ) as $name ) {
			if ( isset( $kinds[ $name ] ) && '"' !== $kinds[ $name ] ) {
				throw new AmbiguousResponse( 'OIDC response guard rejected the response.' );
			}
		}
		if ( isset( $kinds['aud'] ) && '"' !== $kinds['aud'] && '[' !== $kinds['aud'] ) {
			throw new AmbiguousResponse( 'OIDC response guard rejected the response.' );
		}
	}

	/** Strictly decode one base64url segment.
	 *
	 * @param string $segment Segment.
	 * @return string Bytes.
	 * @throws AmbiguousResponse For a malformed segment.
	 */
	private static function decode( string $segment ): string {
		$bytes = false;
		if ( 1 === preg_match( '/\A[A-Za-z0-9_-]+\z/', $segment ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Strict JOSE segment decoding for inspection only.
			$bytes = base64_decode( strtr( $segment, '-_', '+/' ), true );
		}
		if ( false === $bytes || ! is_array( json_decode( $bytes, true ) ) ) {
			throw new AmbiguousResponse( 'OIDC response guard rejected the response.' );
		}
		return $bytes;
	}

	/**
	 * Reject duplicate names at any depth and report the first character of root values.
	 *
	 * Input must already be syntactically valid JSON (checked by json_decode before).
	 * Names are compared after decoding escapes, so "sub" equals "sub".
	 *
	 * @param string $json Valid JSON text.
	 * @return array<string, string> Root member name to first character of its value.
	 * @throws AmbiguousResponse For a duplicate name.
	 */
	private static function scan( string $json ): array {
		$stack   = array();
		$kinds   = array();
		$name    = '';
		$pending = false;
		$length  = strlen( $json );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $json[ $i ];
			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
				continue;
			}
			if ( $pending && 1 === count( $stack ) ) {
				$kinds[ $name ] = $char;
				$pending        = false;
			}
			if ( '{' === $char || '[' === $char ) {
				$stack[] = '{' === $char ? array() : null;
			} elseif ( '}' === $char || ']' === $char ) {
				array_pop( $stack );
			} elseif ( ':' === $char ) {
				$pending = 1 === count( $stack );
			} elseif ( '"' === $char ) {
				$end     = self::stringEnd( $json, $i );
				$literal = substr( $json, $i, $end - $i + 1 );
				$i       = $end;
				$top     = count( $stack ) - 1;
				if ( $top >= 0 && null !== $stack[ $top ] && self::isName( $json, $i ) ) {
					$decoded = json_decode( $literal );
					if ( ! is_string( $decoded ) || isset( $stack[ $top ][ $decoded ] ) ) {
						throw new AmbiguousResponse( 'OIDC response guard rejected the response.' );
					}
					$stack[ $top ][ $decoded ] = true;
					$name                      = 1 === count( $stack ) ? $decoded : $name;
				}
			}
		}
		return $kinds;
	}

	/** Find the closing quote of the string starting at the offset.
	 *
	 * @param string $json JSON text.
	 * @param int    $start Opening quote offset.
	 * @return int Closing quote offset.
	 */
	private static function stringEnd( string $json, int $start ): int {
		$length = strlen( $json );
		for ( $i = $start + 1; $i < $length; $i++ ) {
			if ( '\\' === $json[ $i ] ) {
				++$i;
			} elseif ( '"' === $json[ $i ] ) {
				return $i;
			}
		}
		return $length - 1;
	}

	/** Whether the string ending at the offset is followed by a colon (an object name).
	 *
	 * @param string $json JSON text.
	 * @param int    $end Closing quote offset.
	 * @return bool Name position.
	 */
	private static function isName( string $json, int $end ): bool {
		$next = strspn( $json, " \t\n\r", $end + 1 );
		return ':' === ( $json[ $end + 1 + $next ] ?? '' );
	}
}
