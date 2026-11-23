<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * The cheapest tool that keeps an agentic loop wanting another step: named
 * `clock`, no input schema, and every call answers `noon` under tool call id
 * `call_1`.
 *
 * WHY IT IS SHARED. UsageWiringTest and HttpSettingsSnapshotTest each carried
 * a private copy that differed only in how the anonymous class was spelled
 * (`new class` against `new class ()`), which DuplicatedTestHelperDriftTest
 * reports as a copied helper two tokens apart. One factory here means the two
 * suites cannot drift on what the tool returns.
 */
final class ClockTool
{
    public static function new(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'clock';
            }

            public function description(): string
            {
                return 'test tool';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'call_1', content: 'noon');
            }
        };
    }
}
