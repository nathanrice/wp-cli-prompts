<?php
/**
 * WP-CLI Prompts POC — entry point.
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

// Composer's `autoload.files` also loads this file when vendor/autoload.php is
// required below, so a second pass would redeclare everything.
if ( defined( 'WP_CLI_PROMPTS_LOADED' ) ) {
	return;
}
define( 'WP_CLI_PROMPTS_LOADED', true );

// The function declarations below are wrapped in function_exists() because PHP hoists
// unconditional top-level functions at compile time, i.e. before the early return
// above could stop a second include from redeclaring them.

/**
 * Laravel Prompts' floor, and the floor `vendor/composer/platform_check.php`
 * enforces (composer.json pins `config.platform.php` to match). Below it, even
 * *requiring* the autoloader throws, so the mode decision below must happen first.
 */
const WP_CLI_PROMPTS_MIN_PHP = '8.1';

/**
 * Lowest symfony/console verified to run both wizards end-to-end.
 *
 * Laravel Prompts *declares* `^6.2`, but it only touches five Console types, and
 * WP-CLI 3.0's bundled 5.4.47 provides all of them — both wizards were driven
 * through that phar with a real TTY and completed correctly. 2.12.x's 3.4.47 is
 * missing four Table/TableStyle methods and fatals immediately.
 *
 * Nothing between 3.4 and 5.4 has been tested, so 5.4 is the floor this guard
 * enforces: the oldest version actually known to work, not the oldest that might.
 */
const WP_CLI_PROMPTS_MIN_CONSOLE = '5.4';

if ( ! function_exists( 'wp_cli_prompts_phar_console_version' ) ) {
	/**
	 * Reads the symfony/console version bundled inside a .phar, without loading it.
	 *
	 * Deliberately does not touch `class_exists()`, `Composer\InstalledVersions`, or
	 * any Symfony class. Every previous attempt at this guard did, and every one was
	 * a race or worse — see decisions log entries 1, 3 and 7:
	 *
	 * - A version check via `Composer\InstalledVersions` reads whichever copy of that
	 *   class won the autoload race, so it can cheerfully report *our* dependency
	 *   tree while physically executing inside the phar.
	 * - A capability check (`method_exists()` on the Console types Prompts uses) is
	 *   worse than useless: probing them *loads* them, and at `--require` time this
	 *   package's autoloader wins, so the probe silently imports our 6.4 `Table` on
	 *   top of the phar's 3.4 `Helper` base class and passes. The wizard then dies
	 *   deeper in with `Call to undefined method Helper::width()` — a mixed vendor
	 *   tree the guard itself created.
	 *
	 * Reading the phar's own `installed.php` off the `phar://` stream avoids all of
	 * it. It is a static fact about the archive that launched this process, fixed
	 * before any autoloader ran, and observing it loads nothing.
	 *
	 * @return string|null Version string, or null if absent/undeterminable.
	 */
	function wp_cli_prompts_phar_console_version(): ?string {
		if ( ! defined( 'WP_CLI_PHAR_PATH' ) ) {
			return null;
		}

		// WP_CLI_PHAR_PATH comes from Phar::running( true ), so it already carries the
		// `phar://` scheme — prepending another one silently yields an unreadable path
		// and the guard never fires.
		$installed = WP_CLI_PHAR_PATH . '/vendor/composer/installed.php';

		if ( ! is_readable( $installed ) ) {
			return null;
		}

		$data = @include $installed;

		if ( ! is_array( $data ) || ! isset( $data['versions']['symfony/console']['pretty_version'] ) ) {
			// No symfony/console in the phar at all is a *good* outcome — nothing to
			// collide with, so this package's own copy is used unopposed.
			return null;
		}

		return ltrim( (string) $data['versions']['symfony/console']['pretty_version'], 'v' );
	}
}

if ( ! function_exists( 'wp_cli_prompts_fallback_reason' ) ) {
	/**
	 * Why Laravel Prompts can't run here, or null if it can.
	 *
	 * Nothing in here may load Composer's autoloader or any Symfony/Laravel class.
	 */
	function wp_cli_prompts_fallback_reason(): ?string {
		if ( version_compare( PHP_VERSION, WP_CLI_PROMPTS_MIN_PHP, '<' ) ) {
			return sprintf(
				'Laravel Prompts needs PHP %s or newer; this is PHP %s.',
				WP_CLI_PROMPTS_MIN_PHP,
				PHP_VERSION
			);
		}

		$console = wp_cli_prompts_phar_console_version();

		if ( null !== $console && version_compare( $console, WP_CLI_PROMPTS_MIN_CONSOLE, '<' ) ) {
			// Its copy wins the autoload race over this package's own, and Laravel Prompts
			// then fatals extending the wrong StreamOutput. See README.
			return sprintf(
				'this WP-CLI phar bundles symfony/console %s, older than the %s Laravel Prompts needs (WP-CLI 3.0 bundles 5.4.47).',
				$console,
				WP_CLI_PROMPTS_MIN_CONSOLE
			);
		}

		return null;
	}
}

$wp_cli_prompts_fallback_reason = wp_cli_prompts_fallback_reason();

if ( null === $wp_cli_prompts_fallback_reason ) {
	// Absent when installed via `wp package install`: dependencies then live in the
	// shared packages vendor/, whose autoloader is what loaded this file.
	if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	WP_CLI::add_command( 'plugin install-wizard', 'WP_CLI\Prompts\Plugin_Install_Command' );
	WP_CLI::add_command( 'user wizard', 'WP_CLI\Prompts\User_Wizard_Command' );
	WP_CLI::add_command( 'new', 'WP_CLI\Prompts\New_Command' );

	return;
}

// Fallback: loaded by hand, without Composer's autoloader (see WP_CLI_PROMPTS_MIN_PHP).
define( 'WP_CLI_PROMPTS_FALLBACK_REASON', $wp_cli_prompts_fallback_reason );

require_once __DIR__ . '/src/BaseCommand.php';
require_once __DIR__ . '/src/User_Wizard_Fallback_Command.php';
require_once __DIR__ . '/src/New_Fallback_Command.php';

WP_CLI::add_command( 'user wizard', 'WP_CLI\Prompts\User_Wizard_Fallback_Command' );
WP_CLI::add_command( 'new', 'WP_CLI\Prompts\New_Fallback_Command' );

// Registered anyway so the user gets a reason, not "not a registered subcommand".
// A live search-then-select flow has no sensible line-by-line equivalent.
WP_CLI::add_command(
	'plugin install-wizard',
	function () {
		WP_CLI::error(
			'plugin install-wizard is unavailable: ' . WP_CLI_PROMPTS_FALLBACK_REASON . "\n"
			. 'Use `wp plugin search <term>` and `wp plugin install <slug> --activate` instead.'
		);
	},
	array(
		'shortdesc' => 'Search and install a plugin interactively (unavailable in this environment).',
		'when'      => 'before_wp_load',
	)
);
