<?php
/**
 * ATSL_Core — Singleton bootstrap orchestrator.
 *
 * Responsibilities:
 *  - Activation: links table + capability for administrator / shop_manager
 *  - DB version check on admin_init
 *  - Loads the modules (slug rules, REST API, admin page) and the updater
 *
 * Deliberately no uninstall routine: printed short links must survive an
 * accidental delete + reinstall, so the table is kept.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATSL_Core {

	private static ?ATSL_Core $instance = null;

	/** @var string Current DB schema version */
	const DB_VERSION = '1';

	/** Capability that grants the dashboard and the REST API. */
	const CAP = 'atsl_manage_links';

	const ROLES = [ 'administrator', 'shop_manager' ];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'atsl', false, dirname( plugin_basename( ATSL_FILE ) ) . '/languages' );

		$this->load_modules();
		add_action( 'admin_init', [ $this, 'maybe_upgrade_db' ] );
	}

	// -------------------------------------------------------------------------
	// Activation
	// -------------------------------------------------------------------------

	public static function activate(): void {
		self::create_db_table();
		self::grant_capability();
		update_option( 'atsl_db_version', self::DB_VERSION, false );
	}

	public static function create_db_table(): void {
		global $wpdb;

		$table_name      = ATSL_Repo::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug          VARCHAR(64) NOT NULL,
			url           TEXT NOT NULL,
			url_hash      CHAR(40) NOT NULL,
			is_custom     TINYINT(1) NOT NULL DEFAULT 0,
			clicks        INT UNSIGNED NOT NULL DEFAULT 0,
			last_click_at DATETIME NULL DEFAULT NULL,
			created_by    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at    DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY url_hash (url_hash)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private static function grant_capability(): void {
		foreach ( self::ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
	}

	public function maybe_upgrade_db(): void {
		if ( get_option( 'atsl_db_version', '' ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	// -------------------------------------------------------------------------
	// Module loading
	// -------------------------------------------------------------------------

	private function load_modules(): void {
		require_once ATSL_DIR . 'includes/class-atsl-slug.php';
		require_once ATSL_DIR . 'includes/class-atsl-rest.php';

		ATSL_Slug::init();
		ATSL_Rest::init();

		if ( is_admin() ) {
			require_once ATSL_DIR . 'includes/class-atsl-admin.php';
			ATSL_Admin::init();
		}

		// The updater only matters where updates are checked or installed.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->init_update_checker();
		}
	}

	// -------------------------------------------------------------------------
	// Update checker (Plugin Update Checker v5)
	// -------------------------------------------------------------------------

	private function init_update_checker(): void {
		$puc_path = ATSL_DIR . 'vendor/plugin-update-checker/load-v5p6.php';
		if ( ! file_exists( $puc_path ) ) {
			return; // Library not present — fail silently.
		}

		require_once $puc_path;

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/Samsiani/arttime-short-links/',
			ATSL_FILE,
			'arttime-short-links'
		);

		// Use the .zip asset from each GitHub Release (includes vendor/) instead
		// of the raw source zipball.
		$checker->getVcsApi()->enableReleaseAssets();
	}
}
