<?php

namespace PantheonSystems\UpstreamManagement;

use Composer\IO\IOInterface;
use Composer\Composer;

trait UpstreamManagementTrait
{

    /**
     * Require that the current project is a custom upstream.
     *
     * If a user runs this command from a Pantheon site, or from a
     * local clone of drupal-composer-managed, then an exception
     * is thrown. If a custom upstream previously forgot to change
     * the project name, this is a good hint to spur them to perhaps
     * do that.
     */
    protected function failUnlessIsCustomUpstream(IOInterface $io, Composer $composer)
    {
        $name = $composer->getPackage()->getName();
        $gitRepoUrl = exec('git config --get remote.origin.url');

        // Refuse to run if:
        // a) This is a clone of the standard Pantheon upstream, and it hasn't been renamed
        // b) This is an local working copy of a Pantheon site instread of the upstream
        $isPantheonStandardUpstream = preg_match('#pantheon.*/drupal-composer-managed#', $name);
        $isPantheonSite = (strpos($gitRepoUrl, '@codeserver') !== false);

        if (!$isPantheonStandardUpstream && !$isPantheonSite) {
            return;
        }

        if ($isPantheonStandardUpstream) {
            // @codingStandardsIgnoreLine
            $io->writeError("<info>The upstream-require command can only be used with a custom upstream. If this is a custom upstream, be sure to change the 'name' item in the top-level composer.json file from $name to something else.</info>");
        }

        if ($isPantheonSite) {
            // @codingStandardsIgnoreLine
            $io->write("<info>The upstream:require should only be used on Pantheon sites for testing purposes. Your git repo URL is $gitRepoUrl.</info>");
            return

        }

        // @codingStandardsIgnoreLine
        $io->writeError("<info>See https://pantheon.io/docs/create-custom-upstream for information on how to create a custom upstream.</info>" . PHP_EOL);
        throw new \RuntimeException("Cannot use upstream-require command with this project.");
    }

    /**
     * Add drupal/core-recommended to upstream-configuration if needed.
     *
     * Ensure that the upstream-configuraton composer.json file has
     * drupal/core-recommended if the project-level composer.json does.
     * It is recommended that projects that wish to lock upstream dependencies
     * should also lock Drupal dependencies with drupal/core-recommended.
     * It is a hard requirement of this mechanism that the upstream must lock
     * to drupal/core-recommended if the project-level composer.json does, but
     * the reverse is not the case. This method attempts to detect whether or
     * not drupal/core-recommended exists in the project-level composer.json file;
     * if the upstream indirectly depends on drupal/core-recommended (e.g. through
     * an installation profile), the recommended strategy is to require the
     * profile through the upsream-configuration/composer.json file.
     */
    protected function ensureCoreRecommended()
    {
        $projectComposerJsonContents = file_get_contents("composer.json");
        $projectComposerJson = json_decode($projectComposerJsonContents, true);

        if (!isset($projectComposerJson['require']['drupal/core-recommended'])) {
            return;
        }

        $upstreamComposerJsonContents = file_get_contents("upstream-configuration/composer.json");
        $upstreamComposerJson = json_decode($upstreamComposerJsonContents, true);

        if ((isset($upstreamComposerJson['require']['drupal/core-recommended'])) &&
            ($upstreamComposerJson['require']['drupal/core-recommended'] ===
                $projectComposerJson['require']['drupal/core-recommended'])
        ) {
            return;
        }

        // Make the version of drupal/core-recommended match the version in the project.
        $upstreamComposerJson['require']['drupal/core-recommended'] =
            $projectComposerJson['require']['drupal/core-recommended'];

        $upstreamComposerJsonContents = static::jsonEncodePretty($upstreamComposerJson);
        file_put_contents("upstream-configuration/composer.json", $upstreamComposerJsonContents . PHP_EOL);
    }

