# wp-cli-prompts

A proof of concept: what would a modern, interactive terminal UI for WP-CLI
look like, built on [Laravel Prompts](https://github.com/laravel/prompts)?

Two commands:

- `wp plugin install-wizard` — search the wp.org plugin directory, see plugin
  details, and install (with optional activation) via a live spinner.
- `wp user wizard` — a validated form for creating a WordPress user: username,
  email, password, role (populated from `wp_roles()`), optional name fields,
  a summary table, and a final confirmation.

## The most important finding: symfony/console conflict

**Laravel Prompts cannot run inside WP-CLI 2.12.x's official `.phar`, or any
`wp` installed via Homebrew.** (This package falls back to plain prompts there;
see [below](#plain-prompt-fallback).) Laravel Prompts requires `symfony/console`
`^6.2|^7.0`. The 2.12.x phar bundles `symfony/console` v3.4.47 (held there by
`config.platform.php = 5.6` in `wp-cli-bundle`, via the bundled
`package-command` → `composer/composer`). Because the phar's autoloader boots
first, `Laravel\Prompts\Output\ConsoleOutput` ends up extending the v3.4
`StreamOutput` and PHP fatals immediately with a method-signature
incompatibility error.

This is **not** a deep architectural conflict — `wp-cli/wp-cli` core itself
has no dependency on `symfony/console` at all (3.0 dropped `symfony/finder`
too). It's an artifact of how the distributed phar is built. The fix that
makes this POC possible: install `wp-cli/wp-cli-bundle` and `laravel/prompts`
together in one Composer dependency tree, so Composer resolves a single
consistent `symfony/console` (here, v6.4.45) for everything.

### Update: WP-CLI 3.0 already fixes this in practice

`wp-cli-bundle` trunk raised `config.platform.php` from `5.6` to `7.2.24`,
which moves the phar's bundled `symfony/console` from **3.4.47 to 5.4.47**.
Verified against the current `3.0.0-alpha` nightly phar:

|                                          | 2.12.0 phar (3.4.47) | 3.0-alpha phar (5.4.47) |
| ---------------------------------------- | -------------------- | ----------------------- |
| `Table::setColumnMaxWidth()`             | missing              | present                 |
| `TableStyle::setHorizontalBorderChars()` | missing              | present                 |
| `TableStyle::setVerticalBorderChars()`   | missing              | present                 |
| `TableStyle::setDefaultCrossingChar()`   | missing              | present                 |
| Both wizards, real TTY                   | instant fatal        | **run correctly**       |

5.4.47 does **not** satisfy Prompts' declared `^6.2`, but Prompts only touches
five Console types (`Helper\Table`, `Helper\TableStyle`,
`Helper\TableSeparator`, `Terminal`, `Output\ConsoleOutput` /
`Output\OutputInterface`), and 5.4 provides all of them. Driven through the
3.0 phar with `expect`, `user wizard` created a real user and
`plugin install-wizard` genuinely installed and activated a plugin —
`text()`, `password()`, `select()`, `table()`, `confirm()`, `search()`,
`note()` and `spin()` all rendered correctly.

**This works by accident, not by contract.** `ReflectionClass` confirms
`Table` loads from _inside the phar_ — the phar's copy wins the autoload race,
and this package's declared `^6.2` is never honored at runtime. It works
solely because 5.4's API surface happens to cover Prompts' usage. A bump on
either side breaks it silently. That fragility is the substance of
[wp-cli/wp-cli#5920](https://github.com/wp-cli/wp-cli/issues/5920).

Note also that `package-command` moved to `require-dev`
([bundle#846](https://github.com/wp-cli/wp-cli-bundle/pull/846)), but
`utils/make-phar.php` globs whole vendor directories (`->in(vendor/symfony)`,
`->in(vendor/composer)`), so it is still baked into the phar regardless.

**Concrete implication for real-world adoption**, in descending order of
how "for free" they are:

1. **Already done:** the platform bump to 7.2.24 on `wp-cli-bundle` trunk.
   Unreleased as of this writing — it ships with WP-CLI 3.0.
2. **In flight:**
   [package-command#248](https://github.com/wp-cli/package-command/pull/248)
   (schlessera) stops embedding Composer entirely, driving `composer.phar` as
   a child process. This removes `symfony/*` from the phar altogether, so
   there is nothing left to collide with. Alternative:
   [bundle#1110](https://github.com/wp-cli/wp-cli-bundle/pull/1110)
   (swissspidy) keeps Composer but prefixes its tree under `WP_CLI\Vendor`
   with [PHP-Scoper](https://github.com/humbug/php-scoper).
3. Laravel Prompts' output layer gets its handful of Symfony touchpoints
   vendored or abstracted away.

Getting the phar to a `symfony/console` that genuinely satisfies `^6.2` is a
separate, much larger ask: 6.x requires PHP ≥ 8.1, and WP-CLI's floor is
7.2.24. Composer resolution confirms the threshold — platform `8.0` still
yields 5.4.47; only `8.1` reaches 6.4.

### The guard in `command.php`

`command.php` reads the bundled `symfony/console` version straight out of the
phar's own `vendor/composer/installed.php` over the `phar://` stream. If it is
older than 5.4, or PHP is older than 8.1, the package switches to the
[plain-prompt fallback](#plain-prompt-fallback) instead of loading Laravel
Prompts at all. So WP-CLI 3.0 gets the full UI and 2.12.x gets the fallback,
never the raw fatal.

The decision has to happen _before_ `vendor/autoload.php` is required:
Composer's generated `platform_check.php` throws on anything below the
dependency tree's PHP floor, so on old PHP even loading the autoloader is
fatal.

It deliberately does **not** probe Symfony classes to decide. Doing so was
tried and is actively harmful: `method_exists()` on those types _loads_ them,
and at `--require` time this package's autoloader wins, so the probe imports
our 6.4 `Table` on top of the phar's 3.4 `Helper` base class, passes, and the
wizard then dies deeper in with `Call to undefined method Helper::width()` —
a mixed vendor tree the guard itself created.

## Plain-prompt fallback

When Laravel Prompts can't run (PHP < 8.1, or a phar bundling
`symfony/console` < 5.4), `wp user wizard` becomes a line-by-line version of
the same form, built on php-cli-tools (`\cli\prompt()`, `\cli\menu()`,
`\cli\choose()`). Core WP-CLI renders its tables and colors with that
library, and core's own `--prompt` flag is the same line-at-a-time idea
(implemented with raw `readline()`). Every WP-CLI build ships php-cli-tools, so the fallback depends on nothing this package
installs; it's loaded by hand, without Composer's autoloader, and written to
PHP 7.2 syntax (WP-CLI's floor).

It keeps the same fields, validation (with re-prompting), role list from
`wp_roles()`, summary table (`WP_CLI\Utils\format_items()`), and final
confirmation. Differences from the full UI:

- The role is picked by number, with no default: an accidental Enter
  re-asks instead of creating an administrator.
- The password is typed with echo off. cli-tools' own hidden prompt leaves
  the terminal **echo-less if the user presses Ctrl-C**, because PHP retries
  a blocked `fgets()` internally, so no signal handler can run first. The
  fallback reads through `stream_select()` with a `pcntl` SIGINT handler that
  restores echo and exits 130. Without `pcntl` it degrades to cli-tools'
  behavior.
- Ctrl-D at any prompt cancels cleanly.

Core's `--prompt` itself wasn't used directly: it echoes the password,
accepts any free-text role, and doesn't validate.

`plugin install-wizard` has no fallback, since live search-then-select
doesn't map onto line prompts. In fallback mode it's still registered, but
only to explain why it's unavailable and point at
`wp plugin search` / `wp plugin install`.

Verified: fallback end-to-end on the 2.12 phar under PHP 8.3 and 8.1
(including every validation branch, decline, Ctrl-D, and Ctrl-C at the
password prompt), and the PHP-version trigger by raising the floor in a
copy. Not verified on an actual PHP < 8.1 binary.

## Requirements

- **WP-CLI 3.0 nightly** for the full UI. On 2.12.x (including Homebrew
  installs), `user wizard` runs the plain-prompt fallback and
  `plugin install-wizard` is unavailable.
- **PHP 8.1+** for the full UI. The `user wizard` fallback runs on any PHP
  WP-CLI supports.
- Composer 2.x
- A WordPress install that WP-CLI can already talk to

## Installation

This package isn't published yet, so install it from a clone:

```bash
git clone https://github.com/nathanrice/wp-cli-prompts.git
cd wp-cli-prompts
composer install
```

To get the WP-CLI 3.0 nightly, if you don't already have it:

```bash
wp cli update --nightly
```

or download the phar directly:

```bash
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli-nightly.phar
```

## Usage

Load the package with `--require` from your WordPress directory, or anywhere
using `--path`:

```bash
wp --require=/path/to/wp-cli-prompts/command.php user wizard
wp --require=/path/to/wp-cli-prompts/command.php plugin install-wizard
```

To skip typing `--require` every time, add it to a `wp-cli.yml` in your
WordPress root (or `~/.wp-cli/config.yml` for all sites):

```yaml
require:
  - /path/to/wp-cli-prompts/command.php
```

`composer install` also provides `./vendor/bin/wp`: its own copy of WP-CLI,
resolved in the same dependency tree as Laravel Prompts so nothing collides.
It doesn't depend on whichever `wp` you have installed. Pass
`--require=./command.php --path=/path/to/wordpress`.

Both commands (and the fallback) require a real terminal (TTY). Piping stdin
(e.g. in a script or CI) produces a clean WP-CLI error rather than an
interactive-prompt stack trace.

## What's deliberately out of scope (this is a quick POC)

- No automated tests (PHPUnit/Behat)
- No CI
- Only two commands
- No `--yes` / non-interactive flag parity — these commands are TTY-only
- No PHP-Scoper prefixing of this package's own dependencies (see finding
  above). Unnecessary against a 3.0 phar, but it is what would make the
  package robust against the phar's copy winning the autoload race.

## Known rough edges to fix before any real use

- `user wizard` passes `--user_pass=` on the WP-CLI command line via
  `WP_CLI::runcommand()`, which briefly exposes the plaintext password in
  the OS process table. Fine for a local POC demo; not acceptable for
  anything beyond that. A real implementation should use WP-CLI's STDIN
  password-prompt convention instead.
- No handling for wp.org API rate limiting or network failures beyond an
  empty result set.
- Minimal error handling generally — this optimizes for demonstrating the
  interactive flow, not for resilience.

## Next steps

Candidates include a PHP-Scoper prototype, a search-replace wizard as a
third example, `--yes` parity, test coverage, and publishing the package so
it installs with `wp package install`.

The highest-leverage next step has changed shape now that WP-CLI 3.0's phar is
verified working. The original ask — "rebuild the phar against a modern
Symfony baseline" — is effectively already done, and asking for a genuine
`^6.2` would mean asking WP-CLI to raise its minimum PHP to 8.1, which is a
project-policy decision rather than a patch.

What remains worth contributing upstream is the observation itself: a real
package running on the 3.0 phar purely because 5.4's API surface happens to
cover Laravel Prompts' six touchpoints, with its own declared `^6.2` silently
ignored at runtime. That is a concrete, reproducible instance of the problem
[wp-cli/wp-cli#5920](https://github.com/wp-cli/wp-cli/issues/5920) describes,
and evidence for whichever of
[package-command#248](https://github.com/wp-cli/package-command/pull/248) or
[bundle#1110](https://github.com/wp-cli/wp-cli-bundle/pull/1110) the
maintainers adopt — neither of which currently has a non-synthetic test case
for it.
