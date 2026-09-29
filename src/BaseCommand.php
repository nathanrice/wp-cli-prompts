<?php

namespace WP_CLI\Prompts;

use Laravel\Prompts\Prompt;
use WP_CLI;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

abstract class BaseCommand {

	/**
	 * Bail with a clean WP-CLI error instead of letting Laravel Prompts throw
	 * a NonInteractiveValidationException stack trace when stdin isn't a TTY.
	 */
	protected function require_tty(): void {
		if ( ! stream_isatty( STDIN ) ) {
			WP_CLI::error(
				'This command is interactive and requires a TTY. '
				. 'Use the non-interactive equivalent (e.g. `wp user create`) in scripts.'
			);
		}

		Prompt::interactive( true );
	}

	protected function validate_username( string $value ): ?string {
		if ( strlen( $value ) < 3 ) {
			return 'Username must be at least 3 characters.';
		}

		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return 'Username may only contain letters, numbers, underscores, and hyphens.';
		}

		return null;
	}

	protected function validate_email( string $value ): ?string {
		if ( ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return 'Please enter a valid email address.';
		}

		return null;
	}

	// Quotes the whole `--key=value` token, not just the value — plain
	// escapeshellarg() on the value leaves literal quote chars in place under
	// run_command()'s launch => false. See decisions log entry 4.
	protected function flag( string $key, string $value ): string {
		return "'--{$key}=" . str_replace( "'", "\\'", $value ) . "'";
	}

	/**
	 * Thin wrapper over WP_CLI::runcommand(). `return => 'all'` gives back a
	 * {stdout, stderr, return_code} object (plain `true` gives only a string).
	 * `launch => false` reuses the current process — see decisions log entry 4
	 * for why `launch => true` doesn't work in this project (path has a space).
	 *
	 * @param string $command
	 * @param array<string, mixed> $assoc_args
	 * @return object{stdout: string, stderr: string, return_code: int}
	 */
	protected function run_command( string $command, array $assoc_args = [] ) {
		return WP_CLI::runcommand(
			$command,
			array_merge(
				[
					'return'     => 'all',
					'launch'     => false,
					'exit_error' => false,
				],
				$assoc_args
			)
		);
	}
}
