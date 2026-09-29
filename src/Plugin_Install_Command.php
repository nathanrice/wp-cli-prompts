<?php

namespace WP_CLI\Prompts;

use WP_CLI;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\search;
use function Laravel\Prompts\spin;

/**
 * Search wp.org, pick a plugin, and install it interactively.
 */
class Plugin_Install_Command extends BaseCommand {

	/**
	 * Search the wp.org plugin directory and install a plugin interactively.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->require_tty();

		$slug = search(
			label: 'Search for a plugin',
			options: fn ( string $value ) => $this->search_plugins( $value ),
			placeholder: 'e.g. contact form',
		);

		$plugin = $this->plugin_details[ $slug ] ?? null;

		if ( null === $plugin ) {
			WP_CLI::error( 'Could not load details for the selected plugin.' );
		}

		$description = html_entity_decode( wp_strip_all_tags( (string) $plugin['short_description'] ) );
		$author      = html_entity_decode( wp_strip_all_tags( (string) $plugin['author'] ) );
		$rating      = round( ( (int) $plugin['rating'] ) / 20, 1 );

		note(
			sprintf(
				"%s by %s\nVersion %s · %s/5 stars (%s ratings) · %s active installs\n\n%s",
				$plugin['name'],
				$author,
				$plugin['version'],
				$rating,
				number_format( (int) $plugin['num_ratings'] ),
				number_format( (int) $plugin['active_installs'] ),
				$description
			)
		);

		$activate = confirm(
			label: 'Activate after installing?',
			default: true,
		);

		$result = spin(
			callback: fn () => $this->run_command(
				'plugin install ' . escapeshellarg( $slug ) . ( $activate ? ' --activate' : '' )
			),
			message: "Installing {$plugin['name']}...",
		);

		if ( 0 !== $result->return_code ) {
			WP_CLI::error( "Failed to install plugin:\n" . $result->stderr );
		}

		WP_CLI::success( "Installed {$plugin['name']}" . ( $activate ? ' and activated it.' : '.' ) );
	}

	/**
	 * Plugin records from the last search, keyed by slug, for detail lookup after selection.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $plugin_details = [];

	/**
	 * @return array<string, string> Slug => display label.
	 */
	private function search_plugins( string $term ): array {
		if ( strlen( $term ) < 2 ) {
			return [];
		}

		$url = add_query_arg(
			[
				'action'  => 'query_plugins',
				'request' => [
					'search'   => $term,
					'per_page' => 10,
				],
			],
			'https://api.wordpress.org/plugins/info/1.2/'
		);

		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['plugins'] ) ) {
			return [];
		}

		$options = [];

		foreach ( $body['plugins'] as $plugin ) {
			$this->plugin_details[ $plugin['slug'] ] = $plugin;

			$options[ $plugin['slug'] ] = sprintf(
				'%s (%s) — %s',
				$plugin['name'],
				$plugin['slug'],
				wp_trim_words( html_entity_decode( wp_strip_all_tags( (string) $plugin['short_description'] ) ), 12 )
			);
		}

		return $options;
	}
}
