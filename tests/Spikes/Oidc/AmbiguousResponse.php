<?php
/**
 * Test-only exception for ambiguous OIDC responses.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Spikes\Oidc;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/** Signals an ambiguous response; never carries response content. */
final class AmbiguousResponse extends RuntimeException implements ClientExceptionInterface {
}
