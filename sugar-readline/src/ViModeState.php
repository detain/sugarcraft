<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * The vi submode a {@see \SugarCraft\Readline\Mode\ViMode} is currently in.
 *
 * Public so hosts can branch on the mode instead of comparing bare strings
 * against the constant set the mode used to keep private. The case values
 * spell the historical strings ('insert', 'normal', 'visual') so a host
 * that only needs text can read {@see ViMode::viMode()}->value unchanged.
 *
 * Not a port: the submode model is this repo's own — erikgeiser/promptkit
 * has no vi mode (see the ViMode class docblock and the research capability
 * census); no charmbracelet counterpart defines a public state enum either.
 */
enum ViModeState: string
{
    /** Typing edits the buffer. */
    case Insert = 'insert';

    /** Motions and operators; the default after Escape. */
    case Normal = 'normal';

    /**
     * Line-wise selection state, reachable via V only (plain v is
     * unbound — the scaffold renders no selection).
     */
    case Visual = 'visual';
}
