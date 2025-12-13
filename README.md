# Automattic Behat WP-Env Context

Shared Behat testing infrastructure for WordPress plugins using wp-env.

This package provides a reusable base FeatureContext class that eliminates 300-400 lines of boilerplate from each plugin's Behat tests.

## Why This Package?

When migrating from the deprecated `wp-cli/wp-cli-tests` framework to wp-env, many Automattic plugins ended up duplicating similar test infrastructure code. This package consolidates that into a single, battle-tested implementation.

### Benefits

- ✅ **~450 lines** of production-ready, reusable code
- ✅ **wp-env output filtering** automatically handles status messages (ℹ, ✔, ✖)
- ✅ **Multi-line error parsing** for accurate STDERR assertions
- ✅ **Variable substitution** for dynamic test values (`{POST_ID}`, `{TERM_ID}`, etc.)
- ✅ **Database cleanup** ensures test isolation without slow fresh installs
- ✅ **MU-plugin helpers** for test environment setup
- ✅ **Extensible** via abstract methods and protected properties
- ✅ **Standard step definitions** work out of the box

## Installation

```bash
composer require --dev automattic/behat-wp-env-context
```

## Requirements

- PHP 8.0+
- Node.js (for wp-env)
- `@wordpress/env` installed globally or in `package.json`
- `behat/behat` ^3.7

## Quick Start

### 1. Create Your FeatureContext

**File:** `tests/Behat/FeatureContext.php`

```php
<?php

namespace Automattic\MyPlugin\Tests\Behat;

use Automattic\BehatWpEnv\WpEnvFeatureContext;
use RuntimeException;

final class FeatureContext extends WpEnvFeatureContext {

    protected function get_plugin_slug(): string {
        return 'my-awesome-plugin';
    }

    protected function plugin_specific_cleanup(): void {
        // Clean up custom post types
        $this->run_wp_cli_command( 'post list --post_type=my-cpt --format=ids', false );
        $ids = trim( $this->output );
        if ( ! empty( $ids ) ) {
            $this->run_wp_cli_command( "post delete {$ids} --force", false );
        }

        // Clean up custom taxonomies
        $this->run_wp_cli_command( 'term list my-taxonomy --field=term_id', false );
        $term_ids = array_filter( explode( "\n", trim( $this->output ) ) );
        foreach ( $term_ids as $term_id ) {
            $this->run_wp_cli_command( "term delete my-taxonomy {$term_id}", false );
        }
    }

    /**
     * @Given a WP installation with the My Plugin plugin
     */
    public function given_a_wp_installation_with_plugin(): void {
        $this->reset_database_state();
        $this->run_wp_cli_command( 'plugin activate my-awesome-plugin', false );

        if ( 0 !== $this->exit_code ) {
            throw new RuntimeException(
                'Failed to activate plugin: ' . $this->output
            );
        }
    }
}
```

### 2. Configure behat.yml

```yaml
default:
  suites:
    default:
      paths:
        - '%paths.base%/features'
      contexts:
        - Automattic\MyPlugin\Tests\Behat\FeatureContext
```

### 3. Write Feature Files

**File:** `features/my-plugin.feature`

```gherkin
Feature: My Plugin Commands

  Background:
    Given a WP installation with the My Plugin plugin

  Scenario: Create a custom post and retrieve it
    When I run `my-plugin create-post --title="Test Post" --porcelain`
    Given save STDOUT as {POST_ID}
    Then STDOUT should contain:
      """
      Success
      """

    When I run `post get {POST_ID} --field=post_title`
    Then STDOUT should be:
      """
      Test Post
      """
```

### 4. Run Tests

```bash
# Start wp-env
npm run env start

# Run Behat tests
composer behat
```

## Standard Step Definitions

All of these work out of the box:

### Given Steps

- `Given a WP installation` - Resets database to clean state
- `Given save STDOUT as {VARIABLE_NAME}` - Saves command output to a variable

### When Steps

- `When I run \`command\`` - Execute WP-CLI command (must succeed)
- `When I try \`command\`` - Execute WP-CLI command (may fail)
- `When I (run|try) the previous command again` - Retry last command

### Then Steps

