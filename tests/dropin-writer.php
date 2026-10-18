<?php
/** Standalone transaction tests for the HarborCache object-cache drop-in writer. */
define( 'ABSPATH', __DIR__ );
define( 'FS_CHMOD_FILE', 0644 );
define( 'FS_CHMOD_DIR', 0755 );
define( 'HARBORCACHE_PLUGIN_PATH', dirname( __DIR__ ) );

function wp_generate_password( $length = 12, $special = false ) {
	static $counter = 0;
	return str_pad( (string) ++$counter, $length, 'x' );
}

class WP_Filesystem_Direct {
	public function put_contents( $path, $contents, $mode = false ) { return false !== file_put_contents( $path, $contents ); }
	public function exists( $path ) { return file_exists( $path ); }
	public function get_contents( $path ) { return file_get_contents( $path ); }
	public function copy( $source, $destination, $overwrite = false, $mode = false ) {
		if ( file_exists( $destination ) && ! $overwrite ) { return false; }
		return copy( $source, $destination );
	}
	public function move( $source, $destination, $overwrite = false ) {
		if ( file_exists( $destination ) ) {
			if ( ! $overwrite || ! unlink( $destination ) ) { return false; }
		}
		return rename( $source, $destination );
	}
	public function delete( $path, $recursive = false, $type = false ) {
		return is_dir( $path ) ? rmdir( $path ) : ( ! file_exists( $path ) || unlink( $path ) );
	}
	public function mkdir( $path, $chmod = false ) { return mkdir( $path ); }
	public function is_dir( $path ) { return is_dir( $path ); }
	public function mtime( $path ) { return filemtime( $path ); }
}

class HarborCache_Test_Failing_Filesystem {
	public function put_contents( $path, $contents, $mode = false ) { return false !== file_put_contents( $path, $contents ); }
	public $failed_destination;
	public function __construct( $failed_destination ) { $this->failed_destination = $failed_destination; }
	public function exists( $path ) { return file_exists( $path ); }
	public function get_contents( $path ) { return file_get_contents( $path ); }
	public function copy( $source, $destination, $overwrite = false, $mode = false ) {
		if ( $destination === $this->failed_destination ) {
			$contents = file_get_contents( $source );
			file_put_contents( $destination, substr( $contents, 0, 80 ) );
			return false;
		}
		if ( file_exists( $destination ) && ! $overwrite ) { return false; }
		return copy( $source, $destination );
	}
	public function move( $source, $destination, $overwrite = false ) {
		$contents = file_get_contents( $source );
		file_put_contents( $destination, substr( $contents, 0, 80 ) );
		return false;
	}
	public function delete( $path, $recursive = false, $type = false ) {
		return is_dir( $path ) ? rmdir( $path ) : ( ! file_exists( $path ) || unlink( $path ) );
	}
	public function mkdir( $path, $chmod = false ) { return mkdir( $path ); }
	public function is_dir( $path ) { return is_dir( $path ); }
	public function mtime( $path ) { return filemtime( $path ); }
}

require_once dirname( __DIR__ ) . '/includes/class-dropin-writer.php';

$root = sys_get_temp_dir() . '/harborcache-dropin-' . bin2hex( random_bytes( 6 ) );
mkdir( $root );
$source = $root . '/packaged-object-cache.php';
$file = $root . '/object-cache.php';
$backup = $file . '.harborcache-backup.php';
$previous = "<?php\n/** Drop-In Name: HarborCache Drop-In */\nclass HarborCache_Advanced_Cache {}\n// previous working copy\n";
$current = "<?php\n/** Drop-In Name: HarborCache Drop-In */\nclass HarborCache_Advanced_Cache { public function current() {} }\n// packaged copy\n";
file_put_contents( $source, $current );
$passed = 0;
$failed = 0;
$assert = static function ( $label, $condition ) use ( &$passed, &$failed ) {
	if ( $condition ) { ++$passed; echo "PASS $label\n"; }
	else { ++$failed; echo "FAIL $label\n"; }
};

// A truncated destination from an interrupted replacement must restore the saved working copy.
file_put_contents( $backup, $previous );
file_put_contents( $file, substr( $current, 0, 90 ) );
$recovered = HarborCache\Dropin_Writer::recover( new WP_Filesystem_Direct(), $file, $source );
$assert( 'Interrupted update restores the verified backup over a partial owned file', $recovered && hash_equals( hash( 'sha256', $previous ), hash_file( 'sha256', $file ) ) && ! file_exists( $backup ) );

// A completed update with a leftover backup is committed, not rolled back.
file_put_contents( $file, $current );
file_put_contents( $backup, $previous );
$recovered = HarborCache\Dropin_Writer::recover( new WP_Filesystem_Direct(), $file, $source );
$assert( 'Completed update keeps packaged contents and clears its stale backup', $recovered && hash_equals( hash( 'sha256', $current ), hash_file( 'sha256', $file ) ) && ! file_exists( $backup ) );

