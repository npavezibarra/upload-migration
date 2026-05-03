<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uploads_Migration_Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		add_action( 'wp_ajax_uploads_migration_start_export', array( $this, 'ajax_start_export' ) );
		add_action( 'wp_ajax_uploads_migration_export_batch', array( $this, 'ajax_export_batch' ) );
		add_action( 'wp_ajax_uploads_migration_upload_archive', array( $this, 'ajax_upload_archive' ) );
		add_action( 'wp_ajax_uploads_migration_start_import', array( $this, 'ajax_start_import' ) );
		add_action( 'wp_ajax_uploads_migration_import_batch', array( $this, 'ajax_import_batch' ) );

		add_action( 'admin_post_uploads_migration_download', array( $this, 'handle_download' ) );
	}

	public function register_admin_page(): void {
		add_management_page(
			'Uploads Migration',
			'Uploads Migration',
			'manage_options',
			'uploads-migration',
			array( $this, 'render_admin_page' )
		);
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'tools_page_uploads-migration' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'uploads-migration-admin',
			plugins_url( 'assets/admin.js', UPLOADS_MIGRATION_PLUGIN_FILE ),
			array(),
			UPLOADS_MIGRATION_VERSION,
			true
		);

		wp_localize_script(
			'uploads-migration-admin',
			'UploadsMigration',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'uploads_migration_nonce' ),
				'downloadNonce' => wp_create_nonce( 'uploads_migration_download' ),
			)
		);
	}

	private function ensure_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'Forbidden' ), 403 );
		}
	}

	private function verify_nonce_or_die(): void {
		// Avoid PHP notices polluting JSON output in admin-ajax responses.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			@ini_set( 'display_errors', '0' );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'uploads_migration_nonce' ) ) {
			wp_send_json_error( array( 'error' => 'Invalid nonce.' ), 403 );
		}
	}

	public function ajax_start_export(): void {
		$this->ensure_admin();
		$this->verify_nonce_or_die();

		$result = Uploads_Migration_Exporter::start();
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'error' => (string) ( $result['error'] ?? 'Export failed.' ) ) );
		}

		wp_send_json_success(
			array(
				'state' => $result['state'],
			)
		);
	}

	public function ajax_export_batch(): void {
		$this->ensure_admin();
		$this->verify_nonce_or_die();

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( array( 'error' => 'Missing export id.' ) );
		}

		$result = Uploads_Migration_Exporter::batch( $id );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'error' => (string) ( $result['error'] ?? 'Export batch failed.' ) ) );
		}

		$state = $result['state'];
		$download_url = '';
		if ( ! empty( $result['done'] ) && ! empty( $state['archive_path'] ) ) {
			$download_url = $this->download_url_for_archive( (string) $state['archive_path'] );
		}

		wp_send_json_success(
			array(
				'state'       => $state,
				'done'        => ! empty( $result['done'] ),
				'downloadUrl' => $download_url,
			)
		);
	}

	public function ajax_upload_archive(): void {
		$this->ensure_admin();
		$this->verify_nonce_or_die();

		if ( empty( $_FILES['archive'] ) || ! is_array( $_FILES['archive'] ) ) {
			wp_send_json_error( array( 'error' => 'Missing file.' ) );
		}

		$result = Uploads_Migration_Importer::accept_upload( $_FILES['archive'] );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'error' => (string) ( $result['error'] ?? 'Upload failed.' ) ) );
		}

		wp_send_json_success(
			array(
				'token' => $result['token'],
			)
		);
	}

	public function ajax_start_import(): void {
		$this->ensure_admin();
		$this->verify_nonce_or_die();

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$overwrite = ! empty( $_POST['overwrite'] );

		if ( '' === $token ) {
			wp_send_json_error( array( 'error' => 'Missing upload token.' ) );
		}

		$result = Uploads_Migration_Importer::start_from_token( $token, $overwrite );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'error' => (string) ( $result['error'] ?? 'Import start failed.' ) ) );
		}

		wp_send_json_success(
			array(
				'state' => $result['state'],
			)
		);
	}

	public function ajax_import_batch(): void {
		$this->ensure_admin();
		$this->verify_nonce_or_die();

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( array( 'error' => 'Missing import id.' ) );
		}

		$result = Uploads_Migration_Importer::batch( $id );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'error' => (string) ( $result['error'] ?? 'Import batch failed.' ) ) );
		}

		wp_send_json_success(
			array(
				'state' => $result['state'],
				'done'  => ! empty( $result['done'] ),
			)
		);
	}

	private function download_url_for_archive( string $archive_path ): string {
		$file = basename( $archive_path );
		return add_query_arg(
			array(
				'action' => 'uploads_migration_download',
				'file'   => rawurlencode( $file ),
				'_wpnonce' => wp_create_nonce( 'uploads_migration_download:' . $file ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public function handle_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		if ( '' === $file ) {
			wp_die( 'Missing file', 400 );
		}
		if ( ! str_ends_with( strtolower( $file ), '.zip' ) || ! str_starts_with( $file, 'uploads-' ) ) {
			wp_die( 'Invalid file', 400 );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'uploads_migration_download:' . $file ) ) {
			wp_die( 'Invalid nonce', 403 );
		}

		$path = trailingslashit( Uploads_Migration_Storage::exports_dir() ) . $file;
		if ( ! file_exists( $path ) ) {
			wp_die( 'File not found', 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		$fp = fopen( $path, 'rb' );
		if ( is_resource( $fp ) ) {
			while ( ! feof( $fp ) ) {
				$chunk = fread( $fp, 1024 * 1024 );
				if ( false === $chunk ) {
					break;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				flush();
			}
			fclose( $fp );
		}
		exit;
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		Uploads_Migration_Storage::ensure_storage_ready();
		$uploads_dir = esc_html( Uploads_Migration_Path::uploads_base_dir() );
		$migration_dir = esc_html( Uploads_Migration_Storage::dir() );
		$log_path = esc_html( Uploads_Migration_Storage::log_path() );
		?>
		<div class="wrap">
			<h1>Uploads Migration</h1>
			<p><strong>Uploads directory:</strong> <?php echo $uploads_dir; ?></p>
			<p><strong>Migration storage:</strong> <?php echo $migration_dir; ?></p>
			<p><strong>Log file:</strong> <?php echo $log_path; ?></p>

			<hr />

			<h2>Export (Local)</h2>
			<p>Creates a zip archive of <code>wp-content/uploads</code> and stores it in <code>wp-content/uploads-migration/</code>.</p>
			<p>
				<button class="button button-primary" id="uploads-migration-start-export">Start Export</button>
				<span id="uploads-migration-export-status" style="margin-left:10px;"></span>
			</p>
			<div id="uploads-migration-export-progress" style="max-width: 600px;"></div>
			<p id="uploads-migration-export-download" style="display:none;"></p>

			<hr />

			<h2>Import (Live)</h2>
			<p>Uploads an archive and extracts it into <code>wp-content/uploads</code>. Existing files are skipped by default.</p>
			<p>
				<label>
					<input type="checkbox" id="uploads-migration-overwrite" />
					Overwrite existing files
				</label>
			</p>
			<p>
				<input type="file" id="uploads-migration-archive" accept=".zip,.tar.gz" />
				<button class="button button-primary" id="uploads-migration-start-import">Upload &amp; Start Import</button>
				<span id="uploads-migration-import-status" style="margin-left:10px;"></span>
			</p>
			<div id="uploads-migration-import-progress" style="max-width: 600px;"></div>
			<div id="uploads-migration-import-summary" style="margin-top:10px;"></div>
		</div>
		<?php
	}
}
