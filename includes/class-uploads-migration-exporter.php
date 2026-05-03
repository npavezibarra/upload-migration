<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uploads_Migration_Exporter {
	public const MODE = 'export';

	public static function start(): array {
		Uploads_Migration_Storage::ensure_storage_ready();

		@ignore_user_abort( true );
		@set_time_limit( 0 );

		$id         = Uploads_Migration_Storage::new_id();
		$uploads_dir = Uploads_Migration_Path::uploads_base_dir();
		$uploads_dir = rtrim( Uploads_Migration_Path::normalize( $uploads_dir ), '/' );

		$archive_name = sprintf(
			'uploads-%s-%s.zip',
			gmdate( 'Ymd-His' ),
			sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site' )
		);
		$archive_path = trailingslashit( Uploads_Migration_Storage::exports_dir() ) . $archive_name;
		$manifest_path = trailingslashit( Uploads_Migration_Storage::states_dir() ) . 'manifest-' . $id . '.txt';

		Uploads_Migration_Storage::log( 'Export scan started', array( 'uploads_dir' => $uploads_dir, 'id' => $id ) );
		$scan = self::scan_uploads_to_manifest( $uploads_dir, $manifest_path );
		if ( ! $scan['ok'] ) {
			Uploads_Migration_Storage::log( 'Export scan failed', array( 'error' => $scan['error'] ) );
			return array(
				'ok'    => false,
				'error' => $scan['error'],
			);
		}
		Uploads_Migration_Storage::log(
			'Export scan completed',
			array(
				'id'          => $id,
				'total_files' => (int) $scan['total_files'],
				'total_bytes' => (int) $scan['total_bytes'],
			)
		);

		$zip_ok = self::create_empty_zip( $archive_path );
		if ( ! $zip_ok ) {
			Uploads_Migration_Storage::log( 'Failed to create zip', array( 'archive_path' => $archive_path ) );
			return array(
				'ok'    => false,
				'error' => 'Could not create archive. Check filesystem permissions and ZipArchive availability.',
			);
		}

		$state = array(
			'id'              => $id,
			'mode'            => self::MODE,
			'archive_type'    => 'zip',
			'archive_path'    => $archive_path,
			'manifest_path'   => $manifest_path,
			'manifest_offset' => 0,
			'uploads_dir'     => $uploads_dir,
			'total_files'     => (int) $scan['total_files'],
			'total_bytes'     => (int) $scan['total_bytes'],
			'processed_files' => 0,
			'processed_bytes' => 0,
			'started_at'      => time(),
			'completed'       => false,
			'errors'          => array(),
		);

		Uploads_Migration_Storage::write_state( $id, $state );
		Uploads_Migration_Storage::log( 'Export started', array( 'id' => $id, 'total_files' => $state['total_files'] ) );

		return array(
			'ok'    => true,
			'state' => $state,
		);
	}

	public static function batch( string $id, int $max_files = 200, float $time_budget_seconds = 8.0 ): array {
		@ignore_user_abort( true );
		@set_time_limit( 0 );

		$state = Uploads_Migration_Storage::read_state( $id );
		if ( ! is_array( $state ) || ( $state['mode'] ?? '' ) !== self::MODE ) {
			return array( 'ok' => false, 'error' => 'Invalid export state.' );
		}
		if ( ! empty( $state['completed'] ) ) {
			return array( 'ok' => true, 'state' => $state, 'done' => true );
		}

		$manifest_path = (string) ( $state['manifest_path'] ?? '' );
		$archive_path  = (string) ( $state['archive_path'] ?? '' );
		$uploads_dir   = (string) ( $state['uploads_dir'] ?? '' );

		if ( '' === $manifest_path || '' === $archive_path || '' === $uploads_dir ) {
			return array( 'ok' => false, 'error' => 'Export state is missing paths.' );
		}
		if ( ! file_exists( $manifest_path ) ) {
			return array( 'ok' => false, 'error' => 'Manifest file not found.' );
		}

		$zip = new ZipArchive();
		$zip_open = $zip->open( $archive_path, ZipArchive::CREATE );
		if ( true !== $zip_open ) {
			return array( 'ok' => false, 'error' => 'Could not open archive for writing.' );
		}

		$processed_this_batch = 0;
		$started_at = microtime( true );

		$fh = @fopen( $manifest_path, 'rb' );
		if ( ! is_resource( $fh ) ) {
			$zip->close();
			return array( 'ok' => false, 'error' => 'Could not read manifest.' );
		}

		$offset = (int) ( $state['manifest_offset'] ?? 0 );
		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}

		while ( $processed_this_batch < $max_files && ! feof( $fh ) ) {
			if ( ( microtime( true ) - $started_at ) >= $time_budget_seconds ) {
				break;
			}

			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			$relative = trim( $line );
			if ( '' === $relative ) {
				continue;
			}

			$src_path = Uploads_Migration_Path::join_under( $uploads_dir, $relative );
			if ( null === $src_path || ! is_file( $src_path ) ) {
				$state['errors'][] = 'Missing file: ' . $relative;
				Uploads_Migration_Storage::log( 'Export missing file', array( 'id' => $id, 'path' => $relative ) );
				$processed_this_batch++;
				continue;
			}

			// Avoid archiving symlinks to prevent exporting outside uploads.
			if ( is_link( $src_path ) ) {
				$state['errors'][] = 'Skipped symlink: ' . $relative;
				Uploads_Migration_Storage::log( 'Export skipped symlink', array( 'id' => $id, 'path' => $relative ) );
				$processed_this_batch++;
				continue;
			}

			$added = $zip->addFile( $src_path, $relative );
			if ( ! $added ) {
				$state['errors'][] = 'Failed to add: ' . $relative;
				Uploads_Migration_Storage::log( 'Export addFile failed', array( 'id' => $id, 'path' => $relative ) );
				$processed_this_batch++;
				continue;
			}

			$state['processed_files'] = (int) $state['processed_files'] + 1;
			$state['processed_bytes'] = (int) $state['processed_bytes'] + (int) filesize( $src_path );
			$processed_this_batch++;
		}

		$state['manifest_offset'] = (int) ftell( $fh );
		fclose( $fh );
		$zip->close();

		if ( $processed_this_batch > 0 ) {
			Uploads_Migration_Storage::log(
				'Export batch progress',
				array(
					'id'                 => $id,
					'processed_files'     => (int) $state['processed_files'],
					'processed_this_batch'=> (int) $processed_this_batch,
				)
			);
		}

		// If we reached EOF, mark completed.
		$done = self::manifest_is_complete( $manifest_path, (int) $state['manifest_offset'] );
		if ( $done ) {
			$state['completed'] = true;
			Uploads_Migration_Storage::log( 'Export completed', array( 'id' => $id, 'processed_files' => $state['processed_files'] ) );
		}

		Uploads_Migration_Storage::write_state( $id, $state );

		return array(
			'ok'    => true,
			'state' => $state,
			'done'  => $done,
		);
	}

	private static function create_empty_zip( string $archive_path ): bool {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$zip = new ZipArchive();
		$opened = $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			return false;
		}
		$zip->close();
		return true;
	}

	private static function scan_uploads_to_manifest( string $uploads_dir, string $manifest_path ): array {
		$uploads_dir = rtrim( Uploads_Migration_Path::normalize( $uploads_dir ), '/' );
		if ( '' === $uploads_dir || ! is_dir( $uploads_dir ) ) {
			return array( 'ok' => false, 'error' => 'Uploads directory not found.' );
		}

		$fh = @fopen( $manifest_path, 'wb' );
		if ( ! is_resource( $fh ) ) {
			return array( 'ok' => false, 'error' => 'Could not write manifest file.' );
		}

		$total_files = 0;
		$total_bytes = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$uploads_dir,
					FilesystemIterator::SKIP_DOTS
				),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info instanceof SplFileInfo ) {
					continue;
				}
				if ( ! $file_info->isFile() ) {
					continue;
				}

				$full_path = Uploads_Migration_Path::normalize( $file_info->getPathname() );
				if ( is_link( $full_path ) ) {
					continue;
				}

				$relative = ltrim( str_replace( $uploads_dir, '', $full_path ), '/' );
				$relative = str_replace( '\\', '/', $relative );
				if ( ! Uploads_Migration_Path::is_safe_relative_path( $relative ) ) {
					continue;
				}

				fwrite( $fh, $relative . "\n" );
				$total_files++;
				$total_bytes += (int) $file_info->getSize();
			}
		} catch ( Throwable $e ) {
			fclose( $fh );
			return array( 'ok' => false, 'error' => 'Scan error: ' . $e->getMessage() );
		}

		fclose( $fh );

		return array(
			'ok'          => true,
			'total_files' => $total_files,
			'total_bytes' => $total_bytes,
		);
	}

	private static function manifest_is_complete( string $manifest_path, int $offset ): bool {
		$size = @filesize( $manifest_path );
		if ( false === $size ) {
			return false;
		}
		return $offset >= (int) $size;
	}
}
