<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

interface HookInterface
{
    public function name(): string;

    public function event(): HookEvent;

    public function matcher(): string;

    public function execute(HookContext $context): HookResult;
}
