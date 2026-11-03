<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Fixtures\Autowire;

use SugarCraft\ScriptUi\Attribute\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One required builtin param plus an OPTIONAL param carrying an author
 * default. The old autowire walked EVERY param and pushed type-zero values,
 * clobbering 'author-default' with '' (review 2026-11-02 senior m-3
 * default-clobber half). The fix fills only the required prefix, so the
 * optional param keeps its declared default — observable via description.
 */
#[Command(name: 'autowire-defaults', description: '')]
final class AutowireDefaultCommand extends SymfonyCommand
{
    public function __construct(string $required, string $label = 'author-default')
    {
        parent::__construct();
        // The #[Command] description is empty, so the scanner will NOT
        // overwrite this — whatever lands here is the ctor's own doing.
        $this->setDescription($label);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
