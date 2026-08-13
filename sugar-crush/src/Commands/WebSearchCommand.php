<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Tools\BuiltIn\WebSearch;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Implements the /websearch command for searching the web.
 *
 * Accepts a query string with optional flags:
 *   --safesearch 0|1|2  Safe search filter (0=none, 1=moderate, 2=strict)
 *   --time-range day|month|year  Time range limit
 *
 * @mirrors charmbracelet/<repo>.WebSearchCommand
 */
final class WebSearchCommand
{
    private const MAX_QUERY_LENGTH = 2000;

    public function __construct(
        private ?WebSearch $webSearch = null,
    ) {
        if ($this->webSearch === null) {
            $this->webSearch = new WebSearch();
        }
    }

    /**
     * @param list<string> $args Command arguments (whitespace-split)
     */
    public function execute(Chat $chat, array $args = []): int
    {
        // Parse flags and extract query
        $safesearch = null;
        $timeRange = null;
        $queryParts = [];

        $i = 0;
        while ($i < count($args)) {
            $arg = $args[$i];

            if ($arg === '--help' || $arg === '-h') {
                $this->printHelp();
                return 0;
            }

            if ($arg === '--safesearch' && isset($args[$i + 1])) {
                $value = $args[$i + 1];
                if (is_numeric($value)) {
                    $intVal = (int) $value;
                    if ($intVal >= 0 && $intVal <= 2 && (string) $intVal === (string) $value) {
                        $safesearch = $intVal;
                        $i += 2;
                        continue;
                    }
                }
                // Invalid safesearch value
                $this->printError("Invalid safesearch value '{$value}'. Must be 0, 1, or 2.");
                return 1;
            }

            if ($arg === '--time-range' && isset($args[$i + 1])) {
                $value = $args[$i + 1];
                if (in_array($value, ['day', 'month', 'year'], true)) {
                    $timeRange = $value;
                    $i += 2;
                    continue;
                }
                // Invalid time-range value
                $this->printError("Invalid time-range '{$value}'. Must be day, month, or year.");
                return 1;
            }

            // Check if current arg is a flag (starts with --)
            if (str_starts_with($arg, '--')) {
                $validFlags = ['--safesearch', '--time-range', '--help', '-h'];
                if (!in_array($arg, $validFlags, true)) {
                    $this->printError("Unknown flag '{$arg}'. Valid flags: --safesearch, --time-range, --help");
                    return 1;
                }
                $i++;
                continue;
            }

            $queryParts[] = $arg;
            $i++;
        }

        $query = implode(' ', $queryParts);

        // Validate query
        if (trim($query) === '') {
            $this->printUsage();
            return 1;
        }

        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            $this->printError("Query exceeds maximum length of " . self::MAX_QUERY_LENGTH . " characters");
            return 1;
        }

        // Execute search
        echo "  Searching...\n";

        $result = $this->webSearch->execute([
            'query' => $query,
            'description' => "Web search",
            'safesearch' => $safesearch,
            'time_range' => $timeRange,
        ]);

        if ($result->isError()) {
            echo "\n";
            echo "  " . str_repeat("─", 54) . "\n";
            echo "  ✗ {$result->content()}\n";
            echo "  " . str_repeat("─", 54) . "\n";
            echo "\n";
            return 1;
        }

        echo "\n";
        echo "  " . str_repeat("─", 54) . "\n";

        $lines = explode("\n", $result->content());
        foreach ($lines as $line) {
            echo "  {$line}\n";
        }

        echo "  " . str_repeat("─", 54) . "\n";
        echo "\n";
        return 0;
    }

    /**
     * Print usage information.
     */
    private function printUsage(): void
    {
        echo "\n";
        echo "  Usage: /websearch <query> [--safesearch 0|1|2] [--time-range day|month|year]\n";
        echo "  Use /websearch --help for full options.\n";
        echo "\n";
    }

    private function printHelp(): void
    {
        echo "\n";
        echo "  " . str_repeat("─", 54) . "\n";
        echo "  /websearch — Search the web via SearXNG\n";
        echo "  " . str_repeat("─", 54) . "\n";
        echo "\n";
        echo "  Usage: /websearch <query> [options]\n";
        echo "\n";
        echo "  Options:\n";
        echo "    --safesearch 0|1|2   Safe search (0=none, 1=moderate, 2=strict)\n";
        echo "    --time-range day|month|year  Limit results to time period\n";
        echo "    --help, -h           Show this help message\n";
        echo "\n";
        echo "  Examples:\n";
        echo "    /websearch \"php tutorial\"\n";
        echo "    /websearch \"news\" --safesearch 2 --time-range month\n";
        echo "    /websearch --time-range year \"rust\"\n";
        echo "\n";
    }

    /**
     * Print an error message.
     */
    private function printError(string $message): void
    {
        echo "\n";
        echo "  ✗ {$message}\n";
        echo "\n";
    }
}
