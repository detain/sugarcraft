<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Ansi;

/**
 * Terminal prompt styles.
 */
enum PromptStyle: string
{
    case Bash = 'bash';
    case Pwsh = 'pwsh';
    case PS = 'ps';
    case Simple = 'simple';

    /**
     * Get the prompt string for this style.
     */
    public function prompt(string $cwd = '~'): string
    {
        return match ($this) {
            self::Bash => Ansi::sgr(Ansi::BOLD, 32) . 'user' . Ansi::reset() . '@'
                       . Ansi::sgr(Ansi::BOLD, 34) . 'machine' . Ansi::reset() . ':'
                       . Ansi::sgr(Ansi::BOLD, 34) . $cwd . Ansi::reset() . '$ ',
            self::Pwsh => Ansi::sgr(Ansi::BOLD, 32) . 'PS ' . Ansi::reset()
                       . Ansi::sgr(Ansi::BOLD, 34) . $cwd . Ansi::reset() . '> ',
            self::PS => Ansi::sgr(Ansi::BOLD, 32) . 'user' . Ansi::reset() . '['
                       . Ansi::sgr(Ansi::BOLD, 34) . $cwd . Ansi::reset() . ']: ',
            self::Simple => '$ ',
        };
    }
}
