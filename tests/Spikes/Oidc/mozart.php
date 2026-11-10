<?php
/** Direct upstream experiment in the disposable spike tree; never a production build. */
declare(strict_types=1);

use CoenJacobs\Mozart\Commands\Compose;
use CoenJacobs\Mozart\Exceptions\ConfigurationException;

require '/mozart/vendor/autoload.php';

try {
	(new Compose('/project'))->execute();
} catch (ConfigurationException $error) {
	if ("Couldn't load package based on provided slug: psr/http-client-implementation" !== $error->getMessage()) {
		throw $error;
	}
	echo "UPSTREAM LIMITATION: unmodified Compose cannot resolve psr/http-client-implementation; the project adapter must handle it.\n";
	exit(0);
}
throw new RuntimeException('Upstream Mozart behavior changed; review virtual-package resolution and scoping.');
