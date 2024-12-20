<?php

namespace HardG\S3DiffSync;

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Usage: wp eval-file path/to/handle-acl-for-site-attachments.php' );
}

if ( ! class_exists( 'S3_Uploads\Plugin' ) ) {
    die( 'S3 Uploads plugin is not active.' );
}

require 'vendor/autoload.php';

global $wpdb;

// Command-line arguments
$blog_id = isset( $args[0] ) ? (int) $args[0] : null;
$run_file = isset( $args[1] ) ? $args[1] : null;

if ( ! $blog_id ) {
    die( "Error: blog_id is required.\n" );
}

if ( $run_file ) {
    echo "Logging to $run_file\n";
    file_put_contents( $run_file, "Starting ACL update for blog_id $blog_id\n", FILE_APPEND );
}

// Determine ACL based on blog_public setting
$blog_public = (int) get_blog_option( $blog_id, 'blog_public' );
$new_acl = $blog_public < 0 ? 'private' : 'public-read';

// Initialize the S3 plugin instance
$instance = \S3_Uploads\Plugin::get_instance();

// Extract bucket name and prefix for the blog
$bucket = strtok( S3_UPLOADS_BUCKET, '/' );
$prefix = "wp-content/blogs.dir/{$blog_id}/files/";

// List objects in the S3 bucket for the blog
$objects = $instance->s3()->getIterator(
    'ListObjectsV2',
    [
        'Bucket' => $bucket,
        'Prefix' => $prefix,
    ]
);

$object_keys = [];
foreach ( $objects as $object ) {
    $object_keys[] = $object['Key'];
}

if ( empty( $object_keys ) ) {
    die( "No objects found for blog_id $blog_id\n" );
}
var_dump( $object_keys ); die;

// Prepare batch commands to update ACLs
$commands = [];
foreach ( $object_keys as $object_key ) {
    $commands[] = $instance->s3()->getCommand(
        'putObjectAcl',
        [
            'Bucket' => $bucket,
            'Key'    => $object_key,
            'ACL'    => $new_acl,
        ]
    );
}

// Execute commands in parallel using CommandPool
try {
    $results = Aws\CommandPool::batch(
        $instance->s3,
        $commands,
        [
            'concurrency' => 10, // Adjust concurrency level based on your needs
            'before'      => function ( $cmd ) use ( $run_file ) {
                if ( $run_file ) {
                    file_put_contents( $run_file, "Executing command: " . $cmd->getName() . "\n", FILE_APPEND );
                }
            },
            'rejected'    => function ( $reason, $index ) use ( $run_file ) {
                if ( $run_file ) {
                    file_put_contents( $run_file, "Command rejected: $reason\n", FILE_APPEND );
                }
            },
        ]
    );
} catch ( Exception $e ) {
    die( "Error executing batch commands: " . $e->getMessage() . "\n" );
}

// Log completion
if ( $run_file ) {
    file_put_contents( $run_file, "Updated ACL for " . count( $object_keys ) . " objects\n", FILE_APPEND );
}

echo "Updated ACL for " . count( $object_keys ) . " objects\n";

