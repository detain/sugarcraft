<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\DebugFlags;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\Validator\ThresholdOrderValidator;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\TransientFailure;
use SugarCraft\Code\Skills\SkillLoader;

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
                ->withLabel(Lang::t('settings.turnIdleTimeoutSeconds.label'))
                ->withHelp('Seconds a running turn may go without any progress before it is stopped as hung; idle time, never a total.')
                ->withReaderSymbol(EngineBackend::class . '::turnIdleTimeoutSeconds')
                ->withReadBy('`EngineBackend::completeAsync()`, `summariseAsync()` → `turnIdleTimeoutSeconds()`, before the fork'),
            SettingDefinition::new('connectTimeoutSeconds', SettingType::Float, 15.0)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar('CANDY_CODE_CONNECT_TIMEOUT')
                ->withRange(0.001)
                ->withLabel(Lang::t('settings.connectTimeoutSeconds.label'))
                ->withHelp('Bound on reaching a provider host (DNS, TCP, TLS); not a request timeout.')
                ->withReaderSymbol(CustomProvider::class . '::connectTimeoutSeconds')
                ->withReadBy('`HttpClientDefaults::connectTimeoutSeconds()`, when a provider client is built'),
            SettingDefinition::new('streamIdleTimeoutSeconds', SettingType::Int, 3600)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(30)
                ->withLabel(Lang::t('settings.streamIdleTimeoutSeconds.label'))
                ->withHelp('Seconds one read of a streaming reply may wait for bytes before the connection is called dead; per read, never a total.')
                ->withReaderSymbol(CustomProvider::class . '::streamReadIdleTimeoutSeconds')
                ->withReadBy('`HttpClientDefaults::streamReadIdleTimeoutSeconds()`, per streaming request'),
            SettingDefinition::new('streamHeaderTimeoutSeconds', SettingType::Float, 120.0)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(10.0)
                // ISS-024: the head-wait budget, decoupled from the connect
                // bound that used to double as it at 15s.
                ->withLabel(Lang::t('settings.streamHeaderTimeoutSeconds.label'))
                ->withHelp('Seconds a connected streaming server may take to send its response headers; never a total request timeout.')
                ->withReaderSymbol(CustomProvider::class . '::streamHeaderTimeoutSeconds')
                ->withReadBy('`HttpClientDefaults::streamHeaderTimeoutSeconds()`, per streaming request; `BedrockProvider::completeStream()`, per-operation on ConverseStream'),
            SettingDefinition::new('providerRetryAttempts', SettingType::Int, TransientFailure::MAX_ATTEMPTS)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, TransientFailure::MAX_RETRY_ATTEMPTS_SETTING)
                ->withLabel(Lang::t('settings.providerRetryAttempts.label'))
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
                ->withLabel(Lang::t('settings.providerRetryBaseBackoffMs.label'))
                ->withHelp('Wait before the first retry of a failed provider call; each later wait doubles.')
                ->withReaderSymbol(TransientFailure::class . '::baseBackoffMicroseconds')
                ->withReadBy('`TransientFailure::backoff()` → `baseBackoffMicroseconds()`'),
            SettingDefinition::new('temperature', SettingType::Float)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(0.0, CustomProvider::MAX_TEMPERATURE)
                ->withDefaultText('unset (`' . CustomProvider::DEFAULT_TEMPERATURE . '`)')
                ->withLabel(Lang::t('settings.temperature.label'))
                ->withHelp('Sampling temperature the `custom` provider sends when a request names none.')
                ->withReaderSymbol(CustomProvider::class . '::temperature')
                ->withReadBy('`CustomProvider::complete()`, `completeStream()` → `temperature()`'),
            // Roadmap N-P4g: the `debug.*` flags, the persistent spelling of
            // the CANDY_CODE_DEBUG_* switches ({@see DebugFlags}). Off by
            // default — each writes to stderr under the TUI's alternate screen
            // — and user tier only: a diagnostic switch is the operator's.
            SettingDefinition::new(DebugFlags::SKILLS, SettingType::Bool, false)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar(SkillLoader::DEBUG_SKIPS_ENV)
                ->withLabel(Lang::t('settings.debug.skills.label'))
                ->withHelp('Put each skipped skill file and refused skills directory on stderr, not only the launch\'s one-line count.')
                ->withReaderSymbol(SkillLoader::class . '::debugSkipsRequested')
                ->withReadBy('`SkillLoader` (each skip, at launch) → `debugSkipsRequested()` → `DebugFlags::requested()`'),
            SettingDefinition::new(DebugFlags::COMMANDS, SettingType::Bool, false)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar(CommandLoader::DEBUG_REFUSALS_ENV)
                ->withLabel(Lang::t('settings.debug.commands.label'))
                ->withHelp('Put each refused custom-command directory or file on stderr, not only the launch\'s one-line count.')
                ->withReaderSymbol(CommandLoader::class . '::debugRefusalsRequested')
                ->withReadBy('`CommandLoader::report()` → `debugRefusalsRequested()` → `DebugFlags::requested()`'),
            SettingDefinition::new(DebugFlags::RULES, SettingType::Bool, false)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar(RuleLoader::DEBUG_RULES_REFUSALS_ENV)
                ->withLabel(Lang::t('settings.debug.rules.label'))
                ->withHelp('Put each refused rules directory or file on stderr.')
                ->withReaderSymbol(RuleLoader::class . '::debugRefusalsRequested')
                ->withReadBy('`RuleLoader::report()` → `debugRefusalsRequested()` → `DebugFlags::requested()`'),
            SettingDefinition::new(DebugFlags::STREAM, SettingType::Bool, false)
                ->withCategory(SettingCategory::Advanced)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar(Chat::DEBUG_STREAM_ENV)
                ->withLabel(Lang::t('settings.debug.stream.label'))
                ->withHelp('Put the "onToken observer threw, detaching it" line on stderr when an embedder\'s streaming sink fails mid-turn.')
                ->withReaderSymbol(Chat::class . '::debugStreamRequested')
                ->withReadBy('`Chat` (a throwing `onToken` sink) → `debugStreamRequested()` → `DebugFlags::requested()`'),
        ];
    }
}
