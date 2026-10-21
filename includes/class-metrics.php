<?php
/**
 * Metrics collection
 *
 * @package HarborCache\Core
 */

namespace HarborCache\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Metrics collection class
 */
class Metrics {

    /**
     * Unique identifier
     *
     * @var string
     */
    public $id;

    /**
     * Cache hits
     *
     * @var int
     */
    public $hits;

    /**
     * Cache misses
     *
     * @var int
     */
    public $misses;

    /**
     * Cache ratio
     *
     * @var float
     */
    public $ratio;

    /**
     * Bytes retrieves
     *
     * @var int
     */
    public $bytes;

    /**
     * Cache needed time
     *
     * @var float
     */
    public $time;

    /**
     * Cache calls
     *
     * @var int
     */
    public $calls;

    /**
     * Metrics timestamp
     *
     * @var int
     */
    public $timestamp;

    /**
     * Initializes the metrics collection
     *
     * @return void
     */
    public static function init() {
        if ( ! self::is_enabled() ) {
            return;
        }

        add_action( 'shutdown', [ self::class, 'record' ] );
        add_action( 'harborcache_metrics_discard', [ self::class, 'discard' ] );
    }

    /**
     * Checks if the collection of metrics is enabled.
     *
     * @return bool
     */
    public static function is_enabled() {
        return \HarborCache\Config::load()['analytics']['enabled'] && ( ! defined( 'WP_REDIS_DISABLE_METRICS' ) || ! WP_REDIS_DISABLE_METRICS );
    }

    /**
     * Checks if metrics can be recorded.
     *
     * @return bool
     */
    public static function is_active() {
        global $wp_object_cache;

        return self::is_enabled()
            && Plugin::instance()->get_redis_status()
            && method_exists( $wp_object_cache, 'info' )
            && method_exists( $wp_object_cache, 'redis_instance' );
    }

    /**
     * Retrieves metrics max time
     *
     * @return int
     */
    public static function max_time() {
        if ( defined( 'WP_REDIS_METRICS_MAX_TIME' ) ) {
            return (int) WP_REDIS_METRICS_MAX_TIME;
        }

        return (int) \HarborCache\Config::load()['analytics']['retention'];
    }

    /**
     * Records metrics and adds them to redis
     *
     * @return void
     */
    public static function record() {
        $config = \HarborCache\Config::load();
        if ( empty( $config['analytics']['enabled'] ) || random_int( 1, 10000 ) > (float) $config['analytics']['sample_rate'] * 100 ) { return; }
        try {
            if ( ! self::is_active() ) { return; }
            $metrics = new self();
            $metrics->collect();
            $metrics->save();
        } catch ( \Throwable $error ) { self::log_failure( $error ); }
    }

