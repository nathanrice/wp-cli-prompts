<?php

namespace WP_CLI\Prompts;

use WP_CLI\Utils;

/**
 * Minimal wp.org directory client: plugins, themes and locales.
 *
 * Built on WP-CLI's own `Utils\http_request()` rather than `wp_remote_get()`, so it
 * works before WordPress is loaded (`wp new` runs in a directory with no site yet).
 * Results are cached per query: Laravel Prompts calls the search closure on every
 * keystroke, so backspacing would otherwise re-hit the API.
 */
class WpOrg_Api {

	private const PLUGINS      = 'https://api.wordpress.org/plugins/info/1.2/';
	private const THEMES       = 'https://api.wordpress.org/themes/info/1.2/';
	private const TRANSLATIONS = 'https://api.wordpress.org/translations/core/1.0/';

	/**
	 * Records from every search so far, for detail lookup after selection.
	 *
	 * @var array<string, array<string, array<string, mixed>>> Type => slug => record.
	 */
	private array $records = [ 'plugin' => [], 'theme' => [] ];

	/** @var array<string, array<string, string>> */
	private array $cache = [];

	/** @var array<string, string>|null */
	private ?array $locales = null;

	/**
	 * @return array<string, string> Slug => name. Popular plugins when $term is empty.
	 */
	public function plugins( string $term = '' ): array {
		return $this->directory( 'plugin', $term );
	}

	/**
	 * @return array<string, string> Slug => name. Popular themes when $term is empty.
	 */
	public function themes( string $term = '' ): array {
		return $this->directory( 'theme', $term );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function plugin( string $slug ): ?array {
		return $this->records['plugin'][ $slug ] ?? null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function theme( string $slug ): ?array {
		return $this->records['theme'][ $slug ] ?? null;
	}

	/**
	 * Display name for an already-fetched plugin or theme, falling back to the slug.
	 */
	public function name( string $type, string $slug ): string {
		$record = $this->records[ $type ][ $slug ] ?? null;

		return null === $record ? $slug : self::clean( $record['name'] ?? $slug );
	}

	/**
	 * One-line summary of a plugin or theme, for Prompts' `info:` slot (bottom border).
	 *
	 * Prompts truncates it to the box width, so it carries only short facts.
	 *
	 * @param int|string|null $slug Highlighted option key; null when there are no matches.
	 */
	public function info( string $type, $slug ): string {
		$record = null === $slug ? null : ( $this->records[ $type ][ $slug ] ?? null );

		if ( null === $record ) {
			return '';
		}

		$parts = [ $record['slug'], round( ( (int) ( $record['rating'] ?? 0 ) ) / 20, 1 ) . '/5' ];

		if ( isset( $record['active_installs'] ) ) {
			$parts[] = self::abbreviate( (int) $record['active_installs'] ) . ' installs';
		}

		$parts[] = self::author( $record );

		return implode( ' · ', array_filter( $parts ) );
	}

	/**
	 * @return string Plain-text author name; the plugin API returns HTML, the theme API an object.
	 */
	public static function author( array $record ): string {
		$author = $record['author'] ?? '';

		if ( is_array( $author ) ) {
			$author = $author['display_name'] ?? ( $author['user_nicename'] ?? '' );
		}

		return self::clean( (string) $author );
	}

	public static function clean( string $html ): string {
		return trim( html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5 ) );
	}

	public static function abbreviate( int $count ): string {
		if ( $count >= 1000000 ) {
			return ( $count / 1000000 ) . 'M+';
		}

		return $count >= 1000 ? ( $count / 1000 ) . 'k+' : (string) $count;
	}

	/**
	 * @return array<string, string> Locale code => "English name (native name)".
	 */
	public function locales(): array {
		if ( null !== $this->locales ) {
			return $this->locales;
		}

		$this->locales = [ 'en_US' => 'English (United States)' ];
		$body          = $this->get( self::TRANSLATIONS );

		foreach ( $body['translations'] ?? [] as $translation ) {
			$name = $translation['english_name'];

			if ( $translation['native_name'] !== $name ) {
				$name .= ' (' . $translation['native_name'] . ')';
			}

			$this->locales[ $translation['language'] ] = $name;
		}

		return $this->locales;
	}

	/**
	 * @return array<string, string> Slug => name.
	 */
	private function directory( string $type, string $term ): array {
		$term = trim( $term );

		// One-character terms match far too much to be useful.
		if ( '' !== $term && strlen( $term ) < 2 ) {
			return [];
		}

		$key = $type . ':' . $term;

		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		$request = [ 'per_page' => 10 ];

		if ( '' === $term ) {
			$request['browse'] = 'popular';
		} else {
			$request['search'] = $term;
		}

		$body = $this->get(
			'plugin' === $type ? self::PLUGINS : self::THEMES,
			[
				'action'  => 'plugin' === $type ? 'query_plugins' : 'query_themes',
				'request' => $request,
			]
		);

		$options = [];

		foreach ( $body[ 'plugin' === $type ? 'plugins' : 'themes' ] ?? [] as $record ) {
			$this->records[ $type ][ $record['slug'] ] = $record;

			$options[ $record['slug'] ] = self::clean( (string) $record['name'] );
		}

		// Failed lookups are cached too, so a dead network doesn't stall every keystroke.
		return $this->cache[ $key ] = $options;
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed> Decoded JSON body; empty on any failure.
	 */
	private function get( string $url, array $query = [] ): array {
		if ( $query ) {
			$url .= '?' . http_build_query( $query );
		}

		try {
			$response = Utils\http_request( 'GET', $url, null, [], [ 'halt_on_error' => false, 'timeout' => 15 ] );
		} catch ( \Exception $e ) {
			return [];
		}

		if ( 200 !== (int) $response->status_code ) {
			return [];
		}

		$body = json_decode( $response->body, true );

		return is_array( $body ) ? $body : [];
	}
}
