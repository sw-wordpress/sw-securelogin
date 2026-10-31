<?php
/**
 * Credential deletion, privacy and short-lived retention.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;


use RuntimeException;
use wpdb;

/** Privacy and account deletion remain active when the login module is disabled. */
final class PrivacyController {
	/** Create the data lifecycle adapter.
	 *
	 * @param wpdb                 $database WordPress connection.
	 * @param CredentialRepository $credentials Metadata storage.
	 */
	public function __construct( private wpdb $database, private CredentialRepository $credentials ) {}

	/** Register data lifecycle callbacks independently of module enablement. */
	public function register(): void {
		add_action( 'deleted_user', array( $this, 'deleteUser' ) );
		add_action( 'swsl_cleanup', array( $this, 'cleanup' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	/** Delete the account's plugin data after WordPress account deletion.
	 *
	 * @param int $user_id Deleted or privacy-erased owner.
	 * @throws RuntimeException On persistence failure.
	 */
	public function deleteUser( int $user_id ): void {
		$this->credentials->purgeUser( $user_id );
	}

	/** Remove expired transactions and counters, retaining no authentication logs. */
	public function cleanup(): void {
		foreach ( array( 'swsl_transactions', 'swsl_limits' ) as $suffix ) {
			$this->database->query( $this->database->prepare( 'DELETE FROM %i WHERE expires_at <= %d', $this->database->prefix . $suffix, time() ) );
		}
		// Retry account-deletion cleanup after an earlier interrupted request.
		foreach ( array( 'swsl_passkeys', 'swsl_user_handles', 'swsl_transactions' ) as $suffix ) {
			$this->database->query( $this->database->prepare( 'DELETE owned FROM %i AS owned LEFT JOIN %i AS users ON users.ID = owned.user_id WHERE users.ID IS NULL AND owned.user_id <> 0', $this->database->prefix . $suffix, $this->database->users ) );
		}
	}

	/** Add the WordPress exporter.
	 *
	 * @param array<string, mixed> $exporters Existing exporters.
	 * @return array<string, mixed> Exporters.
	 */
	public function exporters( array $exporters ): array {
		$exporters['swsl_passkeys'] = array(
			'exporter_friendly_name' => __( 'Passkeys', 'sw-securelogin' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/** Add the WordPress eraser.
	 *
	 * @param array<string, mixed> $erasers Existing erasers.
	 * @return array<string, mixed> Erasers.
	 */
	public function erasers( array $erasers ): array {
		$erasers['swsl_passkeys'] = array(
			'eraser_friendly_name' => __( 'Passkeys', 'sw-securelogin' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/** Export bounded metadata, omitting keys and internal session fingerprints.
	 *
	 * @param string $email Verified WordPress privacy request email.
	 * @param int    $page Export page.
	 * @return array<string, mixed> WordPress export result.
	 */
	public function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user && 1 === $page ) {
			foreach ( $this->credentials->listForUser( $user->ID ) as $row ) {
				$fields = array();
				foreach ( $row as $name => $value ) {
					$fields[] = array(
						'name'  => $name,
						'value' => null === $value ? '' : (string) $value,
					);
				}
				$data[] = array(
					'group_id'    => 'swsl_passkeys',
					'group_label' => __( 'Passkeys', 'sw-securelogin' ),
					'item_id'     => 'swsl_passkey_' . $row['id'],
					'data'        => $fields,
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/** Erase credentials under WordPress's verified privacy-request workflow.
	 *
	 * @param string $email Privacy request email.
	 * @param int    $page Erasure page.
	 * @return array<string, mixed> WordPress erasure result.
	 */
	public function erase( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( $user && 1 === $page ) {
			try {
				$this->deleteUser( $user->ID );
			} catch ( RuntimeException $exception ) {
				return array(
					'items_removed'  => false,
					'items_retained' => true,
					'messages'       => array( __( 'Passkey data could not be erased. Please retry.', 'sw-securelogin' ) ),
					'done'           => false,
				);
			}
		}
		return array(
			'items_removed'  => (bool) $user && 1 === $page,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
