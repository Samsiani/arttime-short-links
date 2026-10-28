<?php
/**
 * ATSL_Redirect — answers arttime.ge/<slug> with a 302 to the stored destination.
 *
 * Runs while WordPress is still including plugins (called from the main plugin
 * file), so pluggable functions such as wp_redirect() do not exist yet — raw
 * header() calls only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATSL_Redirect {

	/** One path segment, optional trailing slash. Same shape ATSL_Slug accepts. */
	const PATH_PATTERN = '#^/([a-z0-9][a-z0-9-]{0,62}[a-z0-9])/?$#i';

	/** Link-preview fetchers, crawlers and scripts — redirected, never counted. */
	const BOT_PATTERN = '/bot\b|bot\/|crawl|spider|slurp|preview|facebookexternalhit|facebookcatalog|meta-externalagent|whatsapp|curl|wget|python|go-http|java\/|okhttp|headless|lighthouse|pingdom|uptime/i';

	public static function maybe_redirect(): void {
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$path = self::request_path();
		if ( null === $path || ! preg_match( self::PATH_PATTERN, $path, $m ) ) {
			return;
		}

		$link = ATSL_Repo::lookup( strtolower( $m[1] ) );
		if ( ! $link ) {
			return;
		}

		if ( 'GET' === $method && self::is_human() ) {
			ATSL_Repo::hit( $link['id'] );
		}

		// Every cache layer must ask the origin again, so a changed destination
		// applies immediately and each click is counted.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
		header( 'X-LiteSpeed-Cache-Control: no-cache', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Redirect-By: arttime-short-links', true );
		header( 'Location: ' . $link['url'], true, 302 );
		exit;
	}

	/** Request path relative to the site root, or null when it is not ours to read. */
	private static function request_path(): ?string {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( '' === $uri ) {
			return null;
		}
		$path = (string) parse_url( $uri, PHP_URL_PATH );

		$base = rtrim( (string) parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base ) {
			if ( 0 !== strpos( $path, $base . '/' ) ) {
				return null;
			}
			$path = substr( $path, strlen( $base ) );
		}

		return $path;
	}

	private static function is_human(): bool {
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
		if ( '' === $ua || preg_match( self::BOT_PATTERN, $ua ) ) {
			return false;
		}
		// Browser speculative prefetch is not a click.
		$purpose = strtolower( ( $_SERVER['HTTP_SEC_PURPOSE'] ?? '' ) . ( $_SERVER['HTTP_PURPOSE'] ?? '' ) . ( $_SERVER['HTTP_X_MOZ'] ?? '' ) );
		return false === strpos( $purpose, 'prefetch' );
	}
}