    /**
     * Create a locked composer.json with strict pins based on upstream composer.lock file
     *
     * This function creates a clone of the upstream-configuration/composer.json file
     * that is identical, except for the fact that the `require` section is replaces
     * with the exact versions of all dependencies listed in the upstream-configuration/composer.lock
     * file.
     */
    protected function generateLockedComposerJson(IOInterface $io)
    {
        if (!file_exists("upstream-configuration/composer.lock")) {
            $io->writeError("<warning>No locked dependencies in the upstream; skipping.</warning>");
            return;
        }

        $composerLockContents = file_get_contents("upstream-configuration/composer.lock");
        $composerLockData = json_decode($composerLockContents, true);

        $composerJsonContents = file_get_contents("upstream-configuration/composer.json");
        $composerJson = json_decode($composerJsonContents, true);

        if (!isset($composerLockData['packages'])) {
            $io->writeError("<warning>No packages in the upstream composer.lock; skipping.</warning>");
            return;
        }

        $io->write('Locking upstream dependencies:');

        // Copy the 'packages' section from the Composer lock into our 'require'
        // section. There is also a 'packages-dev' section, but we do not need
        // to pin 'require-dev' versions, as 'require-dev' dependencies are never
        // included from subprojects. Use 'drupal/core-dev' to get Drupal's
        // dev dependencies.
        foreach ($composerLockData['packages'] as $package) {
            // If there is no 'source' record, then this is a path repository
            // or something else that we do not want to include.
            if (isset($package['source'])) {
                $composerJson['require'][$package['name']] = $package['version'];
                $io->write('  "' . $package['name'] . '": "' . $package['version'] .'"');
            }
        }

        // Write the updated composer.json file
        $composerJsonContents = static::jsonEncodePretty($composerJson);
        @mkdir("upstream-configuration/locked");
        file_put_contents("upstream-configuration/locked/composer.json", $composerJsonContents . PHP_EOL);
    }

    /**
     * Update the project composer.json to use locked upstream dependencies.
     *
     * This function modifies the path repository for the upstream-configuration
     * path repository to point at the "locked" composer.json in the directory
     * "upstream-configuration/locked", instead of the default directory,
     * "upstream-configuration".
     */
    protected function useLockedUpstreamDependenciesInProjectComposerJson(IOInterface $io)
    {
        if (!file_exists("upstream-configuration/locked/composer.json")) {
            $io->writeError("<warning>Dependencies are not locked in the upstream; skipping.</warning>");
        }

        $composerJsonContents = file_get_contents("composer.json");
        $composerJson = json_decode($composerJsonContents, true);

        $composerJson['repositories'] = static::updateUpstreamsPathRepo($composerJson['repositories'] ?? []);

        // Write the updated composer.json file
        $composerJsonContents = static::jsonEncodePretty($composerJson);
        file_put_contents("composer.json", $composerJsonContents . PHP_EOL);
    }

    /**
     * Do the actual modification of the 'repositories' section of composer.json.
     */
    protected function updateUpstreamsPathRepo($repositories)
    {
        foreach ($repositories as &$repo) {
            if ($this->isMatchingPathRepo($repo)) {
                $repo['url'] = 'upstream-configuration/locked';
                return $repositories;
            }
        }
        $repositories[] = [
            'type' => 'path',
            'url' => 'upstream-configuration/locked',
        ];
        return $repositories;
    }

    /**
     * Check to see if the provided repo item is a path repo named 'upstream-configuration'.
     */
    protected function isMatchingPathRepo($repo)
    {
        if (!isset($repo['type']) || !isset($repo['url'])) {
            return false;
        }

        return ($repo['type'] == 'path') && (strpos($repo['url'], 'upstream-configuration') === 0);
    }

    /**
     * jsonEncodePretty
     *
     * Convert a nested array into a pretty-printed json-encoded string.
     *
     * @param array $data
     *   The data array to encode
     * @return string
     *   The pretty-printed encoded string version of the supplied data.
     */
    protected function jsonEncodePretty(array $data)
    {
        $prettyContents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $prettyContents = preg_replace('#": \[\s*("[^"]*")\s*\]#m', '": [\1]', $prettyContents);

        return $prettyContents;
    }

    /**
     * Flatten command options.
     */
    protected function flattenOptions(array $options)
    {
        $flattened = '';

        foreach ($options as $key => $value) {
            if (!$value) {
                continue;
            }
            if (is_bool($value)) {
                $flattened .= " --$key";
            } else {
                $flattened .= " --$key=$value";
            }
        }

        return $flattened;
    }
}
