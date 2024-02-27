<?php

namespace PantheonSystems\UpstreamManagement\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PantheonSystems\UpstreamManagement\UpstreamManagementTrait;

/**
 * The "upstream:update-dependencies" command.
 */
class UpstreamUpdateDependenciesCommand extends BaseCommand
{

    use UpstreamManagementTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('upstream:update-dependencies')
            ->setAliases(['update-upstream-dependencies'])
            ->setDescription('Update upstream dependencies (when using pinned versions).')
            ->setHelp('Lorem ipsum dolor sit atem.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $composer = $this->getComposer();

        // This command can only be used in custom upstreams
        $this->failUnlessIsCustomUpstream($io, $composer);
        if (!file_exists("upstream-configuration/composer.json")) {
            $io->writeError(
                "Upstream has no dependencies; use 'composer upstream-require drupal/modulename' to add some."
            );
            return 1;
        }

        // Ensure we have core/composer-recommended listed in the
        // upstream-configuration composer.json file if it is used in the
        // project composer.json file.
        $this->ensureCoreRecommended();

        // Generate or update our upstream-configuration/composer.lock file.
        passthru("composer --working-dir=upstream-configuration update --no-install", $statusCode);
        if ($statusCode) {
            throw new \RuntimeException("Could not update upstream dependencies.");
        }

        // Once we have a composer.lock file, generate a composer.json file from it.
        $this->generateLockedComposerJson($io);

        // Change the project (top-level) composer.json to use the locked composer.json file.
        $this->useLockedUpstreamDependenciesInProjectComposerJson($io);

        $io->write('Upstream dependencies updated.');

        return 0;
    }
}
