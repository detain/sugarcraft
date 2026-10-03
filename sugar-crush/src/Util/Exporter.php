<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Util;

use SugarCraft\Crush\Messages\Message as MessagesMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Message as CrushMessage;
use SugarCraft\Crush\ToolResult;

/**
 * Exports conversation messages to various formats.
 *
 * Every format carries a concrete {@see CrushMessage}'s tool calls AND its tool
 * results (X-35a): an export of a coding session that dropped what the tools
 * answered would show the model's requests with none of the evidence it acted
 * on.
 *
 * And it exports what the TRANSCRIPT shows: a concrete row the user does not
 * see ({@see CrushMessage::$userVisible} false — a turn's step record, the
 * assistant row whose tool calls the visible tool rows already answer, or a
 * harness-written nudge) is skipped in every format, the same rule
 * {@see \SugarCraft\Crush\Renderer}'s history paint applies (roadmap 1.B-2).
 */
final class Exporter
{
    /** Title of an HTML export when the caller names none. */
    public const DEFAULT_HTML_TITLE = 'SugarCrush session';

    /**
     * Export to Markdown format.
     */
    public static function toMarkdown(array $messages): string
    {
        $messages = self::shown($messages);
        $output = [];

        foreach ($messages as $msg) {
            // Handle concrete CrushMessage (uses properties)
            if ($msg instanceof CrushMessage) {
                $role = self::crushRoleLabel($msg);
                $content = $msg->content;
                if ($msg->toolCalls !== []) {
                    $content .= "\n\n**Tool Calls:**\n";
                    foreach ($msg->toolCalls as $tc) {
                        $content .= "- `{$tc->name}`: " . json_encode($tc->arguments) . "\n";
                    }
                }
                foreach ($msg->toolResults as $result) {
                    if ($result instanceof ToolResult) {
                        $content .= "\n\n**Tool Result** (`{$result->name}`"
                            . ($result->isError() ? ', error' : '') . "):\n\n```\n"
                            . self::resultText($result) . "\n```\n";
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
        $messages = self::shown($messages);

        return json_encode(array_map(
            static fn($msg) => match (true) {
                $msg instanceof CrushMessage => self::crushJsonRow($msg),
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
        $messages = self::shown($messages);
        $output = [];

        foreach ($messages as $msg) {
            // Handle concrete CrushMessage (uses properties)
            if ($msg instanceof CrushMessage) {
                $role = self::crushRoleLabel($msg);
                $text = $msg->content;
                foreach ($msg->toolResults as $result) {
                    if ($result instanceof ToolResult) {
                        $text .= "\n[Tool result {$result->name}" . ($result->isError() ? ', error' : '')
                            . "]\n" . self::resultText($result);
                    }
                }
                $output[] = "[$role]\n{$text}\n";
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

    /**
     * Export to one self-contained HTML document (X-35a).
     *
     * SELF-CONTAINED ON PURPOSE: inline CSS, no script, no remote font, image or
     * stylesheet — a transcript can hold secrets, and a page that fetched
     * anything would tell a third party when it was opened. Every piece of
     * message text is escaped with {@see htmlspecialchars()}, so a reply that
     * quotes `<script>` renders as text, never as markup.
     *
     * @param list<mixed> $messages
     */
    public static function toHtml(array $messages, string $title = self::DEFAULT_HTML_TITLE): string
    {
        $messages = self::shown($messages);
        $articles = [];

        foreach ($messages as $msg) {
            $parts = [];
            if ($msg instanceof CrushMessage) {
                $role = self::crushRoleLabel($msg);
                $parts[] = self::htmlBlock($msg->content);
                if ($msg->toolCalls !== []) {
                    $calls = [];
                    foreach ($msg->toolCalls as $tc) {
                        $calls[] = '<li><code>' . self::html((string) $tc->name) . '</code> '
                            . self::html((string) json_encode($tc->arguments, \JSON_UNESCAPED_SLASHES)) . '</li>';
                    }
                    $parts[] = '<p class="label">Tool calls</p><ul>' . implode('', $calls) . '</ul>';
                }
                foreach ($msg->toolResults as $result) {
                    if ($result instanceof ToolResult) {
                        $parts[] = '<p class="label">Tool result <code>' . self::html($result->name) . '</code>'
                            . ($result->isError() ? ' (error)' : '') . '</p>' . self::htmlBlock(self::resultText($result));
                    }
                }
            } else {
                $role = match (true) {
                    $msg instanceof UserMessage => 'User',
                    $msg instanceof AssistantMessage => 'Assistant',
                    $msg instanceof SystemMessage => 'System',
                    $msg instanceof ToolResultMessage => 'Tool',
                    default => 'Unknown',
                };
                $parts[] = self::htmlBlock($msg instanceof MessagesMessage ? $msg->content() : '');
            }

            $articles[] = '<article class="' . strtolower($role) . '"><h2>' . self::html($role) . '</h2>'
                . implode('', $parts) . '</article>';
        }

        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . '<title>' . self::html($title) . "</title>\n"
            . "<style>\n"
            . "body{margin:0;padding:16px;font:15px/1.5 system-ui,sans-serif;background:#fff;color:#1a1a1a}\n"
            . "main{max-width:860px;margin:0 auto}\n"
            . "article{border:1px solid #ddd;border-radius:6px;padding:8px 14px;margin:12px 0}\n"
            . "article.user{background:#f3f7ff}article.tool{background:#f7f7f7}\n"
            . "h2{font-size:13px;text-transform:uppercase;letter-spacing:.04em;margin:4px 0;color:#555}\n"
            . "pre{white-space:pre-wrap;word-wrap:break-word;font:13px/1.45 ui-monospace,monospace;margin:6px 0}\n"
            . ".label{font-size:12px;color:#555;margin:8px 0 0}\n"
            . "@media (prefers-color-scheme:dark){body{background:#121212;color:#e6e6e6}"
            . "article{border-color:#333}article.user{background:#17202e}article.tool{background:#1c1c1c}"
            . "h2,.label{color:#aaa}}\n"
            . "</style>\n</head>\n<body>\n<main>\n<h1>" . self::html($title) . "</h1>\n"
            . implode("\n", $articles) . "\n</main>\n</body>\n</html>\n";
    }

    /**
     * $messages without the concrete rows the transcript does not show
     * ({@see CrushMessage::$userVisible} false); every other entry, of any
     * type, passes through in order.
     *
     * @param array<mixed> $messages
     * @return list<mixed>
     */
    private static function shown(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            static fn(mixed $msg): bool => !$msg instanceof CrushMessage || $msg->userVisible,
        ));
    }

    /**
     * A concrete message's label: a row carrying tool results is the TOOL's
     * answer whatever role the transcript filed it under.
     */
    private static function crushRoleLabel(CrushMessage $msg): string
    {
        return $msg->toolResults !== [] ? 'Tool' : ucfirst($msg->role->name);
    }

    /**
     * The wire row plus the tool results the wire shape leaves out.
     *
     * @return array<string, mixed>
     */
    private static function crushJsonRow(CrushMessage $msg): array
    {
        $row = $msg->toWire();
        $results = [];
        foreach ($msg->toolResults as $result) {
            if ($result instanceof ToolResult) {
                $results[] = $result->toWire() + ['is_error' => $result->isError()];
            }
        }
        if ($results !== []) {
            $row['tool_results'] = $results;
        }

        return $row;
    }

    /** What the tool answered: its error text when it failed, its output otherwise. */
    private static function resultText(ToolResult $result): string
    {
        return $result->error ?? $result->result;
    }

    private static function htmlBlock(string $text): string
    {
        return $text === '' ? '' : '<pre>' . self::html($text) . '</pre>';
    }

    private static function html(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
