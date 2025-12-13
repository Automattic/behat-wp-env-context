<?php
/**
 * Abstract base class for wp-env based Behat testing.
 *
 * Provides reusable WP-CLI command execution, output handling, database cleanup,
 * and standard step definitions for WordPress plugin testing with wp-env.
 *
 * Portions of this code are derived from wp-cli/wp-cli-tests, which is:
 * Copyright (C) WP-CLI Contributors
 * Licensed under GPL-2.0-or-later
 *
 * @package Automattic\BehatWpEnv
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Automattic\BehatWpEnv;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use RuntimeException;

/**
 * Abstract base context for wp-env based Behat testing.
 *
 * This class provides:
 * - WP-CLI command execution via wp-env
 * - Output filtering (removes wp-env status messages)
 * - Multi-line error parsing
 * - Variable substitution ({VAR} in commands and assertions)
 * - Database cleanup between scenarios
 * - Standard Behat step definitions
 * - Extensibility for plugin-specific needs
 *
 * @since 1.0.0
 */
abstract class WpEnvFeatureContext implements Context {

	/**
	 * Command output (STDOUT).
	 *
	 * @var string
	 */
	protected $output = '';

	/**
	 * Command error output (STDERR).
	 *
	 * @var string
	 */
	protected $error_output = '';

	/**
	 * Command exit code.
	 *
	 * @var int
	 */
	protected $exit_code = 0;

	/**
	 * Previous command for "run previous command again" step.
	 *
	 * @var string|null
	 */
	private $previous_command = null;

	/**
	 * Saved variables from STDOUT for use in subsequent commands/assertions.
	 *
	 * Variables can be saved with the step:
	 * Given save STDOUT as {VARIABLE_NAME}
	 *
	 * Then referenced in commands:
	 * When I run `post meta get {POST_ID} custom_field`
	 *
	 * @var array<string, string>
	 */
	protected $variables = array();

	/**
	 * Get the plugin slug for wp-env command execution.
	 *
	 * This is used to set the working directory when executing WP-CLI commands
	 * inside the wp-env container. Should match your plugin's directory name.
	 *
	 * Example return values:
	 * - 'co-authors-plus'
	 * - 'wpcom-legacy-redirector'
	 * - 'my-awesome-plugin'
	 *
	 * @return string Plugin directory name (e.g., 'co-authors-plus')
	 */
	abstract protected function get_plugin_slug(): string;

	/**
	 * Get the wp-env command prefix.
	 *
	 * Override this if you need to customize how wp-env is invoked.
	 * Default uses 'wp-env' which works if wp-env is globally installed
	 * or available in node_modules/.bin via npm scripts.
	 *
	 * @return string Command to invoke wp-env (default: 'wp-env')
	 */
	protected function get_wp_env_command(): string {
		return 'wp-env';
	}

	/**
	 * Plugin-specific database cleanup.
	 *
	 * Override this method to clean up custom post types, taxonomies, options,
	 * or any other plugin-specific data that should be reset between scenarios.
	 *
	 * This is called after generic cleanup (posts, users, cache) during
	 * reset_database_state().
	 *
	 * Example implementation:
	 * <code>
	 * protected function plugin_specific_cleanup(): void {
	 *     // Delete custom post type posts
	 *     $this->run_wp_cli_command( 'post list --post_type=my-cpt --format=ids', false );
	 *     $ids = trim( $this->output );
	 *     if ( ! empty( $ids ) ) {
	 *         $this->run_wp_cli_command( "post delete {$ids} --force", false );
	 *     }
	 *
	 *     // Delete custom taxonomy terms
	 *     $this->run_wp_cli_command( 'term list my-taxonomy --field=term_id', false );
	 *     $term_ids = array_filter( explode( "\n", trim( $this->output ) ) );
	 *     foreach ( $term_ids as $term_id ) {
	 *         $this->run_wp_cli_command( "term delete my-taxonomy {$term_id}", false );
	 *     }
	 * }
	 * </code>
	 *
	 * @return void
	 */
	protected function plugin_specific_cleanup(): void {
		// Default: no plugin-specific cleanup
	}

