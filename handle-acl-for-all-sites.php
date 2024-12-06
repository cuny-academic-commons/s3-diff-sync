<?php

namespace HardG\S3DiffSync;

// Must be executed via wp eval-file
if ( ! defined( 'ABSPATH' ) ) {
	die( 'Usage: wp eval-file path/to/s3-diff-sync.php' );
}

if ( ! class_exists( 'S3_Uploads\Plugin' ) ) {
	die( 'S3 Uploads plugin is not active.' );
}

if ( ! function_exists( 'cac_s3_uploads_update_acl_for_all_attachments' ) ) {
	die( 'CAC s3-uploads mu-plugin is not active.' );
}

global $wpdb;

$runs_dir = __DIR__ . '/runs';
$run_id = 'acl-' . date('Y-m-d-H-i-s');
$run_log = "$runs_dir/$run_id.log";

echo "Logging to $run_log\n";

$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

function log( $message, $run_log ) {
	file_put_contents( $run_log, $message . "\n", FILE_APPEND );
}

$progress = \WP_CLI\Utils\make_progress_bar( 'Checking ACLs', count( $blog_ids ) );

foreach ( $blog_ids as $blog_id ) {
	$progress->tick();

	$blog_public = (int) get_blog_option( $blog_id, 'blog_public' );

	if ( $blog_public >= 0 ) {
		// Nothing to do.
		log( "$blog_id: blog_public is $blog_public. Skipping.", $run_log );
		continue;
	}

	$instance = \S3_Uploads\Plugin::get_instance();

	switch_to_blog( $blog_id );

	cac_s3_uploads_update_acl_for_all_attachments( 'private' );

	restore_current_blog();
}

$progress->finish();
