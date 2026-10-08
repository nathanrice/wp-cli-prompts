<?php

namespace WP_CLI\Prompts;

use WP_CLI;

/**
 * Plain-prompt version of `wp new`, for when Laravel Prompts can't run.
 *
 * Loaded without Composer's autoloader, so this file must stay PHP 7.2-compatible
 * (WP-CLI's floor). It is a thin sequence over WP-CLI's own `--prompt` flag, which
 * asks for the named arguments one line at a time: `core download` if needed, then
 * `config create --prompt` and `core install --prompt`. There is no plugin or theme
 * step, no connection check and no summary; those need the full UI.
 */
class New_Fallback_Command extends BaseCommand {

	/**
	 * Set up a new WordPress site with plain prompts (fallback).
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		// Overridden rather than inherited: the parent's version calls into Laravel Prompts.
		if ( ! stream_isatty( STDIN ) ) {
			WP_CLI::error(
				'This command is interactive and requires a TTY. '
				. 'Use the non-interactive equivalents (`wp core download`, `wp config create`, `wp core install`) in scripts.'
			);
		}

		$dir = $this->target_dir();

		WP_CLI::log( 'Using plain prompts: ' . WP_CLI_PROMPTS_FALLBACK_REASON );
		WP_CLI::log( 'Plugins and themes can be added afterwards with `wp plugin install` and `wp theme install`.' );
		WP_CLI::log( '' );

		// Same guard as the full command: never touch a site that is already installed.
		if ( $this->has_wp_files( $dir ) && $this->has_wp_config( $dir ) ) {
			$installed = WP_CLI::runcommand(
				'core is-installed',
				array(
					'launch'     => true,
					'return'     => 'return_code',
					'exit_error' => false,
				)
			);

			if ( 0 === $installed ) {
				WP_CLI::error( "WordPress is already installed in {$dir}. `wp new` only sets up new sites." );
			}
		}

		if ( ! $this->has_wp_files( $dir ) ) {
			WP_CLI::confirm( "WordPress isn't in {$dir}. Download it here?" );
			$this->launch( 'core download' );
		}

		if ( ! $this->has_wp_config( $dir ) ) {
			$this->launch( 'config create --prompt=dbname,dbuser,dbpass,dbhost,dbprefix' );
		}

		$this->launch( 'core install --prompt=url,title,admin_user,admin_password,admin_email --skip-email' );
	}

	/**
	 * A separate process, so that `--prompt` is read as the global flag it is.
	 */
	private function launch( $command ) {
		WP_CLI::runcommand(
			$command,
			array(
				'launch'     => true,
				'exit_error' => true,
			)
		);
	}
}