	/**
	 * Execute a WP-CLI command inside wp-env tests container.
	 *
	 * This method:
	 * 1. Replaces any {VARIABLES} in the command
	 * 2. Executes the command in wp-env's tests-cli container
	 * 3. Filters out wp-env status messages from output
	 * 4. Parses STDERR from combined output
	 * 5. Handles multi-line error messages
	 *
	 * @param string $command     The WP-CLI command to execute (without 'wp' prefix).
	 * @param bool   $should_fail Whether the command is expected to fail.
	 * @return void
	 */
	protected function run_wp_cli_command( string $command, bool $should_fail = false ): void {
		// Replace saved variables in command.
		$command = $this->replace_variables( $command );

		// Escape command for shell execution.
		$escaped_command = str_replace( "'", "'\\''", $command );

		// Run inside wp-env tests-cli container.
		$wp_env_cmd = $this->get_wp_env_command();
		$plugin_dir = $this->get_plugin_slug();

		$exec_command = sprintf(
			"%s run tests-cli --env-cwd=wp-content/plugins/%s bash -c 'wp %s 2>&1'",
			$wp_env_cmd,
			$plugin_dir,
			$escaped_command
		);

		exec( $exec_command, $output_lines, $exit_code );

		// Filter out wp-env status messages.
		$filtered_lines = array_filter(
			$output_lines,
			function ( $line ) use ( $output_lines ) {
				// Remove wp-env status lines (ℹ, ✔, ✖) and excess blank lines.
				return ! ( 0 === strpos( $line, 'ℹ ' ) ||
						0 === strpos( $line, '✔ ' ) ||
						0 === strpos( $line, '✖ ' ) ||
						'' === trim( $line ) && count( $output_lines ) > 1 );
			}
		);

		$output              = implode( "\n", $filtered_lines );
		$this->output        = $output;
		$this->error_output  = '';
		$this->exit_code     = $exit_code;

		// Parse STDERR from combined output.
		// WP-CLI prefixes errors with "Error:" or "Warning:".
		// Multi-line error messages have continuation lines that are indented.
		if ( 0 !== $exit_code || $should_fail ) {
			$error_lines    = array();
			$in_error_block = false;

			foreach ( $filtered_lines as $line ) {
				// Check if this line starts an error block.
				if ( 0 === strpos( $line, 'Error:' ) || 0 === strpos( $line, 'Warning:' ) ) {
					$error_lines[]  = $line;
					$in_error_block = true;
				} elseif ( $in_error_block && ( 0 === strpos( $line, ' ' ) || 0 === strpos( $line, "\t" ) ) ) {
					// Continuation line (indented) - part of the error message.
					$error_lines[] = $line;
				} else {
					// Not an error line, end the error block.
					$in_error_block = false;
				}
			}

			if ( ! empty( $error_lines ) ) {
				$this->error_output = implode( "\n", $error_lines );
				// Remove error lines from STDOUT.
				$non_error_lines = array_diff( $filtered_lines, $error_lines );
				$this->output    = implode( "\n", $non_error_lines );
			}
		}
	}

	/**
	 * Reset database state between scenarios.
	 *
	 * This method provides a comprehensive database cleanup to ensure test isolation
	 * without recreating WordPress. It performs:
	 *
	 * 1. Generic cleanup (posts, users, cache)
	 * 2. Plugin-specific cleanup (via plugin_specific_cleanup() hook)
	 * 3. Saved variables reset
	 *
	 * This is called:
	 * - In "Given a WP installation" steps
	 * - After each scenario in afterScenario hook
	 *
	 * @return void
	 */
	protected function reset_database_state(): void {
		// Delete all posts except defaults.
		$this->run_wp_cli_command( 'post list --post_type=any --format=ids', false );
		$post_ids = trim( $this->output );

		if ( ! empty( $post_ids ) ) {
			$this->run_wp_cli_command( "post delete {$post_ids} --force", false );
		}

		// Delete all users except admin (ID 1).
		$this->run_wp_cli_command( 'user list --field=ID', false );
		$user_ids = array_filter(
			explode( "\n", trim( $this->output ) ),
			function ( $id ) {
				return '1' !== trim( $id ) && ! empty( trim( $id ) );
			}
		);

		if ( ! empty( $user_ids ) ) {
			foreach ( $user_ids as $user_id ) {
				$this->run_wp_cli_command( "user delete {$user_id} --yes", false );
			}
		}

		// Clean transients.
		$this->run_wp_cli_command( 'transient delete --all', false );

		// Flush cache.
		$this->run_wp_cli_command( 'cache flush', false );

		// Plugin-specific cleanup (custom post types, taxonomies, etc.)
		$this->plugin_specific_cleanup();

		// Reset saved variables.
		$this->variables = array();
	}

