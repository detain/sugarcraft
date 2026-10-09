<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\CommandParser;
use SugarCraft\Crush\Commands\GenerateCommand;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Tools\BuiltIn\GenerateImage;

/**
 * `/generate` — text-to-image from the console (crush-media W2.3).
 *
 * Runs the SAME {@see GenerateImage} tool the model is gated through, so the
 * flag table, capability gate, SD dial, infotext embed, and MediaStore saves
 * exist exactly once. The command's argument map is built in the tool's
 * inputSchema names, which are the sdapi wire names.
 *
 * Permission judgment (recorded per the wave plan): the typed command IS the
 * consent — this mirrors {@see WebSearchHostCommand}, the other egress
 * command, which likewise dials directly rather than re-asking a human who
 * just asked. The catalog permission gate still ASKs on the model-initiated
 * path; nothing here bypasses it for the model. Because the call spends real
 * money and writes artifacts, /generate is deliberately NOT in Chat's
 * READ_ONLY_COMMANDS allowlist.
 *
 * The dial runs synchronously on the command turn, the established shape for
 * network commands in this tree; Wave 2.4's preview loop is what paces long
 * renders on-screen.
 *
 * The {@see $toolFactory} seam exists for tests to pin the argument map
 * against a stubbed transport; the registry builds this class with no
 * arguments (`new $class()`), which selects the production path.
 */
final class GenerateHostCommand implements HostCommand
{
    /**
     * @param (callable(CommandContext): object)|null $toolFactory seam returning
     *        a GenerateImage-shaped tool (execute(array): ToolResult)
     */
    public function __construct(private readonly ?\Closure $toolFactory = null)
    {
    }

    public function run(CommandContext $context, string $text): CommandResult
    {
        $parsed = (new CommandParser())->parse($text);
        $tokens = $parsed?->args ?? [];

        if ($tokens === []) {
            // W3.1 flip-site: the bare command opens the interactive form in
            // Wave 3; today it answers with the usage line and dials nothing.
            return CommandResult::reply($text, GenerateCommand::usage());
        }

        $plan = GenerateCommand::parse($tokens);

        if ($plan['help']) {
            return CommandResult::reply($text, GenerateCommand::help());
        }

        if ($plan['error'] !== null) {
            return CommandResult::failure($text, $plan['error'], 1);
        }

        $result = $this->tool($context)->execute($plan['args']);

        if ($result->isError()) {
            return CommandResult::failure($text, $result->content(), 1);
        }

        // Assistant row like /websearch: the render result stays visible in the
        // transcript (and to the model on the next turn), paths included.
        return CommandResult::new(
            Message::user($text),
            Message::assistant($result->content()),
        );
    }

    /**
     * The tool instance for this run: the injected seam when present, else the
     * production build — engine bound when the launch has one (session-scoped
     * MediaStore folder), plain otherwise.
     */
    private function tool(CommandContext $context): object
    {
        $factory = $this->toolFactory;
        if ($factory !== null) {
            return $factory($context);
        }

        $tool = GenerateImage::new();
        if ($context->backend instanceof EngineBackend) {
            $tool = $tool->withEngine($context->backend);
        }

        return $tool;
    }
}
