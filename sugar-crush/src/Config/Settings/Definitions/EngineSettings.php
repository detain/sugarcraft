<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\Validator\ThresholdOrderValidator;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\TransientFailure;

/**
 * The "Advanced" category's keys: the engine's watchdog, retry and transport
 * bounds, promoted from constants by roadmap N-P4a (design Appendix N §2.2,
 * "Agent loop and provider").
 *
 * Each key's default IS the constant it replaced, cited rather than restated,
 * so the schema and the reader cannot disagree about it. Every one is a
 * TIMING knob — none can make a turn or a completion end on a total deadline:
 * the two idle bounds are re-armed by activity, and the connect bound ends
 * when the connection is up.
 *
 * NEXT TURN FOR FREE: a turn runs in a child forked from the TUI per turn, and
 * every reader below resolves its key from the merged config at or after
 * that fork (the idle ceiling in the parent just before it), so a save — the
 * session tier included, which the child inherits — applies from the next
 * turn with no apply arm. The exception is `connectTimeoutSeconds`: both HTTP
 * transports take the connect bound from the CLIENT, which is built with the
 * backend, so it is honestly `restart`.
 *
 * TIERS (design §2.2): the connect bound and the two retry knobs are
 * throughput a repository has real reason to tune (a slow corporate proxy, an
 * upstream that rotates), so they are layered and project-settable. The two
 * idle bounds and the temperature are the operator's alone and are read from
 * `config.json` only, like `contextPruning.mode`: raising an idle bound only
 * ever lets a hung turn hold the operator's session longer, and the sampling
 * temperature shapes what the model writes on the operator's credential.
 */
final class EngineSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Advanced;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('turnIdleTimeoutSeconds', SettingType::Int, EngineBackend::COMPLETE_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(EngineBackend::MIN_TURN_IDLE_TIMEOUT_SECONDS)
                // The parallel group deadline is enforced inside the turn
                // child and must end before the watchdog kills the turn.
                ->withValidators(ThresholdOrderValidator::new(['parallelToolDeadlineSeconds', 'turnIdleTimeoutSeconds']))
                ->withLabel('Turn idle timeout (s)')
                ->withHelp('Seconds a running turn may go without any progress before it is stopped as hung; idle time, never a total.')
                ->withReaderSymbol(EngineBackend::class . '::turnIdleTimeoutSeconds')
                ->withReadBy('`EngineBackend::completeAsync()`, `summariseAsync()` → `turnIdleTimeoutSeconds()`, before the fork'),
            SettingDefinition::new('connectTimeoutSeconds', SettingType::Float, 15.0)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar('SUGARCRUSH_CONNECT_TIMEOUT')
                ->withRange(0.001)
                ->withLabel('Connect timeout (s)')
                ->withHelp('Bound on reaching a provider host (DNS, TCP, TLS); not a request timeout.')
                ->withReaderSymbol(CustomProvider::class . '::connectTimeoutSeconds')
                ->withReadBy('`HttpClientDefaults::connectTimeoutSeconds()`, when a provider client is built'),
            SettingDefinition::new('streamIdleTimeoutSeconds', SettingType::Int, 3600)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(30)
                ->withLabel('Stream read idle (s)')
                ->withHelp('Seconds one read of a streaming reply may wait for bytes before the connection is called dead; per read, never a total.')
                ->withReaderSymbol(CustomProvider::class . '::streamReadIdleTimeoutSeconds')
                ->withReadBy('`HttpClientDefaults::streamReadIdleTimeoutSeconds()`, per streaming request'),
            SettingDefinition::new('providerRetryAttempts', SettingType::Int, TransientFailure::MAX_ATTEMPTS)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, TransientFailure::MAX_RETRY_ATTEMPTS_SETTING)
                ->withLabel('Provider attempts')
                ->withHelp('Calls per provider request when it fails transiently (network, 5xx, 408, 429), the first included; 1 never retries.')
                ->withReaderSymbol(TransientFailure::class . '::maxAttempts')
                ->withReadBy('`Runtime::runStreaming()`, `runBatch()`, `AgentManager::executeSubAgent()` → `TransientFailure::maxAttempts()`'),
            SettingDefinition::new('providerRetryBaseBackoffMs', SettingType::Int, intdiv(TransientFailure::BASE_BACKOFF_MICROSECONDS, 1000))
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(0, TransientFailure::MAX_BASE_BACKOFF_MS_SETTING)
                ->withLabel('Retry backoff (ms)')
                ->withHelp('Wait before the first retry of a failed provider call; each later wait doubles.')
                ->withReaderSymbol(TransientFailure::class . '::baseBackoffMicroseconds')
                ->withReadBy('`TransientFailure::backoff()` → `baseBackoffMicroseconds()`'),
            SettingDefinition::new('temperature', SettingType::Float)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(0.0, CustomProvider::MAX_TEMPERATURE)
                ->withDefaultText('unset (`' . CustomProvider::DEFAULT_TEMPERATURE . '`)')
                ->withLabel('Temperature (custom)')
                ->withHelp('Sampling temperature the `custom` provider sends when a request names none.')
                ->withReaderSymbol(CustomProvider::class . '::temperature')
                ->withReadBy('`CustomProvider::complete()`, `completeStream()` → `temperature()`'),
        ];
    }
}