	/**
	 * Replace saved variables in text.
	 *
	 * Variables are saved with the step:
	 *   Given save STDOUT as {VARIABLE_NAME}
	 *
	 * Then referenced as {VARIABLE_NAME} in:
	 * - Command arguments
	 * - Assertion expectations
	 *
	 * @param string $text Text containing {VARIABLE} placeholders.
	 * @return string Text with variables replaced by their saved values.
	 */
	protected function replace_variables( string $text ): string {
		foreach ( $this->variables as $var => $value ) {
			$text = str_replace( '{' . $var . '}', $value, $text );
		}
		return $text;
	}

	/**
	 * Create an mu-plugin for test setup.
	 *
	 * This is a helper for injecting PHP code into WordPress during tests.
	 * Useful for adding filters, bypassing validations, or modifying behavior.
	 *
	 * Example usage:
	 * <code>
	 * $this->create_mu_plugin(
	 *     'disable-emails',
	 *     "<?php add_filter( 'pre_wp_mail', '__return_false' );"
	 * );
	 * </code>
	 *
	 * @param string $slug Unique slug for the mu-plugin file.
	 * @param string $code PHP code to include in the mu-plugin.
	 * @return void
	 */
	protected function create_mu_plugin( string $slug, string $code ): void {
		// Use base64 encoding to avoid shell escaping issues.
		$encoded = base64_encode( $code );

		$command = sprintf(
			'eval \'if ( ! is_dir( WPMU_PLUGIN_DIR ) ) { mkdir( WPMU_PLUGIN_DIR, 0755, true ); } file_put_contents( WPMU_PLUGIN_DIR . "/%s.php", base64_decode( "%s" ) );\'',
			$slug,
			$encoded
		);

		$this->run_wp_cli_command( $command, false );

		if ( 0 !== $this->exit_code ) {
			throw new RuntimeException(
				'Failed to create mu-plugin: ' . $this->output
			);
		}
	}

	/**
	 * Remove an mu-plugin created by create_mu_plugin().
	 *
	 * @param string $slug Slug of the mu-plugin to remove.
	 * @return void
	 */
	protected function remove_mu_plugin( string $slug ): void {
		$command = sprintf(
			'eval \'if ( file_exists( WPMU_PLUGIN_DIR . "/%s.php" ) ) { unlink( WPMU_PLUGIN_DIR . "/%s.php" ); }\'',
			$slug,
			$slug
		);

		$this->run_wp_cli_command( $command, false );
	}

	/**
	 * Set up clean state before each scenario.
	 *
	 * @BeforeScenario
	 * @param BeforeScenarioScope $scope Scenario scope.
	 * @return void
	 */
	public function before_scenario( BeforeScenarioScope $scope ): void {
		// Hook for subclasses to add before scenario setup.
		// Clean state will be set up by Given steps.
	}

	/**
	 * Clean up after each scenario.
	 *
	 * @AfterScenario
	 * @param AfterScenarioScope $scope Scenario scope.
	 * @return void
	 */
	public function after_scenario( AfterScenarioScope $scope ): void {
		// Clean up database state after scenario.
		$this->reset_database_state();
	}

	/**
	 * Set up a basic WP installation.
	 *
	 * Ensures clean database state without reinstalling WordPress.
	 *
	 * @Given a WP install
	 * @Given a WP installation
	 * @return void
	 */
	public function given_a_wp_installation(): void {
		// wp-env is already running with WordPress installed.
		// Just ensure we have a clean database state.
		$this->reset_database_state();
	}

