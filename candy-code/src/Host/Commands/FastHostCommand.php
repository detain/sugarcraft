<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\FallbackProvider;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Providers\ShapesServiceTier;

/**
 * `/fast [on|off]` on a served session (C-PRV-3.b) — the premium service
 * lane switched the same way `ModelHostCommand` switches the engine: a
 * `SetModel` effect carrying the tiered backend and the pair's UNCHANGED
 * names, which {@see \SugarCraft\Code\Host\SessionHost} stores verbatim as
 * the session backend.
 *
 * Nothing here writes settings. The terminal's `/fast` persists the
 * `serviceTier` word into the user tier through its own SettingsWriter;
 * on this surface that save stays the terminal's (the 0300-model doctrine,
 * stated in the 0300 spec), and the row says so.
 *
 * The eligibility question is asked exactly as the launch door
 * {@see \SugarCraft\Code\Cli\LaunchContext::withResolvedServiceTier()}
 * asks it: of `$engine->provider()`, NOT of an unwrapped primary — the tier
 * is a provider leg, not a request field, and under a `fallbackModels`
 * block that leg belongs to the wrapper, which shapes none. A wrapped pair
 * is therefore named `fallback(...)` in the refusal, so the accusation
 * lands on the leg that is actually missing rather than on a primary whose
 * own wire does carry the field. `off` (the standard lane) never refuses:
 * every pair is on it, whether or not it can leave.
 */
final class FastHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $words = CommandText::words($text);

        if ($words === []) {
            return CommandResult::reply($text, self::status($context->backend));
        }

        if (count($words) > 1) {
            return CommandResult::reply($text, Lang::t('host.fast.usage'));
        }

        if ($context->readOnly) {
            return CommandResult::refused(Lang::t('host.command.read_only', ['name' => '/fast']));
        }

        $token = strtolower(trim($words[0]));

        if ($token !== 'on' && $token !== 'off') {
            return CommandResult::reply($text, Lang::t('host.fast.usage'));
        }

        if (!$context->backend instanceof EngineBackend) {
            return CommandResult::reply($text, Lang::t('host.fast.status_no_backend'));
        }

        $engine = $context->backend;
        $provider = $engine->provider();
        $named = self::providerLabel($provider);

        if ($token === 'on') {
            if (!self::shapes($engine)) {
                return CommandResult::reply($text, $provider instanceof ShapesServiceTier
                    ? Lang::t('host.fast.refused_model', ['provider' => $named, 'model' => $engine->model()])
                    : Lang::t('host.fast.refused', ['provider' => $named]));
            }

            return CommandResult::reply($text, Lang::t('host.fast.on', [
                'provider' => $named,
                'model' => $engine->model(),
                'note' => Lang::t('host.fast.note_session'),
            ]))->withEffect(self::switchEffect($engine, $engine->withServiceTier(ShapesServiceTier::WORD_FAST)));
        }

        return CommandResult::reply($text, Lang::t('host.fast.off', [
            'provider' => $named,
            'note' => Lang::t('host.fast.note_session'),
        ]))->withEffect(self::switchEffect($engine, $engine->withServiceTier(ShapesServiceTier::WORD_DEFAULT)));
    }

    /** The bare-`/fast` answer: what this pair's wire is doing right now. */
    private static function status(?Backend $backend): string
    {
        if (!$backend instanceof EngineBackend) {
            return Lang::t('host.fast.status_no_backend');
        }

        $named = self::providerLabel($backend->provider());
        $word = $backend->serviceTier();

        if ($word === ShapesServiceTier::WORD_FAST) {
            return Lang::t('host.fast.status_on', ['provider' => $named, 'model' => $backend->model()]);
        }

        if ($word === ShapesServiceTier::WORD_DEFAULT) {
            return Lang::t('host.fast.status_default', ['provider' => $named]);
        }

        return Lang::t(self::shapes($backend) ? 'host.fast.status_unset_shaped' : 'host.fast.status_unset_unshaped', [
            'provider' => $named,
        ]);
    }

    /**
     * The pair's own verdict: does the provider this engine CARRIES shape a
     * premium lane for the model it carries? One door for every surface, so
     * the command never promises a lane the wire will not send.
     */
    private static function shapes(EngineBackend $engine): bool
    {
        $provider = $engine->provider();

        return $provider instanceof ShapesServiceTier && $provider->serviceTierFor($engine->model()) !== null;
    }

    /**
     * A `SetModel` effect that changes no names — the tiered backend rides
     * with the pair the session already runs, which is what makes the web's
     * model cell byte-unchanged while the lane moves.
     */
    private static function switchEffect(EngineBackend $engine, EngineBackend $next): CommandEffect
    {
        return CommandEffect::setModel($engine->provider()->name(), $engine->model(), $next);
    }

    /** The wrapper is named for the leg it lacks, exactly as the launch door names it. */
    private static function providerLabel(ProviderInterface $provider): string
    {
        return $provider instanceof FallbackProvider ? 'fallback(' . $provider->name() . ')' : $provider->name();
    }
}
