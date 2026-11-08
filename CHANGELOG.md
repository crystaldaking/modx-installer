# Changelog

All notable changes are recorded here. Versions follow semantic versioning.

## [0.1.1] - 2026-11-09

Deep self-review fixes.

### Fixed

- Parse project `.env` literally, without Symfony Dotenv shell execution or variable expansion; keep secret values out of syntax diagnostics.
- Give each new site a random persistent Compose identity so a recreated path cannot attach to an earlier site's database volume.
- Verify the actual installed database and MODX bootstrap on every startup, fail on missing config/data and detect unexpected `exit(0)` from MODX.
- Wait for PHP and manager HTTP readiness before reporting success; add PHP/web health checks to new templates.
- Serialize `up`/`down` with a host lock and installation/verification with a container lock.
- Use the current CLI's bundled runtime for older generated projects; allow installed projects to omit initial admin credentials.
- Keep generated Docker files editable when running from a PHAR; restrict installed database configuration to `0600` and stage on the target filesystem.
- Reject normalized ZIP path/file-directory conflicts before extraction and bound each entry's copied bytes.
- Stream container logs without an unbounded retained copy; suppress credentials before smoke-test output reaches pipes.
- Derive UID/GID from actual project file ownership without a POSIX-extension dependency; report unsupported root-owned PHP-FPM startup before launching containers.
- Prefer the local Composer autoloader and respect the CLI end-of-options separator.

### Validation

- Expanded unit regression coverage and real PHAR/Docker smoke tests, including legacy templates, file permissions, deleted configuration/database records and early bootstrap exit.
- Smoke-test filesystem warnings now fail the test rather than allowing false-positive results.

## [0.1.0] - 2026-11-09

Initial public release.

### Added

- Symfony Console CLI with `new`, `up`, `down`, `logs` and `doctor`.
- Latest stable MODX 3 discovery, explicit version selection, official ZIP download, archive validation and optional trusted SHA-256 verification.
- Docker environment using Nginx 1.28, PHP 8.3 and MariaDB 11.4, with native ARM64/AMD64 support.
- Automatic CLI installation, database readiness checks, independent administrator verification and setup cleanup.
- Random private credentials, localhost binding, unique Compose project names and automatic free-port selection.
- Safe empty-directory scaffolding and deferred installation with `--no-start`.
- Composer source installation, standalone PHAR, checksums, English/Russian documentation and contributor guidance.
- Unit tests and real Docker smoke tests in GitHub Actions.

### Known limitations

- Local development only; no TLS, deployment, automated upgrades, backup or reset commands.
- A partial MODX installation may need manual recovery; existing installed configuration is never overwritten automatically.
- Host PHP and extensions are required; standalone bootstrap without PHP is not included.
- Packagist registration is not part of this release.
- Prepared projects do not reserve ports; Docker image tags do not pin immutable digests.

[0.1.0]: https://github.com/crystaldaking/modx-installer/releases/tag/v0.1.0

[0.1.1]: https://github.com/crystaldaking/modx-installer/compare/v0.1.0...v0.1.1
