<?php
/**
 * ATSL_Admin — the "მოკლე ბმულები" dashboard page.
 *
 * Markup only; assets/admin.js drives it through the REST API. The first page
 * of links is embedded in the page so the table shows without a request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATSL_Admin {

	const PAGE = 'atsl';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function add_menu(): void {
		add_menu_page(
			__( 'მოკლე ბმულები', 'atsl' ),
			__( 'მოკლე ბმულები', 'atsl' ),
			ATSL_Core::CAP,
			self::PAGE,
			[ __CLASS__, 'render' ],
			'dashicons-admin-links',
			58
		);
	}

	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'atsl-admin', ATSL_URL . 'assets/admin.css', [], ATSL_VERSION );
		wp_enqueue_script( 'atsl-admin', ATSL_URL . 'assets/admin.js', [], ATSL_VERSION, true );

		$data = [
			'api'     => esc_url_raw( rest_url( ATSL_Rest::NS ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'base'    => ATSL_Rest::display_base(),
			'initial' => ATSL_Rest::list_payload( '', 1 ),
			'i18n'    => [
				'created'     => __( 'მზადაა — დაკოპირდა', 'atsl' ),
				'createdNoCp' => __( 'მზადაა', 'atsl' ),
				'existing'    => __( 'ეს ბმული უკვე შემოკლებულია — დაკოპირდა', 'atsl' ),
				'copied'      => __( 'დაკოპირდა', 'atsl' ),
				'copy'        => __( 'კოპირება', 'atsl' ),
				'open'        => __( 'გახსნა', 'atsl' ),
				'edit'        => __( 'სად მიდის — შეცვლა', 'atsl' ),
				'save'        => __( 'შენახვა', 'atsl' ),
				'cancel'      => __( 'გაუქმება', 'atsl' ),
				'delete'      => __( 'წაშლა', 'atsl' ),
				'confirmDel'  => __( 'წავშალო %s? ამ ბმულზე გადასვლა აღარ იმუშავებს.', 'atsl' ),
				'deleted'     => __( 'წაიშალა', 'atsl' ),
				'saved'       => __( 'შეინახა', 'atsl' ),
				'checking'    => __( 'მოწმდება…', 'atsl' ),
				'free'        => __( 'თავისუფალია', 'atsl' ),
				'working'     => __( 'იქმნება…', 'atsl' ),
				'empty'       => __( 'ჯერ ბმული არ გაქვთ — ჩასვით პირველი ზემოთ.', 'atsl' ),
				'noResults'   => __( 'ვერაფერი მოიძებნა.', 'atsl' ),
				'total'       => __( 'სულ: %d', 'atsl' ),
				'pageOf'      => __( 'გვ. %1$d / %2$d', 'atsl' ),
				'prev'        => __( '‹ წინა', 'atsl' ),
				'next'        => __( 'შემდეგი ›', 'atsl' ),
				'custom'      => __( 'საკუთარი', 'atsl' ),
				'clicks'      => __( 'კლიკები', 'atsl' ),
				'error'       => __( 'შეცდომა, სცადეთ თავიდან.', 'atsl' ),
			],
		];

		wp_add_inline_script( 'atsl-admin', 'window.ATSL = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	public static function render(): void {
		if ( ! current_user_can( ATSL_Core::CAP ) ) {
			return;
		}
		$base = ATSL_Rest::display_base();
		?>
		<div class="wrap atsl">
			<h1><?php esc_html_e( 'მოკლე ბმულები', 'atsl' ); ?></h1>

			<div class="atsl-card">
				<label class="atsl-label" for="atsl-url"><?php esc_html_e( 'ჩასვით ნებისმიერი ბმული და დააჭირეთ „შექმნა“-ს — მოკლე ბმული დაკოპირდება', 'atsl' ); ?></label>
				<div class="atsl-create-row">
					<input id="atsl-url" class="atsl-url" type="text" inputmode="url" autocomplete="off" spellcheck="false" autofocus
						placeholder="https://…">
					<button id="atsl-create" type="button" class="button button-primary atsl-create"><?php esc_html_e( 'შექმნა', 'atsl' ); ?></button>
				</div>

				<div class="atsl-custom">
					<label for="atsl-slug"><?php esc_html_e( 'საკუთარი ბოლოსართი (არასავალდებულო):', 'atsl' ); ?></label>
					<span class="atsl-slug-field">
						<span class="atsl-prefix"><?php echo esc_html( $base ); ?></span><input id="atsl-slug" type="text" autocomplete="off" spellcheck="false" maxlength="64" placeholder="my-url">
					</span>
					<span id="atsl-slug-status" class="atsl-slug-status" aria-live="polite"></span>
				</div>
				<p class="atsl-hint"><?php esc_html_e( 'ბოლოსართის ცარიელად დატოვების შემთხვევაში შეიქმნება ავტომატური კოდი (მაგ. k7m3p).', 'atsl' ); ?></p>

				<div id="atsl-result" class="atsl-result" aria-live="polite" hidden></div>
			</div>

			<div class="atsl-toolbar">
				<input id="atsl-search" type="search" placeholder="<?php esc_attr_e( 'ძებნა ბოლოსართით ან ბმულით…', 'atsl' ); ?>">
				<span id="atsl-count" class="atsl-count"></span>
			</div>

			<table class="widefat striped atsl-table">
				<thead>
					<tr>
						<th class="col-short"><?php esc_html_e( 'მოკლე ბმული', 'atsl' ); ?></th>
						<th class="col-dest"><?php esc_html_e( 'სად მიდის', 'atsl' ); ?></th>
						<th class="col-num"><?php esc_html_e( 'კლიკები', 'atsl' ); ?></th>
						<th class="col-date"><?php esc_html_e( 'ბოლო კლიკი', 'atsl' ); ?></th>
						<th class="col-date"><?php esc_html_e( 'შეიქმნა', 'atsl' ); ?></th>
						<th class="col-actions"><span class="screen-reader-text"><?php esc_html_e( 'მოქმედებები', 'atsl' ); ?></span></th>
					</tr>
				</thead>
				<tbody id="atsl-rows"></tbody>
			</table>

			<div id="atsl-pager" class="atsl-pager"></div>
			<div id="atsl-toast" class="atsl-toast" role="status" hidden></div>
		</div>
		<?php
	}
}
