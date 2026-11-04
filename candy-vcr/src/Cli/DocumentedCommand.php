<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

/**
 * A subcommand that carries its own usage block.
 *
 * {@see Command} is the minimal dispatch shape (`run()` + `summary()`), and
 * it stays that way on purpose: it is the app's public command contract and
 * widening it would break any third-party command that already implements it.
 * Hand-rolled verbs add this interface so the text a bad call prints to
 * stderr and the text `-h`/`--help` answers with come from ONE method —
 * previously each verb duplicated its usage line inline in the error path
 * only, which is how `--help` ended up refused on half the verbs while the
 * other half answered it (each in its own dialect).
 */
interface DocumentedCommand extends Command
{
    /**
     * The verb's usage block, newline-terminated, ready to write.
     */
    public function usage(): string;
}
