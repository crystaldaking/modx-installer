<?php

declare(strict_types=1);

namespace ModxInstaller\Bootstrap;

/** Standalone bootstrap, embedded in install.sh; no Composer dependencies. */
final class Installer
{
    private const REPOSITORY = 'https://github.com/crystaldaking/modx-installer';
    private const START = '# >>> MODX Installer >>>';
    private const END = '# <<< MODX Installer <<<';
    private array $temporaryFiles = [];

    /** The optional transport is used by offline regression tests. */
    public function __construct(private readonly array $environment, private readonly ?\Closure $transport = null) {}

    public function run(array $arguments): void
    {
        $this->temporaryFiles = [];
        $options = $this->options($arguments);
        if ($options['help']) {
            echo "MODX CLI installer (Linux/macOS, PHP 8.2+)\n\n";
            echo "Usage: sh install.sh [--version=0.2.1] [--install-dir=/absolute/path] [--no-modify-path] [--force]\n";
            echo "Defaults: latest stable release, ~/.local/bin, configure Bash/Zsh/Fish PATH.\n";
            echo "--force replaces an existing unmanaged/modified modx command. Never use sudo for a user install.\n";
            return;
        }
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            throw new \RuntimeException('Supported platforms are Linux and macOS; use WSL2 on Windows.');
        }
        foreach (['curl', 'dom', 'zip', 'Phar'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new \RuntimeException('Required PHP extension is missing: '.$extension.'. Enable it for CLI PHP and rerun the installer.');
            }
        }
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('Enable proc_open in CLI PHP before installing MODX Installer.');
        }
        $userHome = $this->absolute($this->environment['HOME'] ?? '', 'HOME');
        if (!is_dir($userHome)) {
            throw new \RuntimeException('HOME must refer to an existing directory.');
        }
        $directory = $this->absolute($options['install-dir'] ?? $userHome.'/.local/bin', '--install-dir');
        $binary = $directory.'/modx';
        $metadataPath = $directory.'/.modx-installer.json';
        $workspace = sys_get_temp_dir().'/modx-bootstrap-'.bin2hex(random_bytes(8));
        $lock = null;
        try {
            $this->mkdir($directory);
            if (is_link($directory.'/.modx-installer.lock')) {
                throw new \RuntimeException('Installer lock must not be a symbolic link.');
            }
            $lock = fopen($directory.'/.modx-installer.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Another installation is running in this directory.');
            }
            if (is_dir($binary) || is_link($metadataPath) || is_dir($metadataPath)) {
                throw new \RuntimeException('The modx command/metadata path is a directory or unsafe symlink. Nothing was replaced.');
            }
            $originalBinary = $this->fingerprint($binary);
            if ((file_exists($binary) || is_link($binary)) && !$options['force']) {
                $metadata = is_file($metadataPath) ? json_decode((string)file_get_contents($metadataPath, false, null, 0, 8192), true) : null;
                if (is_link($binary) || !is_array($metadata) || ($metadata['package'] ?? '') !== 'crystaldaking/modx-installer'
                    || !is_string($metadata['sha256'] ?? null) || !hash_equals($metadata['sha256'], hash_file('sha256', $binary))) {
                    throw new \RuntimeException('An unmanaged or modified modx already exists at '.$binary.'. Use --force only if you want to replace it.');
                }
            }
            // Preflight profiles before downloading or replacing the working command.
            $profiles = $options['no-modify-path'] ? [] : $this->profiles($userHome, $directory);
            $plans = [];
            foreach ($profiles as $path => $block) {
                $plan = $this->profilePlan($path, $block);
                if ($plan !== null) {
                    $plans[$plan['path']] = $plan;
                }
            }
            $this->mkdir($workspace, 0700);
            $version = $options['version'];
            if ($version === null) {
                // Resolve once without the GitHub API's unauthenticated rate limit.
                $url = $this->fetch(self::REPOSITORY.'/releases/latest', null, 0);
                $prefix = self::REPOSITORY.'/releases/tag/';
                if (!str_starts_with($url, $prefix)) {
                    throw new \RuntimeException('Cannot resolve the latest release. Retry with --version=X.Y.Z.');
                }
                $version = $this->version(substr($url, strlen($prefix)));
            }
            echo 'Downloading MODX Installer '.$version."…\n";
            $base = self::REPOSITORY.'/releases/download/'.$version;
            $archive = $workspace.'/modx.phar';
            $checksums = $workspace.'/SHA256SUMS';
            $this->temporaryFiles = [...$this->temporaryFiles, $archive, $checksums];
            $this->fetch($base.'/modx.phar', $archive, 32 * 1024 * 1024);
            $this->fetch($base.'/SHA256SUMS', $checksums, 65536);
            preg_match_all('/^([a-fA-F0-9]{64})[ \t]+\*?modx\.phar\r?$/m', file_get_contents($checksums), $matches);
            $actual = hash_file('sha256', $archive);
            if (count($matches[1]) !== 1 || !hash_equals(strtolower($matches[1][0]), $actual)) {
                throw new \RuntimeException('PHAR SHA-256 verification failed. Existing installation and profiles were preserved.');
            }
            // Opening the PHAR validates its embedded signature without running it.
            $phar = new \Phar($archive);
            $manifest = json_decode($phar['composer.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['name'] ?? '') !== 'crystaldaking/modx-installer') {
                throw new \RuntimeException('Downloaded PHAR is not MODX Installer.');
            }
            unset($phar);
            $this->probe($archive, $version);
            $binaryStage = $this->stage($binary, file_get_contents($archive), 0755);
            $metadataStage = $this->stage($metadataPath, json_encode(['package' => 'crystaldaking/modx-installer', 'version' => $version, 'sha256' => $actual], JSON_PRETTY_PRINT)."\n", 0600);
            // Prepare complete files on their destination filesystems before commit.
            foreach ($plans as &$plan) {
                $plan['stage'] = $this->stage($plan['path'], $plan['contents'], $plan['mode']);
            }
            unset($plan);
            if ($this->fingerprint($binary) !== $originalBinary) {
                throw new \RuntimeException('The modx command changed during download. Nothing was replaced; rerun the installer.');
            }
            $this->replace($binaryStage, $binary);
            $this->replace($metadataStage, $metadataPath);
            foreach ($plans as $plan) {
                $current = is_file($plan['path']) ? file_get_contents($plan['path'], false, null, 0, 1048577) : null;
                if ($current !== $plan['original']) {
                    throw new \RuntimeException('Installed modx, but a shell profile changed concurrently: '.$plan['path'].'. Rerun the installer to finish PATH setup.');
                }
                $this->replace($plan['stage'], $plan['path']);
                echo 'PATH configured in '.$plan['display']."\n";
            }
            echo 'Installed '.$version.' at '.$binary."\n";
            echo $options['no-modify-path'] ? "Shell profiles were left unchanged. Add the install directory to PATH manually.\n" : "Open a new terminal, then run: modx --version\n";
            $fish = basename($this->environment['SHELL'] ?? '') === 'fish';
            echo "For this terminal, run:\n".($fish ? 'fish_add_path --path --prepend --move '.$this->quote($directory, true) : 'export PATH='.$this->quote($directory).'"${PATH:+:$PATH}"')."\n";
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            foreach ($this->temporaryFiles as $path) {
                if (is_file($path) || is_link($path)) { @unlink($path); }
            }
            if (is_dir($workspace)) { @rmdir($workspace); }
        }
    }

    private function options(array $arguments): array
    {
        $options = ['version' => null, 'install-dir' => null, 'no-modify-path' => false, 'force' => false, 'help' => false];
        for ($i = 0; $i < count($arguments); ++$i) {
            $argument = $arguments[$i];
            if ($argument === '--help' || $argument === '-h') { $options['help'] = true; continue; }
            if ($argument === '--force' || $argument === '--no-modify-path') { $options[substr($argument, 2)] = true; continue; }
            if (preg_match('/^--(version|install-dir)(?:=(.*))?$/D', $argument, $match)) {
                $value = $match[2] ?? ($arguments[++$i] ?? '');
                if ($value === '' || str_starts_with($value, '--')) { throw new \InvalidArgumentException('Missing value for --'.$match[1].'.'); }
                $options[$match[1]] = $match[1] === 'version' ? $this->version($value) : $value;
                continue;
            }
            throw new \InvalidArgumentException('Unknown installer argument: '.$argument.'. Use --help.');
        }
        return $options;
    }

    private function version(string $version): string
    {
        if (!preg_match('/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D', $version, $match)) {
            throw new \InvalidArgumentException('Use a stable installer version such as --version=0.2.1.');
        }
        return 'v'.$match[1];
    }

    private function absolute(string $path, string $label): string
    {
        if (!str_starts_with($path, '/') || preg_match('/[:\r\n\x00]/', $path)) {
            throw new \InvalidArgumentException($label.' must be an absolute path without colons, newlines or NUL bytes.');
        }
        return rtrim($path, '/') ?: '/';
    }

    private function profiles(string $userHome, string $directory): array
    {
        $shell = basename($this->environment['SHELL'] ?? '') ?: 'sh';
        $expression = $this->quote($directory);
        $body = <<<'PROFILE'
_modx_installer_bin=@BIN@
_modx_installer_new=$_modx_installer_bin
_modx_installer_rest=${PATH-}
if [ -n "$_modx_installer_rest" ]; then
    while :; do
        case "$_modx_installer_rest" in
            *:*) _modx_installer_entry=${_modx_installer_rest%%:*}; _modx_installer_rest=${_modx_installer_rest#*:}; _modx_installer_done=0 ;;
            *) _modx_installer_entry=$_modx_installer_rest; _modx_installer_rest=''; _modx_installer_done=1 ;;
        esac
        if [ "$_modx_installer_entry" != "$_modx_installer_bin" ]; then
            _modx_installer_new=$_modx_installer_new:$_modx_installer_entry
        fi
        [ "$_modx_installer_done" = 1 ] && break
    done
