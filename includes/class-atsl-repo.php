<?php
/**
 * ATSL_Repo — all reads/writes of the links table.
 *
 * Loaded before most of WordPress is up (see the redirect hot path), so this
 * file may only use $wpdb, the object cache and core functions that exist
 * before plugins are included. Times are stored in UTC.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATSL_Repo {

	const CACHE_GROUP = 'atsl';

	/** Seconds a slug lookup (hit or miss) stays in the object cache. */
	const CACHE_TTL = 3600;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atsl_links';
	}

	// -------------------------------------------------------------------------
	// Hot path
	// -------------------------------------------------------------------------

	/**
	 * Redirect lookup by slug. Misses are cached too, so ordinary single-segment
	 * pages (/blog/, /about/) cost one object-cache get, not a query.
	 *
	 * @return array{id:int,url:string}|null
	 */
	public static function lookup( string $slug ): ?array {
		$cached = wp_cache_get( 'slug:' . $slug, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, url FROM ' . self::table() . ' WHERE slug = %s', $slug ),
			ARRAY_A
		);

		$link = $row ? [ 'id' => (int) $row['id'], 'url' => (string) $row['url'] ] : null;
		wp_cache_set( 'slug:' . $slug, $link ?? 'none', self::CACHE_GROUP, self::CACHE_TTL );

		return $link;
	}

	public static function hit( int $id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET clicks = clicks + 1, last_click_at = UTC_TIMESTAMP() WHERE id = %d',
				$id
			)
		);
	}

	public static function forget( string $slug ): void {
		wp_cache_delete( 'slug:' . $slug, self::CACHE_GROUP );
	}

	// -------------------------------------------------------------------------
	// Dashboard / API
	// -------------------------------------------------------------------------

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function slug_exists( string $slug, int $exclude_id = 0 ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE slug = %s AND id <> %d', $slug, $exclude_id )
		);
	}

	/** Existing auto-generated link for exactly this destination, if any. */
	public static function find_auto_by_url( string $url ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE url_hash = %s AND is_custom = 0 AND url = %s ORDER BY id LIMIT 1',
				sha1( $url ),
				$url
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/** @return int|false New id, or false when the slug was taken meanwhile (unique key). */
	public static function insert( string $slug, string $url, bool $is_custom, int $user_id ) {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			[
				'slug'       => $slug,
				'url'        => $url,
				'url_hash'   => sha1( $url ),
				'is_custom'  => $is_custom ? 1 : 0,
				'created_by' => $user_id,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s', '%d', '%d', '%s' ]
		);
		if ( ! $ok ) {
			return false;
		}
		self::forget( $slug );
		return (int) $wpdb->insert_id;
	}

	public static function update_url( int $id, string $url ): bool {
		global $wpdb;
		$row = self::find( $id );
		if ( ! $row ) {
			return false;
		}
		$ok = false !== $wpdb->update(
			self::table(),
			[ 'url' => $url, 'url_hash' => sha1( $url ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		self::forget( $row['slug'] );
		return $ok;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$row = self::find( $id );
		if ( ! $row ) {
			return false;
		}
		$ok = (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
		self::forget( $row['slug'] );
		return $ok;
	}

	/**
	 * Newest first; search matches slug or destination.
	 *
	 * @return array{rows:array<int,array>,total:int}
	 */
	public static function paginate( string $search, int $page, int $per_page ): array {
		global $wpdb;
		$table  = self::table();
		$where  = '1=1';
		$params = [];

		if ( '' !== $search ) {
			$where    = '(slug LIKE %s OR url LIKE %s)';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			// Destinations are stored percent-encoded; let Georgian search text match too.
			$params[] = '%' . $wpdb->esc_like( self::encode_non_ascii( $search ) ) . '%';
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, [ $per_page, $offset ] )
			),
			ARRAY_A
		);

		return [ 'rows' => $rows ?: [], 'total' => $total ];
	}

	/** Percent-encode every non-ASCII byte (Georgian paths) so Location headers stay valid. */
	public static function encode_non_ascii( string $value ): string {
		return (string) preg_replace_callback(
			'/[^\x20-\x7e]+/',
			static fn( $m ) => rawurlencode( $m[0] ),
			$value
		);
	}
}
