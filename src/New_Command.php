<?php

namespace WP_CLI\Prompts;

use WP_CLI;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\form;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Guided setup for a brand-new WordPress site, in three phases.
 *
 * Each phase asks its questions, shows a short summary, and runs on confirmation
 * before the next one asks anything. Every step wraps the stock command that already
 * does the work (`core download`, `config create`, `core install`, `plugin install`,
 * `theme install`), so this command only gathers answers and sequences them.
 */
class New_Command extends BaseCommand {

	private const EXTRAS = [
		'permalinks'      => 'Pretty permalinks (/%postname%/)',
		'sample_content'  => 'Remove the sample post, page and comment',
		'starter_plugins' => 'Remove the bundled Hello Dolly and Akismet plugins',
		'discourage'      => 'Discourage search engines (good for development sites)',
	];

	/** Short forms for the summary table, where the long labels would blow out the width. */
	private const EXTRAS_SHORT = [
		'permalinks'      => 'pretty permalinks',
		'sample_content'  => 'remove sample content',
		'starter_plugins' => 'remove bundled plugins',
		'discourage'      => 'discourage search engines',
	];

	private WpOrg_Api $api;

	/** True for `--dry-run`: ask everything, run nothing, and assume each phase succeeded. */
	private bool $dry_run = false;

	public function __construct() {
		$this->api = new WpOrg_Api();
	}

	/**
	 * Set up a new WordPress site interactively.
	 *
	 * Three phases: (1) download WordPress, (2) create wp-config.php and the database,
	 * install the site and the admin account, (3) add plugins, a theme and a few
	 * tidy-ups. Each phase confirms before it changes anything. Phases that are already
	 * done are skipped, so it can pick up a half-finished setup, and on a site that is
	 * already installed it offers phase 3 only. It never overwrites an existing install.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Ask the questions and show the commands that would run, without running them.
	 *
	 * ## EXAMPLES
	 *
	 *     # Set up a site in an empty directory
	 *     $ mkdir my-site && cd my-site
	 *     $ wp new
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->require_tty();

		$this->dry_run = ! empty( $assoc_args['dry-run'] );

		$dir        = $this->target_dir();
		$has_files  = $this->has_wp_files( $dir );
		$has_config = $this->has_wp_config( $dir );
		$installed  = $has_files && $has_config && $this->is_installed();

		// Extras (sample-content removal and friends) are only offered right after this
		// run installs the site: on an existing one they could delete someone's content.
		$fresh = false;
		$locale = 'en_US';

		if ( ! $has_files ) {
			$locale    = $this->phase_files( $dir );
			$has_files = true;
		} else {
			info( '1/3 WordPress files: already in ' . $dir );
		}

		if ( ! $installed ) {
			$this->phase_install( $has_config, $locale );
			$fresh = true;
		} else {
			info( '2/3 Install: WordPress is already installed here' );
		}

		$this->phase_addons( $fresh );

		WP_CLI::success( $this->dry_run ? 'Dry run finished — nothing was changed.' : 'All done.' );
	}

	/**
	 * Phase 1: download WordPress.
	 *
	 * @return string The locale chosen, which `core install` needs to repeat.
	 */
	private function phase_files( string $dir ): string {
		intro( '1/3 WordPress files' );

		$existing = is_dir( $dir ) ? array_diff( (array) scandir( $dir ), [ '.', '..', 'wp-cli.yml', '.DS_Store' ] ) : [];

		if ( $existing ) {
			warning( sprintf( '%s already has %d other file(s) or folder(s); WordPress will be added alongside them.', $dir, count( $existing ) ) );
		}

		$a = form()
			->confirm( label: 'Download WordPress in English (United States)?', default: true, name: 'english' )
			->add(
				fn ( array $r ) => $r['english']
					? 'en_US'
					: search(
						label: 'Language',
						options: fn ( string $v ) => $this->match_locales( $v ),
						placeholder: 'e.g. Español, Deutsch, 日本語',
					),
				'locale'
			)
			->submit();

		$this->summarize(
			[
				[ 'Directory', $dir ],
				[ 'Language', $a['locale'] ],
			]
		);

		$step = [
			'label'   => 'Downloading WordPress',
			'command' => 'core download' . ( 'en_US' !== $a['locale'] ? ' ' . $this->flag( 'locale', $a['locale'] ) : '' ),
		];

		$this->run_phase( [ $step ], 'Download WordPress now?' );

		return $a['locale'];
	}

