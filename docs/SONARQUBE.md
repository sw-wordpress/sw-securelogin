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

The independent PHP coverage job merges reports into coverage/clover.xml with repository-relative original
source paths. Overlapping line hits use the maximum count, never a fabricated sum.
The merger rejects foreign files, tests/compiled source paths and reports with no
executed production statements. Three unit tests cover these properties. Report artifacts are retained for 14 days; generated reports and .scannerwork are ignored
by Git and are not included in the plugin build.

Analysis covers PHP source/entry points, profile JavaScript and CSS. Tests are classified
separately; generated dependencies and synthetic fixture files are excluded. Chromium V8 coverage is converted through v8-to-istanbul/Istanbul to
coverage/js/lcov.info and imported by Sonar. Every production script starts uncovered;
only executed browser ranges add hits. Both language gates require strictly >80% line
coverage, including on fork PRs. No production files are excluded.

## Local checks

With Xdebug or PCOV available and the disposable WordPress database running:

```sh
composer validate --strict
composer cs
composer analyse
XDEBUG_MODE=coverage composer test:coverage
SWSL_TEST_WORDPRESS=/tmp/swsl-test-wordpress SWSL_EXPECT_PLUGIN_ROOT="$PWD" XDEBUG_MODE=coverage composer test:integration:coverage
composer coverage:merge
composer coverage:check
npm run test:e2e # disposable site required; writes LCOV and checks JavaScript >80%
```

Use the disposable setup from TESTING.md; never point integration tests at production.
Coverage commands fail on warnings, including a missing coverage driver. Ordinary
composer test works without that driver. No local sonar CLI or global agent configuration
is required for the GitHub Actions integration.

Verification performed locally: unit and WordPress suites with an isolated Xdebug PHP
container, Clover merging, path guards, configuration parsing and existing quality checks.
Actions run 41090636610 (attempt 2): all four test jobs pass; the scanner accepts the
configured project and credentials but rejects CI analysis while Automatic Analysis is
enabled. Disable Automatic Analysis in the Cloud project, then rerun the failed job.
Coverage import and the quality gate remain unverified.

## What to test as a user

1. Commit/push the configuration and open Actions > Tests and SonarQube: PHP and
   WordPress matrix jobs should start automatically.
2. Open a successful test run: unit/WordPress Clover and JavaScript LCOV artifacts should exist.
3. On a default-branch run or internal PR, check the SonarQube Cloud job: merged
   php-coverage should be attached, and missing token/organization should be reported clearly.
4. Once credentials and the Cloud project are configured, check that Sonar imports PHP
   coverage for src files and that the quality-gate result agrees with the Actions status.
5. A fork PR should execute tests while skipping the secret-dependent Sonar scan.

References:

- https://docs.sonarsource.com/sonarqube-server/analyzing-source-code/test-coverage/test-coverage-parameters
- https://github.com/SonarSource/sonarqube-scan-action
