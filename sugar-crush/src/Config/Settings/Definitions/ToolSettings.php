<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\OptionsSource;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Lint\TestRunner;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\WebFetch;
use SugarCraft\Crush\Tools\BuiltIn\WebSearch;
use SugarCraft\Crush\Tools\McpToolBridge;
use SugarCraft\Crush\Tools\Sandbox\Bubblewrap;
use SugarCraft\Crush\Tools\ToolLimits;

/**
 * The "Tools" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
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
                ->withLabel('Allowed tools')
                ->withHelp('Whitelist of tool names or globs; unset offers every tool.')
                ->withReaderSymbol(Bootstrap::class . '::filterToolSet')
                ->withReadBy('`Bootstrap::tools()` → `filterToolSet()`'),
            SettingDefinition::new('disabledTools', SettingType::StringList, [])
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::Tools)
                ->withLabel('Disabled tools')
                ->withHelp('Tool names or globs removed from the model-facing tool set.')
                ->withReaderSymbol(Bootstrap::class . '::filterToolSet')
                ->withReadBy('`Bootstrap::tools()` → `filterToolSet()`'),
            // User tier only: `off` is the direction that widens, and a
            // checked-out repository must not be able to take it.
            SettingDefinition::new('bashSandbox', SettingType::Enum, Bubblewrap::MODE_OFF)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withEnumValues(Bubblewrap::MODES)
                ->withLabel('Bash sandbox')
                ->withHelp('Linux only: run Bash inside bubblewrap, writable only in the working root (`no-network` also cuts the network). Refuses commands when bwrap cannot start.')
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
                ->withLabel('Test command')
                ->withHelp('Shell command that runs the project\'s tests, in the project root (`composer test`, `pytest -q`); what `autoTest` runs.')
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `TestRunner::withCommand()`'),
            SettingDefinition::new(TestRunner::AUTO_TEST_SETTINGS_KEY, SettingType::Bool, false)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withLabel('Auto-test')
                ->withHelp('After a turn that edited a file, run `testCommand`; on failure the output goes back to the model, at most 3 times a turn.')
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `AutoTestHook`'),
            SettingDefinition::new(ToolLimits::OUTPUT_CAP_KEY, SettingType::Int, Grep::DEFAULT_MAX_OUTPUT_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(4096, 1048576)
                ->withLabel('Tool output cap (bytes)')
                ->withHelp('Most bytes one Bash, Grep, Glob, Lsp or WebFetch result may hand the model; what is cut is saved to a file the result names.')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::MCP_RESULT_CAP_KEY, SettingType::Int, McpToolBridge::DEFAULT_MAX_OUTPUT_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(4096, 1048576)
                ->withLabel('MCP result cap (bytes)')
                ->withHelp('Most bytes one MCP tool\'s answer may hand the model.')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::READ_MAX_BYTES_KEY, SettingType::Int, Read::DEFAULT_MAX_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(4096, 4194304)
                ->withLabel('Read bound (bytes)')
                ->withHelp('Ceiling on one Read call; the page below is the size a read usually comes back at.')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::READ_PAGE_LINES_KEY, SettingType::Int, Read::PAGE_LINES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(10, 100000)
                ->withLabel('Read page (lines)')
                ->withHelp('Lines a Read returns when the call names no `limit`.')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::READ_PAGE_BYTES_KEY, SettingType::Int, Read::PAGE_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1024, 1048576)
                ->withLabel('Read page (bytes)')
                ->withHelp('Bytes one Read page may hold, line numbers included, whatever `limit` asks for.')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::GLOB_MAX_MATCHES_KEY, SettingType::Int, Glob::DEFAULT_MAX_MATCHES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(10, 100000)
                ->withLabel('Glob match cap')
                ->withHelp('Paths one Glob collects before its walk stops; the output cap still bounds the result.')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::WEB_FETCH_MAX_BYTES_KEY, SettingType::Int, WebFetch::MAX_WIRE_BYTES)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(65536, 67108864)
                ->withLabel('WebFetch body bound (bytes)')
                ->withHelp('Most of a fetched body held in memory; a memory bound, the output cap is what reaches the model.')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::WEB_FETCH_TIMEOUT_KEY, SettingType::Int, WebFetch::READ_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 600)
                ->withLabel('WebFetch timeout (s)')
                ->withHelp('Seconds a WebFetch socket read may stall before the fetch fails.')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()`'),
            SettingDefinition::new(ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY, SettingType::Int, WebSearch::DEFAULT_MAX_RESULTS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withRange(1, 50)
                ->withLabel('WebSearch results')
                ->withHelp('Results one WebSearch digest lists, each a title, URL and snippet.')
                ->withReaderSymbol(WebSearch::class . '::__construct')
                ->withReadBy('`WebSearch::__construct()`, at launch and for `/websearch`'),
            SettingDefinition::new(ToolLimits::WEB_SEARCH_TIMEOUT_KEY, SettingType::Int, WebSearch::DEFAULT_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withRange(1, 600)
                ->withLabel('WebSearch timeout (s)')
                ->withHelp('Seconds one search request may take.')
                ->withReaderSymbol(WebSearch::class . '::__construct')
                ->withReadBy('`WebSearch::__construct()`, at launch and for `/websearch`'),
            // Roadmap N-P4e. Egress, so user tier only: the endpoint receives
            // every model-composed query, which routinely quotes the
            // repository's code. No default host, by design (audit F-W3(b)).
            SettingDefinition::new(ToolLimits::WEB_SEARCH_ENDPOINT_KEY, SettingType::Url)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Egress)
                ->withLayered()
                ->withEnvVar('SUGARCRUSH_SEARCH_ENDPOINT')
                ->withDefaultText('unset: no default; WebSearch refuses every call until one is set')
                ->withLabel('WebSearch endpoint')
                ->withHelp('Search URL of a SearXNG instance you trust (`https://searx.example.org/search`); every WebSearch query is sent there.')
                ->withReaderSymbol(WebSearch::class . '::__construct')
                ->withReadBy('`WebSearch::__construct()`, at launch and for `/websearch`'),
            SettingDefinition::new(ToolLimits::INTERACTIVE_IDLE_KEY, SettingType::Float, 8.0)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1.0, 600.0)
                ->withLabel('Interactive Bash idle (s)')
                ->withHelp('Seconds an `interactive: true` Bash run may print nothing before it is stopped as waiting for a keystroke.')
                ->withReaderSymbol(Bash::class . '::runCapturedInteractive')
                ->withReadBy('`CapturesProcessOutput::runCapturedInteractive()`, as the run starts'),
            SettingDefinition::new(ToolLimits::PARALLEL_TIMEOUT_KEY, SettingType::Int, Chat::PARALLEL_TOOL_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 3600)
                ->withLabel('Chat-native tool timeout (s)')
                ->withHelp('Wall-clock budget of a batch of tool calls the chat-native (`command` provider) path forks; stragglers are killed.')
                ->withReaderSymbol(Chat::class . '::waitForToolChildrenAsync')
                ->withReadBy('`Chat::waitForToolChildrenAsync()`, as a batch starts'),
        ];
    }
}
