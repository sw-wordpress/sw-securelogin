<?php
/**
 * Passkey persistence schema.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;


use RuntimeException;
use SW\Securelogin\Core\MigrationInterface;

/** Additive, idempotent tables for enrollment and credentials. */
final class PasskeysSchema implements MigrationInterface {
	/** Create tables and verify their essential indexes.
	 *
	 * @throws RuntimeException When a table or index is missing.
	 */
	public function apply(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}swsl_passkeys (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 user_id bigint(20) unsigned NOT NULL,
 credential_id varbinary(1023) NOT NULL,
 public_key text NOT NULL,
 user_handle varbinary(32) NOT NULL,
 sign_count bigint(20) unsigned NOT NULL DEFAULT 0,
 transports text NOT NULL,
 aaguid varchar(36) NOT NULL,
 backup_eligible tinyint(1) NOT NULL DEFAULT 0,
 backup_state tinyint(1) NOT NULL DEFAULT 0,
 name varchar(160) NOT NULL,
 created_at datetime NOT NULL,
 last_used_at datetime DEFAULT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY credential_id (credential_id),
 KEY user_id (user_id)
) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}swsl_transactions (
 token_hash char(64) NOT NULL,
 user_id bigint(20) unsigned NOT NULL,
 binding char(64) NOT NULL,
 purpose varchar(32) NOT NULL,
 payload longtext NOT NULL,
 expires_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (token_hash),
 KEY user_id (user_id),
 KEY expires_at (expires_at)
) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}swsl_user_handles (
 user_id bigint(20) unsigned NOT NULL,
 handle varbinary(32) NOT NULL,
 PRIMARY KEY  (user_id),
 UNIQUE KEY handle (handle)
) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}swsl_limits (
 bucket char(64) NOT NULL,
 attempts bigint(20) unsigned NOT NULL DEFAULT 0,
 expires_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (bucket),
 KEY expires_at (expires_at)
) $charset;"
		);
		$schemas = array(
			'swsl_passkeys'     => array(
				'key'        => 'credential_id',
				'key_column' => 'credential_id',
				'columns'    => 'id user_id credential_id public_key user_handle sign_count transports aaguid backup_eligible backup_state name created_at last_used_at',
			),
			'swsl_transactions' => array(
				'key'        => 'PRIMARY',
				'key_column' => 'token_hash',
				'columns'    => 'token_hash user_id binding purpose payload expires_at',
			),
			'swsl_user_handles' => array(
				'key'        => 'PRIMARY',
				'key_column' => 'user_id',
				'columns'    => 'user_id handle',
			),
			'swsl_limits'       => array(
				'key'        => 'PRIMARY',
				'key_column' => 'bucket',
				'columns'    => 'bucket attempts expires_at',
			),
		);
		foreach ( $schemas as $suffix => $schema ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify live schema postconditions.
			$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $wpdb->prefix . $suffix ), ARRAY_A );
			if ( ! is_array( $columns ) || array_diff( explode( ' ', $schema['columns'] ), array_column( $columns, 'Field' ) ) ) {
				throw new RuntimeException( 'Passkey schema columns are incomplete.' );
			}
			if ( 'swsl_passkeys' === $suffix ) {
				$types = array_column( $columns, 'Type', 'Field' );
				if ( 'varbinary(1023)' !== $types['credential_id'] || 'varbinary(32)' !== $types['user_handle'] ) {
					throw new RuntimeException( 'Credential byte columns do not match the schema.' );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uniqueness must be verified against the live database.
			$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $wpdb->prefix . $suffix, $schema['key'] ), ARRAY_A );
			if ( ! is_array( $indexes ) || 1 !== count( $indexes ) || '0' !== (string) $indexes[0]['Non_unique'] || $schema['key_column'] !== $indexes[0]['Column_name'] || null !== $indexes[0]['Sub_part'] ) {
				throw new RuntimeException( 'Passkey schema uniqueness verification failed.' );
			}
		}
	}
}
