<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

/**
 * The four transcript shapes {@see SessionImporter} recognises.
 *
 * The case values double as the provider suffix of the imported session
 * (`import:candy-code` and friends), so the session row itself names where
 * the history came from.
 */
enum ImportFormat: string
{
    case CandyCode = 'candy-code';
    case ClaudeCode = 'claude-code';
    case Codex = 'codex';
    case OpenCode = 'opencode';

    /** The brand name the notice row and refusals show a person. */
    public function label(): string
    {
        return match ($this) {
            self::CandyCode => 'CandyCode',
            self::ClaudeCode => 'Claude Code',
            self::Codex => 'Codex',
            self::OpenCode => 'OpenCode',
        };
    }
}
