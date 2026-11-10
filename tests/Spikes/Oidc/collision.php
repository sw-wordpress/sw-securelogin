<?php
/** Deliberately incompatible symbols representing another WordPress plugin. */
declare(strict_types=1);

namespace Facile\OpenIDClient\Client {
	final class ClientBuilder {
		public function __construct() { throw new \RuntimeException('Foreign client must not be used.'); }
	}
}
namespace Facile\OpenIDClient {
	function parse_metadata_response(): never { throw new \RuntimeException('Foreign function must not be used.'); }
}
namespace Jose\Component\Signature\Algorithm {
	final class RS256 {
		public function __construct() { throw new \RuntimeException('Foreign algorithm must not be used.'); }
	}
}
namespace Psr\Http\Client {
	interface ClientInterface { public function foreignMarker(): void; }
}
namespace {
	function trigger_deprecation(): never { throw new \RuntimeException('Foreign deprecation helper must not be used.'); }
}
