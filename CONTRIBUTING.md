# Contributing

Bug reports, documentation and focused pull requests are welcome. Please use the issue templates and search existing issues first. Do not include credentials, `.env`, installed MODX configuration or unredacted logs.

## Local setup

Use PHP 8.2+, Composer 2 and Docker Compose 2.20+. Clone the repository, create a branch and run:

```sh
composer install
composer validate --strict
composer lint
composer test
php scripts/smoke.php
composer build
php dist/modx.phar --version
```

Unit tests use temporary synthetic ZIP files and never require Docker or network access. The smoke test downloads the actual latest stable MODX 3 and builds containers. It creates a unique temporary project and removes only its own containers, image and database volume in a `finally` block. If Docker cleanup fails, the project directory is retained for diagnosis. Run integration tests only where Docker is available; allow several minutes on the first build.

## Architecture

- `bin/modx` loads the Composer autoloader; `Application` registers the five commands and resolves the `new --version` alias.
- `HttpClient`, `Release` and `Archive` handle bounded HTTPS downloads, official tag selection and validated extraction.
- `Project` validates/discovers project directories, selects ports and reads private environment files through a literal `Environment` parser. Neither shell execution nor variable interpolation is allowed.
- `Scaffolder` stages files and generates project metadata, random credentials and Docker templates.
- `Docker` uses argument arrays through Symfony Process, waits for readiness, installs, verifies and starts Nginx.
- `resources/` are copied into generated projects as editable files. For CLI operations, the current bundled install/verification code is passed to container PHP with `-r`; secrets are passed only on stdin. This also patches runtime behavior for old generated projects. The container installer uses MODX's own CLI; verification checks a real MODX administrator before deleting setup. Installed startups verify the database without depending on initial admin credentials.

The smoke test also covers v0.1.0 Compose compatibility, PHAR file permissions, missing database/configuration and an early bootstrap `exit(0)`. Filesystem warnings fail the test.

Keep the no-overwrite contract, private credentials, localhost binding and database-preserving `down` behavior. Add meaningful regression coverage for behavioral changes. Do not add production deployment or destructive reset behavior implicitly.

## Release process

1. Update `Application::VERSION`, README version examples and `CHANGELOG.md`. Follow semantic versioning; v0.x may evolve between minor releases.
2. Pass the checks above and the real Docker smoke test. Review the Git diff and ensure no local project credentials are tracked.
3. Merge to `main` and wait for CI to pass. Build the PHAR from the exact commit being tagged (`composer build`).
4. Create an annotated `vX.Y.Z` tag and push it. Publish a GitHub release with `dist/modx.phar` and `dist/SHA256SUMS`, describing changes and known limitations.
5. Confirm downloadable assets and checksum. The release workflow independently rebuilds and verifies the PHAR and uploads a CI artifact.

PHARs embed production Composer dependencies and their license files. They do not embed MODX, tests or private files. Packagist registration is a separate maintainer action.
