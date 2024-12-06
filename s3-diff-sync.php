<?php

namespace HardG\S3DiffSync;

require __DIR__ . '/vendor/autoload.php';

if ( ! isset( $argv[1] ) ) {
    echo "Usage: php s3-diff-sync.php <wp_path> [<blog_id>]\n";
    echo "Example: php s3-diff-sync.php /path/to/wp 123\n";
	echo "Blog ID is optional\n";
    exit(1);
}

$wp_path = $argv[1];
$blog_id = $argv[2] ?? null;

$runs_dir = __DIR__ . '/runs';
$run_id = date('Y-m-d-H-i-s');
$run_log = "$runs_dir/$run_id.log";

echo "Logging to $run_log\n";

require $wp_path . '/cac-env-config.php';

if ( ! defined( 'S3_UPLOADS_BUCKET' ) ) {
    echo "S3_UPLOADS_BUCKET is not defined\n";
    exit(1);
}

if ( ! defined( 'S3_UPLOADS_REGION' ) ) {
    echo "S3_UPLOADS_REGION is not defined\n";
    exit(1);
}

if ( ! defined( 'S3_UPLOADS_KEY' ) ) {
    echo "S3_UPLOADS_KEY is not defined\n";
    exit(1);
}

if ( ! defined( 'S3_UPLOADS_SECRET' ) ) {
    echo "S3_UPLOADS_SECRET is not defined\n";
    exit(1);
}

use Aws\S3\S3Client;

function get_s3_client() {
	static $client;

	if ( null === $client ) {
		// AWS Configuration
		$client = new S3Client( [
			'version' => 'latest',
			'region'  => S3_UPLOADS_REGION,
			'signature' => 'v4',
			'credentials' => [
				'key'    => S3_UPLOADS_KEY,
				'secret' => S3_UPLOADS_SECRET,
			],
		] );
	}

	return $client;
}

$bucket = strtok( S3_UPLOADS_BUCKET, '/' ); // Extract the bucket name

function log( $message ) {
	global $run_log;
	file_put_contents( $run_log, $message . "\n", FILE_APPEND );
}

function listS3Keys($s3, $bucket, $prefix) {
    $keys = [];
    $continuationToken = null;

    do {
        $params = [
            'Bucket' => $bucket,
            'Prefix' => $prefix,
        ];
        if ( $continuationToken ) {
            $params['ContinuationToken'] = $continuationToken;
        }

        $result = $s3->listObjectsV2($params);
        if ( ! empty( $result['Contents'] ) ) {
            foreach ( $result['Contents'] as $object ) {
                $keys[] = $object['Key'];
            }
        }

        $continuationToken = $result['NextContinuationToken'] ?? null;
    } while ( $continuationToken );

    return $keys;
}

function uploadToS3( $s3, $bucket, $file_path, $key ) {
    try {
        $s3->putObject( [
            'Bucket' => $bucket,
            'Key'    => $key,
            'SourceFile' => $file_path,
        ] );
		log( "Uploaded: $key" );
    } catch ( Exception $e ) {
		log( "Error uploading $key: " . $e->getMessage() );
    }
}

function upload_all_site_directories() {
	global $wp_path;

	// Get a list of directories in wp-content/blogs.dir
	$blog_dirs = glob( $wp_path . '/wp-content/blogs.dir/*', GLOB_ONLYDIR );

	$blog_ids = array_map( function( $dir ) {
		return basename( $dir );
	}, $blog_dirs );

	foreach ( $blog_ids as $blog_id ) {
		upload_site_directory( $blog_id );
	}
}

function upload_site_directory( $blog_id ) {
	global $wp_path;

	$prefix = "wp-content/blogs.dir/{$blog_id}/";
	log( "Processing blog {$blog_id}" );

	$blog_dir = $wp_path . "/wp-content/blogs.dir/{$blog_id}";

	upload_directory( $prefix, $blog_dir );
}

function upload_directory( $prefix, $directory ) {
	global $bucket, $wp_path;

	// Fetch existing S3 keys
	$s3_keys = listS3Keys( get_s3_client(), $bucket, $prefix );
	$s3_key_set = array_flip( $s3_keys ); // Use a set for fast lookups

	// Iterate over local files
	$directory_iterator = new \RecursiveDirectoryIterator( $directory );
	$iterator = new \RecursiveIteratorIterator( $directory_iterator );

	$file_count = 0;
	foreach ( $iterator as $file_info ) {
		if ( ! $file_info->isFile() ) {
			continue;
		}

		// Don't upload .htaccess files
		if ( strpos( $file_info->getFilename(), '.htaccess' ) !== false ) {
			continue;
		}

		$file_path = $file_info->getPathname();
		$s3_key = str_replace( $wp_path . '/', '', $file_path );

		// Upload if missing
		if ( ! isset( $s3_key_set[ $s3_key ] ) ) {
			uploadToS3( get_s3_client(), $bucket, $file_path, $s3_key );
		} else {
			log( "Exists in S3: $s3_key" );
		}
	}

	if ( ! $file_count ) {
		log( "No files found in $directory" );
	}
}

if ( $blog_id ) {
	upload_site_directory( $blog_id );
	return;
}

upload_all_site_directories();