- `Then STDOUT should be:` - Exact match assertion (multiline)
- `Then STDOUT should contain:` - Substring match assertion (multiline)
- `Then STDERR should be:` - Exact error match assertion (multiline)
- `Then STDERR should contain:` - Substring error match assertion (multiline)

## Variable Substitution

Save dynamic values from one command and reuse them in subsequent commands or assertions:

```gherkin
# Create a post and save its ID
When I run `post create --post_title="Test" --porcelain`
Given save STDOUT as {POST_ID}

# Use the saved ID in subsequent commands
When I run `post meta add {POST_ID} key value`
When I run `post get {POST_ID} --field=post_status`
Then STDOUT should contain:
  """
  publish
  ```

# Use variables in assertions
Then STDOUT should contain:
  """
  Post {POST_ID} updated
  """
```

## Advanced Usage

### Custom Step Definitions

Add plugin-specific steps in your FeatureContext:

```php
/**
 * @When I import data from :file
 */
public function i_import_data_from( string $file ): void {
    $this->run_wp_cli_command( "my-plugin import {$file}", false );

    if ( 0 !== $this->exit_code ) {
        throw new RuntimeException(
            'Import failed: ' . $this->error_output
        );
    }
}
```

### MU-Plugin Injection

Inject code into WordPress for test setup:

```php
protected function before_scenario( BeforeScenarioScope $scope ): void {
    parent::before_scenario( $scope );

    // Disable emails during tests
    $this->create_mu_plugin(
        'disable-emails',
        "<?php add_filter( 'pre_wp_mail', '__return_false' );"
    );

    // Add a custom filter
    $this->create_mu_plugin(
        'test-filter',
        "<?php add_filter( 'my_filter', function() { return 'test value'; } );"
    );
}

protected function after_scenario( AfterScenarioScope $scope ): void {
    $this->remove_mu_plugin( 'disable-emails' );
    $this->remove_mu_plugin( 'test-filter' );

    parent::after_scenario( $scope );
}
```

### Accessing Command Results

In custom step definitions, you have access to:

```php
$this->output;        // STDOUT from last command
$this->error_output;  // STDERR from last command
$this->exit_code;     // Exit code (0 = success)
$this->variables;     // Saved variables array
```

### Custom wp-env Command

If you need to customize how wp-env is invoked:

```php
protected function get_wp_env_command(): string {
    return 'npx wp-env';  // Use npx explicitly
}
```

## Migrating from wp-cli-tests

Replace this:

```php
use WP_CLI\Tests\Context\FeatureContext as WP_CLI_FeatureContext;

final class FeatureContext extends WP_CLI_FeatureContext {
    // Old implementation
}
```

With this:

```php
use Automattic\BehatWpEnv\WpEnvFeatureContext;

final class FeatureContext extends WpEnvFeatureContext {

    protected function get_plugin_slug(): string {
        return 'my-plugin';
    }

    protected function plugin_specific_cleanup(): void {
        // Move your database cleanup code here
    }
}
```

### Migration Checklist

- [ ] Remove `wp-cli/wp-cli-tests` from `composer.json`
- [ ] Add `automattic/behat-wp-env-context` to `composer.json`
- [ ] Update FeatureContext to extend `WpEnvFeatureContext`
- [ ] Implement `get_plugin_slug()` method
- [ ] Move plugin-specific cleanup to `plugin_specific_cleanup()`
- [ ] Update `.github/workflows/behat.yml` to use wp-env
- [ ] Add `.wp-env.json` configuration
- [ ] Run `composer update`
- [ ] Test locally with `npm run env start && composer behat`

## Examples

See these plugins for real-world usage:

- [co-authors-plus](https://github.com/Automattic/Co-Authors-Plus)
- [wpcom-legacy-redirector](https://github.com/Automattic/WPCOM-Legacy-Redirector)

## Contributing

Found a bug or have a feature request? Please [open an issue](https://github.com/Automattic/behat-wp-env-context/issues).

Pull requests are welcome! Please follow WordPress VIP coding standards.

## License

GPL-2.0-or-later

## Credits

This package consolidates best practices from:
- Co-Authors Plus wp-env migration (Dec 2024)
- WPCOM Legacy Redirector wp-env implementation (Jun 2023)
- wp-cli/wp-cli-tests framework (deprecated)

Maintained by the [Automattic](https://automattic.com/) WordPress VIP team.
