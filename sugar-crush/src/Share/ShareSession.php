<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Share;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Util\Exporter;

/**
 * Represents a session prepared for sharing.
 *
 * Extracts and serializes the conversation history from a Chat instance
 * into shareable export formats (Markdown, JSON, plain text).
 *
 * @mirrors charmbracelet/<repo>.ShareSession
 */
final class ShareSession
{
    public const FORMAT_MARKDOWN = 'markdown';
    public const FORMAT_JSON = 'json';
    public const FORMAT_TEXT = 'text';

    /** @var list<string> Supported export formats */
    public const SUPPORTED_FORMATS = [
        self::FORMAT_MARKDOWN,
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
        // Chat::$history contains concrete SugarCraft\Crush\Message objects
        // (not the SugarCraft\Crush\Messages\Message interface).
        $this->messages = array_values(array_filter(
            $messages,
            static fn(mixed $m): bool => $m instanceof Message,
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
     * Serialize the session to the specified format.
     *
     * @return string The serialized session content.
     */
    public function serialize(): string
    {
        // Use wire format (toWire()) for export - handles the concrete Message type
        return match ($this->format) {
            self::FORMAT_JSON => Exporter::toJson($this->messages),
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
            self::FORMAT_TEXT => 'txt',
            default => 'md',
        };
    }
}
