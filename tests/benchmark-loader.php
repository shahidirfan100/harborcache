<?php
/** Installed only in disposable CI to load the selected main plugin during bootstrap. */
if ( 'cli' === PHP_SAPI && defined( 'HC_BENCH_MAIN' ) ) { require_once HC_BENCH_MAIN; }