    private static function log_failure( $error ) {
        global $wp_object_cache;
        try {
            $client = $wp_object_cache->redis_instance();
            if ( $client instanceof \Redis ) {
                // Server error replies are complete; transport failures may leave an unread reply.
                $server_error = $client->getLastError() || preg_match( '/^(?:ERR|NOPERM|NOAUTH|WRONGTYPE|OOM|READONLY|MISCONF|NOSCRIPT|BUSY|LOADING)\b/', $error->getMessage() );
                if ( $server_error ) { $client->clearLastError(); }
                elseif ( $error instanceof \RedisException ) { $client->close(); }
            }
        } catch ( \Throwable $ignored ) {}
        static $logged = false;
        if ( $logged ) { return; }
        $logged = true;
        if ( class_exists( '\HarborCache\Observer' ) ) { \HarborCache\Observer::log( \HarborCache\Config::load(), 'warning', 'Request metrics are unavailable.' ); }
        error_log( '[HarborCache] Request metrics are unavailable.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    /**
     * Collect metrics from object cache instance.
     */
    public function collect() {
        global $wp_object_cache;

        $info = $wp_object_cache->info();

        // phpcs:disable WordPress.WP.AlternativeFunctions.rand_mt_rand
        $this->id = substr( md5( uniqid( strval( mt_rand() ), true ) ), 12 );
        $this->hits = $info->hits;
        $this->misses = $info->misses;
        $this->ratio = $info->ratio;
        $this->bytes = $info->bytes;
        $this->time = round( $info->time, 5 );
        $this->calls = $info->calls;
        $this->timestamp = time();
    }

    /**
     * Retrieves metrics from redis
     *
     * @param int $seconds Number of seconds of the oldest entry to retrieve.
     * @param int|null $limit Maximum recent samples to transfer, or null for the full retention window.
     * @return Metrics[]
     */
    public static function get( $seconds = null, $limit = null ) {
        global $wp_object_cache;

        if ( ! self::is_active() ) {
            return [];
        }

        if ( null === $seconds ) {
            $seconds = self::max_time();
        }

        try {
            if ( null !== $limit ) {
                $raw_metrics = array_reverse( $wp_object_cache->redis_instance()->zrevrangebyscore(
                    $wp_object_cache->build_key( 'metrics', 'harborcache' ),
                    time() - MINUTE_IN_SECONDS,
                    time() - $seconds,
                    array( 'withscores' => true, 'limit' => array( 0, max( 1, min( 1000, (int) $limit ) ) ) )
                ), true );
            } else {
                $raw_metrics = $wp_object_cache->redis_instance()->zrangebyscore(
                $wp_object_cache->build_key( 'metrics', 'harborcache' ),
                time() - $seconds,
                time() - MINUTE_IN_SECONDS,
                [ 'withscores' => true ]
                );
            }
            if ( ! is_array( $raw_metrics ) ) { throw new \RuntimeException( 'Metrics read failed.' ); }
        } catch ( \Throwable $exception ) {
            self::log_failure( $exception );

            return [];
        }

        $metrics = [];
        $prefix = sprintf( 'O:%d:"%s', strlen( self::class ), self::class );

        foreach ( $raw_metrics as $serialized => $timestamp ) {
            // Compatibility: Ignore all non serialized entries as they were used by prior versions.
            if ( strpos( $serialized, $prefix ) !== 0 ) {
                continue;
            }

            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            try { $sample = @unserialize( $serialized, array( 'allowed_classes' => array( self::class ) ) ); }
            catch ( \Throwable $error ) { continue; }
            if ( ! $sample instanceof self ) { continue; }
            $valid = is_numeric( $sample->timestamp ) && (float) $sample->timestamp >= time() - $seconds && (float) $sample->timestamp <= time() - MINUTE_IN_SECONDS;
            foreach ( array( 'hits', 'misses', 'bytes', 'time', 'calls', 'ratio' ) as $field ) { $valid = $valid && is_numeric( $sample->$field ) && is_finite( (float) $sample->$field ) && (float) $sample->$field >= 0; }
            if ( $valid && (float) $sample->ratio <= 100 ) { $metrics[] = $sample; }
        }

        return $metrics;
    }

    /**
     * Saves the current metrics to redis
     *
     * @return void
     */
    public function save() {
        global $wp_object_cache;
        $analytics = \HarborCache\Config::load()['analytics'];
        if ( ! $analytics['enabled'] ) { return; }
        try {
        $client = $wp_object_cache->redis_instance();
        if ( $wp_object_cache instanceof \HarborCache_Advanced_Cache ) {
            $key = $wp_object_cache->build_key( 'metrics', 'harborcache' );
            $wp_object_cache->ch_command( array( 'ZREMRANGEBYSCORE', $key, '-inf', time() - self::max_time() ) );
        }
            $saved = $client->zadd(
                $wp_object_cache->build_key( 'metrics', 'harborcache' ),
                $this->timestamp,
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
                serialize( $this )
            );
            if ( false === $saved ) { throw new \RuntimeException( 'Metrics write failed.' ); }
            if ( $wp_object_cache instanceof \HarborCache_Advanced_Cache ) {
                if ( null !== $analytics['persist_limit'] ) { $wp_object_cache->ch_command( array( 'ZREMRANGEBYRANK', $key, 0, -$analytics['persist_limit'] - 1 ) ); }
                $wp_object_cache->ch_command( array( 'EXPIRE', $key, self::max_time() ) );
            }
        } catch ( \Throwable $exception ) {
            self::log_failure( $exception );
        }
    }

    /**
     * Removes metrics older than the configured retention window.
     *
     * @return void
     */
    public static function discard() {
        global $wp_object_cache;

        if ( ! self::is_active() ) {
            return;
        }

        try {
            $removed = $wp_object_cache->redis_instance()->zremrangebyscore(
                $wp_object_cache->build_key( 'metrics', 'harborcache' ),
                0,
                time() - self::max_time()
            );
            if ( false === $removed ) { throw new \RuntimeException( 'Metrics cleanup failed.' ); }
        } catch ( \Throwable $exception ) {
            self::log_failure( $exception );
        }
    }

    /**
     * Counts the recorded metrics
     *
     * @return int
     */
    public static function count() {
        global $wp_object_cache;

        if ( ! self::is_active() ) {
            return 0;
        }

        try {
            $count = $wp_object_cache->redis_instance()->zcount(
                $wp_object_cache->build_key( 'metrics', 'harborcache' ),
                '-inf',
                '+inf'
            );
            if ( ! is_int( $count ) ) { throw new \RuntimeException( 'Metrics count failed.' ); }
            return $count;
        } catch ( \Throwable $exception ) {
            self::log_failure( $exception );
            return 0;
        }
    }

}
