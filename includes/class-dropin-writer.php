<?php
/** Serialized, verified drop-in replacement with a recoverable previous copy. */
namespace HarborCache;

defined( 'ABSPATH' ) || exit;

final class Dropin_Writer {
	private static $last_error = '';

	private static function locked( $fs, $file, callable $operation ) {
		$lock = $file . '.harborcache-lock';
		$handle = null;
		if ( $fs instanceof \WP_Filesystem_Direct ) {
			// A persistent lock inode prevents overlapping updates across PHP workers.
			$handle = @fopen( $lock, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
				if ( $handle ) { fclose( $handle ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release an unused lock handle.
				return false;
			}
		} else {
			$lock .= '-directory';
			// Remote transports cannot promise atomic replacement. Keep a backup for recovery.
			if ( $fs->is_dir( $lock ) && $fs->mtime( $lock ) < time() - 300 ) { $fs->delete( $lock, false, 'd' ); }
			if ( $fs->exists( $lock ) || ! $fs->mkdir( $lock, FS_CHMOD_DIR ) ) { return false; }
		}
		try { return $operation(); }
		finally {
			if ( $handle ) { flock( $handle, LOCK_UN ); fclose( $handle ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the direct-transport lock handle.
			else { $fs->delete( $lock, false, 'd' ); }
		}
	}

	private static function matches( $fs, $file, $expected ) {
		$content = $fs->get_contents( $file );
		return is_string( $content ) && hash_equals( $expected, hash( 'sha256', $content ) );
	}

	private static function owned( $contents ) {
		return is_string( $contents ) && false !== strpos( $contents, 'HarborCache Drop-In' ) && false !== strpos( $contents, 'HarborCache_Advanced_Cache' );
	}

	private static function fail( $code ) {
		self::$last_error = $code;
		return false;
	}

	public static function last_error() { return self::$last_error; }

	/** Return a safe, actionable reason from the last failed operation. */
	public static function error_message() {
		switch ( self::$last_error ) {
			case 'foreign_destination':
				return __( 'HarborCache could not identify object-cache.php as its own and left it untouched. Disable the cache plugin that owns this file before enabling HarborCache.', 'harborcache-object-cache-with-redis' );
			case 'invalid_backup':
				return __( 'HarborCache could not verify its saved drop-in backup. Keep the backup file and check it before retrying.', 'harborcache-object-cache-with-redis' );
			case 'restore_failed':
				return __( 'HarborCache could not restore the saved drop-in. The backup was kept; check wp-content permissions and retry recovery.', 'harborcache-object-cache-with-redis' );
			case 'stage_failed':
				return __( 'HarborCache could not stage and verify the new drop-in. The existing cache file was left in place.', 'harborcache-object-cache-with-redis' );
			case 'backup_failed':
				return __( 'HarborCache could not verify a backup of the existing drop-in. The existing cache file was left in place.', 'harborcache-object-cache-with-redis' );
			case 'replace_failed':
				return __( 'HarborCache could not verify the new drop-in. It preserved the previous file where possible and kept any recovery backup.', 'harborcache-object-cache-with-redis' );
			case 'lock_failed':
				return __( 'Another HarborCache drop-in operation is running. Wait briefly and retry.', 'harborcache-object-cache-with-redis' );
			default:
				return '';
		}
	}

	/** Restore an interrupted update without accepting a partial destination as success. */
	public static function recover( $fs, $file, $source = '' ) {
		self::$last_error = '';
		$backup = $file . '.harborcache-backup.php';
		if ( ! $fs->exists( $backup ) ) { return true; }

		$result = self::locked( $fs, $file, static function () use ( $fs, $file, $backup, $source ) {
			if ( ! $fs->exists( $backup ) ) { return true; }
			$previous = $fs->get_contents( $backup );
			if ( ! self::owned( $previous ) ) { return self::fail( 'invalid_backup' ); }
			$previous_hash = hash( 'sha256', $previous );
			$source_text = $source && $fs->exists( $source ) ? $fs->get_contents( $source ) : false;
			$source_hash = is_string( $source_text ) ? hash( 'sha256', $source_text ) : '';

			if ( $fs->exists( $file ) ) {
				$current = $fs->get_contents( $file );
				if ( ! is_string( $current ) ) { return self::fail( 'restore_failed' ); }
				$current_hash = hash( 'sha256', $current );
				// A completed commit or rollback only needs stale-backup cleanup.
				if ( hash_equals( $previous_hash, $current_hash ) || ( $source_hash && hash_equals( $source_hash, $current_hash ) ) ) {
					$fs->delete( $backup );
					return true;
				}
				// A backup proves HarborCache began this transaction. Accept its own
				// marked file, or a truncated prefix of the packaged source, as a
				// damaged destination. Never replace an unrecognized foreign file.
				$partial_source = is_string( $source_text ) && strlen( $current ) < strlen( $source_text ) && false !== strpos( $current, 'HarborCache Drop-In' ) && 0 === strncmp( $source_text, $current, strlen( $current ) );
				if ( ! self::owned( $current ) && ! $partial_source ) { return self::fail( 'foreign_destination' ); }
			}

			try { $fs->copy( $backup, $file, true, FS_CHMOD_FILE ); } catch ( \Throwable $error ) { /* Verify below; a transport may report failure after writing. */ }
			$restored = self::matches( $fs, $file, $previous_hash );
			if ( ! $restored ) {
				// Do not leave a partially copied PHP file for WordPress to load on
				// the next request. With the drop-in absent, WordPress safely falls
				// back to its request-local object cache while the backup remains.
				if ( $fs->exists( $file ) && ! self::matches( $fs, $file, $previous_hash ) ) { $fs->delete( $file ); }
				return self::fail( 'restore_failed' );
			}
			$fs->delete( $backup );
			return true;
		} );

		if ( false === $result && '' === self::$last_error ) { return self::fail( 'lock_failed' ); }
		return $result;
	}

	public static function replace( $fs, $source, $file ) {
		self::$last_error = '';
		$result = self::locked( $fs, $file, static function () use ( $fs, $source, $file ) {
			$backup = $file . '.harborcache-backup.php';
			$temp = $file . '.' . wp_generate_password( 12, false ) . '.php';
			$text = $fs->get_contents( $source );
			if ( ! self::owned( $text ) ) { return self::fail( 'stage_failed' ); }
			$expected = hash( 'sha256', $text );
			$previous = $fs->exists( $file ) ? $fs->get_contents( $file ) : null;
			if ( null !== $previous && ! self::owned( $previous ) ) { return self::fail( 'foreign_destination' ); }
			$previous_hash = is_string( $previous ) ? hash( 'sha256', $previous ) : '';
			try {
				if ( ! $fs->copy( $source, $temp, false, FS_CHMOD_FILE ) || ! self::matches( $fs, $temp, $expected ) ) { return self::fail( 'stage_failed' ); }
				if ( null !== $previous && ( ! $fs->copy( $file, $backup, true, FS_CHMOD_FILE ) || ! self::matches( $fs, $backup, $previous_hash ) ) ) { return self::fail( 'backup_failed' ); }
				// Direct same-directory rename preserves the old destination until success on POSIX.
				$ok = $fs instanceof \WP_Filesystem_Direct && @rename( $temp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				if ( ! $ok ) { try { $ok = $fs->move( $temp, $file, true ); } catch ( \Throwable $error ) { $ok = false; } }
				$ok = $ok && self::matches( $fs, $file, $expected );
				if ( ! $ok && null !== $previous ) {
					$restored = false;
					try { $fs->copy( $backup, $file, true, FS_CHMOD_FILE ); } catch ( \Throwable $error ) { /* Verify below; a transport can report failure after writing. */ }
					$restored = self::matches( $fs, $file, $previous_hash );
					if ( $restored ) { $fs->delete( $backup ); }
					elseif ( $fs->exists( $file ) && ! self::matches( $fs, $file, $expected ) ) { $fs->delete( $file ); }
				} elseif ( ! $ok && $fs->exists( $file ) && ! self::matches( $fs, $file, $expected ) ) {
					// A failed first install has no prior copy to restore. Remove any
					// partial destination so WordPress boots with its runtime cache.
					$fs->delete( $file );
				}
				if ( ! $ok ) { return self::fail( 'replace_failed' ); }
				$fs->delete( $backup );
				return true;
			} finally { if ( $fs->exists( $temp ) ) { $fs->delete( $temp ); } }
		} );
		if ( false === $result && '' === self::$last_error ) { return self::fail( 'lock_failed' ); }
		return $result;
	}

	public static function remove( $fs, $file ) {
		self::$last_error = '';
		$result = self::locked( $fs, $file, static function () use ( $fs, $file ) {
			if ( $fs->exists( $file ) ) {
				$text = $fs->get_contents( $file );
				if ( ! self::owned( $text ) ) { return self::fail( 'foreign_destination' ); }
				if ( ! $fs->delete( $file ) ) { return self::fail( 'replace_failed' ); }
			}
			$backup = $file . '.harborcache-backup.php';
			return ! $fs->exists( $backup ) || $fs->delete( $backup ) || self::fail( 'replace_failed' );
		} );
		if ( false === $result && '' === self::$last_error ) { return self::fail( 'lock_failed' ); }
		return $result;
	}
}
