<?php
namespace PantheonSystems\UpstreamManagement\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use PantheonSystems\UpstreamManagement\Tests\Util\Cleaner;

/**
 * Test requiring and updating upstream dependencies.
 */
class UpstreamManagementCommandTest extends TestCase
{
    protected $cleaner;
    protected $sut;

    public function setUp(): void
    {
        $this->cleaner = new Cleaner();
        $this->cleaner->preventRegistration();
        $tmpDir = $this->cleaner->tmpdir(sys_get_temp_dir(), 'sut');
        $this->sut = $tmpDir . DIRECTORY_SEPARATOR . 'sut';
    }

    public function tearDown(): void
    {
    }

    protected function createSut()
    {
        $fixtureDir = dirname(__DIR__) . '/fixtures/upstream';
        $fs = new Filesystem();
        $fs->mirror($fixtureDir, $this->sut);

        $this->composer('config', ['minimum-stability', 'dev']);
        $this->composer('config', ['repositories.upstream', 'path', dirname(__DIR__, 2)]);
        $this->composer('config', ['--no-plugins', 'allow-plugins.pantheon-systems/upstream-management', 'true']);
        // Installing our plugin creates the root composer.lock, which is necessary
        // for the upstream dependency locking feature to work.
        $this->composer('require', ['pantheon-systems/upstream-management', '*']);
    }

    public function testUpstreamRequire()
    {
        $this->createSut();
        // 'composer upstream require' will return an error if used on the Pantheon platform upstream.
        $process = $this->composer('upstream-require', ['psr/log']);
        $this->assertFalse($process->isSuccessful());
        $output = $process->getErrorOutput();
        $this->assertStringContainsString(
            'The upstream-require command can only be used with a custom upstream',
            $output
        );

        $this->pregReplaceSutFile(
            '#pantheon-upstreams/drupal-composer-managed#',
            'customer-org/custom-upstream',
            'composer.json'
        );
        $this->assertSutFileContains('"customer-org/custom-upstream"', 'composer.json');

        // Once we change the name of the upstream, 'composer upstream require' should work.
        $process = $this->composer('upstream-require', ['psr/log']);
        $this->assertTrue($process->isSuccessful());
        $this->assertSutFileDoesNotExist('upstream-configuration/composer.lock');
        $this->assertSutFileContains('"psr/log"', 'upstream-configuration/composer.json');
        $this->assertSutFileNotContains('"psr/log"', 'composer.json');
        $this->assertSutFileNotContains('"psr/log"', 'composer.lock');
        $process = $this->composer('update');
        $output = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
        $this->assertStringContainsString('psr/log', $output);
        $this->assertSutFileNotContains('"psr/log"', 'composer.json');
        $this->assertSutFileContains('psr/log', 'composer.lock');
        $this->assertSutFileDoesNotExist('upstream-configuration/composer.lock');
    }

