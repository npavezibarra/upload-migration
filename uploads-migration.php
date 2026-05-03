<?php
/**
 * Plugin Name: Uploads Migration
 * Description: Export/import only the wp-content/uploads folder as an archive for migrations.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Politeia
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UPLOADS_MIGRATION_VERSION', '0.1.0' );
define( 'UPLOADS_MIGRATION_PLUGIN_FILE', __FILE__ );
define( 'UPLOADS_MIGRATION_PLUGIN_DIR', __DIR__ );

require_once UPLOADS_MIGRATION_PLUGIN_DIR . '/includes/class-uploads-migration-storage.php';
require_once UPLOADS_MIGRATION_PLUGIN_DIR . '/includes/class-uploads-migration-path.php';
require_once UPLOADS_MIGRATION_PLUGIN_DIR . '/includes/class-uploads-migration-exporter.php';
require_once UPLOADS_MIGRATION_PLUGIN_DIR . '/includes/class-uploads-migration-importer.php';
require_once UPLOADS_MIGRATION_PLUGIN_DIR . '/includes/class-uploads-migration-plugin.php';

add_action(
	'plugins_loaded',
	static function (): void {
		Uploads_Migration_Plugin::instance()->init();
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		Uploads_Migration_Storage::ensure_storage_ready();
	}
);