fi
export PATH=$_modx_installer_new
unset _modx_installer_bin _modx_installer_new _modx_installer_rest _modx_installer_entry _modx_installer_done
PROFILE;
        $posix = self::START."\n".str_replace('@BIN@', $expression, $body)."\n".self::END."\n";
        if ($shell === 'fish') {
            $config = $this->absolute(($this->environment['XDG_CONFIG_HOME'] ?? '') ?: $userHome.'/.config', 'XDG_CONFIG_HOME');
            return [$config.'/fish/conf.d/modx-installer.fish' => self::START."\n".'fish_add_path --path --prepend --move '.$this->quote($directory, true)."\n".self::END."\n"];
        }
        if ($shell === 'zsh') {
            $dot = $this->absolute(($this->environment['ZDOTDIR'] ?? '') ?: $userHome, 'ZDOTDIR');
            return [$dot.'/.zprofile' => $posix, $dot.'/.zshrc' => $posix];
        }
        if ($shell === 'bash') {
            $login = $userHome.'/.profile';
            foreach (['.bash_profile', '.bash_login', '.profile'] as $name) {
                if (file_exists($userHome.'/'.$name) || is_link($userHome.'/'.$name)) { $login = $userHome.'/'.$name; break; }
            }
            return [$login => $posix, $userHome.'/.bashrc' => $posix];
        }
        if (in_array($shell, ['sh', 'dash', 'ksh'], true)) { return [$userHome.'/.profile' => $posix]; }
        throw new \RuntimeException('Automatic PATH setup does not support '.$shell.'. Use --no-modify-path and add the install directory manually.');
    }

    private function quote(string $value, bool $fish = false): string
    {
        return "'".($fish ? str_replace(['\\', "'"], ['\\\\', "\\'"], $value) : str_replace("'", "'\\''", $value))."'";
    }

    private function profilePlan(string $path, string $block): ?array
    {
        $display = $path;
        if (is_link($path)) {
            $path = realpath($path) ?: throw new \RuntimeException('Shell profile has a dangling symlink: '.$path);
        }
        if (file_exists($path) && !is_file($path)) { throw new \RuntimeException('Shell profile is not a regular file: '.$path); }
        $original = is_file($path) ? file_get_contents($path, false, null, 0, 1048577) : null;
        if ($original === false || strlen($original ?? '') > 1024 * 1024) { throw new \RuntimeException('Cannot read shell profile or it exceeds 1 MiB: '.$path); }
        $pattern = '~^'.preg_quote(self::START, '~').'\r?\n.*?^'.preg_quote(self::END, '~').'\r?$\n?~ms';
        if (substr_count($original ?? '', self::START) !== substr_count($original ?? '', self::END)
            || substr_count($original ?? '', self::START) > 1) {
            throw new \RuntimeException('Malformed/duplicate MODX Installer block in '.$path.'. Fix it locally or use --no-modify-path.');
        }
        $contents = $original ?? '';
        if (str_contains($contents, self::START)) {
            $contents = preg_replace_callback($pattern, static fn (): string => $block, $contents, 1, $count);
            if ($count !== 1) { throw new \RuntimeException('Malformed MODX Installer block in '.$path); }
        } else {
            $contents .= ($contents !== '' && !str_ends_with($contents, "\n") ? "\n" : '').$block;
        }
        if ($contents === $original) { return null; }
        return ['path' => $path, 'display' => $display, 'original' => $original, 'contents' => $contents, 'mode' => is_file($path) ? fileperms($path) & 0777 : 0600];
    }

    private function mkdir(string $path, int $mode = 0755): void
    {
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) { throw new \RuntimeException('Cannot create directory: '.$path); }
    }

    private function fingerprint(string $path): ?string
    {
        clearstatcache(true, $path);
        if (is_link($path)) { return 'symlink:'.readlink($path); }
        if (is_file($path)) { return 'sha256:'.hash_file('sha256', $path); }
        if (file_exists($path)) { return 'occupied'; }
        return null;
    }

    private function stage(string $target, string $contents, int $mode): string
    {
        $this->mkdir(dirname($target));
        $stage = tempnam(dirname($target), '.modx-stage-');
        if ($stage === false) { throw new \RuntimeException('Cannot stage file: '.$target); }
        $this->temporaryFiles[] = $stage;
        if (file_put_contents($stage, $contents) !== strlen($contents) || !chmod($stage, $mode)) { throw new \RuntimeException('Cannot write staged file: '.$target); }
        return $stage;
    }

    private function replace(string $stage, string $target): void
    {
        if (!rename($stage, $target)) { throw new \RuntimeException('Cannot replace '.$target.'. Check directory permissions.'); }
    }

    private function fetch(string $url, ?string $path, int $limit): string
    {
        if ($this->transport !== null) { return ($this->transport)($url, $path, $limit); }
        $handle = curl_init($url);
        $stream = $path === null ? null : fopen($path, 'xb');
        if ($path !== null && $stream === false) { throw new \RuntimeException('Cannot create temporary download file.'); }
        $bytes = 0;
        curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 180, CURLOPT_FAILONERROR => true, CURLOPT_NOBODY => $path === null,
            CURLOPT_USERAGENT => 'MODX-Installer-Bootstrap', CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($stream, $limit, &$bytes): int {
                $bytes += strlen($chunk);
                if ($bytes > $limit || !is_resource($stream) || fwrite($stream, $chunk) !== strlen($chunk)) { return 0; }
                return strlen($chunk);
            }]);
        try {
            if (curl_exec($handle) === false) { throw new \RuntimeException('HTTPS download failed: '.curl_error($handle).'. Existing installation has been preserved.'); }
            return curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            unset($handle);
        }
    }

    private function probe(string $path, string $version): void
    {
        $process = proc_open([PHP_BINARY, $path, '--version'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot verify downloaded PHAR with CLI PHP.'); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $error = '';
        $deadline = microtime(true) + 15;
        do {
            $output .= stream_get_contents($pipes[1], 4096);
            $error .= stream_get_contents($pipes[2], 4096);
            $status = proc_get_status($process);
            if (strlen($output) + strlen($error) > 8192 || microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $status['exitcode'] = 1;
                break;
            }
            if ($status['running']) { usleep(10000); }
        } while ($status['running']);
        $output .= stream_get_contents($pipes[1], 4096);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        if ($exit !== 0 || trim($output) !== 'MODX Installer '.substr($version, 1)) {
            throw new \RuntimeException('Downloaded PHAR did not pass its version check. Confirm CLI PHP >=8.2 and required extensions. Existing installation has been preserved.');
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        (new Installer(getenv()))->run(array_slice($argv, 1));
    } catch (\Throwable $error) {
        fwrite(STDERR, 'Installer error: '.$error->getMessage()."\n");
        exit(1);
    }
}
