<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uploads_Migration_Importer {
	public const MODE = 'import';

	public static function accept_upload( array $file ): array {
		Uploads_Migration_Storage::ensure_storage_ready();

		if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
			return array( 'ok' => false, 'error' => 'No file uploaded.' );
		}

		$original_name = (string) $file['name'];
		$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$is_tar_gz = str_ends_with( strtolower( $original_name ), '.tar.gz' );
		$is_zip = 'zip' === $ext;

		if ( ! $is_zip && ! $is_tar_gz ) {
			return array( 'ok' => false, 'error' => 'Invalid archive type. Upload a .zip or .tar.gz file.' );
		}

		if ( $is_zip && ! class_exists( 'ZipArchive' ) ) {
			return array( 'ok' => false, 'error' => 'ZipArchive is not available on this server.' );
		}

		$safe_name = sanitize_file_name( $original_name );
		if ( '' === $safe_name ) {
			$safe_name = 'uploads-archive.' . ( $is_zip ? 'zip' : 'tar.gz' );
		}

		$dest = trailingslashit( Uploads_Migration_Storage::imports_dir() ) . gmdate( 'Ymd-His' ) . '-' . $safe_name;
		$moved = @move_uploaded_file( (string) $file['tmp_name'], $dest );
		if ( ! $moved ) {
			return array( 'ok' => false, 'error' => 'Could not save uploaded file.' );
		}

		@chmod( $dest, 0644 );
		Uploads_Migration_Storage::log( 'Archive uploaded', array( 'path' => $dest ) );

		$token = Uploads_Migration_Storage::new_id();
		set_transient(
			'uploads_migration_import_token_' . $token,
			array(
				'user_id'      => get_current_user_id(),
				'archive_path' => $dest,
				'created_at'   => time(),
			),
			HOUR_IN_SECONDS
		);

		return array(
			'ok'    => true,
			'token' => $token,
		);
	}

	public static function start_from_token( string $token, bool $overwrite ): array {
		$data = get_transient( 'uploads_migration_import_token_' . $token );
		if ( ! is_array( $data ) || (int) ( $data['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return array( 'ok' => false, 'error' => 'Upload token expired or invalid.' );
		}

		$archive_path = (string) ( $data['archive_path'] ?? '' );
		if ( '' === $archive_path || ! file_exists( $archive_path ) ) {
			return array( 'ok' => false, 'error' => 'Uploaded archive not found.' );
		}

		@ignore_user_abort( true );
		@set_time_limit( 0 );

		$id = Uploads_Migration_Storage::new_id();

		$type = self::detect_archive_type( $archive_path );
		if ( null === $type ) {
			return array( 'ok' => false, 'error' => 'Unsupported archive type.' );
		}

		$total_entries = 0;
		$manifest_path = '';
		if ( 'zip' === $type ) {
			$zip = new ZipArchive();
			$opened = $zip->open( $archive_path );
			if ( true !== $opened ) {
				return array( 'ok' => false, 'error' => 'Could not open zip archive.' );
			}
			$total_entries = (int) $zip->numFiles;
			$zip->close();
		}
		if ( 'tar.gz' === $type ) {
			$manifest_path = trailingslashit( Uploads_Migration_Storage::states_dir() ) . 'import-manifest-' . $id . '.txt';
			$manifest = self::scan_tar_to_manifest( $archive_path, $manifest_path );
			if ( ! $manifest['ok'] ) {
				return array( 'ok' => false, 'error' => (string) $manifest['error'] );
			}
			$total_entries = (int) $manifest['total_entries'];
		}

		$state = array(
			'id'            => $id,
			'mode'          => self::MODE,
			'archive_type'  => $type,
			'archive_path'  => $archive_path,
			'uploads_dir'   => rtrim( Uploads_Migration_Path::normalize( Uploads_Migration_Path::uploads_base_dir() ), '/' ),
			'overwrite'     => $overwrite,
			'started_at'    => time(),
			'completed'     => false,
			'current_index' => 0,
			'total_entries' => $total_entries,
			'manifest_path' => $manifest_path,
			'manifest_offset' => 0,
			'summary'       => array(
				'imported'    => 0,
				'skipped'     => 0,
				'overwritten' => 0,
				'errors'      => 0,
			),
			'errors'        => array(),
		);

		Uploads_Migration_Storage::write_state( $id, $state );
		Uploads_Migration_Storage::log( 'Import started', array( 'id' => $id, 'overwrite' => $overwrite, 'type' => $type ) );

		return array( 'ok' => true, 'state' => $state );
	}

	public static function batch( string $id, int $max_entries = 120, float $time_budget_seconds = 8.0 ): array {
		@ignore_user_abort( true );
		@set_time_limit( 0 );

		$state = Uploads_Migration_Storage::read_state( $id );
		if ( ! is_array( $state ) || ( $state['mode'] ?? '' ) !== self::MODE ) {
			return array( 'ok' => false, 'error' => 'Invalid import state.' );
		}
		if ( ! empty( $state['completed'] ) ) {
			return array( 'ok' => true, 'state' => $state, 'done' => true );
		}

		$type        = (string) ( $state['archive_type'] ?? '' );
		$archive_path = (string) ( $state['archive_path'] ?? '' );
		$uploads_dir = (string) ( $state['uploads_dir'] ?? '' );
		$overwrite   = ! empty( $state['overwrite'] );

		if ( '' === $type || '' === $archive_path || '' === $uploads_dir ) {
			return array( 'ok' => false, 'error' => 'Import state is missing paths.' );
		}

		$started_at = microtime( true );
		$processed = 0;

		if ( 'zip' === $type ) {
			$zip = new ZipArchive();
			$opened = $zip->open( $archive_path );
			if ( true !== $opened ) {
				return array( 'ok' => false, 'error' => 'Could not open zip archive.' );
			}

			$num_files = (int) $zip->numFiles;
			$index     = (int) ( $state['current_index'] ?? 0 );

			while ( $index < $num_files && $processed < $max_entries ) {
				if ( ( microtime( true ) - $started_at ) >= $time_budget_seconds ) {
					break;
				}

				$name = (string) $zip->getNameIndex( $index );
				$index++;
				$processed++;

				if ( '' === $name || str_ends_with( $name, '/' ) ) {
					continue;
				}

				$relative = ltrim( str_replace( '\\', '/', $name ), '/' );
				if ( ! Uploads_Migration_Path::is_safe_relative_path( $relative ) ) {
					$state['summary']['errors']++;
					$state['errors'][] = 'Unsafe path in archive: ' . $name;
					continue;
				}

				$dest = Uploads_Migration_Path::join_under( $uploads_dir, $relative );
				if ( null === $dest ) {
					$state['summary']['errors']++;
					$state['errors'][] = 'Path traversal blocked: ' . $name;
					continue;
				}

				$dest_dir = dirname( $dest );
				if ( ! is_dir( $dest_dir ) ) {
					wp_mkdir_p( $dest_dir );
					@chmod( $dest_dir, 0755 );
				}

				$existed = file_exists( $dest );
				if ( $existed && ! $overwrite ) {
					$state['summary']['skipped']++;
					continue;
				}

				$stream = $zip->getStream( $name );
				if ( false === $stream ) {
					$state['summary']['errors']++;
					$state['errors'][] = 'Could not read entry: ' . $name;
					continue;
				}

				$fp = @fopen( $dest, 'wb' );
				if ( false === $fp ) {
					fclose( $stream );
					$state['summary']['errors']++;
					$state['errors'][] = 'Could not write file: ' . $relative;
					continue;
				}

				while ( ! feof( $stream ) ) {
					$chunk = fread( $stream, 1024 * 1024 );
					if ( false === $chunk ) {
						break;
					}
					fwrite( $fp, $chunk );
				}

				fclose( $fp );
				fclose( $stream );

				$stat = $zip->statName( $name );
				if ( is_array( $stat ) && ! empty( $stat['mtime'] ) ) {
					@touch( $dest, (int) $stat['mtime'] );
				}

				@chmod( $dest, 0644 );

				if ( $existed && $overwrite ) {
					$state['summary']['overwritten']++;
				} else {
					$state['summary']['imported']++;
				}
			}

			$zip->close();
			$state['current_index'] = $index;

			$done = $index >= $num_files;
			if ( $done ) {
				$state['completed'] = true;
				Uploads_Migration_Storage::log( 'Import completed', array( 'id' => $id, 'summary' => $state['summary'] ) );
			}
			if ( $processed > 0 ) {
				Uploads_Migration_Storage::log(
					'Import batch progress',
					array(
						'id'            => $id,
						'type'          => 'zip',
						'current_index' => (int) $state['current_index'],
						'summary'       => $state['summary'],
					)
				);
			}

			Uploads_Migration_Storage::write_state( $id, $state );

			return array(
				'ok'    => true,
				'state' => $state,
				'done'  => $done,
			);
		}

		// tar.gz via manifest for indexed batching.
		if ( 'tar.gz' === $type ) {
			$manifest_path = (string) ( $state['manifest_path'] ?? '' );
			if ( '' === $manifest_path || ! file_exists( $manifest_path ) ) {
				return array( 'ok' => false, 'error' => 'Import manifest not found.' );
			}

			$fh = @fopen( $manifest_path, 'rb' );
			if ( ! is_resource( $fh ) ) {
				return array( 'ok' => false, 'error' => 'Could not read import manifest.' );
			}

			$offset = (int) ( $state['manifest_offset'] ?? 0 );
			if ( $offset > 0 ) {
				fseek( $fh, $offset );
			}

			while ( $processed < $max_entries && ! feof( $fh ) ) {
				if ( ( microtime( true ) - $started_at ) >= $time_budget_seconds ) {
					break;
				}

				$line = fgets( $fh );
				if ( false === $line ) {
					break;
				}

				$processed++;
				$relative = trim( $line );
				if ( '' === $relative ) {
					continue;
				}

				if ( ! Uploads_Migration_Path::is_safe_relative_path( $relative ) ) {
					$state['summary']['errors']++;
					$state['errors'][] = 'Unsafe path in archive: ' . $relative;
					continue;
				}

				$dest = Uploads_Migration_Path::join_under( $uploads_dir, $relative );
				if ( null === $dest ) {
					$state['summary']['errors']++;
					$state['errors'][] = 'Path traversal blocked: ' . $relative;
					continue;
				}

				$dest_dir = dirname( $dest );
				if ( ! is_dir( $dest_dir ) ) {
					wp_mkdir_p( $dest_dir );
					@chmod( $dest_dir, 0755 );
				}

				$existed = file_exists( $dest );
				if ( $existed && ! $overwrite ) {
					$state['summary']['skipped']++;
					continue;
				}

				$src = 'phar://' . $archive_path . '/' . $relative;
				$in  = @fopen( $src, 'rb' );
				if ( false === $in ) {
					$state['summary']['errors']++;
					$state['errors'][] = 'Could not read entry: ' . $relative;
					continue;
				}
				$out = @fopen( $dest, 'wb' );
				if ( false === $out ) {
					fclose( $in );
					$state['summary']['errors']++;
					$state['errors'][] = 'Could not write file: ' . $relative;
					continue;
				}

				while ( ! feof( $in ) ) {
					$chunk = fread( $in, 1024 * 1024 );
					if ( false === $chunk ) {
						break;
					}
					fwrite( $out, $chunk );
				}

				fclose( $out );
				fclose( $in );

				@chmod( $dest, 0644 );
				$mtime = @filemtime( $src );
				if ( false !== $mtime ) {
					@touch( $dest, (int) $mtime );
				}

				if ( $existed && $overwrite ) {
					$state['summary']['overwritten']++;
				} else {
					$state['summary']['imported']++;
				}

				$state['current_index'] = (int) $state['current_index'] + 1;
			}

			$state['manifest_offset'] = (int) ftell( $fh );
			fclose( $fh );

			$done = self::manifest_is_complete( $manifest_path, (int) $state['manifest_offset'] );
			if ( $done ) {
				$state['completed'] = true;
				Uploads_Migration_Storage::log( 'Import completed', array( 'id' => $id, 'summary' => $state['summary'] ) );
			}
			if ( $processed > 0 ) {
				Uploads_Migration_Storage::log(
					'Import batch progress',
					array(
						'id'            => $id,
						'type'          => 'tar.gz',
						'current_index' => (int) $state['current_index'],
						'summary'       => $state['summary'],
					)
				);
			}

			Uploads_Migration_Storage::write_state( $id, $state );

			return array(
				'ok'    => true,
				'state' => $state,
				'done'  => $done,
			);
		}

		return array( 'ok' => false, 'error' => 'Unsupported archive type.' );
	}

	private static function detect_archive_type( string $archive_path ): ?string {
		$lower = strtolower( $archive_path );
		if ( str_ends_with( $lower, '.zip' ) ) {
			return 'zip';
		}
		if ( str_ends_with( $lower, '.tar.gz' ) ) {
			return 'tar.gz';
		}
		return null;
	}

	private static function scan_tar_to_manifest( string $archive_path, string $manifest_path ): array {
		$fh = @fopen( $manifest_path, 'wb' );
		if ( ! is_resource( $fh ) ) {
			return array( 'ok' => false, 'error' => 'Could not write import manifest.' );
		}

		$total_entries = 0;
		try {
			$phar = new PharData( $archive_path );
			$iterator = new RecursiveIteratorIterator( $phar, RecursiveIteratorIterator::LEAVES_ONLY );
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof PharFileInfo ) {
					continue;
				}
				if ( $file->isDir() ) {
					continue;
				}
				$relative = (string) $file->getRelativePathname();
				$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
				if ( ! Uploads_Migration_Path::is_safe_relative_path( $relative ) ) {
					continue;
				}
				fwrite( $fh, $relative . "\n" );
				$total_entries++;
			}
		} catch ( Throwable $e ) {
			fclose( $fh );
			return array( 'ok' => false, 'error' => 'Scan error: ' . $e->getMessage() );
		}

		fclose( $fh );
		return array( 'ok' => true, 'total_entries' => $total_entries );
	}

	private static function manifest_is_complete( string $manifest_path, int $offset ): bool {
		$size = @filesize( $manifest_path );
		if ( false === $size ) {
			return false;
		}
		return $offset >= (int) $size;
	}
}
