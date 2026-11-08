# MODX Installer

[![CI](https://github.com/crystaldaking/modx-installer/actions/workflows/ci.yml/badge.svg)](https://github.com/crystaldaking/modx-installer/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/crystaldaking/modx-installer)](https://github.com/crystaldaking/modx-installer/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Create a local MODX Revolution site in one command. Official MODX distribution, Nginx, PHP-FPM and MariaDB; no browser setup wizard.

```sh
modx new my-site
cd my-site
# Open the site and manager URLs printed by the installer.
```

[Русская документация](README.ru.md) · [Contributing](CONTRIBUTING.md) · [Releases](https://github.com/crystaldaking/modx-installer/releases)

## Requirements

- PHP 8.2+ on the host, with `curl`, `dom`, and `zip` extensions.
- Docker Engine or Docker Desktop, running Linux containers; Compose **2.20+**.
- Internet access to GitHub (version discovery), MODX (ZIP download), Docker Hub and Debian mirrors (first build).
- A regular (non-root) host user for running site containers. UID/GID are derived from project file ownership; the PHP POSIX extension is not required.
- macOS or Linux, on ARM64 (including Apple Silicon) or AMD64. Windows users should run inside WSL2 with Docker integration.

The generated site runs PHP 8.3, MariaDB 11.4 and Nginx 1.28. The host only runs the installer. This is a **local development environment**: HTTP binds to `127.0.0.1` and the database has no published port. Production deployment, TLS, backups and upgrades are outside v0.1 scope.

## Install

Download the PHAR and checksum from the [latest release](https://github.com/crystaldaking/modx-installer/releases/latest):

```sh
mkdir modx-cli && cd modx-cli
gh release download --repo crystaldaking/modx-installer --pattern modx.phar --pattern SHA256SUMS
shasum -a 256 -c SHA256SUMS
chmod +x modx.phar
mkdir -p "$HOME/.local/bin"
mv modx.phar "$HOME/.local/bin/modx"
# Ensure $HOME/.local/bin is on your PATH.
modx doctor
```

Or install from source with Composer:

```sh
git clone https://github.com/crystaldaking/modx-installer.git
cd modx-installer
git checkout v0.1.1
composer install --no-dev
./bin/modx doctor
./bin/modx new ../my-site
```

The package declares a Composer `modx` binary. Composer global installation will become available after a separate Packagist registration; it is not required for either method above. To build the PHAR yourself, install development dependencies and run `composer build`.

## Commands

```sh
modx new my-site                         # Latest stable MODX 3, then install
modx new .                               # Current directory must be completely empty
modx new my-site --version=3.2.4-pl       # Pin MODX version
modx new my-site --modx-version=3.2.4-pl  # Equivalent, canonical Symfony option
modx new my-site --port=8090 --language=ru --admin-email=you@example.com
modx new my-site --no-start              # Download and scaffold; Docker not needed yet
cd my-site
modx up                                  # Build/start; finish pending installation
modx down                                # Stop containers; keep database
modx logs                                # Last 100 lines per service
modx logs --follow --service=php --tail=200
modx doctor                              # Host dependencies
modx doctor --project-dir=.               # Also validate project files and .env
```

`up`, `down` and `logs` discover the nearest project from the current directory or accept `--project-dir=/path/to/site`. `--version` alone prints the installer version; `new --version=...` selects MODX. Commands return a nonzero exit code on failure and support `--no-interaction` for scripts. `--no-start` still downloads MODX and needs host PHP extensions and internet.

## What is generated

```text
my-site/
├── app/                         # Official MODX traditional distribution
├── docker/
│   ├── nginx/default.conf
│   ├── php/Dockerfile
│   ├── install.php
│   └── verify.php
├── compose.yaml
├── modx.json                    # Chosen version, source URL and archive SHA-256
├── .env                         # Random credentials, port and host UID/GID (0600)
├── .env.example                 # No passwords
├── .gitignore
└── README.md
```

The CLI validates the target, downloads over verified HTTPS, rejects unsafe archive paths and symlinks, and prepares files in a private staging directory on the target filesystem. It never overwrites a nonempty directory, including hidden files. A failed network download leaves the target empty. No force-overwrite option is provided.

Each site gets a distinct Compose project name with a random identity persisted in `modx.json`. Ports default to the first free port in 8080–8180. Start one site before scaffolding the next to let automatic port selection see it, or assign explicit ports. Files prepared with `--no-start` do not reserve ports. If a port later becomes occupied, set `HTTP_PORT` in `.env` before the first installation. Moving or copying an installed project requires reviewing `modx.json`, Docker volume ownership and MODX configuration paths; automatic relocation is not provided.

Latest stable MODX 3 is resolved from official `modxcms/revolution` tags, ignoring pre-releases and other major versions. ZIPs come from the official MODX download endpoint (which redirects to MODX's S3 bucket), never GitHub source archives. GitHub's unauthenticated rate limit can affect discovery: use `--modx-version` to bypass it.

`modx.json` records the downloaded archive's SHA-256 for traceability. To validate against a separately obtained trusted hash, pass `--sha256=<64 hex characters>`. A locally calculated hash alone is not independent proof of authenticity. Docker image tags pin release lines, not immutable digests; rebuilding later can pick up upstream patches.

## Credentials, data and installation recovery

Database and initial administrator passwords are random and saved in `.env`, excluded from Git. The initial manager login is printed after successful installation. Treat terminal output and `.env` as private. The generated `.gitignore` also excludes installed MODX configuration files containing database secrets. Back up `.env`, site files and database separately.

Installation waits for database and PHP readiness, passes secrets through stdin to PHP, writes a private temporary XML outside the webroot and removes it on process shutdown. A separate MODX bootstrap verifies the administrator password before removing `app/setup` and marking installation complete. Nginx blocks setup, core, hidden files and configuration paths throughout.

`modx up` completes projects created with `--no-start`. If installation fails, files and database are retained for diagnosis. Fix the cause and rerun `modx up`. Existing `core/config/config.inc.php` prevents a fresh install over a partially installed database; the CLI attempts verification and reports failure if the installation is inconsistent. It does not promise automatic rollback. Inspect `modx logs` and `app/core/cache/logs/`; for an unrecoverable fresh site, create another project or explicitly back up and reset the old one yourself.

After installation, `up` checks the actual database and MODX bootstrap, then waits for an HTTP 200 response from the manager. It refuses to reinstall when installed configuration is missing and reports failure if the database has been lost, even if `.modx-installed` is present. Startup no longer needs the initial `ADMIN_*` values, so you can remove them from `.env` after recording credentials privately. `down` keeps the named database volume. Never use `docker compose down --volumes` unless you intentionally want to delete the database. Changing passwords in `.env` **does not** rotate existing MODX or MariaDB accounts. If the initial manager password changes, `.env` still contains the old initial value.

On Linux, PHP runs under the generating user's UID/GID so bind-mounted files remain editable. If you copy a project between machines, review `LOCAL_UID`/`LOCAL_GID` and file ownership. Docker Desktop handles macOS bind mounts. An abrupt container kill can leave a temporary XML in that container's private `/tmp`; remove the container with `modx down` after diagnosis. No XML is written into `app/`.

## Updating from v0.1.0

Install the v0.1.1 PHAR (or update this source checkout and run `composer install`). `modx up` uses the current CLI's bundled install/verification code inside PHP, so old generated runtime copies do not bypass fixes. It does not overwrite your Compose file or Docker customizations. Do not edit `docker/install.php` or `docker/verify.php` to customize CLI installation; these copies are for reference/manual execution.

New sites also get PHP and web health checks. Existing sites remain supported without them because the CLI independently verifies the database/bootstrap and HTTP readiness. Existing `modx.json` project names and database volumes are retained. Newly generated sites use a random identity, so recreating a deleted directory cannot accidentally select its old database volume.

If v0.1.0 PHAR-generated Docker files are read-only, restore owner write access once from that site's directory:

```sh
chmod u+w compose.yaml docker/php/Dockerfile docker/nginx/default.conf docker/install.php docker/verify.php
```

Add `/.modx.lock` to an existing site's `.gitignore`. The CLI serializes `up`/`down` with that persistent lock file; the container installer also holds a lock so a detached installation cannot race verification.

Project `.env` files now use **literal single-line values**: `KEY=value`, `KEY='value with spaces'` or `KEY="value"`, with optional comments. Shell commands, `${VAR}`/`$VAR` interpolation and quote concatenation are not evaluated. Dollar expressions in quoted values remain literal. If you previously used variable references, replace them with their actual values before updating. Syntax errors report only a line number and never echo source values. Installed MODX database configuration is restricted to mode `0600` after verification.

## Development

```sh
composer install
composer validate --strict
composer lint
composer test
php scripts/smoke.php                    # Real MODX + Docker; temporary project, cleaned afterwards
composer build
php dist/modx.phar --version
```

CI runs unit tests on PHP 8.2–8.5, validates the Composer manifest, builds/smoke-tests the PHAR, and installs a real MODX site on Linux AMD64. The smoke test checks writable PHAR scaffolding, the homepage, manager, blocked paths, setup removal, persistence across down/up, old project templates, missing configuration/database records and an unexpected `exit(0)` during bootstrap. See [CONTRIBUTING.md](CONTRIBUTING.md) for the architecture and release process.

## Roadmap

- Interactive container shell and command execution.
- Starter templates and Extras presets.
- Explicit database export/import workflows.
- Packagist distribution and richer version metadata.

Independent community software. MODX is downloaded separately under its own license. See [NOTICE](NOTICE) and [MIT license](LICENSE).
