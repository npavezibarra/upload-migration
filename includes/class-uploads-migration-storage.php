<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uploads_Migration_Storage {
	public const DIR_NAME = 'uploads-migration';

	public static function dir(): string {
		return trailingslashit( WP_CONTENT_DIR ) . self::DIR_NAME;
	}

	public static function url(): string {
		return content_url( '/' . self::DIR_NAME );
	}

	public static function exports_dir(): string {
		// Archives are stored directly under wp-content/uploads-migration/ as requested.
		return self::dir();
	}

	public static function imports_dir(): string {
		return trailingslashit( self::dir() ) . 'imports';
	}

	public static function states_dir(): string {
		return trailingslashit( self::dir() ) . 'states';
	}

	public static function log_path(): string {
		return trailingslashit( self::dir() ) . 'migration.log';
	}

	public static function ensure_storage_ready(): void {
		$dirs = array(
			self::dir(),
			self::imports_dir(),
			self::states_dir(),
		);

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		// Best-effort protection from direct browsing.
		$index_path = trailingslashit( self::dir() ) . 'index.php';
		if ( ! file_exists( $index_path ) ) {
			@file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_path = trailingslashit( self::dir() ) . '.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			@file_put_contents( $htaccess_path, "Deny from all\n" );
		}
	}

	public static function log( string $message, array $context = array() ): void {
		self::ensure_storage_ready();

		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . $message;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		$line .= "\n";

		@file_put_contents( self::log_path(), $line, FILE_APPEND );
	}

	public static function write_state( string $id, array $state ): bool {
		self::ensure_storage_ready();
		$path = self::state_path( $id );
		$json = wp_json_encode( $state, JSON_PRETTY_PRINT );
		if ( false === $json ) {
			return false;
		}
		return false !== @file_put_contents( $path, $json, LOCK_EX );
	}

	public static function read_state( string $id ): ?array {
		$path = self::state_path( $id );
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$raw = @file_get_contents( $path );
		if ( false === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public static function delete_state( string $id ): void {
		$path = self::state_path( $id );
		if ( file_exists( $path ) ) {
			@unlink( $path );
		}
	}

	public static function state_path( string $id ): string {
		return trailingslashit( self::states_dir() ) . 'state-' . sanitize_file_name( $id ) . '.json';
	}

	public static function new_id(): string {
		return wp_generate_password( 16, false, false );
	}
}
