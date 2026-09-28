<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

/**
 * Select and enable a skill by name from an open picker.
 */
final readonly class SelectSkillMsg implements Msg
{
    public function __construct(public string $skillName) {}
}
