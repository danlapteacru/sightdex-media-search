<?php
/**
 * Atomic per-attachment lock stored in the options table.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic per-attachment lock.
 *
 * A transient check-then-set is not atomic: two requests can both see no lock
 * and both call the provider. Here the unique index on option_name decides:
 * INSERT IGNORE creates the row for exactly one request. An expired lock is
 * taken over with a compare-and-swap UPDATE, so only one request can win that
 * race too. The same approach as WP_Upgrader::create_lock() in core.
 *
 * Not final: the indexer tests replace it with a PHPUnit mock.
 */
class Attachment_Lock {
	const PREFIX = 'aims_lock_';

	/**
	 * Tries to take the lock.
	 *
	 * @return string|null Owner token on success, null when another request holds it.
	 */
	public function acquire( int $id, int $ttl ): ?string {
		global $wpdb;

		$name  = self::PREFIX . $id;
		$token = ( time() + $ttl ) . ':' . wp_generate_password( 12, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock must be atomic, so it goes straight to the database and never through the options cache.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $name, $token ) );
		if ( 1 === $inserted ) {
			return $token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the live lock row, see above.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null === $current || (int) $current > time() ) {
			return null;
		}

		// Expired lock (for example a crashed request): take it over only if nobody else already did.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the live lock row, see above.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $current ) );

		return 1 === $updated ? $token : null;
	}

	/**
	 * Releases the lock, but only if this request still owns it.
	 */
	public function release( int $id, string $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes the live lock row, see acquire().
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::PREFIX . $id,
				'option_value' => $token,
			),
			array( '%s', '%s' )
		);
	}
}