	/**
	 * Phase 2: database, wp-config.php, and the install itself.
	 */
	private function phase_install( bool $has_config, string $locale ): void {
		intro( '2/3 Install' );

		$a = $this->ask_install( $has_config );

		$rows = [];

		if ( $has_config ) {
			$rows[] = [ 'Database', 'existing wp-config.php' ];
		} else {
			$rows[] = [ 'Database', sprintf( '%s@%s / %s%s', $a['dbuser'], $a['dbhost'], $a['dbname'], $a['create_db'] ? ' (will be created)' : '' ) ];
			$rows[] = [ 'Table prefix', $a['dbprefix'] ];
		}

		$rows[] = [ 'Site title', $a['title'] ];
		$rows[] = [ 'Site URL', $a['url'] ];
		$rows[] = [ 'Admin', $a['admin_user'] . ' <' . $a['admin_email'] . '>' ];
		$rows[] = [ 'Admin password', 'generate' === $a['password_mode'] ? 'generated (shown once, after the install)' : 'as entered' ];

		$this->summarize( $rows );

		$this->run_phase( $this->install_steps( $a, $has_config, $locale ), 'Install WordPress now?' );

		if ( $this->dry_run ) {
			return;
		}

		$lines = "Log in at {$a['url']}/wp-login.php\nUsername: {$a['admin_user']}";

		if ( 'generate' === $a['password_mode'] ) {
			$lines .= "\nPassword: {$a['admin_password']}\n\nThis is the only time the password is shown. Save it now.";
		}

		note( $lines );
	}

	/**
	 * Phase 3: plugins, a theme, and (on a site this run just installed) a few tidy-ups.
	 */
	private function phase_addons( bool $fresh ): void {
		intro( '3/3 Plugins, theme and extras' );

		if ( ! confirm( label: $fresh ? 'Add plugins, a theme or extras now?' : 'Add plugins or a theme now?', default: true ) ) {
			info( 'Skipped. Run `wp new` again to come back to this step.' );
			return;
		}

		$form = form()
			->add(
				fn () => multisearch(
					label: 'Add plugins (optional)',
					options: fn ( string $v ) => $this->api->plugins( $v ),
					placeholder: 'Search the directory, or pick from the popular list',
					info: fn ( $slug ) => $this->api->info( 'plugin', $slug ),
				),
				'plugins'
			)
			->confirm( label: 'Install and activate a theme from wordpress.org?', default: false, name: 'add_theme' )
			->add(
				fn ( array $r ) => $r['add_theme']
					? search(
						label: 'Search for a theme',
						options: fn ( string $v ) => $this->api->themes( $v ),
						placeholder: 'Search the directory, or pick from the popular list',
						info: fn ( $slug ) => $this->api->info( 'theme', $slug ),
					)
					: null,
				'theme'
			);

		if ( $fresh ) {
			$form->add(
				fn () => multiselect(
					label: 'Extras',
					options: self::EXTRAS,
					default: [ 'permalinks' ],
				),
				'extras'
			);
		}

		$a = $form->submit() + [ 'extras' => [] ];

		if ( ! $a['plugins'] && ! $a['theme'] && ! $a['extras'] ) {
			info( 'Nothing selected.' );
			return;
		}

		$names = implode( ', ', array_map( fn ( $s ) => $this->api->name( 'plugin', $s ), $a['plugins'] ) );

		$rows = [
			[ 'Plugins', $names ?: 'none' ],
			[ 'Theme', $a['theme'] ? $this->api->name( 'theme', $a['theme'] ) : 'none' ],
		];

		if ( $fresh ) {
			$rows[] = [ 'Extras', $a['extras'] ? implode( ', ', array_map( fn ( $k ) => self::EXTRAS_SHORT[ $k ], $a['extras'] ) ) : 'none' ];
		}

		$this->summarize( $rows );

		$this->run_phase( $this->addon_steps( $a ), 'Apply these now?' );
	}

