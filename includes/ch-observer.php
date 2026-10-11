<?php
/** Optional, redacted command instrumentation. */
namespace HarborCache;
defined( 'ABSPATH' ) || exit;

final class Observer {
	public static function log( array $config, $level, $message, array $context = array() ) {
		if ( ! in_array( $level, $config['log_levels'], true ) ) { return; }
		try {
			$logger = $config['logger'];
			if ( is_string( $logger ) && class_exists( $logger ) ) { $logger = new $logger(); }
			if ( is_object( $logger ) && method_exists( $logger, 'log' ) ) { $logger->log( $level, $message, $context ); }
			elseif ( is_callable( $logger ) ) { call_user_func( $logger, $message, $context ); }
		} catch ( \Throwable $ignored ) { /* An optional logger cannot break cache operations. */ }
	}

	public static function trace( $tracer, $command, callable $call ) {
		if ( null === $tracer || 'none' === $tracer ) { return $call(); }
		if ( is_callable( $tracer ) ) { return call_user_func( $tracer, $call, $command ); }
		if ( 'newrelic' === $tracer && function_exists( 'newrelic_record_datastore_segment' ) ) {
			$invoked = false;
			$callback = static function () use ( $call, &$invoked ) { $invoked = true; return $call(); };
			try { $value = newrelic_record_datastore_segment( $callback, array( 'product' => 'Redis', 'operation' => $command, 'collection' => 'WordPress object cache' ) ); return $invoked ? $value : $call(); }
			catch ( \Throwable $error ) { if ( $invoked ) { throw $error; } return $call(); }
		}
		$span = null; $scope = null; $start = microtime( true );
		try {
			$provider = is_object( $tracer ) ? $tracer : null;
			if ( in_array( $tracer, array( 'opentelemetry', 'open-telemetry' ), true ) && class_exists( '\OpenTelemetry\API\Globals' ) ) { $provider = \OpenTelemetry\API\Globals::tracerProvider(); }
			if ( $provider ) {
				$instrument = method_exists( $provider, 'getTracer' ) ? $provider->getTracer( 'harborcache' ) : $provider;
				$span = $instrument->spanBuilder( 'redis ' . $command )->setSpanKind( 3 )->setAttribute( 'db.system', 'redis' )->setAttribute( 'db.operation', $command )->startSpan();
				$scope = $span->activate();
			}
		} catch ( \Throwable $ignored ) { $span = null; }
		try { return $call(); }
		catch ( \Throwable $error ) {
			if ( $span ) { try { $span->setStatus( 'Error', 'Redis command failed' ); } catch ( \Throwable $ignored ) {} }
			throw $error;
		} finally {
			try {
				if ( $scope ) { $scope->detach(); }
				if ( $span ) { $span->end(); }
				if ( 'newrelic' === $tracer && function_exists( 'newrelic_record_custom_event' ) ) { newrelic_record_custom_event( 'HarborCacheCommand', array( 'command' => $command, 'milliseconds' => ( microtime( true ) - $start ) * 1000 ) ); }
			} catch ( \Throwable $ignored ) { /* Telemetry export is optional. */ }
		}
	}
}

final class Pipeline {
	private $pipeline;
	private $cache;
	private $commands = array();
	public function __construct( $pipeline, $cache ) { $this->pipeline = $pipeline; $this->cache = $cache; }
	public function __call( $method, $args ) {
		if ( in_array( strtolower( $method ), array( 'execute', 'exec' ), true ) ) {
			try { return $this->cache->observe_command( 'PIPELINE', function () use ( $method, $args ) { return $this->pipeline->$method( ...$args ); }, false, $this->commands ); }
			finally { $this->commands = array(); }
		}
		$result = $this->pipeline->$method( ...$args );
		if ( in_array( strtolower( $method ), array( 'set', 'setex', 'del', 'unlink', 'hset', 'hdel' ), true ) ) { $this->cache->mark_write( $args[0] ?? '' ); }
		if ( 'discard' === strtolower( $method ) ) { $this->commands = array(); }
		elseif ( $this->cache->ch_config['save_commands'] || $this->cache->ch_config['debug'] ) { $this->commands[] = strtoupper( $method ); }
		return $result === $this->pipeline ? $this : $result;
	}
}
