<?php

namespace WP_CLI\Prompts;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Plain line-by-line version of `user wizard`, for when Laravel Prompts can't run.
 *
 * Loaded without Composer's autoloader, so this file must stay PHP 7.2-compatible
 * (WP-CLI's floor): no arrow functions, named arguments, or union types. Prompts
 * come from php-cli-tools, which every WP-CLI build ships (core renders its
 * tables and colors with it).
 */
class User_Wizard_Fallback_Command extends BaseCommand {

	/**
	 * Walk through creating a new user interactively (plain-prompt fallback).
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		// Overridden rather than inherited: the parent's version calls into Laravel Prompts.
		if ( ! stream_isatty( STDIN ) ) {
			WP_CLI::error(
				'This command is interactive and requires a TTY. '
				. 'Use the non-interactive equivalent (e.g. `wp user create`) in scripts.'
			);
		}

		WP_CLI::log( 'Using plain prompts: ' . WP_CLI_PROMPTS_FALLBACK_REASON );
		WP_CLI::log( '' );

		try {
			$this->run_wizard();
		} catch ( \Exception $e ) {
			// php-cli-tools throws a bare \Exception on Ctrl-D; anything more specific
			// (e.g. WP_CLI\ExitException from a real error) is not a cancellation.
			if ( 'Exception' !== get_class( $e ) ) {
				throw $e;
			}
			WP_CLI::error( 'Cancelled — no user was created.' );
		}
	}

	private function run_wizard() {
		$username = $this->ask(
			'Username',
			function ( $value ) {
				return $this->validate_username( $value );
			}
		);

		$email = $this->ask(
			'Email address',
			function ( $value ) {
				return $this->validate_email( $value );
			}
		);

		$password = $this->ask(
			'Password (input hidden)',
			function ( $value ) {
				return strlen( $value ) < 8 ? 'Password must be at least 8 characters.' : null;
			},
			false,
			true
		);

		$roles = wp_roles()->get_names();

		// No default: a mistyped Enter shouldn't silently mint an administrator.
		$role = \cli\menu( $roles, null, 'Role (enter a number)' );

		$first_name = $this->ask( 'First name (optional)', null, '' );
		$last_name  = $this->ask( 'Last name (optional)', null, '' );

		WP_CLI::log( '' );
		Utils\format_items(
			'table',
			array(
				array( 'Field' => 'Username', 'Value' => $username ),
				array( 'Field' => 'Email', 'Value' => $email ),
				array( 'Field' => 'Role', 'Value' => $roles[ $role ] ),
				array( 'Field' => 'First name', 'Value' => $first_name ?: '—' ),
				array( 'Field' => 'Last name', 'Value' => $last_name ?: '—' ),
			),
			array( 'Field', 'Value' )
		);

		if ( 'y' !== \cli\choose( 'Create this user', 'yn', 'y' ) ) {
			WP_CLI::log( 'Cancelled — no user was created.' );
			return;
		}

		$command = sprintf(
			'user create %s %s %s %s --porcelain',
			escapeshellarg( $username ),
			escapeshellarg( $email ),
			$this->flag( 'role', $role ),
			$this->flag( 'user_pass', $password )
		);

		if ( $first_name ) {
			$command .= ' ' . $this->flag( 'first_name', $first_name );
		}

		if ( $last_name ) {
			$command .= ' ' . $this->flag( 'last_name', $last_name );
		}

		$result = $this->run_command( $command );

		if ( 0 !== $result->return_code ) {
			WP_CLI::error( "Failed to create user:\n" . $result->stderr );
		}

		WP_CLI::success( "Created user '{$username}' (ID {$result->stdout})." );
	}

	/**
	 * Prompt until the validator returns null.
	 *
	 * @param string        $question
	 * @param callable|null $validate Returns an error message, or null when valid.
	 * @param string|false  $default  false = required (cli\prompt re-asks on blank).
	 * @param bool          $hide     Hidden input is always required; $default is ignored.
	 * @return string
	 */
	private function ask( $question, $validate = null, $default = false, $hide = false ) {
		while ( true ) {
			$value = $hide
				? $this->prompt_hidden( $question )
				: \cli\prompt( $question, $default );
			$error = $validate ? $validate( $value ) : null;

			if ( null === $error ) {
				return $value;
			}

			WP_CLI::warning( $error );
		}
	}

	/**
	 * Required hidden input that restores the terminal if Ctrl-C lands mid-entry.
	 *
	 * Not cli\prompt( ..., true ): that runs `stty -echo`, then blocks in fgets(),
	 * and PHP retries that read on EINTR internally, so no signal handler can run
	 * before SIGINT kills the process, leaving the user's shell with echo off.
	 * Waiting in stream_select() instead gives the async handler a chance to fire.
	 *
	 * @param string $question
	 * @return string
	 */
	private function prompt_hidden( $question ) {
		if ( ! function_exists( 'pcntl_async_signals' ) ) {
			return \cli\prompt( $question, false, ': ', true );
		}

		$async = pcntl_async_signals( true );
		pcntl_signal(
			SIGINT,
			function () {
				system( 'stty echo' );
				WP_CLI::line( '' );
				exit( 130 );
			}
		);

		try {
			do {
				fwrite( STDOUT, $question . ': ' );
				system( 'stty -echo' );
				try {
					$line = $this->read_line_interruptibly();
				} finally {
					system( 'stty echo' );
					WP_CLI::line( '' );
				}

				if ( false === $line ) {
					throw new \Exception( 'Caught ^D during input' );
				}

				$line = trim( $line );
			} while ( '' === $line );

			return $line;
		} finally {
			pcntl_signal( SIGINT, SIG_DFL );
			pcntl_async_signals( $async );
		}
	}

	/**
	 * @return string|false
	 */
	private function read_line_interruptibly() {
		do {
			$read   = array( STDIN );
			$write  = null;
			$except = null;
			$ready  = @stream_select( $read, $write, $except, 0, 200000 );
		} while ( ! $ready );

		return fgets( STDIN );
	}
}
