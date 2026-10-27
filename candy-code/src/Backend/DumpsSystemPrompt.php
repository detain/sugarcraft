<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use SugarCraft\Code\Backend;

/**
 * Opt-in declaration that a {@see Backend} can hand over the system prompt it
 * is about to send, VERBATIM, together with the name, description and input
 * schema of every tool that rides the request — what `/context --raw` writes
 * to a file (GAP-PRM-15).
 *
 * `/context` itself shows only layer labels and byte counts: the panel is a
 * readout, and pasting a whole prompt through a transcript row would paint
 * thousands of cells of it across the user's screen on the way to a file.
 * What the user needs to REPRODUCE a request — in a debugger, a diff, or a
 * bug report to the model's vendor — is the exact string, and only a backend
 * that assembles the prompt itself can say what that string is.
 *
 * A separate capability rather than a method on `Backend`, for the reason
 * {@see ReportsContextWindow} gives: `Backend` is a third-party extension
 * point, and a required method would be a load-time fatal for every
 * implementation outside this repo. Not implementing it is the safe default —
 * `/context --raw` then names the refusal instead of writing a fabricated
 * dump, and the dump test pins that a backend WITHOUT the capability is
 * refused by name.
 */
interface DumpsSystemPrompt
{
    /**
     * The next request's prompt and tools, built the way a turn builds them.
     * `prompt` is the assembled system prompt string, byte-for-byte what the
     * wire would carry; its length equals the sum of the
     * {@see ReportsPromptSections::promptSectionSizes()} rows this backend
     * reports (the one fold behind both is what keeps them equal). `tools`
     * is the turn's tool list in wire order, each entry its name, one-line
     * description and JSON-schema array.
     *
     * @return array{prompt: string, tools: list<array{name: string, description: string, input_schema: array<mixed>}>}
     */
    public function dumpSystemPrompt(): array;
}