// Missing destination is safe for WordPress to boot without a persistent cache until restored.
unlink( $file );
file_put_contents( $backup, $previous );
$recovered = HarborCache\Dropin_Writer::recover( new WP_Filesystem_Direct(), $file, $source );
$assert( 'Missing destination is restored from the verified backup', $recovered && hash_equals( hash( 'sha256', $previous ), hash_file( 'sha256', $file ) ) );

// The recovery mechanism must never replace a file owned by another object-cache plugin.
$foreign = "<?php\n/** foreign object cache */\n";
file_put_contents( $file, $foreign );
file_put_contents( $backup, $previous );
$recovered = HarborCache\Dropin_Writer::recover( new WP_Filesystem_Direct(), $file, $source );
$assert( 'Foreign destination remains untouched when a HarborCache backup exists', ! $recovered && $foreign === file_get_contents( $file ) && file_exists( $backup ) );

// A failed remote copy must not leave a partial PHP file at WordPress's canonical drop-in path.
unlink( $file );
$remote = new HarborCache_Test_Failing_Filesystem( $file );
$recovered = HarborCache\Dropin_Writer::recover( $remote, $file, $source );
$assert( 'Failed recovery removes the partial destination and preserves the backup', ! $recovered && ! file_exists( $file ) && file_exists( $backup ) );

// A failed first install has no backup, so its partial target must also be removed.
unlink( $backup );
$remote = new HarborCache_Test_Failing_Filesystem( $file );
$replaced = HarborCache\Dropin_Writer::replace( $remote, $source, $file );
$assert( 'Failed first install removes a partial canonical drop-in', ! $replaced && ! file_exists( $file ) );

// A failed replacement must leave the old copy recoverable without a partial target.
file_put_contents( $file, $previous );
$remote = new HarborCache_Test_Failing_Filesystem( $file );
$replaced = HarborCache\Dropin_Writer::replace( $remote, $source, $file );
$assert( 'Failed upgrade removes the partial target and retains the old backup', ! $replaced && ! file_exists( $file ) && file_exists( $backup ) );
$recovered = HarborCache\Dropin_Writer::recover( new WP_Filesystem_Direct(), $file, $source );
$assert( 'Next request can restore the old drop-in after failed upgrade', $recovered && hash_equals( hash( 'sha256', $previous ), hash_file( 'sha256', $file ) ) );

// Successful replacement verifies the staged bytes before retiring the old file.
file_put_contents( $file, $previous );
$replaced = HarborCache\Dropin_Writer::replace( new WP_Filesystem_Direct(), $source, $file );
$assert( 'Owned drop-in replacement installs exact packaged bytes', $replaced && hash_equals( hash( 'sha256', $current ), hash_file( 'sha256', $file ) ) && ! file_exists( $backup ) );

// The installed engine contains the rendered source path, and recovery compares those bytes.
$template = file_get_contents( dirname( __DIR__ ) . '/includes/object-cache.php' );
$rendered = HarborCache\Dropin_Writer::render( $template, HARBORCACHE_PLUGIN_PATH );
file_put_contents( $source, $template );
$replaced = HarborCache\Dropin_Writer::replace( new WP_Filesystem_Direct(), $source, $file );
$assert( 'Replacement verifies rendered bytes rather than the uninstalled template', $replaced && $rendered === file_get_contents( $file ) );
file_put_contents( $backup, $previous );
$recovered = HarborCache\Dropin_Writer::recover( new WP_Filesystem_Direct(), $file, $source );
$assert( 'Completed rendered update clears backup without rolling back', $recovered && $rendered === file_get_contents( $file ) && ! file_exists( $backup ) );

$staging = new class extends WP_Filesystem_Direct {
	public function put_contents( $path, $contents, $mode = false ) { file_put_contents( $path, substr( $contents, 0, 80 ) ); return false; }
};
$replaced = HarborCache\Dropin_Writer::replace( $staging, $source, $file );
$assert( 'Partial staging preserves the working drop-in and leaves no temporary PHP', ! $replaced && $rendered === file_get_contents( $file ) && ! file_exists( $backup ) && 0 === count( glob( $root . '/object-cache.php.*.php' ) ) );

foreach ( glob( $root . '/*' ) as $path ) { if ( is_file( $path ) ) { unlink( $path ); } }
foreach ( glob( $root . '/*' ) as $path ) { if ( is_dir( $path ) ) { rmdir( $path ); } }
foreach ( glob( $root . '/*' ) as $path ) { if ( is_file( $path ) ) { unlink( $path ); } }
@rmdir( $root );
echo sprintf( "RESULT %d passed, %d failed\n", $passed, $failed );
exit( $failed ? 1 : 0 );
