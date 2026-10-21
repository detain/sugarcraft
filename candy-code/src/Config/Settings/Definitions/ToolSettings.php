<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\OptionsSource;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Lint\TestRunner;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\BuiltIn\Edit;
use SugarCraft\Code\Tools\BuiltIn\Glob;
use SugarCraft\Code\Tools\BuiltIn\Grep;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\BuiltIn\WebFetch;
use SugarCraft\Code\Tools\BuiltIn\WebSearch;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Support\ToolOutputSpill;
use SugarCraft\Code\Tools\ToolLimits;

/**
 * The "Tools" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Code\Config\Settings\SettingsSchema}
 * collects every set.
 *
 * THE TOOL BOUNDS (roadmap N-P4c, design Appendix N §2.2 "Tools"). Each
 * key's default IS the constant the tool is built with, cited rather than
 * restated, and an unset key leaves the tool exactly as built
 * ({@see ToolLimits}). The tier follows what raising the value costs, not the
 * design table's first guess: a cap on what reaches the MODEL is Spend — every
 * byte of a tool result is replayed into each later request of the turn — so
 * a checked-out repository cannot raise it; a timeout or a memory bound costs
 * time, not money, so it is Tuning and project-settable. `mcpResultCapBytes`
 * is filed here, beside the cap it mirrors, since one category may not span
 * two definition files.
 */
