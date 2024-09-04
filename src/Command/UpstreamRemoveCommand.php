<?php

namespace PantheonSystems\UpstreamManagement\Command;

use Composer\Command\RemoveCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PantheonSystems\UpstreamManagement\UpstreamManagementTrait;

/**
 * The "upstream:remove" command.
 */
class UpstreamRemoveCommand extends RemoveCommand
{

    use UpstreamManagementTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('upstream:remove')
            ->setAliases(['upstream-remove'])
            ->setDescription('Removes a package from the upstream.')
            ->setHelp('The <info>upstream:remove</info> command removes a package from the upstream.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = $this->getIO();
        $composer = $this->getComposer();
        $packages = $input->getArgument('packages');

        $options = $input->getOptions();

        // This command can only be used in custom upstreams
        $this->failUnlessIsCustomUpstream($io, $composer);
        $hasNoUpdate = !empty($options['no-update']);

        // Remove --working-dir, --no-update and --no-install, if provided
        $options['working-dir'] = null;
        $options['no-update'] = $options['no-install'] = false;

        // Run `remove` with '--no-update' if there is no composer.lock file,
        // and without it if there is.
        $addNoUpdate = $hasNoUpdate || !file_exists('upstream-configuration/composer.lock');

        if ($addNoUpdate) {
            $options['no-update'] = true;
        } else {
            $options['no-install'] = true;
        }

        $options_string = $this->flattenOptions($options);

        // Removes packages from the upstream-configuration composer.json
        // without writing vendor & etc to the upstream-configuration directory.
        $cmd = "composer --working-dir=upstream-configuration remove " . implode(' ', $packages) . $options_string;
        $io->writeError($cmd . PHP_EOL);
        passthru($cmd, $statusCode);

        if ($statusCode) {
            throw new \RuntimeException("Could not add dependency to upstream.");
        }

        // @codingStandardsIgnoreLine
        $io->writeError('upstream-configuration/composer.json updated. Commit the upstream-configuration/composer.lock file if you wish to lock your upstream dependency versions in sites created from this upstream.');
        return $statusCode;
    }
}