	/**
	 * Save STDOUT to a variable for later use.
	 *
	 * Useful for capturing dynamic values (like post IDs) and using them
	 * in subsequent commands.
	 *
	 * Example:
	 *   When I run `post create --post_title="Test" --porcelain`
	 *   Given save STDOUT as {POST_ID}
	 *   When I run `post meta get {POST_ID} custom_field`
	 *
	 * @Given /^save STDOUT as \{([A-Z_][A-Z_0-9]*)\}$/
	 * @param string $var_name Variable name (uppercase with underscores).
	 * @return void
	 */
	public function save_stdout_as( string $var_name ): void {
		$this->variables[ $var_name ] = trim( $this->output );
	}

	/**
	 * Run a WP-CLI command that is expected to succeed.
	 *
	 * If the command fails (non-zero exit code), the test will fail.
	 *
	 * @When I run :command
	 * @When /^I run `([^`]+)`$/
	 * @param string $command The command to run (without 'wp' prefix).
	 * @return void
	 */
	public function i_run( string $command ): void {
		// Remove backticks if present.
		$command = trim( $command, '`' );

		// Remove 'wp ' prefix if present (we add it in run_wp_cli_command).
		if ( 0 === strpos( $command, 'wp ' ) ) {
			$command = substr( $command, 3 );
		}

		$this->previous_command = $command;
		$this->run_wp_cli_command( $command, false );
	}

	/**
	 * Run a WP-CLI command that may fail.
	 *
	 * Unlike "I run", this allows the command to fail without failing the test.
	 * Useful for testing error conditions.
	 *
	 * @When I try :command
	 * @When /^I try `([^`]+)`$/
	 * @param string $command The command to try (without 'wp' prefix).
	 * @return void
	 */
	public function i_try( string $command ): void {
		// Remove backticks if present.
		$command = trim( $command, '`' );

		// Remove 'wp ' prefix if present.
		if ( 0 === strpos( $command, 'wp ' ) ) {
			$command = substr( $command, 3 );
		}

		$this->previous_command = $command;
		$this->run_wp_cli_command( $command, true );
	}

	/**
	 * Run the previous command again.
	 *
	 * Useful for testing idempotency or retrying operations.
	 *
	 * @When /^I (run|try) the previous command again$/
	 * @param string $action Either 'run' or 'try'.
	 * @return void
	 */
	public function i_run_the_previous_command_again( string $action ): void {
		if ( empty( $this->previous_command ) ) {
			throw new RuntimeException( 'No previous command to run' );
		}

		if ( 'run' === $action ) {
			$this->i_run( $this->previous_command );
		} else {
			$this->i_try( $this->previous_command );
		}
	}

	/**
	 * Assert that STDOUT exactly matches expected output.
	 *
	 * Performs exact string comparison after trimming whitespace.
	 *
	 * @Then STDOUT should be:
	 * @param PyStringNode $expected Expected output (multiline).
	 * @return void
	 */
	public function stdout_should_be( PyStringNode $expected ): void {
		$actual        = trim( $this->output );
		$expected_text = $this->replace_variables( trim( $expected->getRaw() ) );

		if ( $actual !== $expected_text ) {
			throw new RuntimeException(
				sprintf(
					"STDOUT does not match.\nExpected:\n%s\n\nActual:\n%s",
					$expected_text,
					$actual
				)
			);
		}
	}

	/**
	 * Assert that STDOUT contains expected text.
	 *
	 * Performs substring match (case-sensitive).
	 *
	 * @Then STDOUT should contain:
	 * @param PyStringNode $expected Expected text to find (multiline).
	 * @return void
	 */
	public function stdout_should_contain( PyStringNode $expected ): void {
		$actual        = trim( $this->output );
		$expected_text = $this->replace_variables( trim( $expected->getRaw() ) );

		if ( false === strpos( $actual, $expected_text ) ) {
			throw new RuntimeException(
				sprintf(
					"STDOUT does not contain expected text.\nExpected to find:\n%s\n\nActual output:\n%s",
					$expected_text,
					$actual
				)
			);
		}
	}

