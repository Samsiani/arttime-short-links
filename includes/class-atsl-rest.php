<?php
/**
 * ATSL_Rest — REST API behind the dashboard (namespace atsl/v1).
 *
 *  GET    /links?search=&page=   list (newest first, 50 per page)
 *  POST   /links {url, slug?}    create — automatic code, or the custom ending
 *  PATCH  /links/{id} {url}      change destination (the ending never changes: it may be printed)
 *  DELETE /links/{id}
 *  GET    /check?slug=           is this ending free? + reason when not
 *
 * Everything requires ATSL_Core::CAP plus the wp_rest nonce. /wp-json is
 * already bypassed by the Cloudflare cache rules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATSL_Rest {

	const NS       = 'atsl/v1';
	const PER_PAGE = 50;

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/links',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'list_links' ],
					'permission_callback' => [ __CLASS__, 'can_manage' ],
					'args'                => [
						'search' => [ 'type' => 'string', 'default' => '' ],
						'page'   => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'create_link' ],
					'permission_callback' => [ __CLASS__, 'can_manage' ],
					'args'                => [
						'url'  => [ 'type' => 'string', 'required' => true ],
						'slug' => [ 'type' => 'string', 'default' => '' ],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/links/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'update_link' ],
					'permission_callback' => [ __CLASS__, 'can_manage' ],
					'args'                => [
						'url' => [ 'type' => 'string', 'required' => true ],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ __CLASS__, 'delete_link' ],
					'permission_callback' => [ __CLASS__, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/check',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'check_slug' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'args'                => [
					'slug' => [ 'type' => 'string', 'required' => true ],
				],
			]
		);
	}

	public static function can_manage(): bool {
		return current_user_can( ATSL_Core::CAP );
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	public static function list_links( WP_REST_Request $request ) {
		$search = trim( sanitize_text_field( (string) $request['search'] ) );
		return rest_ensure_response( self::list_payload( $search, (int) $request['page'] ) );
	}

	public static function create_link( WP_REST_Request $request ) {
		$url = self::clean_url( (string) $request['url'] );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$wanted = trim( (string) $request['slug'] );
		if ( '' !== $wanted ) {
			$slug  = ATSL_Slug::normalize( $wanted );
			$check = ATSL_Slug::check( $slug );
			if ( ! $check['available'] ) {
				return new WP_Error( 'atsl_slug_taken', $check['reason'], [ 'status' => 409 ] );
			}
			$is_custom = true;
		} else {
			// Same link pasted again → hand back the one it already has.
			$existing = ATSL_Repo::find_auto_by_url( $url );
			if ( $existing ) {
				return rest_ensure_response( self::format( $existing ) + [ 'existing' => true ] );
			}
			$slug = ATSL_Slug::generate();
			if ( null === $slug ) {
				return new WP_Error( 'atsl_no_code', __( 'კოდის შექმნა ვერ მოხერხდა, სცადეთ თავიდან.', 'atsl' ), [ 'status' => 500 ] );
			}
			$is_custom = false;
		}

		$id = ATSL_Repo::insert( $slug, $url, $is_custom, get_current_user_id() );
		if ( ! $id ) {
			return new WP_Error( 'atsl_slug_taken', __( 'დაკავებულია — ასეთი მოკლე ბმული უკვე არსებობს.', 'atsl' ), [ 'status' => 409 ] );
		}

		$response = rest_ensure_response( self::format( ATSL_Repo::find( $id ) ) + [ 'existing' => false ] );
		$response->set_status( 201 );
		return $response;
	}

	public static function update_link( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! ATSL_Repo::find( $id ) ) {
			return self::not_found();
		}

		$url = self::clean_url( (string) $request['url'] );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		ATSL_Repo::update_url( $id, $url );
		return rest_ensure_response( self::format( ATSL_Repo::find( $id ) ) );
	}

	public static function delete_link( WP_REST_Request $request ) {
		if ( ! ATSL_Repo::delete( (int) $request['id'] ) ) {
			return self::not_found();
		}
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	public static function check_slug( WP_REST_Request $request ) {
		$slug = ATSL_Slug::normalize( (string) $request['slug'] );
		return rest_ensure_response( [ 'slug' => $slug ] + ATSL_Slug::check( $slug ) );
	}

	// -------------------------------------------------------------------------
	// Shared with the admin page (first page is embedded, no extra request)
	// -------------------------------------------------------------------------

	public static function list_payload( string $search, int $page ): array {
		$page   = max( 1, $page );
		$result = ATSL_Repo::paginate( $search, $page, self::PER_PAGE );

		// Flag links whose path got taken later (e.g. a new plugin route).
		$warnings = $result['rows'] ? ATSL_Slug::conflicts( array_column( $result['rows'], 'slug' ) ) : [];

		$items = [];
		foreach ( $result['rows'] as $row ) {
			$items[] = self::format( $row, $warnings[ $row['slug'] ] ?? '' );
		}

		return [
			'items' => $items,
			'total' => $result['total'],
			'page'  => $page,
			'pages' => max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) ),
		];
	}

	public static function format( array $row, string $warning = '' ): array {
		$base    = untrailingslashit( (string) get_option( 'home' ) );
		$display = rawurldecode( $row['url'] );
		if ( '' === wp_check_invalid_utf8( $display ) ) {
			$display = $row['url'];
		}

		return [
			'id'            => (int) $row['id'],
			'slug'          => $row['slug'],
			'short_url'     => $base . '/' . $row['slug'],
			'short_display' => self::display_base() . $row['slug'],
			'url'           => $row['url'],
			'url_display'   => $display,
			'is_custom'     => (bool) $row['is_custom'],
			'clicks'        => (int) $row['clicks'],
			'last_click'    => $row['last_click_at'] ? wp_date( 'd.m.Y H:i', strtotime( $row['last_click_at'] . ' UTC' ) ) : '',
			'created'       => wp_date( 'd.m.Y', strtotime( $row['created_at'] . ' UTC' ) ),
			'warning'       => $warning,
		];
	}

	/** "arttime.ge/" — the part shown in front of every ending. */
	public static function display_base(): string {
		return preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( (string) get_option( 'home' ) ) ) . '/';
	}

	// -------------------------------------------------------------------------
	// Destination validation
	// -------------------------------------------------------------------------

	/** @return string|WP_Error Clean http(s) URL, non-ASCII percent-encoded. */
	private static function clean_url( string $raw ) {
		$url = trim( $raw );
		if ( '' === $url ) {
			return self::bad_url( __( 'ჩასვით ბმული.', 'atsl' ) );
		}

		// "instagram.com/arttime" → https://instagram.com/arttime
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || false === strpos( $host, '.' ) ) {
			return self::bad_url( __( 'ეს არ ჰგავს ბმულს. ჩასვით სრული მისამართი, მაგ. https://…', 'atsl' ) );
		}

		$clean = esc_url_raw( ATSL_Repo::encode_non_ascii( $url ), [ 'http', 'https' ] );
		if ( '' === $clean || strlen( $clean ) > 2048 ) {
			return self::bad_url( __( 'ბმული არასწორია ან ძალიან გრძელია.', 'atsl' ) );
		}

		// A short link pointing at another short link would loop or chain.
		$home = preg_replace( '/^www\./', '', strtolower( (string) parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) ) );
		if ( preg_replace( '/^www\./', '', $host ) === $home
			&& preg_match( ATSL_Redirect::PATH_PATTERN, (string) ( $parts['path'] ?? '' ), $m )
			&& ATSL_Repo::slug_exists( strtolower( $m[1] ) ) ) {
			return self::bad_url( __( 'ეს უკვე მოკლე ბმულია — ჩასვით სრული მისამართი.', 'atsl' ) );
		}

		return $clean;
	}

	private static function bad_url( string $message ): WP_Error {
		return new WP_Error( 'atsl_bad_url', $message, [ 'status' => 400 ] );
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'atsl_not_found', __( 'ბმული ვერ მოიძებნა.', 'atsl' ), [ 'status' => 404 ] );
	}
}
