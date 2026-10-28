<?php
/**
 * ATSL_Slug — what a short-link ending may be.
 *
 *  - Format + "is this path already used on the site?" (reserved words, WPML
 *    language prefixes, rewrite-rule roots, root-level posts/pages, Rank Math
 *    redirects, other short links)
 *  - Random code generator for automatic links
 *  - wp_unique_post_slug guard: a new post/page can never take a short link's
 *    slug, so neither side can ever hide the other
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATSL_Slug {

	/** No look-alikes (0 o 1 l i) — codes get retyped from print. */
	const ALPHABET    = 'abcdefghjkmnpqrstuvwxyz23456789';
	const AUTO_LENGTH = 5;

	const PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}[a-z0-9]$/';

	/** Root paths that are always taken, whether or not a rewrite rule exists for them. */
	const RESERVED = [
		'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'wp-json', 'wp-cron', 'wp-signup',
		'wp-activate', 'wp-sitemap', 'xmlrpc', 'index', 'admin', 'login', 'logout', 'register',
		'dashboard', 'feed', 'rss', 'rss2', 'atom', 'rdf', 'sitemap', 'robots', 'favicon',
		'cart', 'checkout', 'my-account', 'shop', 'blog', 'page', 'search', 'embed', 'trackback',
		'comments', 'author', 'category', 'tag', 'product', 'products', 'cdn-cgi',
		'en', 'ka', 'ka-ge', 'ru',
	];

	/** @var array{exact:array<string,bool>,prefix:string[]}|null */
	private static ?array $rewrite_roots = null;

	public static function init(): void {
		add_filter( 'wp_unique_post_slug', [ __CLASS__, 'avoid_short_links' ], 10, 6 );
	}

	// -------------------------------------------------------------------------
	// Input
	// -------------------------------------------------------------------------

	/** Lowercases, accepts a pasted "arttime.ge/my-url", turns spaces/underscores into hyphens. */
	public static function normalize( string $input ): string {
		$slug = strtolower( trim( $input ) );

		$host = preg_replace( '/^www\./', '', (string) parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) );
		if ( $host ) {
			$slug = (string) preg_replace( '#^(https?://)?(www\.)?' . preg_quote( $host, '#' ) . '/+#', '', $slug );
		}

		$slug = trim( $slug, '/' );
		$slug = (string) preg_replace( '/[\s_]+/', '-', $slug );
		$slug = (string) preg_replace( '/-{2,}/', '-', $slug );

		return trim( $slug, '-' );
	}

	/**
	 * @return array{available:bool,reason:string}
	 */
	public static function check( string $slug ): array {
		$len = strlen( $slug );
		if ( $len < 2 || $len > 64 ) {
			return self::taken( __( 'ბოლოსართი უნდა იყოს 2-დან 64 სიმბოლომდე.', 'atsl' ) );
		}
		if ( ! preg_match( self::PATTERN, $slug ) ) {
			return self::taken( __( 'გამოიყენეთ მხოლოდ ლათინური ასოები, ციფრები და ტირე (-).', 'atsl' ) );
		}
		if ( ATSL_Repo::slug_exists( $slug ) ) {
			return self::taken( __( 'დაკავებულია — ასეთი მოკლე ბმული უკვე არსებობს.', 'atsl' ) );
		}

		$conflicts = self::conflicts( [ $slug ] );
		if ( isset( $conflicts[ $slug ] ) ) {
			return self::taken( $conflicts[ $slug ] );
		}

		return [ 'available' => true, 'reason' => '' ];
	}

	/** Random code that passes check(); grows by one character if 5-char codes keep colliding. */
	public static function generate(): ?string {
		$max = strlen( self::ALPHABET ) - 1;
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$length = self::AUTO_LENGTH + intdiv( $attempt, 10 );
			$code   = '';
			for ( $i = 0; $i < $length; $i++ ) {
				$code .= self::ALPHABET[ random_int( 0, $max ) ];
			}
			if ( self::check( $code )['available'] ) {
				return $code;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// "Already used on the site?"
	// -------------------------------------------------------------------------

	/**
	 * Site paths each slug would collide with. Batch form so the dashboard can
	 * flag a page of links with one posts query.
	 *
	 * @param string[] $slugs
	 * @return array<string,string> slug => human reason (only colliding slugs)
	 */
	public static function conflicts( array $slugs ): array {
		$out   = [];
		$langs = self::language_codes();
		$roots = self::rewrite_roots();

		foreach ( $slugs as $slug ) {
			if ( in_array( $slug, self::RESERVED, true ) ) {
				$out[ $slug ] = __( 'დაკავებულია — სისტემური მისამართია (WordPress / WooCommerce).', 'atsl' );
			} elseif ( isset( $langs[ $slug ] ) ) {
				$out[ $slug ] = __( 'დაკავებულია — ენის პრეფიქსია.', 'atsl' );
			} elseif ( isset( $roots['exact'][ $slug ] ) || self::starts_with_any( $slug, $roots['prefix'] ) ) {
				$out[ $slug ] = __( 'დაკავებულია — საიტის განყოფილებაა (მაგ. მაღაზია, ბრენდი, კატეგორია).', 'atsl' );
			}
		}

		$rest = array_values( array_diff( $slugs, array_keys( $out ) ) );
		if ( $rest ) {
			$out += self::post_conflicts( $rest );
		}
		$rest = array_values( array_diff( $slugs, array_keys( $out ) ) );
		if ( $rest ) {
			$out += self::rank_math_conflicts( $rest );
		}

		return $out;
	}

	/**
	 * Posts, top-level pages and unattached media live at /<post_name>/ on this
	 * site (/%postname%/). Drafts and scheduled items count — they would collide
	 * the moment they are published.
	 */
	private static function post_conflicts( array $slugs ): array {
		global $wpdb;

		$in   = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_name, post_type, post_title FROM {$wpdb->posts}
				 WHERE post_name IN ({$in})
				   AND post_status NOT IN ('trash','auto-draft')
				   AND ( post_type = 'post'
				      OR ( post_type IN ('page','attachment') AND post_parent = 0 ) )",
				$slugs
			),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$type  = get_post_type_object( $row['post_type'] );
			$label = $type ? $type->labels->singular_name : $row['post_type'];
			/* translators: 1: content type (Page, Post…), 2: its title */
			$out[ $row['post_name'] ] = sprintf( __( 'დაკავებულია — %1$s «%2$s».', 'atsl' ), $label, $row['post_title'] );
		}
		return $out;
	}

	/** Active Rank Math redirects whose source is exactly this path (or starts with it). */
	private static function rank_math_conflicts( array $slugs ): array {
		global $wpdb;

		$modules = get_option( 'rank_math_modules' );
		if ( ! is_array( $modules ) || ! in_array( 'redirections', $modules, true ) ) {
			return [];
		}
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}

		$out = [];
		foreach ( (array) $wpdb->get_col( "SELECT sources FROM {$table} WHERE status = 'active'" ) as $raw ) {
			foreach ( (array) maybe_unserialize( $raw ) as $source ) {
				$pattern    = strtolower( trim( (string) ( $source['pattern'] ?? '' ), '/' ) );
				$comparison = (string) ( $source['comparison'] ?? 'exact' );
				if ( '' === $pattern ) {
					continue;
				}
				foreach ( $slugs as $slug ) {
					if ( $slug === $pattern || ( 'start' === $comparison && 0 === strpos( $slug, $pattern ) ) ) {
						$out[ $slug ] = __( 'დაკავებულია — Rank Math-ის გადამისამართება იყენებს.', 'atsl' );
					}
				}
			}
		}
		return $out;
	}

	/** @return array<string,bool> */
	private static function language_codes(): array {
		$langs = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
		return is_array( $langs ) ? array_fill_keys( array_map( 'strtolower', array_keys( $langs ) ), true ) : [];
	}

	/**
	 * First path segment of every rewrite rule: /shop/, /product/, /brendi/,
	 * /print-anketa/ … Rules like "comment-page-([0-9]+)" become prefixes.
	 *
	 * @return array{exact:array<string,bool>,prefix:string[]}
	 */
	private static function rewrite_roots(): array {
		if ( null !== self::$rewrite_roots ) {
			return self::$rewrite_roots;
		}

		$exact  = [];
		$prefix = [];
		foreach ( array_keys( (array) get_option( 'rewrite_rules' ) ) as $rule ) {
			$rule = strtolower( ltrim( (string) $rule, '^' ) );

			if ( preg_match( '#^\(([a-z0-9_-]+(?:\|[a-z0-9_-]+)+)\)(?:/|\$|\?|$)#', $rule, $m ) ) {
				foreach ( explode( '|', $m[1] ) as $alt ) {
					$exact[ $alt ] = true;
				}
			} elseif ( preg_match( '#^([a-z0-9_-]+)(/|\$|\?|\\\\\.|\(|$)#', $rule, $m ) ) {
				if ( '(' === $m[2] ) {
					$prefix[] = $m[1];
				} else {
					$exact[ $m[1] ] = true;
				}
			}
		}

		self::$rewrite_roots = [ 'exact' => $exact, 'prefix' => array_values( array_unique( $prefix ) ) ];
		return self::$rewrite_roots;
	}

	// -------------------------------------------------------------------------
	// Guard for new posts / pages
	// -------------------------------------------------------------------------

	/**
	 * A root-level post/page/media item that would get a short link's slug is
	 * renamed the WordPress way: my-url → my-url-2.
	 */
	public static function avoid_short_links( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		$at_root = 'post' === $post_type || ( in_array( $post_type, [ 'page', 'attachment' ], true ) && 0 === (int) $post_parent );
		if ( ! $at_root || ! is_string( $slug ) || ! preg_match( self::PATTERN, $slug ) || ! ATSL_Repo::slug_exists( $slug ) ) {
			return $slug;
		}

		global $wpdb;
		$suffix = 2;
		do {
			$candidate = $slug . '-' . $suffix++;
			$post_hit  = $wpdb->get_var(
				$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND ID <> %d LIMIT 1", $candidate, (int) $post_id )
			);
		} while ( $post_hit || ATSL_Repo::slug_exists( $candidate ) );

		return $candidate;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private static function taken( string $reason ): array {
		return [ 'available' => false, 'reason' => $reason ];
	}

	private static function starts_with_any( string $slug, array $prefixes ): bool {
		foreach ( $prefixes as $p ) {
			if ( 0 === strpos( $slug, $p ) ) {
				return true;
			}
		}
		return false;
	}
}
