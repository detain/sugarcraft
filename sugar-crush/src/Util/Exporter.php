<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Util;

use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;

/**
 * Exports conversation messages to various formats.
 */
final class Exporter
{
    /**
     * Export to Markdown format.
     */
    public static function toMarkdown(array $messages): string
    {
        $output = [];

        foreach ($messages as $msg) {
            $role = ucfirst(match (true) {
                $msg instanceof UserMessage => 'User',
                $msg instanceof AssistantMessage => 'Assistant',
                $msg instanceof SystemMessage => 'System',
                $msg instanceof ToolResultMessage => 'Tool',
                default => 'Unknown',
            });

            $content = $msg->content();
            if ($msg instanceof AssistantMessage && $msg->toolCalls()) {
                $content .= "\n\n**Tool Calls:**\n";
                foreach ($msg->toolCalls() as $tc) {
                    $content .= "- `{$tc->name()}`: " . json_encode($tc->arguments()) . "\n";
                }
            }

            $output[] = "### $role\n\n$content\n";
        }

        return implode("\n---\n", $output);
    }

    /**
     * Export to JSON format.
     */
    public static function toJson(array $messages): string
    {
        return json_encode(array_map(
            fn($msg) => $msg instanceof Message ? $msg->toArray() : $msg,
            $messages
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Export to plain text format.
     */
    public static function toText(array $messages): string
    {
        $output = [];

        foreach ($messages as $msg) {
            $role = match (true) {
                $msg instanceof UserMessage => 'User',
                $msg instanceof AssistantMessage => 'Assistant',
                $msg instanceof SystemMessage => 'System',
                $msg instanceof ToolResultMessage => 'Tool',
                default => 'Unknown',
            };

            $output[] = "[$role]\n{$msg->content()}\n";
        }

        return implode("\n", $output);
    }
}