	/**
	 * Assert that STDERR exactly matches expected output.
	 *
	 * Performs exact string comparison after trimming whitespace.
	 *
	 * @Then STDERR should be:
	 * @param PyStringNode $expected Expected error output (multiline).
	 * @return void
	 */
	public function stderr_should_be( PyStringNode $expected ): void {
		$actual        = trim( $this->error_output );
		$expected_text = $this->replace_variables( trim( $expected->getRaw() ) );

		if ( $actual !== $expected_text ) {
			throw new RuntimeException(
				sprintf(
					"STDERR does not match.\nExpected:\n%s\n\nActual:\n%s",
					$expected_text,
					$actual
				)
			);
		}
	}

	/**
	 * Assert that STDERR contains expected text.
	 *
	 * Performs substring match (case-sensitive).
	 *
	 * @Then STDERR should contain:
	 * @param PyStringNode $expected Expected text to find in STDERR (multiline).
	 * @return void
	 */
	public function stderr_should_contain( PyStringNode $expected ): void {
		$actual        = trim( $this->error_output );
		$expected_text = $this->replace_variables( trim( $expected->getRaw() ) );

		if ( false === strpos( $actual, $expected_text ) ) {
			throw new RuntimeException(
				sprintf(
					"STDERR does not contain expected text.\nExpected to find:\n%s\n\nActual error output:\n%s",
					$expected_text,
					$actual
				)
			);
		}
	}

	/**
	 * Assert the return code matches expected value.
	 *
	 * Useful for testing exit codes explicitly, especially in "I try" scenarios.
	 *
	 * @Then /^the return code should( not)? be (\d+)$/
	 * @param string $not Optional "not" for negation.
	 * @param int    $expected_code Expected exit code.
	 * @return void
	 */
	public function the_return_code_should_be( string $not, int $expected_code ): void {
		if (
			( ! $not && $expected_code !== $this->exit_code ) ||
			( $not && $expected_code === $this->exit_code )
		) {
			throw new RuntimeException(
				sprintf(
					"Expected return code %s%d, got %d.\nSTDOUT:\n%s\n\nSTDERR:\n%s",
					$not ? 'not ' : '',
					$expected_code,
					$this->exit_code,
					$this->output,
					$this->error_output
				)
			);
		}
	}

	/**
	 * Assert that STDOUT is empty.
	 *
	 * @Then STDOUT should be empty
	 * @return void
	 */
	public function stdout_should_be_empty(): void {
		if ( ! empty( trim( $this->output ) ) ) {
			throw new RuntimeException(
				sprintf(
					"Expected STDOUT to be empty, but got:\n%s",
					$this->output
				)
			);
		}
	}

	/**
	 * Assert that STDERR is empty.
	 *
	 * @Then STDERR should be empty
	 * @return void
	 */
	public function stderr_should_be_empty(): void {
		if ( ! empty( trim( $this->error_output ) ) ) {
			throw new RuntimeException(
				sprintf(
					"Expected STDERR to be empty, but got:\n%s",
					$this->error_output
				)
			);
		}
	}

	/**
	 * Assert that STDOUT is not empty.
	 *
	 * @Then STDOUT should not be empty
	 * @return void
	 */
	public function stdout_should_not_be_empty(): void {
		if ( '' === trim( $this->output ) ) {
			throw new RuntimeException( 'Expected STDOUT to not be empty, but it was empty.' );
		}
	}

	/**
	 * Assert that STDERR is not empty.
	 *
	 * @Then STDERR should not be empty
	 * @return void
	 */
	public function stderr_should_not_be_empty(): void {
		if ( '' === trim( $this->error_output ) ) {
			throw new RuntimeException( 'Expected STDERR to not be empty, but it was empty.' );
		}
	}

	/**
	 * Assert that STDOUT matches a regex pattern.
	 *
	 * @Then /^STDOUT should( not)? match (((\/.+\/)|(#.+#))([a-z]+)?)$/
	 * @param string $not Optional "not" for negation.
	 * @param string $pattern Regex pattern (with delimiters and optional modifiers).
	 * @return void
	 */
	public function stdout_should_match( string $not, string $pattern ): void {
		$matches = (bool) preg_match( $pattern, $this->output );

		if ( ( ! $not && ! $matches ) || ( $not && $matches ) ) {
			throw new RuntimeException(
				sprintf(
					"Expected STDOUT to %smatch pattern %s.\nActual STDOUT:\n%s",
					$not ? 'not ' : '',
					$pattern,
					$this->output
				)
			);
		}
	}

