<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Commands\EffortDial;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Code\Lang;

/**
 * `/effort [level|auto]` on a served session (C-WEB-2.f) — the reasoning
 * dial turned through the SAME pure judgement the terminal uses
 * ({@see EffortDial}), so the web and the TUI answer word for word the same
 * rows from the same authorities and cannot drift.
 *
 * The applied tier rides back as the existing `SetModel` effect carrying the
 * dialled engine with the session's UNCHANGED provider/model names — the
 * shape {@see FastHostCommand} established for a dial that is not a switch:
 * {@see \SugarCraft\Code\Host\SessionHost} stores it verbatim as the session
 * backend, and `reasoningEffort()` reads the new value. No new effect kind:
 * the web's model cell stays byte-identical while the dial moves.
 *
 * Unlike `/fast`, whose session-only save doctrine the 0300 spec states for
 * that lane, the tier here PERSISTS — through the workspace's
 * {@see SettingsWriter} user tier, the exact door the terminal's
 * `Chat::persistEffort()` opens and {@see ModelHostCommand} opened for the
 * model choice: a serve restart or `--continue` resume re-reads the same
 * saved `reasoningEffort` the terminal's launch dial consults. `auto`
 * DELETES the key rather than writing an empty one, so a lower layer still
 * speaks, and clears the live overlay to match. A failed save is reported in
 * the row's note, never fatal — and a refusal or a bare read never reaches
 * the writer at all, because the dial only invokes its persist door on the
 * branches that change something.
 */
final class EffortHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $words = CommandText::words($text);

        // A bare read is harmless; only a turn needs a writable session. The
        // bare row rides the dial (status / status_no_backend), so both
        // surfaces' "what is the dial on" answers stay one code path.
        if ($words !== [] && $context->readOnly) {
            return CommandResult::refused(Lang::t('host.command.read_only', ['name' => '/effort']));
        }

        $engine = $context->backend instanceof EngineBackend ? $context->backend : null;

        [$reply, $next] = EffortDial::decide(
            $words,
            $engine,
            fn (?string $level): string => $this->persist($context, $level),
        );

        if (!$next instanceof EngineBackend || !$engine instanceof EngineBackend) {
            return CommandResult::reply($text, $reply);
        }

        // The pair's names ride unchanged; only the backend underneath moves -
        // the FastHostCommand shape for a dial that is not a switch.
        return CommandResult::reply($text, $reply)->withEffect(
            CommandEffect::setModel($engine->provider()->name(), $engine->model(), $next),
        );
    }

    /**
     * The user-tier save door - the workspace service, the same two rows and
     * failure semantics as the terminal's `Chat::persistEffort()`, reported
     * through the host surface's shared `reportField` helper.
     */
    private function persist(CommandContext $context, ?string $level): string
    {
        $writer = $context->workspace?->service(SettingsWriter::class);
        if (!$writer instanceof SettingsWriter) {
            return Lang::t('chat.effort.note_unsavable');
        }

        try {
            $path = $level === null
                ? $writer->write(SettingsTier::You, [], ['reasoningEffort'])
                : $writer->write(SettingsTier::You, ['reasoningEffort' => $level]);

            return $level === null
                ? Lang::t('chat.effort.note_cleared', ['path' => PermissionsCommand::reportField($path)])
                : Lang::t('chat.effort.note_saved', ['path' => PermissionsCommand::reportField($path)]);
        } catch (\Throwable $e) {
            return Lang::t('chat.effort.note_failed', ['error' => PermissionsCommand::reportField($e->getMessage())]);
        }
    }
}
