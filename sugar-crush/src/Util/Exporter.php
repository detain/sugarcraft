<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Util;

use SugarCraft\Crush\Messages\Message as MessagesMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Message as CrushMessage;

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
            // Handle concrete CrushMessage (uses properties)
            if ($msg instanceof CrushMessage) {
                $role = ucfirst($msg->role->name);
                $content = $msg->content;
                if ($msg->toolCalls !== []) {
                    $content .= "\n\n**Tool Calls:**\n";
                    foreach ($msg->toolCalls as $tc) {
                        $content .= "- `{$tc->name}`: " . json_encode($tc->arguments) . "\n";
                    }
                }
                $output[] = "### $role\n\n$content\n";
                continue;
            }

            // Handle Messages interface types (use methods)
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
     *
     * Handles both the concrete CrushMessage class (via ->toWire())
     * and Messages\Message interface implementations (via ->toArray()).
     */
    public static function toJson(array $messages): string
    {
        return json_encode(array_map(
            static fn($msg) => match (true) {
                $msg instanceof CrushMessage => $msg->toWire(),
                $msg instanceof MessagesMessage => $msg->toArray(),
                default => $msg,
            },
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
            // Handle concrete CrushMessage (uses properties)
            if ($msg instanceof CrushMessage) {
                $role = $msg->role->name;
                $output[] = "[$role]\n{$msg->content}\n";
                continue;
            }

            // Handle Messages interface types (use methods)
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
