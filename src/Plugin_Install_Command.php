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
			options: fn ( string $value ) => $this->api->plugins( $value ),
			placeholder: 'e.g. contact form',
			info: fn ( $slug ) => $this->api->info( 'plugin', $slug ),
		);

		$plugin = $this->api->plugin( $slug );

		if ( null === $plugin ) {
			WP_CLI::error( 'Could not load details for the selected plugin.' );
		}

		$description = WpOrg_Api::clean( (string) $plugin['short_description'] );
		$author      = WpOrg_Api::author( $plugin );
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

	private WpOrg_Api $api;

	public function __construct() {
		$this->api = new WpOrg_Api();
	}
}
