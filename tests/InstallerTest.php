<?php

declare(strict_types=1);

namespace ModxInstaller\Tests;

use ModxInstaller\{Application, Archive, Project, Release, Scaffolder};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class InstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/modx-test-'.bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void { (new Filesystem())->remove($this->root); }

    private function archive(array $extra = []): string
    {
        $path = $this->root.'/release.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addEmptyDir('modx-3.2.4-pl/./');
        foreach (['index.php' => '<?php echo "MODX";', 'setup/index.php' => '<?php', 'core/docs/version.inc.php' => '<?php'] + $extra as $name => $data) {
            $zip->addFromString('modx-3.2.4-pl/'.$name, $data);
        }
        $zip->close();
        return $path;
    }

    public function testStableReleaseSelectionIgnoresPrereleasesAndSortsNumerically(): void
    {
        self::assertSame('3.10.0-pl', Release::latestFromTags([['name' => 'v3.2.4-pl'], ['name' => 'v4.0.0-pl'], ['name' => 'v3.11.0-rc1'], ['name' => 'v3.10.0-pl']]));
        self::assertSame('3.2.4-pl', Release::validate('v3.2.4-pl'));
        self::assertSame('https://modx.com/download/direct/modx-3.2.4-pl.zip', Release::url('3.2.4-pl'));
    }

    public function testInvalidVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Release::validate('../../../etc/passwd');
    }

    public function testOccupiedDirectoryIncludingDotfilesIsNeverOverwritten(): void
    {
        file_put_contents($this->root.'/.keep', 'keep me');
        try {
            Project::target($this->root);
            self::fail('Expected refusal.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not empty', $e->getMessage());
            self::assertSame('keep me', file_get_contents($this->root.'/.keep'));
        }
    }

    public function testSymlinkTargetIsRejected(): void
    {
        mkdir($this->root.'/real');
        symlink($this->root.'/real', $this->root.'/link');
        $this->expectException(\RuntimeException::class);
        Project::target($this->root.'/link');
    }

    public function testZipTraversalIsRejectedBeforeExtraction(): void
    {
        $archive = $this->archive(['../../escaped.php' => 'bad']);
        try {
            (new Archive())->extract($archive, $this->root.'/app', '3.2.4-pl');
            self::fail('Expected unsafe ZIP rejection.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Unsafe ZIP entry', $e->getMessage());
            self::assertDirectoryDoesNotExist($this->root.'/app');
            self::assertFileDoesNotExist($this->root.'/escaped.php');
        }
    }

    public function testZipSymlinkIsRejected(): void
    {
        $archive = $this->archive(['link' => '/etc/passwd']);
        $zip = new \ZipArchive();
        $zip->open($archive);
        $zip->setExternalAttributesName('modx-3.2.4-pl/link', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(\RuntimeException::class);
        (new Archive())->extract($archive, $this->root.'/app', '3.2.4-pl');
    }

    public function testWrongPackageIsRejected(): void
    {
        $zip = new \ZipArchive();
        $path = $this->root.'/wrong.zip';
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('modx-3.2.4-pl/index.php', '<?php');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        (new Archive())->extract($path, $this->root.'/app', '3.2.4-pl');
    }

    public function testScaffoldCreatesPrivateSecretsAndDiscoverableUniqueProjects(): void
    {
        $archive = $this->archive();
        $scaffolder = new Scaffolder();
        $a = $scaffolder->create(Project::target($this->root.'/a/site'), '3.2.4-pl', 18080, $archive, 'admin@example.test', 'ru');
        $b = $scaffolder->create(Project::target($this->root.'/b/site'), '3.2.4-pl', 18081, $archive, 'admin@example.test', 'en');
        self::assertNotSame($a->metadata['name'], $b->metadata['name']);
        self::assertSame(hash_file('sha256', $archive), $a->metadata['archive_sha256']);
        self::assertSame($a->directory, Project::find($a->directory.'/app/core')->directory);
        self::assertSame('ru', $a->env()['MODX_LANGUAGE']);
        self::assertSame(0600, fileperms($a->directory.'/.env') & 0777);
        self::assertNotSame($a->env()['DB_PASSWORD'], $b->env()['DB_PASSWORD']);
        self::assertNotSame($a->env()['DB_PASSWORD'], $a->env()['DB_ROOT_PASSWORD']);
        self::assertStringNotContainsString($a->env()['ADMIN_PASSWORD'], file_get_contents($a->directory.'/.env.example'));
        self::assertStringNotContainsString($a->env()['DB_PASSWORD'], file_get_contents($a->directory.'/modx.json'));
        $compose = Yaml::parseFile($a->directory.'/compose.yaml');
        self::assertArrayNotHasKey('ports', $compose['services']['db']);
        self::assertStringStartsWith('127.0.0.1:', $compose['services']['web']['ports'][0]);
        self::assertArrayNotHasKey('platform', $compose['services']['php']);
        self::assertFileExists($a->directory.'/docker/install.php');
        self::assertFileDoesNotExist($a->directory.'/app/setup/config.xml');
    }

    public function testFailedScaffoldLeavesTargetEmpty(): void
    {
        $archive = $this->archive(['../../escape' => 'bad']);
        $directory = Project::target($this->root.'/target');
        try {
            (new Scaffolder())->create($directory, '3.2.4-pl', 8080, $archive, 'admin@example.test', 'en');
            self::fail('Expected refusal.');
        } catch (\RuntimeException) {
            self::assertSame(['.', '..'], scandir($directory));
        }
    }

    public function testOccupiedPortAndInvalidPortAreRejected(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        try {
            Project::port($port);
            self::fail('Expected occupied port refusal.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('port', $e->getMessage());
        } finally {
            fclose($socket);
        }
        $this->expectException(\InvalidArgumentException::class);
        Project::port('80abc');
    }

    public function testVersionAliasDoesNotBreakApplicationVersion(): void
    {
        self::assertSame(['modx', 'new', '.', '--modx-version=3.2.4-pl'], Application::normalizeArguments(['modx', 'new', '.', '--version=3.2.4-pl']));
        self::assertSame(['modx', 'new', '.', '--modx-version', '3.2.4-pl'], Application::normalizeArguments(['modx', 'new', '.', '--version', '3.2.4-pl']));
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/bin/modx', '--version']);
        $process->mustRun();
        self::assertStringContainsString('MODX Installer '.Application::VERSION, $process->getOutput());
    }

    public function testCliRejectsInvalidVersionBeforeNetworkOrDocker(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/bin/modx', 'new', $this->root.'/site', '--no-start', '--version=2.8.9-pl']);
        $process->run();
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('stable MODX 3', $process->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->root.'/site');
    }
}
