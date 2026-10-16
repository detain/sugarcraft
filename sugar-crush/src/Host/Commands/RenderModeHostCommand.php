<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\CommandParser;
use SugarCraft\Crush\Commands\RenderModeCommand;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolResult;

/**
 * `/rendermode [mode]` — show or switch the media render mode mid-session
 * (crush-media W5.7).
 *
 * The write goes through the ONE validated door — the workspace
 * {@see SettingsWriter} service Bootstrap registers — exactly like the
 * protocol's `settings.set` and {@see \SugarCraft\Crush\Config\Settings\ModelChoice}
 * persist: tier-You, `refusal()` pre-check first, `write()` second, every
 * throw reported rather than fatalled. No side-channel config.json edit
 * exists here, which is what keeps the ConfigWriteProducer census honest.
 *
 * After the settings land, the session state follows the word:
 * {@see ToolResult::setRenderMode()} moves the live word and
 * {@see ToolResult::resetRenderMode()} discards the per-mode probe memo so
 * the next derivation re-probes (the memo is what made the boot-time word
 * feel restart-only). What the flip does NOT reach is stated rather than
 * hidden: Bootstrap::chat() threads ONE mosaic into Chat at launch and the
 * transcript paints through it, so transcript images keep the LAUNCH-time
 * renderer for the whole session — new image results' collapsed protocol
 * labels re-derive through this class, and the painted transcript follows
 * only at the next launch. `SUGARCRUSH_MEDIA_RENDER_MODE` reasserts its
 * precedence there too — a mid-session switch is a switch of the session,
 * not of the environment. The player picks the new word up on the NEXT
 * `/play`; inside the player, `m` still cycles the player's own ladder live
 * (W5.4 asymmetry).
 *
 * Permission reasoning (recorded per house law): this writes the user's own
 * config through the same door `/settings` uses, at the operator's explicit
 * typed command — but it PERSISTS state beyond the window, so it does not
 * join READ_ONLY_COMMANDS the way view-only `/theme` previews or read-only
 * `/play` do; a read-only session should not gain a durable config writer.
 */
final class RenderModeHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $parsed = (new CommandParser())->parse($text);
        $tokens = $parsed?->args ?? [];

        $plan = RenderModeCommand::parse($tokens);

        if ($plan['help']) {
            return CommandResult::reply($text, RenderModeCommand::help());
        }

        if ($plan['error'] !== null) {
            return CommandResult::failure($text, $plan['error'], 1);
        }

        if ($plan['word'] === null) {
            return CommandResult::reply($text, RenderModeCommand::report(ToolResult::renderMode()));
        }

        $word = (string) $plan['word'];
        $writer = $context->workspace?->service(SettingsWriter::class);

        if (!$writer instanceof SettingsWriter) {
            return CommandResult::failure($text, 'no settings writer is reachable — the mode stays ' . ToolResult::renderMode() . ' this session', 1);
        }

        $refusal = $writer->refusal(SettingsTier::You, RenderModeCommand::SETTINGS_KEY, $word);

        if ($refusal !== null) {
            return CommandResult::failure($text, $refusal, 1);
        }

        try {
            $path = $writer->write(SettingsTier::You, [RenderModeCommand::SETTINGS_KEY => $word]);
        } catch (\Throwable $e) {
            return CommandResult::failure($text, 'settings write failed: ' . $e->getMessage(), 1);
        }

        ToolResult::setRenderMode($word);
        ToolResult::resetRenderMode();

        return CommandResult::new(
            Message::user($text),
            Message::assistant(sprintf(
                "render mode: %s — saved to %s\nnew image labels and the player on the next /play follow this word; transcript images keep the renderer chosen at launch and repaint in this one at the next launch\nSUGARCRUSH_MEDIA_RENDER_MODE reasserts at next launch when set",
                $word,
                $path,
            )),
        );
    }
}