    public function testUpdateUpstreamDependencies()
    {
        $this->createSut();

        $this->pregReplaceSutFile(
            '#pantheon-upstreams/drupal-composer-managed#',
            'customer-org/custom-upstream',
            'composer.json'
        );

        // Add psr/log to the project; only allow version 1.1.3.
        $process = $this->composer('upstream-require', ['psr/log:1.1.3']);
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $this->assertSutFileDoesNotExist('upstream-configuration/composer.lock');

        // Running 'update-upstream-dependencies' creates our locked composer.json
        // file. psr/log will not update past version 1.1.3 until updated.
        $process = $this->composer('update-upstream-dependencies');
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $this->assertSutFileExists('upstream-configuration/composer.lock');
        $this->assertSutFileExists('upstream-configuration/locked/composer.json');
        $this->assertMatchesRegularExpression(
            '#psr/log"[^"]*"1\.1\.3#',
            $this->sutFileContents('upstream-configuration/composer.json')
        );
        $process = $this->composer('info');
        $output = $process->getOutput();
        $this->assertStringNotContainsString('psr/log', $output);
        $this->assertSutFileContains('"psr/log"', 'upstream-configuration/composer.json');

        // Run `composer update`. This should bring in the locked (1.1.3) version of psr/log.
        $this->assertSutFileExists('upstream-configuration/composer.lock');
        $process = $this->composer('update');
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $this->assertSutFileExists('upstream-configuration/composer.lock');
        $output = $process->getErrorOutput();
        $process = $this->composer('info', ['--format=json']);
        $output = $process->getOutput();
        $this->assertPackageVersionMatchesRegularExpression('psr/log', '#1\.1\.3#', $output);

        // Set psr/log constraint back to ^1.1. At this point, though, the
        // upstream dependency lock file is still at version 1.1.3.
        $process = $this->composer('upstream-require', ['psr/log:^1.1', '--', '--no-update']);
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $output = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
        $this->assertMatchesRegularExpression(
            '#psr/log"[^"]*"\^1\.1#',
            $this->sutFileContents('upstream-configuration/composer.json')
        );

        // Run `composer update` again. This should not affect psr/log; it should stay at version 1.1.3.
        $process = $this->composer('update');
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $this->assertMatchesRegularExpression(
            '#psr/log"[^"]*"\^1\.1#',
            $this->sutFileContents('upstream-configuration/composer.json')
        );
        $output = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
        $this->assertStringNotContainsString('psr/log (1.1.3 => 1.1.', $output);
        $this->assertStringNotContainsString('No locked dependencies in the upstream', $output);
        $process = $this->composer('info', ['--format=json']);
        $output = $process->getOutput();
        $this->assertTrue($process->isSuccessful());
        $this->assertPackageVersionMatchesRegularExpression('psr/log', '#1\.1\.3#', $output);

        // Update the upstream dependencies. This should not affect the installed dependencies;
        // however, it will update the locked version of psr/log to the latest
        // available version. The project will acquire this version the next time it is updated.
        $process = $this->composer('update-upstream-dependencies');
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $output = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
        $this->assertMatchesRegularExpression('#"psr/log": "1\.1\.4"#', $output);
        $process = $this->composer('info', ['--format=json']);
        $output = $process->getOutput();
        $this->assertTrue($process->isSuccessful());
        $this->assertPackageVersionMatchesRegularExpression('psr/log', '#1\.1\.3#', $output);

        // Now run `composer update` again. This should update psr/log.
        $process = $this->composer('update');
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $output = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
        $this->assertStringNotContainsString('No locked dependencies in the upstream', $output);
        $process = $this->composer('info', ['--format=json']);
        $output = $process->getOutput();
        $this->assertTrue($process->isSuccessful());
        $this->assertPackageVersionMatchesRegularExpression('psr/log', '#1\.1\.#', $output);
        $this->assertPackageVersionDoesNotMatchesRegularExpression('psr/log', '#1\.1\.3#', $output);
    }

    public function sutFileContents($file)
    {
        return file_get_contents($this->sut . DIRECTORY_SEPARATOR . $file);
    }

    public function assertPackageVersionMatchesRegularExpression($packageName, $version, $jsonString)
    {
        $data = json_decode($jsonString, true);
        foreach ($data['installed'] as $package) {
            if ($package['name'] === $packageName) {
                $this->assertMatchesRegularExpression($version, $package['version']);
                return;
            }
        }
    }

    public function assertPackageVersionDoesNotMatchesRegularExpression($packageName, $version, $jsonString)
    {
        $data = json_decode($jsonString, true);
        foreach ($data['installed'] as $package) {
            if ($package['name'] === $packageName) {
                $this->assertDoesNotMatchRegularExpression($version, $package['version']);
                return;
            }
        }
    }

    public function assertSutFileContains($needle, $haystackFile)
    {
        $this->assertStringContainsString($needle, $this->sutFileContents($haystackFile));
    }

    public function assertSutFileNotContains($needle, $haystackFile)
    {
        $this->assertStringNotContainsString($needle, $this->sutFileContents($haystackFile));
    }

    public function assertSutFileDoesNotExist($file)
    {
        $this->assertFileDoesNotExist($this->sut . DIRECTORY_SEPARATOR . $file);
    }

    public function assertSutFileExists($file)
    {
        $this->assertFileExists($this->sut . DIRECTORY_SEPARATOR . $file);
    }

    public function pregReplaceSutFile($regExp, $replace, $file)
    {
        $path = $this->sut . DIRECTORY_SEPARATOR . $file;
        $contents = file_get_contents($path);
        $contents = preg_replace($regExp, $replace, $contents);
        file_put_contents($path, $contents);
    }

    protected function composer(string $command, array $args = []): Process
    {
        $cmd = array_merge(['composer', '--working-dir=' . $this->sut, $command], $args);
        $process = new Process($cmd);
        $process->run();

        return $process;
    }
}
