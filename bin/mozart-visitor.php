<?php
/** Preserve local class references during Mozart's additional cross-package pass. */
declare(strict_types=1);

namespace SW\Securelogin\Build;

use CoenJacobs\Mozart\Replace\Namespace\PrefixVisitor;

final class MozartVisitor extends PrefixVisitor {
	/** Already scoped namespaces must retain unqualified local class names too.
	 *
	 * E.g. JOSE's local AESKW class is not the external AESKW namespace. Upstream
	 * checks only original namespaces, so a second pass otherwise confuses them.
	 */
	protected function isCurrentNamespaceBeingPrefixed(): bool {
		return parent::isCurrentNamespaceBeingPrefixed()
			|| ( null !== $this->currentNamespace && 0 === strncasecmp( $this->currentNamespace, $this->prefix . '\\', strlen( $this->prefix ) + 1 ) );
	}
}
