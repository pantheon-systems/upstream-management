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
        echo "Cloning DCM to $this->sut";
        passthru('git clone https://github.com/pantheon-upstreams/drupal-composer-managed.git ' . $this->sut);

        // Override php version for this test.
        $this->pregReplaceSutFile(
            '#php_version: 8.1#',
            'php_version: ' . substr(phpversion(), 0, 3),
            'pantheon.upstream.yml'
        );

        // Run 'composer update'. This has two important impacts:
        // 1. The composer.lock file is created, which is necessary for the upstream dependency locking feature to work.
        // 2. Our preUpdate modifications are applied to the SUT.
        $this->composer('update');

        $this->composer('config', ['minimum-stability', 'dev']);
        $this->composer('config', ['repositories.upstream', 'path', dirname(__DIR__, 2)]);
        $this->composer('config', ['--no-plugins', 'allow-plugins.pantheon-systems/upstream-management', 'true']);
        $this->composer('require', ['pantheon-systems/upstream-management', '*']);
    }

    public function testUpstreamRequire()
    {
        $this->createSut();
        // 'composer upstream require' will return an error if used on the Pantheon platform upstream.
        $process = $this->composer('upstream-require', ['drupal/ctools']);
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
        $process = $this->composer('upstream-require', ['drupal/ctools']);
        $this->assertTrue($process->isSuccessful());
        $this->assertSutFileDoesNotExist('upstream-configuration/composer.lock');
        $this->assertSutFileContains('"drupal/ctools"', 'upstream-configuration/composer.json');
        $this->assertSutFileNotContains('"drupal/ctools"', 'composer.json');
        $this->assertSutFileNotContains('"drupal/ctools"', 'composer.lock');
        $process = $this->composer('update');
        $output = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
        $this->assertStringContainsString('drupal/ctools', $output);
        $this->assertSutFileNotContains('"drupal/ctools"', 'composer.json');
        $this->assertSutFileContains('drupal/ctools', 'composer.lock');
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

        // Add drupal/ctools to the project; only allow version 4.0.2.
        $process = $this->composer('upstream-require', ['drupal/ctools:4.0.2']);
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $this->assertSutFileDoesNotExist('upstream-configuration/composer.lock');

        // Running 'update-upstream-dependencies' creates our locked composer.json
        // file. drupal/ctools will not update past version 4.0.2 until updated.
        $process = $this->composer('update-upstream-dependencies');
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $this->assertSutFileExists('upstream-configuration/composer.lock');
        $this->assertSutFileExists('upstream-configuration/locked/composer.json');
        $this->assertMatchesRegularExpression(
            '#drupal/ctools"[^"]*"4\.0\.2#',
            $this->sutFileContents('upstream-configuration/composer.json')
        );
        $process = $this->composer('info');
        $output = $process->getOutput();
        $this->assertStringNotContainsString('drupal/ctools', $output);
        $this->assertSutFileContains('"drupal/ctools"', 'upstream-configuration/composer.json');

        // Run `composer update`. This should bring in the locked (4.0.2) version of drupal/ctools.
        $this->assertSutFileExists('upstream-configuration/composer.lock');
        $process = $this->composer('update');
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $this->assertSutFileExists('upstream-configuration/composer.lock');
        $output = $process->getErrorOutput();
        $process = $this->composer('info', ['--format=json']);
        $output = $process->getOutput();
        $this->assertPackageVersionMatchesRegularExpression('drupal/ctools', '#4\.0\.2#', $output);

        // Set drupal/ctools constraint back to ^4. At this point, though, the
        // upstream dependency lock file is still at version 4.0.2.
        $process = $this->composer('upstream-require', ['drupal/ctools:^4', '--', '--no-update']);
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $output = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
        $this->assertMatchesRegularExpression(
            '#drupal/ctools"[^"]*"\^4#',
            $this->sutFileContents('upstream-configuration/composer.json')
        );

        // Run `composer update` again. This should not affect drupal/ctools; it should stay at version 4.0.2.
        $process = $this->composer('update');
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $this->assertMatchesRegularExpression(
            '#drupal/ctools"[^"]*"\^4#',
            $this->sutFileContents('upstream-configuration/composer.json')
        );
        $output = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
        $this->assertStringNotContainsString('drupal/ctools (4.0.2 => 4.)', $output);
        $this->assertStringNotContainsString('No locked dependencies in the upstream', $output);
        $process = $this->composer('info', ['--format=json']);
        $output = $process->getOutput();
        $this->assertTrue($process->isSuccessful());
        $this->assertPackageVersionMatchesRegularExpression('drupal/ctools', '#4\.0\.2#', $output);

        // Update the upstream dependencies. This should not affect the installed dependencies;
        // however, it will update the locked version of drupal/ctools to the latest
        // avaiable version. The project will acquire this version the next time it is updated.
        $process = $this->composer('update-upstream-dependencies');
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $output = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
        $this->assertMatchesRegularExpression('#"drupal/ctools": "4\.0\.4"#', $output);
        $process = $this->composer('info', ['--format=json']);
        $output = $process->getOutput();
        $this->assertTrue($process->isSuccessful());
        $this->assertPackageVersionMatchesRegularExpression('drupal/ctools', '#4\.0\.2#', $output);

        // Now run `composer update` again. This should update drupal/ctools.
        $process = $this->composer('update');
        $this->assertTrue($process->isSuccessful(), $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        $output = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
        $this->assertStringNotContainsString('No locked dependencies in the upstream', $output);
        $process = $this->composer('info', ['--format=json']);
        $output = $process->getOutput();
        $this->assertTrue($process->isSuccessful());
        $this->assertPackageVersionMatchesRegularExpression('drupal/ctools', '#4\.#', $output);
        $this->assertPackageVersionDoesNotMatchesRegularExpression('drupal/ctools', '#4\.0\.2#', $output);
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