final class ToolSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Tools;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('allowedTools', SettingType::StringList)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::Tools)
                ->withLabel(Lang::t('settings.allowedTools.label'))
                ->withHelp(Lang::t('settings.allowedTools.help'))
                ->withReaderSymbol(Bootstrap::class . '::filterToolSet')
                ->withReadBy('`Bootstrap::tools()` → `filterToolSet()`'),
            SettingDefinition::new('disabledTools', SettingType::StringList, [])
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::Tools)
                ->withLabel(Lang::t('settings.disabledTools.label'))
                ->withHelp(Lang::t('settings.disabledTools.help'))
                ->withReaderSymbol(Bootstrap::class . '::filterToolSet')
                ->withReadBy('`Bootstrap::tools()` → `filterToolSet()`'),
            // User tier only: `off` is the direction that widens, and a
            // checked-out repository must not be able to take it.
            SettingDefinition::new('bashSandbox', SettingType::Enum, Bubblewrap::MODE_OFF)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withEnumValues(Bubblewrap::MODES)
                ->withLabel(Lang::t('settings.bashSandbox.label'))
                ->withHelp(Lang::t('settings.bashSandbox.help'))
                ->withReaderSymbol(Bash::class . '::fromCatalog')
                ->withReadBy('`Bash::fromCatalog()` → `Bubblewrap::fromSetting()`'),
            // Step 3.H. Both user tier only: the command is shell the
            // after-the-turn run executes with no tool call and no gate in
            // the path, so a checkout must neither name it nor switch it on
            // (`lintCommands`' argument).
            SettingDefinition::new(TestRunner::SETTINGS_KEY, SettingType::String)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withLabel(Lang::t('settings.testCommand.label'))
                ->withHelp(Lang::t('settings.testCommand.help'))
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `TestRunner::withCommand()`'),
            SettingDefinition::new(TestRunner::AUTO_TEST_SETTINGS_KEY, SettingType::Bool, false)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withLabel(Lang::t('settings.autoTest.label'))
                ->withHelp(Lang::t('settings.autoTest.help'))
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `AutoTestHook`'),
            SettingDefinition::new(ToolLimits::OUTPUT_CAP_KEY, SettingType::Int, Grep::DEFAULT_MAX_OUTPUT_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(4096, 1048576)
                ->withLabel(Lang::t('settings.toolOutputCapBytes.label'))
                ->withHelp(Lang::t('settings.toolOutputCapBytes.help'))
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::MCP_RESULT_CAP_KEY, SettingType::Int, McpToolBridge::DEFAULT_MAX_OUTPUT_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(4096, 1048576)
                ->withLabel(Lang::t('settings.mcpResultCapBytes.label'))
                ->withHelp(Lang::t('settings.mcpResultCapBytes.help'))
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::READ_MAX_BYTES_KEY, SettingType::Int, Read::DEFAULT_MAX_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(4096, 4194304)
                ->withLabel(Lang::t('settings.readMaxBytes.label'))
                ->withHelp(Lang::t('settings.readMaxBytes.help'))
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::READ_PAGE_LINES_KEY, SettingType::Int, Read::PAGE_LINES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(10, 100000)
                ->withLabel(Lang::t('settings.readPageLines.label'))
                ->withHelp(Lang::t('settings.readPageLines.help'))
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::READ_PAGE_BYTES_KEY, SettingType::Int, Read::PAGE_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1024, 1048576)
                ->withLabel(Lang::t('settings.readPageBytes.label'))
                ->withHelp(Lang::t('settings.readPageBytes.help'))
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            // Spend, so user tier only like the caps above: a larger share
            // is more of one result replayed into every later request.
            SettingDefinition::new(ToolLimits::SPILL_WINDOW_PERCENT_KEY, SettingType::Int, ToolOutputSpill::WINDOW_SHARE_PERCENT)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(5, 90)
                ->withLabel(Lang::t('settings.toolSpillWindowPercent.label'))
                ->withHelp(Lang::t('settings.toolSpillWindowPercent.help'))
                ->withReaderSymbol(ToolOutputSpill::class . '::forModel')
                ->withReadBy('`Runtime::settle()` → `ToolOutputSpill::forModel()`, per large result'),
            // Spend, user tier only: the instruction body is prepended to an
            // Edit/Write/ApplyPatch result and replayed into every later
            // request of the turn, like any capped result.
            SettingDefinition::new(ToolLimits::INSTRUCTION_CAP_KEY, SettingType::Int, Edit::DEFAULT_MAX_INSTRUCTION_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1024, 1048576)
                ->withLabel(Lang::t('settings.toolInstructionCapBytes.label'))
                ->withHelp(Lang::t('settings.toolInstructionCapBytes.help'))
                ->withReaderSymbol(Edit::class . '::instructionCapBytes')
                ->withReadBy('`TruncatesOutput::instructionCapBytes()`, each Edit, Write or ApplyPatch call'),
            // Memory and disk, not what the model is shown: the result cap
            // still bounds that, so these are Tuning like the WebFetch body
            // bound below.
            SettingDefinition::new(ToolLimits::SPILL_CAPTURE_BYTES_KEY, SettingType::Int, ToolOutputSpill::CAPTURE_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(65536, 67108864)
                ->withLabel(Lang::t('settings.toolSpillCaptureBytes.label'))
                ->withHelp(Lang::t('settings.toolSpillCaptureBytes.help'))
                ->withReaderSymbol(ToolOutputSpill::class . '::captureBytes')
                ->withReadBy('`TruncatesOutput::captureBound()` → `ToolOutputSpill::captureBytes()`, as a capture starts'),
            SettingDefinition::new(ToolLimits::SPILL_MIN_CAP_KEY, SettingType::Int, ToolOutputSpill::MIN_CAP_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(2048, 1048576)
                ->withLabel(Lang::t('settings.toolSpillMinCapBytes.label'))
                ->withHelp(Lang::t('settings.toolSpillMinCapBytes.help'))
                ->withReaderSymbol(ToolOutputSpill::class . '::minCapBytes')
                ->withReadBy('`TruncatesOutput::spillOverflow()` → `ToolOutputSpill::minCapBytes()`, per cut result'),
            SettingDefinition::new(ToolLimits::GLOB_MAX_MATCHES_KEY, SettingType::Int, Glob::DEFAULT_MAX_MATCHES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(10, 100000)
                ->withLabel(Lang::t('settings.globMaxMatches.label'))
                ->withHelp(Lang::t('settings.globMaxMatches.help'))
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::WEB_FETCH_MAX_BYTES_KEY, SettingType::Int, WebFetch::MAX_WIRE_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(65536, 67108864)
                ->withLabel(Lang::t('settings.webFetchMaxBytes.label'))
                ->withHelp(Lang::t('settings.webFetchMaxBytes.help'))
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::WEB_FETCH_TIMEOUT_KEY, SettingType::Int, WebFetch::READ_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 600)
                ->withLabel(Lang::t('settings.webFetchTimeoutSeconds.label'))
                ->withHelp(Lang::t('settings.webFetchTimeoutSeconds.help'))
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY, SettingType::Int, WebSearch::DEFAULT_MAX_RESULTS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withRange(1, 50)
                ->withLabel(Lang::t('settings.webSearchMaxResults.label'))
                ->withHelp(Lang::t('settings.webSearchMaxResults.help'))
                ->withReaderSymbol(WebSearch::class . '::__construct')
                ->withReadBy('`WebSearch::__construct()`, at launch and for `/websearch`'),
            SettingDefinition::new(ToolLimits::WEB_SEARCH_TIMEOUT_KEY, SettingType::Int, WebSearch::DEFAULT_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withRange(1, 600)
                ->withLabel(Lang::t('settings.webSearchTimeoutSeconds.label'))
                ->withHelp(Lang::t('settings.webSearchTimeoutSeconds.help'))
                ->withReaderSymbol(WebSearch::class . '::__construct')
                ->withReadBy('`WebSearch::__construct()`, at launch and for `/websearch`'),
            // Roadmap N-P4e. Egress, so user tier only: the endpoint receives
            // every model-composed query, which routinely quotes the
            // repository's code. No default host, by design (audit F-W3(b)).
            SettingDefinition::new(ToolLimits::WEB_SEARCH_ENDPOINT_KEY, SettingType::Url)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Egress)
                ->withLayered()
                ->withEnvVar('CANDY_CODE_SEARCH_ENDPOINT')
                ->withDefaultText('unset: no default; WebSearch refuses every call until one is set')
                ->withLabel(Lang::t('settings.webSearchEndpoint.label'))
                ->withHelp(Lang::t('settings.webSearchEndpoint.help'))
                ->withReaderSymbol(WebSearch::class . '::__construct')
                ->withReadBy('`WebSearch::__construct()`, at launch and for `/websearch`'),
            SettingDefinition::new(ToolLimits::INTERACTIVE_IDLE_KEY, SettingType::Float, 8.0)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1.0, 600.0)
                ->withLabel(Lang::t('settings.bashInteractiveIdleSeconds.label'))
                ->withHelp(Lang::t('settings.bashInteractiveIdleSeconds.help'))
                ->withReaderSymbol(Bash::class . '::runCapturedInteractive')
                ->withReadBy('`CapturesProcessOutput::runCapturedInteractive()`, as the run starts'),
            // Bash's `timeout` bounds: time, not spend — the command's output
            // is capped either way — so a trusted project may set them like
            // the idle ceiling above. A default over the ceiling is lowered to
            // it by `Bash::withTimeoutBounds()`.
            SettingDefinition::new(ToolLimits::BASH_TIMEOUT_KEY, SettingType::Int, Bash::DEFAULT_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 3600)
                ->withLabel(Lang::t('settings.bashTimeoutSeconds.label'))
                ->withHelp(Lang::t('settings.bashTimeoutSeconds.help'))
                ->withReaderSymbol(Bash::class . '::withTimeoutBounds')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()` → `Bash::withTimeoutBounds()`, each turn'),
            SettingDefinition::new(ToolLimits::BASH_MAX_TIMEOUT_KEY, SettingType::Int, Bash::MAX_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 3600)
                ->withLabel(Lang::t('settings.bashMaxTimeoutSeconds.label'))
                ->withHelp(Lang::t('settings.bashMaxTimeoutSeconds.help'))
                ->withReaderSymbol(Bash::class . '::withTimeoutBounds')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()` → `Bash::withTimeoutBounds()`, each turn'),
            SettingDefinition::new(ToolLimits::PARALLEL_TIMEOUT_KEY, SettingType::Int, Chat::PARALLEL_TOOL_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 3600)
                ->withLabel(Lang::t('settings.chatToolTimeoutSeconds.label'))
                ->withHelp(Lang::t('settings.chatToolTimeoutSeconds.help'))
                ->withReaderSymbol(Chat::class . '::waitForToolChildrenAsync')
                ->withReadBy('`Chat::waitForToolChildrenAsync()`, as a batch starts'),
            // A search's deadline is time, not spend — like the Bash bounds
            // above — so a trusted project may set it. The reader is not the
            // turn-time rebind: `Grep::searchTimeoutSeconds()` reads the bound
            // at the moment each run is armed (ISS-061).
            SettingDefinition::new(ToolLimits::GREP_TIMEOUT_KEY, SettingType::Int, Grep::DEFAULT_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 3600)
                ->withLabel(Lang::t('settings.grepTimeoutSeconds.label'))
                ->withHelp(Lang::t('settings.grepTimeoutSeconds.help'))
                ->withReaderSymbol(Grep::class . '::searchTimeoutSeconds')
                ->withReadBy('`Grep::searchTimeoutSeconds()` → `ToolLimits::current()`, as each search starts'),
        ];
    }
}
