<?php

namespace WP_CLI\Prompts;

use WP_CLI;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

/**
 * Create a WordPress user through an interactive form.
 */
class User_Wizard_Command extends BaseCommand {

	/**
	 * Walk through creating a new user interactively.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->require_tty();

		$username = text(
			label: 'Username',
			required: true,
			validate: fn ( string $value ) => $this->validate_username( $value ),
		);

		$email = text(
			label: 'Email address',
			required: true,
			validate: fn ( string $value ) => $this->validate_email( $value ),
		);

		$password = password(
			label: 'Password',
			required: true,
			validate: fn ( string $value ) => strlen( $value ) < 8
				? 'Password must be at least 8 characters.'
				: null,
		);

		$roles = wp_roles()->get_names();

		$role = select(
			label: 'Role',
			options: $roles,
			info: fn ( $value ) => "WordPress role: {$value}",
		);

		$first_name = text( label: 'First name (optional)' );
		$last_name  = text( label: 'Last name (optional)' );

		table(
			headers: [ 'Field', 'Value' ],
			rows: [
				[ 'Username', $username ],
				[ 'Email', $email ],
				[ 'Role', $roles[ $role ] ],
				[ 'First name', $first_name ?: '—' ],
				[ 'Last name', $last_name ?: '—' ],
			],
		);

		if ( ! confirm( label: 'Create this user?', default: true ) ) {
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
}
