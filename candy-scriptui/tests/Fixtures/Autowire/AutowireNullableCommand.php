<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Fixtures\Autowire;

use SugarCraft\ScriptUi\Attribute\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Required-NULLABLE class param — null is a legal fill the author's body
 * already handles. The old autowire injected null here and then rejected its
 * own injection via an in_array(null) sweep, silently skipping the command
 * (review 2026-11-02 senior m-3 over-reject half).
 */
#[Command(name: 'autowire-nullable', description: 'Required nullable dependency.')]
final class AutowireNullableCommand extends SymfonyCommand
{
    public function __construct(private ?AutowireMarker $marker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
