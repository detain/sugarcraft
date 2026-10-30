<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\EffortBudget;
use SugarCraft\Code\Providers\EffortGate;
use SugarCraft\Code\Providers\FallbackProvider;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Providers\ShapesReasoningEffort;

/**
 * C-WEB-2.f: the `/effort` decision as ONE pure judgement, so the terminal's
 * `Chat::handleEffortCommand()` and the served {@see \SugarCraft\Code\Host\Commands\EffortHostCommand}
 * answer the same rows from the same authority instead of maintaining two
 * copies that could drift — the shared-dial law the plan's §6.5 reasoning
 * dial has always implied, now that a second surface reaches for the knob.
 *
 * Everything the decision reads is a function of its three inputs:
 *  - `list<string> $args` — the parsed words (TUI: the parser's unquoted
 *    tokens; host: `CommandText::words()` of the draft);
 *  - `?EngineBackend $engine` — the session's engine, null when the surface
 *    has none yet;
 *  - `\Closure(?string): string $persist` — the DRIVER's save door, handed
 *    the level to remember or null to DELETE the key, returning the note the
 *    reply line quotes. The dial never touches settings itself: which file a
 *    tier reaches is the driver's business (Chat's workspace writer, the
 *    host's workspace-registered writer), and a refusal or a bare read never
 *    invokes the door at all — that is the "refuses and never persists"
 *    promise, made structural.
 *
 * The verdict authority stays {@see EffortGate} (C-PRV-1.a/b): per-model,
 * fallback judged by its primary, and every off-roster class refused OUT
 * LOUD — a tier that saves cleanly while no wire reads it is the exact
 * set-but-ignored failure the plan bans.
 *
 * Returns `[$reply, $nextBackend]`: the transcript row's text, and the
 * session's next engine with {@see EngineBackend::withReasoningEffort()}
 * already applied — null when nothing changes, so a refusal never ships an
 * effect. `auto`/`clear` DELETES the key rather than writing an empty one, so
 * a lower layer still speaks, and clears the live overlay to match, exactly
 * as the terminal's dial did before the lift.
 *
 * This is a SugarCraft architecture gate, not a port: no
 * `Mirrors charmbracelet/...` citation attaches to it.
 */
final class EffortDial
{
    /**
     * @param list<string> $args
     * @param \Closure(?string): string $persist
     * @return array{0: string, 1: ?EngineBackend}
     */
    public static function decide(array $args, ?EngineBackend $engine, \Closure $persist): array
    {
        $provider = $engine?->provider();
        if ($provider instanceof FallbackProvider) {
            $provider = $provider->primary();
        }
        $names = $provider instanceof ProviderInterface ? $provider->name() : 'this backend';
        $shapes = EffortGate::verdict($provider, $engine?->model() ?? '');

        if ($args === []) {
            if ($engine === null) {
                return [Lang::t('chat.effort.status_no_backend'), null];
            }

            $raw = $engine->reasoningEffort();
            $level = $raw === null ? null : (string) $raw;

            $shape = EffortGate::shape($provider, $level, $engine->model());

            return [Lang::t('chat.effort.status', ['level' => $level ?? 'unset', 'provider' => $names, 'shape' => $shape]), null];
        }

        $levels = implode('|', EffortBudget::levels());

        if (count($args) > 1) {
            return [Lang::t('chat.effort.usage', ['levels' => $levels]), null];
        }

        $token = strtolower(trim($args[0]));

        if ($token === 'auto' || $token === 'clear') {
            $row = Lang::t('chat.effort.cleared', [
                'provider' => $names,
                'note' => $persist(null),
            ]);

            return [$row, $engine === null ? null : $engine->withReasoningEffort(null)];
        }

        $level = Effort::tryFrom($token)?->value;

        if ($level === null) {
            return [Lang::t('chat.effort.usage', ['levels' => $levels]), null];
        }

        if (!$shapes) {
            // C-PRV-1.b: refuse the pair the user actually named. An off-roster
            // class (echo, custom) has no dial at all; a roster member whose
            // wire has no field for THIS model id (OpenAI on `gpt-4o`) does
            // have the dial and simply does not read it for this pair —
            // claiming the provider lacks the control would trade one false
            // sentence for another. The persist door is never reached.
            return [Lang::t($provider instanceof ShapesReasoningEffort
                ? 'chat.effort.unsupported_model'
                : 'chat.effort.unsupported', [
                'provider' => $names,
                'model' => (string) $engine?->model(),
            ]), null];
        }

        // $shapes can only have been answered through a live engine, so the
        // overlay below never dereferences null - the roster is read off it.
        return [Lang::t('chat.effort.set', [
            'level' => $level,
            'provider' => $names,
            'note' => $persist($level),
        ]), $engine->withReasoningEffort($level)];
    }

    private function __construct()
    {
    }
}
