# Changelog

All notable changes are recorded here. Versions follow semantic versioning.

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
