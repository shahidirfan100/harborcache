<?php
/**
 * Configuration used by the listing screenshot capture job.
 * Copied to wp-content/harborcache-config.php before the drop-in is installed.
 */
return array(
	'client'    => 'phpredis',
	'maxttl'    => 86400,
	'analytics' => array(
		'enabled'     => true,
		'sample_rate' => 100,
		'retention'   => 7200,
		'persist'     => true,
	),
);
