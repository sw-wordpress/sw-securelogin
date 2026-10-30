# Tests, coverage and SonarQube Cloud

The project follows sw-backupit's Tests and SonarQube workflow. Configuration lives in
sonar-project.properties and .github/workflows/quality.yml. The project key is
sw-wordpress_sw-securelogin, matching the repository's sw-wordpress/sw-securelogin name.
No credentials are stored in these files.

## Repository setup

In GitHub Settings > Secrets and variables > Actions, provide:

- Repository or accessible organization secret SONAR_TOKEN with permission to analyze this project.
- Repository variable SONAR_ORGANIZATION with the SonarQube Cloud organization key.

In SonarQube Cloud, import/configure the corresponding project and choose CI-based
analysis (GitHub Actions). Automatic Analysis must be disabled when importing test
coverage through this workflow. Existing sw-backupit secrets are not copied or exposed;
organization secrets can be granted access to this repository by its administrator.

Pushes and pull requests run PHP checks, unit tests, WordPress integration tests,
Mozart isolation tests, JavaScript checks and virtual-authenticator browser tests.
The Actions Run workflow button can also trigger them after this workflow is committed
to the default branch. Sonar runs after all test jobs pass on the default branch or
same-repository PRs. Fork PRs run tests without access to Sonar credentials. The scan
uses the same pinned SonarSource action as sw-backupit, waits for the quality gate and
fails if the gate fails. It reports a clear error when the required configuration is missing.

## Coverage accuracy

The PHP 8.2 jobs generate coverage/unit.xml and coverage/wordpress.xml using Xdebug.
WordPress coverage runs against the original source checkout, with an explicit loaded-
plugin path assertion. Afterward the disposable site switches to build/sw-securelogin
for integration and browser tests of the isolated artifact. Rewriting compiled file
paths back to src would produce incorrect line numbers and is deliberately rejected.

The Sonar job merges reports into coverage/clover.xml with repository-relative original
source paths. Overlapping line hits use the maximum count, never a fabricated sum.
The merger rejects foreign files, tests/compiled source paths and reports with no
executed production statements. Three unit tests cover these properties. All three
report artifacts are retained for 14 days; generated reports and .scannerwork are ignored
by Git and are not included in the plugin build.

Analysis covers PHP source/entry points, profile JavaScript and CSS. Tests are classified
separately; generated dependencies and synthetic fixture files are excluded. The coverage
report is PHP-only. Existing browser E2E validates JavaScript behavior but does not yet
produce JavaScript LCOV coverage. No coverage exclusion is used to conceal that limitation.

## Local checks

With Xdebug or PCOV available and the disposable WordPress database running:

```sh
composer validate --strict
composer cs
composer analyse
XDEBUG_MODE=coverage composer test:coverage
SWSL_TEST_WORDPRESS=/tmp/swsl-test-wordpress SWSL_EXPECT_PLUGIN_ROOT="$PWD" XDEBUG_MODE=coverage composer test:integration:coverage
composer coverage:merge
```

Use the disposable setup from TESTING.md; never point integration tests at production.
Coverage commands fail on warnings, including a missing coverage driver. Ordinary
composer test works without that driver. No local sonar CLI or global agent configuration
is required for the GitHub Actions integration.

Verification performed locally: unit and WordPress suites with an isolated Xdebug PHP
container, Clover merging, path guards, configuration parsing and existing quality checks.
The actual Cloud upload, project permissions, GitHub secrets and remote quality gate
remain unverified until the first repository workflow run. Test percentages must be read
from the generated report/Sonar result, not inferred from the number of passing tests.

## What to test as a user

1. Commit/push the configuration and open Actions > Tests and SonarQube: PHP and
   WordPress matrix jobs should start automatically.
2. Open a successful test run: unit and WordPress Clover artifacts should exist.
3. On a default-branch run or internal PR, check the SonarQube Cloud job: merged
   php-coverage should be attached, and missing token/organization should be reported clearly.
4. Once credentials and the Cloud project are configured, check that Sonar imports PHP
   coverage for src files and that the quality-gate result agrees with the Actions status.
5. A fork PR should execute tests while skipping the secret-dependent Sonar scan.

References:

- https://docs.sonarsource.com/sonarqube-server/analyzing-source-code/test-coverage/test-coverage-parameters
- https://github.com/SonarSource/sonarqube-scan-action
