<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Internal sentinel returned by {@see Cmd::windowSize()}. The Program resolves
 * it against its own terminal (or ProgramOptions::$windowSize) and dispatches
 * a {@see Msg\WindowSizeMsg}, so the size is read when the Cmd runs, not when
 * it was built.
 *
 * @internal
 */
final class WindowSizeRequest implements Msg
{
}
