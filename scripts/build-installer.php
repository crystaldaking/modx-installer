<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$prefix = <<<'SH'
#!/bin/sh
# Generated from scripts/install.php by scripts/build-installer.php. Do not edit.
set -eu
if ! command -v php >/dev/null 2>&1; then
    printf '%s\n' 'PHP CLI is missing. Install PHP 8.2+ with curl, dom and zip, then rerun this installer.' >&2
    exit 1
fi
php -r 'if (PHP_VERSION_ID < 80200) { fwrite(STDERR, "PHP CLI 8.2+ is required.\n"); exit(1); }'
modx_bootstrap_tmp=$(mktemp -d "${TMPDIR:-/tmp}/modx-install.XXXXXXXX")
trap 'rm -rf "$modx_bootstrap_tmp"' 0
trap 'exit 1' 1 2 15
cat > "$modx_bootstrap_tmp/install.php" <<'MODX_INSTALLER_PHP'
SH;
$suffix = <<<'SH'
MODX_INSTALLER_PHP
php "$modx_bootstrap_tmp/install.php" "$@"
SH;
$script = $prefix."\n".file_get_contents(__DIR__.'/install.php').$suffix."\n";
if (file_put_contents($projectRoot.'/install.sh', $script) !== strlen($script)) {
    throw new RuntimeException('Cannot generate install.sh.');
}
chmod($projectRoot.'/install.sh', 0755);
echo "Generated install.sh\n";