	/**
	 * Assert that STDERR matches a regex pattern.
	 *
	 * @Then /^STDERR should( not)? match (((\/.+\/)|(#.+#))([a-z]+)?)$/
	 * @param string $not Optional "not" for negation.
	 * @param string $pattern Regex pattern (with delimiters and optional modifiers).
	 * @return void
	 */
	public function stderr_should_match( string $not, string $pattern ): void {
		$matches = (bool) preg_match( $pattern, $this->error_output );

		if ( ( ! $not && ! $matches ) || ( $not && $matches ) ) {
			throw new RuntimeException(
				sprintf(
					"Expected STDERR to %smatch pattern %s.\nActual STDERR:\n%s",
					$not ? 'not ' : '',
					$pattern,
					$this->error_output
				)
			);
		}
	}

	/**
	 * Assert that STDOUT contains valid JSON that includes expected values.
	 *
	 * @Then STDOUT should be JSON containing:
	 * @param PyStringNode $expected Expected JSON subset.
	 * @return void
	 */
	public function stdout_should_be_json_containing( PyStringNode $expected ): void {
		$output         = $this->output;
		$expected_text  = $this->replace_variables( trim( $expected->getRaw() ) );

		$actual_json   = json_decode( $output, true );
		$expected_json = json_decode( $expected_text, true );

		if ( null === $actual_json ) {
			throw new RuntimeException(
				sprintf(
					"STDOUT is not valid JSON.\nActual output:\n%s",
					$output
				)
			);
		}

		if ( null === $expected_json ) {
			throw new RuntimeException(
				sprintf(
					"Expected value is not valid JSON:\n%s",
					$expected_text
				)
			);
		}

		if ( ! $this->json_contains( $actual_json, $expected_json ) ) {
			throw new RuntimeException(
				sprintf(
					"STDOUT JSON does not contain expected values.\nExpected:\n%s\n\nActual:\n%s",
					$expected_text,
					$output
				)
			);
		}
	}

	/**
	 * Assert that a file or directory exists (or doesn't exist).
	 *
	 * @Then /^the (.+) (file|directory) should( not)? exist$/
	 * @param string $path Path to file/directory (relative to RUN_DIR or absolute).
	 * @param string $type Either "file" or "directory".
	 * @param string $not Optional "not" for negation.
	 * @return void
	 */
	public function the_file_should_exist( string $path, string $type, string $not ): void {
		$path = $this->replace_variables( $path );

		// If it's a relative path, make it relative to wp-content/plugins/{plugin-slug}.
		if ( '/' !== $path[0] ) {
			$plugin_slug = $this->get_plugin_slug();
			$path        = "/var/www/html/wp-content/plugins/{$plugin_slug}/{$path}";
		}

		// Check existence via wp-env.
		$check_command = 'directory' === $type ? "test -d {$path} && echo 'exists'" : "test -f {$path} && echo 'exists'";
		$this->run_wp_cli_command( "eval 'system(\"" . addslashes( $check_command ) . "\");'", true );

		$exists = false !== strpos( $this->output, 'exists' );

		if ( ( ! $not && ! $exists ) || ( $not && $exists ) ) {
			throw new RuntimeException(
				sprintf(
					"Expected %s '%s' to %sexist.",
					$type,
					$path,
					$not ? 'not ' : ''
				)
			);
		}
	}

	/**
	 * Helper method to check if actual JSON contains all expected values.
	 *
	 * @param mixed $actual Actual JSON data (decoded).
	 * @param mixed $expected Expected JSON data (decoded).
	 * @return bool True if actual contains expected.
	 */
	private function json_contains( $actual, $expected ): bool {
		if ( is_array( $expected ) ) {
			foreach ( $expected as $key => $value ) {
				if ( ! isset( $actual[ $key ] ) ) {
					return false;
				}
				if ( ! $this->json_contains( $actual[ $key ], $value ) ) {
					return false;
				}
			}
			return true;
		}

		return $actual === $expected;
	}
}
