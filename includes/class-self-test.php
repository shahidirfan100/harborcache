<?php
/**
 * End-to-end cache self-test through the live WordPress cache API.
 *
 * A raw Redis PING proves the server is reachable. This test proves WordPress
 * can write, force-read and delete through the active drop-in.
 *
 * @package HarborCache
 */

namespace HarborCache;

defined( 'ABSPATH' ) || exit;

final class Self_Test {
	public static function run() {
		$steps   = array();
		$cache   = Status::cache();
		$status  = Status::read();
		$steps[] = self::step( __( 'Drop-in loaded', 'harborcache-object-cache-with-redis' ), (bool) $cache, $cache ? __( 'Yes', 'harborcache-object-cache-with-redis' ) : __( 'No', 'harborcache-object-cache-with-redis' ) );
		$steps[] = self::step( __( 'Transport', 'harborcache-object-cache-with-redis' ), 'connected' === ( $status['transport'] ?? '' ), (string) ( $status['transport'] ?? 'unknown' ) );
		$steps[] = self::step( __( 'Namespace', 'harborcache-object-cache-with-redis' ), 'owned' === ( $status['namespace'] ?? '' ), (string) ( $status['namespace'] ?? 'unknown' ) );
		$steps[] = self::step( __( 'Value codec', 'harborcache-object-cache-with-redis' ), 'ok' === ( $status['codec'] ?? '' ), (string) ( $status['codec'] ?? 'unknown' ) );

		if ( $cache ) {
			$group = 'harborcache-selftest';
			$key   = 'probe-' . bin2hex( random_bytes( 6 ) );
			$value = array( 'nonce' => bin2hex( random_bytes( 8 ) ), 'checked_at' => time() );

			wp_cache_delete( $key, $group );
			$set = wp_cache_set( $key, $value, $group, 30 );
			$steps[] = self::step( __( 'Write a temporary value', 'harborcache-object-cache-with-redis' ), (bool) $set, $set ? __( 'Stored', 'harborcache-object-cache-with-redis' ) : __( 'Rejected', 'harborcache-object-cache-with-redis' ) );

			$found = false;
			$get   = wp_cache_get( $key, $group, true, $found );
			$ok    = (bool) $found && $get === $value;
			$steps[] = self::step( __( 'Read it back from Redis', 'harborcache-object-cache-with-redis' ), $ok, $ok ? __( 'Matched', 'harborcache-object-cache-with-redis' ) : __( 'Missing or different', 'harborcache-object-cache-with-redis' ) );

			$deleted = wp_cache_delete( $key, $group );
			$steps[] = self::step( __( 'Delete the temporary value', 'harborcache-object-cache-with-redis' ), (bool) $deleted, $deleted ? __( 'Removed', 'harborcache-object-cache-with-redis' ) : __( 'Not removed', 'harborcache-object-cache-with-redis' ) );
		}

		$ok = true;
		foreach ( $steps as $step ) {
			if ( empty( $step['ok'] ) ) {
				$ok = false;
			}
		}

		return array(
			'ok'         => $ok,
			'steps'      => $steps,
			'checked_at' => time(),
			'status'     => $status,
		);
	}

	private static function step( $label, $ok, $value ) {
		return array( 'label' => $label, 'ok' => (bool) $ok, 'value' => (string) $value );
	}

	public static function render( array $result ) {
		$class = ! empty( $result['ok'] ) ? 'is-connected' : 'is-degraded';
		$html  = '<div class="ch-probe ' . esc_attr( $class ) . '" role="status" aria-live="polite"><strong>' . esc_html( ! empty( $result['ok'] ) ? __( 'Cache self-test passed', 'harborcache-object-cache-with-redis' ) : __( 'Cache self-test needs attention', 'harborcache-object-cache-with-redis' ) ) . '</strong><ul class="ch-probe-checks">';
		foreach ( (array) ( $result['steps'] ?? array() ) as $step ) {
			$html .= '<li class="' . esc_attr( ! empty( $step['ok'] ) ? 'is-ok' : 'is-bad' ) . '"><span>' . esc_html( (string) ( $step['label'] ?? '' ) ) . '</span><strong>' . esc_html( (string) ( $step['value'] ?? '' ) ) . '</strong></li>';
		}
		$html .= '</ul><p class="ch-probe-meta">' . esc_html( sprintf(
			/* translators: %s: date and time of the self-test. */
			__( 'Checked %s.', 'harborcache-object-cache-with-redis' ),
			wp_date( 'M j, H:i:s', (int) ( $result['checked_at'] ?? time() ) )
		) ) . '</p></div>';
		return $html;
	}
}
