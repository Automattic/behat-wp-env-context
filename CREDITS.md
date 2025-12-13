# Credits

This package builds upon and is inspired by several projects in the WordPress and WP-CLI ecosystem.

## wp-cli/wp-cli-tests

Portions of the step definitions and testing patterns in this package are derived from or inspired by the [wp-cli/wp-cli-tests](https://github.com/wp-cli/wp-cli-tests) framework.

- **Copyright:** WP-CLI Contributors
- **License:** GPL-2.0-or-later
- **Repository:** https://github.com/wp-cli/wp-cli-tests

**Specific contributions:**
- Step definition patterns and annotations
- Command execution and output parsing concepts
- Variable substitution system
- Database cleanup patterns

While wp-cli-tests is no longer actively maintained, it provided the foundation for Behat-based WordPress testing. This package adapts those patterns for wp-env-based testing environments.

## WPCOM Legacy Redirector

Variable substitution patterns (`save STDOUT as {VAR}`) and MU-plugin injection helpers were refined based on the implementation in the WPCOM Legacy Redirector plugin's wp-env migration (June 2023).

- **Repository:** https://github.com/Automattic/WPCOM-Legacy-Redirector
- **License:** GPL-2.0-or-later

## Co-Authors Plus

Enhanced multi-line error parsing for WP-CLI error messages with indented continuation lines was developed during the Co-Authors Plus migration to wp-env (December 2024).

- **Repository:** https://github.com/Automattic/Co-Authors-Plus
- **License:** GPL-2.0-or-later

## Maintainers

This package is maintained by [Automattic](https://automattic.com/) as part of the WordPress VIP ecosystem.

**Primary contributors:**
- Gary Pendergast ([@pento](https://github.com/pento))
- WordPress VIP team

## License

This package is licensed under GPL-2.0-or-later, consistent with WordPress and WP-CLI licensing.
