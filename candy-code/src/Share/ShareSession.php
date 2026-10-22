<?php

declare(strict_types=1);

namespace SugarCraft\Code\Share;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Memory\SecretRedactor;
use SugarCraft\Code\Message;
use SugarCraft\Code\Util\Exporter;

/**
 * Represents a session prepared for sharing.
 *
 * Extracts and serializes the conversation history from a Chat instance
 * into shareable export formats (Markdown, self-contained HTML, JSON, plain
 * text). `/share` writes the result to a local file (roadmap X-35a); the
 * HTML rendering doubles as the published snapshot behind `/share serve`
 * ({@see ShareStore}, COMPARISON §6.5).
 *
 * WHAT IS EXPORTED: the rows a backend may be shown
 * ({@see Message::agentVisible()}'s rule — not `uiOnly`) plus the tool rows
 * (a message carrying tool results), so a reader sees what the model saw and
 * what its tools answered. App chrome — notices, command echoes, the
 * transient "tool is running" placeholders — is left out, and so is a row
 * the transcript hides ({@see Message::$userVisible} false: a turn's step
 * record or a harness-written nudge, roadmap 1.B-2), exactly as the
 * transcript's own paint skips it.
 *
 * @mirrors charmbracelet/<repo>.ShareSession
 */
final class ShareSession
{
    public const FORMAT_MARKDOWN = 'markdown';
    public const FORMAT_HTML = 'html';
    public const FORMAT_JSON = 'json';
    public const FORMAT_TEXT = 'text';

    /** @var list<string> Supported export formats */
    public const SUPPORTED_FORMATS = [
        self::FORMAT_MARKDOWN,
        self::FORMAT_HTML,
        self::FORMAT_JSON,
        self::FORMAT_TEXT,
    ];

    /** @var int Default expiry in days */
    public const DEFAULT_EXPIRY_DAYS = 7;

    /** @var list<Message> */
    private readonly array $messages;

    /**
     * @param list<Message> $messages The conversation messages to share.
     * @param string $format The export format (markdown, json, text).
     * @param int $expiryDays Number of days until the share URL expires.
     */
    public function __construct(
        array $messages,
        private readonly string $format = self::FORMAT_MARKDOWN,
        private readonly int $expiryDays = self::DEFAULT_EXPIRY_DAYS,
    ) {
        // Chat::$history contains concrete SugarCraft\Code\Message objects
        // (not the SugarCraft\Code\Messages\Message interface).
        $this->messages = array_values(array_filter(
            $messages,
            static fn(mixed $m): bool => $m instanceof Message
                && $m->pendingToolCallId === null
                && $m->userVisible
                && (!$m->uiOnly || $m->toolResults !== []),
        ));
    }

    /**
     * Create a ShareSession from a Chat instance.
     */
    public static function fromChat(Chat $chat, string $format = self::FORMAT_MARKDOWN, int $expiryDays = self::DEFAULT_EXPIRY_DAYS): self
    {
        return new self($chat->history, $format, $expiryDays);
    }

    /**
     * Get the message count.
     */
    public function messageCount(): int
    {
        return count($this->messages);
    }

    /**
     * Get the export format.
     */
    public function format(): string
    {
        return $this->format;
    }

    /**
     * Get the expiry days.
     */
    public function expiryDays(): int
    {
        return $this->expiryDays;
    }

    /**
     * Serialize the session to the specified format — the PUBLISHED body.
     *
     * SEC-4 (audit 2026-10-21): a share URL is a public artifact, but the
     * transcript behind it carries whatever an API key-shaped string ever
     * reached the chat, while the memory audit trail already redacts. The
     * same {@see SecretRedactor} runs over the finished body here, at the
     * single choke every format passes through, so publishing can no longer
     * leak what refusing to share your own files already protects. This
     * covers EVERY serialize() consumer, /share's local files included —
     * `/export` ({@see \SugarCraft\Code\Commands\ExportCommand}) is the verb
     * that keeps the raw body, reading {@see serializeRaw()} directly.
     *
     * @return string The serialized, secret-redacted session content.
     */
    public function serialize(): string
    {
        return SecretRedactor::new()->redact($this->serializeRaw());
    }

    /**
     * The serialized body WITHOUT secret redaction — the local-export half
     * of the SEC-4 ruling ("local export keeps raw bytes").
     *
     * The row filter is the constructor's, the format render is
     * {@see serialize()}'s; the only difference is the missing redactor pass.
     * Callers must be a file the user owns, never a wire, because a raw body
     * can carry whatever API key ever reached the chat.
     *
     * @return string The serialized session content, secrets intact.
     */
    public function serializeRaw(): string
    {
        // Use wire format (toWire()) for export - handles the concrete Message type
        return match ($this->format) {
            self::FORMAT_JSON => Exporter::toJson($this->messages),
            self::FORMAT_HTML => Exporter::toHtml($this->messages),
            self::FORMAT_TEXT => Exporter::toText($this->messages),
            default => Exporter::toMarkdown($this->messages),
        };
    }

    /**
     * Check if the given format is supported.
     */
    public static function isValidFormat(string $format): bool
    {
        return in_array($format, self::SUPPORTED_FORMATS, true);
    }

    /**
     * Get the content type for the current format.
     */
    public function contentType(): string
    {
        return match ($this->format) {
            self::FORMAT_JSON => 'application/json',
            self::FORMAT_HTML => 'text/html',
            self::FORMAT_TEXT => 'text/plain',
            default => 'text/markdown',
        };
    }

    /**
     * Get the file extension for the current format.
     */
    public function fileExtension(): string
    {
        return match ($this->format) {
            self::FORMAT_JSON => 'json',
            self::FORMAT_HTML => 'html',
            self::FORMAT_TEXT => 'txt',
            default => 'md',
        };
    }
}
