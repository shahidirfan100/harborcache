<?php
/** Serialized, verified drop-in replacement with a recoverable previous copy. */
namespace HarborCache;

defined( 'ABSPATH' ) || exit;

final class Dropin_Writer {
	private static function locked( $fs, $file, callable $operation ) {
		$lock = $file . '.harborcache-lock';
		$handle = null;
		if ( $fs instanceof \WP_Filesystem_Direct ) {
			// A persistent lock inode prevents overlapping updates across PHP workers.
			$handle = @fopen( $lock, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) { if ( $handle ) { fclose( $handle ); } return false; } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no file-handle lock API.
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

	public static function recover( $fs, $file ) {
		$backup = $file . '.harborcache-backup.php';
		if ( $fs->exists( $file ) || ! $fs->exists( $backup ) ) { return true; }
		return self::locked( $fs, $file, static function () use ( $fs, $file, $backup ) {
			if ( $fs->exists( $file ) ) { return true; }
			$text = $fs->get_contents( $backup );
			if ( ! is_string( $text ) || false === strpos( $text, 'HarborCache Drop-In' ) || false === strpos( $text, 'HarborCache_Advanced_Cache' ) ) { return false; }
			$ok = $fs->copy( $backup, $file, false, FS_CHMOD_FILE ) && self::matches( $fs, $file, hash( 'sha256', $text ) );
			if ( $ok ) { $fs->delete( $backup ); }
			return $ok;
		} );
	}

	public static function replace( $fs, $source, $file ) {
		return self::locked( $fs, $file, static function () use ( $fs, $source, $file ) {
			$backup = $file . '.harborcache-backup.php';
			$temp = $file . '.' . wp_generate_password( 12, false ) . '.php';
			$text = $fs->get_contents( $source );
			if ( ! is_string( $text ) || false === strpos( $text, 'HarborCache Drop-In' ) ) { return false; }
			$previous = $fs->exists( $file ) ? $fs->get_contents( $file ) : null;
			if ( null !== $previous && ( ! is_string( $previous ) || false === strpos( $previous, 'HarborCache_Advanced_Cache' ) || false === strpos( $previous, 'HarborCache Drop-In' ) ) ) { return false; }
			try {
				if ( ! $fs->copy( $source, $temp, false, FS_CHMOD_FILE ) || ! self::matches( $fs, $temp, hash( 'sha256', $text ) ) ) { return false; }
				if ( null !== $previous && ( ! $fs->copy( $file, $backup, true, FS_CHMOD_FILE ) || ! self::matches( $fs, $backup, hash( 'sha256', $previous ) ) ) ) { return false; }
				// Direct same-directory rename preserves the old destination until success on POSIX.
				$ok = $fs instanceof \WP_Filesystem_Direct && @rename( $temp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				if ( ! $ok ) { try { $ok = $fs->move( $temp, $file, true ); } catch ( \Throwable $error ) { $ok = false; } }
				$ok = $ok && self::matches( $fs, $file, hash( 'sha256', $text ) );
				if ( ! $ok && null !== $previous ) {
					$restored = $fs->copy( $backup, $file, true, FS_CHMOD_FILE ) && self::matches( $fs, $file, hash( 'sha256', $previous ) );
					if ( $restored ) { $fs->delete( $backup ); }
				} elseif ( $ok ) { $fs->delete( $backup ); }
				return $ok;
			} finally { if ( $fs->exists( $temp ) ) { $fs->delete( $temp ); } }
		} );
	}

	public static function remove( $fs, $file ) {
		return self::locked( $fs, $file, static function () use ( $fs, $file ) {
			if ( $fs->exists( $file ) ) {
				$text = $fs->get_contents( $file );
				if ( ! is_string( $text ) || false === strpos( $text, 'HarborCache Drop-In' ) || false === strpos( $text, 'HarborCache_Advanced_Cache' ) ) { return false; }
				if ( ! $fs->delete( $file ) ) { return false; }
			}
			$backup = $file . '.harborcache-backup.php';
			return ! $fs->exists( $backup ) || $fs->delete( $backup );
		} );
	}
}
