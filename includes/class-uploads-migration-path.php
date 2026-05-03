<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uploads_Migration_Path {
	public static function uploads_base_dir(): string {
		$uploads = wp_get_upload_dir();
		return (string) $uploads['basedir'];
	}

	public static function normalize( string $path ): string {
		return wp_normalize_path( $path );
	}

	public static function is_safe_relative_path( string $relative ): bool {
		$relative = str_replace( '\\', '/', $relative );
		$relative = ltrim( $relative, '/' );

		// Disallow empty and disallow traversal/drive paths.
		if ( '' === $relative ) {
			return false;
		}
		if ( str_contains( $relative, "\0" ) ) {
			return false;
		}
		if ( preg_match( '#^[A-Za-z]:/#', $relative ) ) {
			return false;
		}

		$parts = explode( '/', $relative );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return false;
			}
		}

		return true;
	}

	public static function join_under( string $base_dir, string $relative ): ?string {
		$relative = str_replace( '\\', '/', $relative );
		$relative = ltrim( $relative, '/' );

		if ( ! self::is_safe_relative_path( $relative ) ) {
			return null;
		}

		$base_dir = rtrim( self::normalize( $base_dir ), '/' );
		$target   = self::normalize( $base_dir . '/' . $relative );

		if ( $target === $base_dir ) {
			return null;
		}
		if ( ! str_starts_with( $target . '/', $base_dir . '/' ) ) {
			return null;
		}

		return $target;
	}
}

