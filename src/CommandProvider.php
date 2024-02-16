<?php

namespace PantheonSystems\UpstreamManagement;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use PantheonSystems\UpstreamManagement\Command\UpstreamRemoveCommand;
use PantheonSystems\UpstreamManagement\Command\UpstreamRequireCommand;
use PantheonSystems\UpstreamManagement\Command\UpstreamUpdateDependenciesCommand;

class CommandProvider implements CommandProviderCapability
{

    /**
     * {@inheritdoc}
     */
    public function getCommands()
    {
        return [
            new UpstreamRequireCommand(),
            new UpstreamRemoveCommand(),
            new UpstreamUpdateDependenciesCommand(),
        ];
    }
}