	/**
	 * `core is-installed` needs WordPress loaded, which isn't possible here, so ask a
	 * fresh process. Any failure (including a spawn failure or an unreachable database)
	 * reads as "not installed"; `core install` itself still refuses to overwrite.
	 */
	private function is_installed(): bool {
		return 0 === WP_CLI::runcommand(
			'core is-installed',
			[
				'launch'     => true,
				'return'     => 'return_code',
				'exit_error' => false,
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function ask_install( bool $has_config ): array {
		$dir_slug = preg_replace( '/[^a-z0-9_]+/', '_', strtolower( basename( $this->target_dir() ) ) );

		$form = form();

		if ( ! $has_config ) {
			$form
				->text( label: 'Database name', default: trim( (string) $dir_slug, '_' ), required: true, name: 'dbname' )
				->text( label: 'Database user', default: 'root', required: true, name: 'dbuser' )
				->text( label: 'Database host', default: 'localhost', required: true, hint: 'host, host:port, or host:/path/to/socket', name: 'dbhost' )
				->text( label: 'Table prefix', default: 'wp_', required: true, validate: fn ( string $v ) => preg_match( '/^[A-Za-z0-9_]+$/', $v ) ? null : 'Letters, numbers and underscores only.', name: 'dbprefix' )
				->add(
					fn () => confirm( label: 'Create the database if it does not exist?', default: true, hint: 'Needs permission to create databases.' ),
					'create_db'
				)
				->add(
					fn ( array $r ) => password(
						label: 'Database password',
						hint: 'Leave blank for none. Ctrl+U goes back to change an earlier answer.',
						validate: fn ( string $v ) => $this->check_db( $r, $v ),
					),
					'dbpass'
				);
		}

		return $form
			->text( label: 'Site title', required: true, name: 'title' )
			->text(
				label: 'Site URL',
				default: 'http://localhost',
				required: true,
				validate: fn ( string $v ) => preg_match( '#^https?://[^\s/]+#i', $v ) ? null : 'Enter a full URL starting with http:// or https://.',
				name: 'url'
			)
			->text( label: 'Admin username', required: true, validate: fn ( string $v ) => $this->validate_username( $v ), name: 'admin_user' )
			->text( label: 'Admin email', required: true, validate: fn ( string $v ) => $this->validate_email( $v ), name: 'admin_email' )
			->select(
				label: 'Admin password',
				options: [
					'generate' => 'Generate a strong password (shown once, after the install)',
					'enter'    => 'Enter my own',
				],
				name: 'password_mode'
			)
			->add(
				fn ( array $r ) => 'enter' === $r['password_mode']
					? password(
						label: 'Admin password',
						required: true,
						validate: fn ( string $v ) => strlen( $v ) < 8 ? 'Password must be at least 8 characters.' : null,
					)
					: $this->generate_password(),
				'admin_password'
			)
			->submit();
	}

	/**
	 * Steps run in this process (no `process` key) or in a fresh one. `core install` and
	 * everything after it need WordPress loaded, which the runner only does for the
	 * command it was started with; and `db` commands need the wp-config constants it
	 * defines at startup, so the database is created here with mysqli instead.
	 *
	 * @param array<string, mixed> $a
	 * @return array<int, array<string, mixed>>
	 */
	private function install_steps( array $a, bool $has_config, string $locale ): array {
		$steps = [];

		if ( ! $has_config ) {
			if ( $a['create_db'] ) {
				$steps[] = [
					'label'    => 'Creating the database',
					'display'  => "(mysqli) CREATE DATABASE IF NOT EXISTS `{$a['dbname']}`",
					'callback' => fn () => $this->create_database( $a ),
				];
			}

			$command = 'config create ' . $this->flag( 'dbname', $a['dbname'] ) . ' ' . $this->flag( 'dbuser', $a['dbuser'] )
				. ' ' . $this->flag( 'dbhost', $a['dbhost'] ) . ' ' . $this->flag( 'dbprefix', $a['dbprefix'] );
			$display = $command;

			if ( '' !== $a['dbpass'] ) {
				$command .= ' ' . $this->flag( 'dbpass', $a['dbpass'] );
				$display .= " '--dbpass=***'";
			}

			$steps[] = [
				'label'   => 'Creating wp-config.php',
				'command' => $command,
				'display' => $display,
			];
		}

		$install = 'core install ' . $this->flag( 'url', $a['url'] ) . ' ' . $this->flag( 'title', $a['title'] )
			. ' ' . $this->flag( 'admin_user', $a['admin_user'] ) . ' ' . $this->flag( 'admin_email', $a['admin_email'] )
			. ( 'en_US' !== $locale ? ' ' . $this->flag( 'locale', $locale ) : '' );

		$steps[] = [
			'label'   => 'Installing WordPress',
			'command' => $install . ' ' . $this->flag( 'admin_password', $a['admin_password'] ) . ' --skip-email',
			'display' => $install . " '--admin_password=***' --skip-email",
			'process' => true,
		];

		// `core install` guesses the address from the directory it runs in, which tacks
		// the folder name onto the URL; set exactly what was asked for instead.
		$steps[] = [
			'label'   => 'Setting the site address',
			'command' => 'option update siteurl ' . escapeshellarg( $a['url'] ),
			'process' => true,
		];
		$steps[] = [
			'label'   => 'Setting the home address',
			'command' => 'option update home ' . escapeshellarg( $a['url'] ),
			'process' => true,
		];

		return $steps;
	}

	/**
	 * @param array<string, mixed> $a
	 * @return array<int, array<string, mixed>>
	 */
	private function addon_steps( array $a ): array {
		$steps  = [];
		$extras = $a['extras'];

		// Before the plugin installs below, so a chosen Akismet isn't removed again.
		if ( in_array( 'starter_plugins', $extras, true ) ) {
			$steps[] = [
				'label'    => 'Removing the bundled plugins',
				'command'  => 'plugin delete hello akismet',
				'process'  => true,
				'tolerate' => true,
			];
		}

		if ( in_array( 'sample_content', $extras, true ) ) {
			$steps[] = [
				'label'   => 'Removing the sample content',
				'command' => 'site empty --yes',
				'process' => true,
			];
		}

		if ( in_array( 'discourage', $extras, true ) ) {
			$steps[] = [
				'label'   => 'Discouraging search engines',
				'command' => 'option update blog_public 0',
				'process' => true,
			];
		}

		if ( in_array( 'permalinks', $extras, true ) ) {
			$steps[] = [
				'label'   => 'Setting pretty permalinks',
				'command' => "rewrite structure '/%postname%/'",
				'process' => true,
			];
		}

		foreach ( $a['plugins'] as $slug ) {
			$steps[] = [
				'label'   => 'Installing ' . $this->api->name( 'plugin', $slug ),
				'command' => 'plugin install ' . escapeshellarg( $slug ) . ' --activate',
				'process' => true,
			];
		}

		if ( ! empty( $a['theme'] ) ) {
			$steps[] = [
				'label'   => 'Installing ' . $this->api->name( 'theme', $a['theme'] ),
				'command' => 'theme install ' . escapeshellarg( $a['theme'] ) . ' --activate',
				'process' => true,
			];
		}

		return $steps;
	}

	/**
	 * @param array<int, array{0: string, 1: string}> $rows
	 */
	private function summarize( array $rows ): void {
		table( headers: [ 'Setting', 'Value' ], rows: $rows );
	}

	/**
	 * Confirm, then run the phase's steps. In a dry run, print them instead.
	 *
	 * @param array<int, array<string, mixed>> $steps
	 */
	private function run_phase( array $steps, string $question ): void {
		if ( $this->dry_run ) {
			info( 'Dry run — these commands would run, in order:' );

			foreach ( $steps as $step ) {
				$line = $step['display'] ?? $step['command'];
				WP_CLI::log( '  ' . ( 0 === strpos( $line, '(' ) ? '' : 'wp ' ) . $line );
			}

			return;
		}

		if ( ! confirm( label: $question, default: true ) ) {
			WP_CLI::error( 'Cancelled. Run `wp new` again to continue from here.' );
		}

		$this->execute( $steps );
	}

	/**
	 * Stops at the first failure.
	 *
	 * Known rough edge: the `process` steps pass the admin password (`core install`)
	 * on a child process's command line, so it is briefly visible in the process table.
	 * The inline steps (`config create` with the database password) are not exposed.
	 *
	 * @param array<int, array<string, mixed>> $steps
	 */
	private function execute( array $steps ): void {
		$done = [];

		foreach ( $steps as $step ) {
			$error = spin(
				function () use ( $step ) {
					if ( isset( $step['callback'] ) ) {
						return ( $step['callback'] )();
					}

					$result = $this->run_command( $step['command'], ! empty( $step['process'] ) ? [ 'launch' => true ] : [] );

					return 0 === $result->return_code || ! empty( $step['tolerate'] )
						? null
						: ( $result->stderr ?: $result->stdout ?: 'exit code ' . $result->return_code );
				},
				$step['label'] . '...'
			);

			if ( null !== $error ) {
				WP_CLI::error(
					"{$step['label']} failed:\n{$error}"
					. "\n\n" . ( $done ? 'Completed before that: ' . implode( ', ', $done ) . ".\n" : '' )
					. 'Fix the problem and run `wp new` again; it picks up from what is already in place.'
				);
			}

			$done[] = $step['label'];
		}
	}

	/**
	 * Tests the credentials before anything is written, so a typo fails here rather
	 * than halfway through the run. Skipped when mysqli isn't available.
	 *
	 * @param array<string, mixed> $r Answers so far.
	 */
	private function check_db( array $r, string $pass ): ?string {
		if ( ! function_exists( 'mysqli_connect' ) ) {
			return null;
		}

		// No database to select when it's about to be created.
		$link = $this->db_connect( $r['dbhost'], $r['dbuser'], $pass, $r['create_db'] ? null : $r['dbname'] );

		if ( is_string( $link ) ) {
			return 1049 === mysqli_connect_errno()
				? "Database '{$r['dbname']}' doesn't exist. Press Ctrl+U to go back and choose to create it, or create it first."
				: $link;
		}

		mysqli_close( $link );

		return null;
	}

	/**
	 * Create the database with mysqli. `wp db create` can't be used from here: it needs
	 * the wp-config constants, which don't exist yet, and the mysql client binary.
	 *
	 * @param array<string, mixed> $a
	 * @return string|null Error message, or null on success.
	 */
	private function create_database( array $a ): ?string {
		$link = $this->db_connect( $a['dbhost'], $a['dbuser'], $a['dbpass'], null );

		if ( is_string( $link ) ) {
			return $link;
		}

		// utf8mb4 is what `config create` writes as DB_CHARSET by default.
		$ok    = mysqli_query( $link, 'CREATE DATABASE IF NOT EXISTS `' . str_replace( '`', '``', $a['dbname'] ) . '` DEFAULT CHARACTER SET utf8mb4' );
		$error = $ok ? null : mysqli_error( $link );

		mysqli_close( $link );

		return $error;
	}

	/**
	 * @return \mysqli|string Connection, or an error message.
	 */
	private function db_connect( string $host, string $user, string $pass, ?string $name ) {
		$port   = null;
		$socket = null;

		if ( preg_match( '/^(.*):(\d+)$/', $host, $m ) ) {
			list( , $host, $port ) = $m;
			$port                  = (int) $port;
		} elseif ( false !== strpos( $host, ':/' ) ) {
			list( $host, $socket ) = explode( ':', $host, 2 );
		}

		mysqli_report( MYSQLI_REPORT_OFF );

		$link = @mysqli_connect( $host, $user, $pass, $name, $port, $socket );

		return $link ?: 'Could not connect: ' . mysqli_connect_error();
	}

	/**
	 * @return array<string, string> Locale code => label; a short default list when $term is empty.
	 */
	private function match_locales( string $term ): array {
		$all = $this->api->locales();

		if ( '' === trim( $term ) ) {
			return array_intersect_key( $all, array_flip( [ 'en_US', 'en_GB', 'es_ES', 'fr_FR', 'de_DE', 'pt_BR', 'ja' ] ) );
		}

		return array_slice(
			array_filter( $all, fn ( $label, $code ) => false !== stripos( $label . ' ' . $code, $term ), ARRAY_FILTER_USE_BOTH ),
			0,
			10,
			true
		);
	}

	/**
	 * Letters and digits only: no quoting surprises when the value passes through
	 * the command string.
	 */
	private function generate_password( int $length = 24 ): string {
		$chars    = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$password = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$password .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}

		return $password;
	}
}
