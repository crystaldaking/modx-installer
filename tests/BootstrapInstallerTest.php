<?php

declare(strict_types=1);

namespace ModxInstaller\Tests;

require_once dirname(__DIR__).'/scripts/install.php';

use ModxInstaller\Bootstrap\Installer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class BootstrapInstallerTest extends TestCase
{
    private string $root;
    private string $artifact;
    private array $environment;
    private array $requests = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/modx-bootstrap-test-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->environment = ['HOME' => $this->root.'/user home\'s $(literal)', 'SHELL' => '/bin/bash'];
        mkdir($this->environment['HOME']);
        $this->artifact = $this->root.'/fixture.phar';
        $builder = <<<'CODE'
$p = new Phar($argv[1]);
$p['composer.json'] = json_encode(['name' => 'crystaldaking/modx-installer']);
$p['version.php'] = '<?php echo '.var_export('MODX Installer '.($argv[2] ?? '0.2.0')."\n", true).';';
$p->setStub("#!/usr/bin/env php\n<?php Phar::mapPhar('fixture.phar'); require 'phar://fixture.phar/version.php'; __HALT_COMPILER();\n");
$p->setSignatureAlgorithm(Phar::SHA512);
CODE;
        (new Process([PHP_BINARY, '-d', 'phar.readonly=0', '-r', $builder, $this->artifact]))->mustRun();
    }

    protected function tearDown(): void { (new Filesystem())->remove($this->root); }

    private function transport(?string $checksum = null, ?\Closure $afterFetch = null): \Closure
    {
        return function (string $url, ?string $path, int $limit) use ($checksum, $afterFetch): string {
            $this->requests[] = $url;
            if ($path === null) { return 'https://github.com/crystaldaking/modx-installer/releases/tag/v0.2.0'; }
            if (str_ends_with($url, '/modx.phar')) {
                copy($this->artifact, $path);
            } elseif (str_ends_with($url, '/SHA256SUMS')) {
                file_put_contents($path, ($checksum ?? hash_file('sha256', $this->artifact)."  modx.phar\n".str_repeat('f', 64).'  install.sh')."\n");
                if ($afterFetch !== null) { $afterFetch(); }
            } else {
                self::fail('Unexpected download URL.');
            }
            return $url;
        };
    }

    private function install(array $arguments = [], ?\Closure $transport = null): string
    {
        ob_start();
        try {
            (new Installer($this->environment, $transport ?? $this->transport()))->run($arguments);
            return ob_get_contents();
        } finally { ob_end_clean(); }
    }

    public function testInstallsVerifiedExecutableAndConfiguresBashIdempotently(): void
    {
        $rc = $this->environment['HOME'].'/.bashrc';
        file_put_contents($rc, '# Existing configuration without final newline');
        $output = $this->install();
        $binary = $this->environment['HOME'].'/.local/bin/modx';
        self::assertSame(hash_file('sha256', $this->artifact), hash_file('sha256', $binary));
        self::assertSame(0755, fileperms($binary) & 0777);
        self::assertStringContainsString('Installed v0.2.0', $output);
        $before = file_get_contents($rc);
        self::assertStringStartsWith("# Existing configuration without final newline\n", $before);
        $this->install();
        self::assertSame($before, file_get_contents($rc));
        self::assertCount(6, $this->requests);
        self::assertStringContainsString('/download/v0.2.0/modx.phar', $this->requests[1]);
        // Source twice from another directory: path quoting and repeat activation must work.
        $process = new Process(['/bin/bash', '--noprofile', '--norc', '-c', '. "$HOME/.bashrc"; . "$HOME/.bashrc"; cd /; command -v modx; modx --version'], '/', ['HOME' => $this->environment['HOME'], 'PATH' => '/usr/bin:/bin:'.dirname(PHP_BINARY)]);
        $process->mustRun();
        self::assertStringContainsString($binary, $process->getOutput());
        self::assertStringContainsString('MODX Installer 0.2.0', $process->getOutput());
    }

    public function testPinnedVersionSkipsLatestLookupAndCanLeaveProfilesUntouched(): void
    {
        $this->install(['--version', '0.2.0', '--no-modify-path']);
        self::assertCount(2, $this->requests);
        self::assertFileDoesNotExist($this->environment['HOME'].'/.bashrc');
        self::assertFileDoesNotExist($this->environment['HOME'].'/.profile');
    }

    public function testChecksumFailurePreservesBinaryAndProfiles(): void
    {
        $this->install();
        $binary = $this->environment['HOME'].'/.local/bin/modx';
        $rc = $this->environment['HOME'].'/.bashrc';
        $before = file_get_contents($rc);
        try {
            $this->install([], $this->transport(str_repeat('0', 64).'  modx.phar'));
            self::fail('Expected checksum rejection.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('SHA-256', $error->getMessage());
            self::assertSame(hash_file('sha256', $this->artifact), hash_file('sha256', $binary));
            self::assertSame($before, file_get_contents($rc));
        }
    }

    public function testDuplicateChecksumEntriesAreRejected(): void
    {
        $line = hash_file('sha256', $this->artifact).'  modx.phar';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SHA-256');
        $this->install([], $this->transport($line."\n".$line));
    }

    public function testUnmanagedCommandIsPreservedUnlessForceWasRequested(): void
    {
        $directory = $this->environment['HOME'].'/.local/bin';
        mkdir($directory, 0755, true);
        file_put_contents($directory.'/modx', 'another application');
        try {
            $this->install();
            self::fail('Expected unmanaged command refusal.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('--force', $error->getMessage());
            self::assertSame('another application', file_get_contents($directory.'/modx'));
            self::assertSame([], $this->requests);
        }
        $this->install(['--force']);
        self::assertSame(hash_file('sha256', $this->artifact), hash_file('sha256', $directory.'/modx'));
    }

    public function testEditedInstalledCommandIsNotSilentlyOverwritten(): void
    {
        $this->install();
        file_put_contents($this->environment['HOME'].'/.local/bin/modx', 'manual edit');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('modified modx');
        $this->install();
    }

    public function testDownloadFailureLeavesShellConfigurationUntouched(): void
    {
        $rc = $this->environment['HOME'].'/.bashrc';
        file_put_contents($rc, '# keep');
        try {
            $this->install([], static function (): never { throw new \RuntimeException('Network unavailable.'); });
            self::fail('Expected download error.');
        } catch (\RuntimeException) {
            self::assertSame('# keep', file_get_contents($rc));
            self::assertFileDoesNotExist($this->environment['HOME'].'/.local/bin/modx');
        }
    }

    public function testCustomDirectoryAndZdotdirWorkWithRealZsh(): void
    {
        if (!is_executable('/bin/zsh') && !is_executable('/usr/bin/zsh')) { self::markTestSkipped('Zsh is not installed.'); }
        $shell = is_executable('/bin/zsh') ? '/bin/zsh' : '/usr/bin/zsh';
        $this->environment['SHELL'] = $shell;
        $this->environment['ZDOTDIR'] = $this->root.'/zsh config';
        $directory = $this->root.'/custom bin\'s $(literal)';
        $this->install(['--install-dir='.$directory]);
        $process = new Process([$shell, '-ic', 'cd /; command -v modx; modx --version'], '/', [...$this->environment, 'PATH' => '/usr/bin:/bin:'.dirname(PHP_BINARY)]);
        $process->mustRun();
        self::assertStringContainsString($directory.'/modx', $process->getOutput());
        self::assertStringContainsString('MODX Installer 0.2.0', $process->getOutput());
        self::assertFileDoesNotExist($this->environment['HOME'].'/.zshrc');
    }

    public function testFishConfigRespectsXdgAndIsIdempotent(): void
    {
        $this->environment['SHELL'] = '/usr/bin/fish';
        $this->environment['XDG_CONFIG_HOME'] = $this->root.'/fish config';
        $this->install();
        $path = $this->root.'/fish config/fish/conf.d/modx-installer.fish';
        $before = file_get_contents($path);
        self::assertStringContainsString('fish_add_path --path --prepend --move', $before);
        $this->install();
        self::assertSame($before, file_get_contents($path));
        $shell = (new \Symfony\Component\Process\ExecutableFinder())->find('fish');
        if ($shell !== null) {
            $process = new Process([$shell, '-c', 'cd /; command -v modx; modx --version'], '/', [...$this->environment, 'PATH' => '/usr/bin:/bin:'.dirname(PHP_BINARY)]);
            $process->mustRun();
            self::assertStringContainsString('MODX Installer 0.2.0', $process->getOutput());
        }
    }

    public function testSymlinkedProfilesArePreservedAndDeduplicated(): void
    {
        $target = $this->root.'/shared-profile';
        file_put_contents($target, "# shared\n");
        symlink($target, $this->environment['HOME'].'/.bashrc');
        symlink($target, $this->environment['HOME'].'/.bash_profile');
        $this->install();
        self::assertTrue(is_link($this->environment['HOME'].'/.bashrc'));
        self::assertTrue(is_link($this->environment['HOME'].'/.bash_profile'));
        self::assertSame(1, substr_count(file_get_contents($target), '# >>> MODX Installer >>>'));
    }

    public function testConcurrentCommandEditIsPreserved(): void
    {
        $this->install();
        $path = $this->environment['HOME'].'/.local/bin/modx';
        try {
            $this->install([], $this->transport(null, static function () use ($path): void { file_put_contents($path, 'concurrent edit'); }));
            self::fail('Expected concurrent edit rejection.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('changed during download', $error->getMessage());
            self::assertSame('concurrent edit', file_get_contents($path));
        }
    }

    public function testInvalidArgumentsAndUnsafePathAreRejectedBeforeDownload(): void
    {
        foreach ([['--version=../bad'], ['--install-dir=relative'], ['--install-dir=/tmp/bad:path'], ['--unknown']] as $arguments) {
            try {
                $this->install($arguments);
                self::fail('Expected argument rejection.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->requests);
            }
        }
    }

    public function testMalformedManagedBlockIsPreserved(): void
    {
        $rc = $this->environment['HOME'].'/.bashrc';
        $before = "# >>> MODX Installer >>>\n# incomplete";
        file_put_contents($rc, $before);
        try {
            $this->install();
            self::fail('Expected malformed block refusal.');
        } catch (\RuntimeException) {
            self::assertSame($before, file_get_contents($rc));
            self::assertSame([], $this->requests);
        }
    }

    public function testStandaloneShellBootstrapHelpWorksOutsideTheCheckout(): void
    {
        $process = new Process(['/bin/sh', dirname(__DIR__).'/install.sh', '--help'], '/', [...$this->environment, 'PATH' => dirname(PHP_BINARY).':/usr/bin:/bin']);
        $process->mustRun();
        self::assertStringContainsString('MODX CLI installer', $process->getOutput());
        self::assertStringContainsString('--no-modify-path', $process->getOutput());
        self::assertFileDoesNotExist($this->environment['HOME'].'/.local/bin/modx');
    }

    public function testUpdatingManagedInstallationReplacesBinaryAndRetainsOnePathBlock(): void
    {
        $this->install();
        $profile = file_get_contents($this->environment['HOME'].'/.bashrc');
        $builder = '$p=new Phar($argv[1]); $p["version.php"]="<?php echo \"MODX Installer 0.2.1\\n\";";';
        (new Process([PHP_BINARY, '-d', 'phar.readonly=0', '-r', $builder, $this->artifact]))->mustRun();
        $this->install(['--version=0.2.1']);
        $binary = $this->environment['HOME'].'/.local/bin/modx';
        self::assertSame(hash_file('sha256', $this->artifact), hash_file('sha256', $binary));
        self::assertSame($profile, file_get_contents($this->environment['HOME'].'/.bashrc'));
        self::assertSame('v0.2.1', json_decode(file_get_contents(dirname($binary).'/.modx-installer.json'), true)['version']);
    }

    public function testVersionMismatchDoesNotInstallOrChangeProfiles(): void
    {
        try {
            $this->install(['--version=0.2.1']);
            self::fail('Expected version check failure.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('version check', $error->getMessage());
            self::assertFileDoesNotExist($this->environment['HOME'].'/.local/bin/modx');
            self::assertFileDoesNotExist($this->environment['HOME'].'/.bashrc');
        }
    }

    public function testReplacingCommandSymlinkDoesNotModifyItsTarget(): void
    {
        $directory = $this->environment['HOME'].'/.local/bin';
        mkdir($directory, 0755, true);
        $outside = $this->root.'/outside-command';
        file_put_contents($outside, 'keep');
        symlink($outside, $directory.'/modx');
        $this->install(['--force']);
        self::assertSame('keep', file_get_contents($outside));
        self::assertFalse(is_link($directory.'/modx'));
    }

    public function testConcurrentProfileEditIsRetainedWithRecoveryMessage(): void
    {
        $rc = $this->environment['HOME'].'/.bashrc';
        file_put_contents($rc, '# original');
        try {
            $this->install([], $this->transport(null, static function () use ($rc): void { file_put_contents($rc, '# edited concurrently'); }));
            self::fail('Expected profile change detection.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Rerun the installer', $error->getMessage());
            self::assertSame('# edited concurrently', file_get_contents($rc));
            self::assertFileExists($this->environment['HOME'].'/.local/bin/modx');
        }
    }

    public function testConcurrentInstallerCannotStartAnotherDownload(): void
    {
        $directory = $this->environment['HOME'].'/.local/bin';
        mkdir($directory, 0755, true);
        $lock = fopen($directory.'/.modx-installer.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->install();
            self::fail('Expected installer lock exclusion.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Another installation', $error->getMessage());
            self::assertSame([], $this->requests);
        } finally { fclose($lock); }
    }

    public function testInvalidPharCannotExecuteEvenWithMatchingChecksum(): void
    {
        $marker = $this->root.'/must-not-execute';
        file_put_contents($this->artifact, '<?php file_put_contents('.var_export($marker, true).', "bad"); echo "MODX Installer 0.2.0";');
        $rejected = false;
        try {
            $this->install();
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'Expected invalid PHAR rejection.');
        self::assertFileDoesNotExist($marker);
        self::assertFileDoesNotExist($this->environment['HOME'].'/.local/bin/modx');
    }

    public function testPathActivationRemovesExistingDuplicatesAndPreservesOtherEntries(): void
    {
        $this->install();
        $directory = $this->environment['HOME'].'/.local/bin';
        $process = new Process(['/bin/bash', '--noprofile', '--norc', '-c', '. "$HOME/.bashrc"; . "$HOME/.bashrc"; printf "%s" "$PATH"'], '/', ['HOME' => $this->environment['HOME'], 'PATH' => '/usr/bin:'.$directory.':/bin:'.$directory.':']);
        $process->mustRun();
        self::assertSame($directory.':/usr/bin:/bin:', $process->getOutput());
    }

}
