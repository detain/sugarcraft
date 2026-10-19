<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use SugarCraft\Code\Messages\UserMessage;

/**
 * The per-wire shapes of a user turn that carries attachments (audit 15b-15).
 *
 * ONE definition per wire family, shared by every provider speaking it, so the
 * OpenAI-compatible three (OpenAI, SGLang, Custom) cannot drift apart on what a
 * `image_url` part looks like. Files are already text by the time anything
 * here runs ({@see UserMessage::wireText()}); images become the family's own
 * image block. Which providers may receive an image at all is decided once,
 * upstream, by {@see ProviderInterface::supportsVision()} in
 * {@see \SugarCraft\Code\Backend\EngineBackend::toTypedMessages()} - a
 * provider that answers false never has an image handed to it, and the
 * text-only encoders still name any that arrive ({@see UserMessage::textOnly()}).
 *
 * A turn with no sendable image keeps the exact pre-15b-15 shape on every wire
 * (a plain string `content` for the OpenAI family, one text block for the block
 * families), so attachments cost nothing - and change no cached prefix - for
 * the ordinary prompt.
 *
 * CTX-2 adds the Anthropic family's `document` block for PDF attachments;
 * capability is decided the same upstream way by
 * {@see ProviderInterface::supportsDocuments()}. The OpenAI/Gemini/Bedrock
 * encoders decline the block this slice - a document reaching one of those
 * anyway (hand-built past the gate) is named in the text, never dropped and
 * never inlined as bytes.
 */
final class AttachmentEncoding
{
    /**
     * OpenAI chat/completions: a string, or text + `image_url` parts carrying a
     * `data:` URL. SGLang's OpenAI-compatible server and Anthropic's OpenAI
     * compatibility endpoint read the same shape.
     *
     * @return string|list<array<string, mixed>>
     */
    public static function openAiContent(UserMessage $message): string|array
    {
        $images = $message->images();
        if ($images === []) {
            return self::withDeclinedDocuments($message->wireText(), $message);
        }

        $parts = [];
        $text = self::withDeclinedDocuments($message->wireText(), $message);
        if ($text !== '') {
            $parts[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($images as $image) {
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $image->mimeType . ';base64,' . base64_encode((string) $image->data)],
            ];
        }

        return $parts;
    }

    /**
     * Anthropic Messages (the Messages API, and Claude on Vertex's
     * rawPredict): a text block, then one base64 `image` block per image,
     * then one base64 `document` block per PDF (CTX-2 - the wire's own
     * document family, titled so the model can name the file back).
     *
     * @return list<array<string, mixed>>
     */
    public static function anthropicBlocks(UserMessage $message): array
    {
        $blocks = [];
        $text = $message->wireText();
        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($message->images() as $image) {
            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image->mimeType,
                    'data' => base64_encode((string) $image->data),
                ],
            ];
        }
        foreach ($message->documents() as $document) {
            $blocks[] = [
                'type' => 'document',
                'title' => $document->name(),
                'source' => [
                    'type' => 'base64',
                    'media_type' => (string) $document->mimeType,
                    'data' => base64_encode((string) $document->data),
                ],
            ];
        }

        return $blocks;
    }

    /**
     * Gemini `generateContent`: a text part, then one `inlineData` part per
     * image.
     *
     * @return list<array<string, mixed>>
     */
    public static function geminiParts(UserMessage $message): array
    {
        $parts = [];
        $text = self::withDeclinedDocuments($message->wireText(), $message);
        if ($text !== '') {
            $parts[] = ['text' => $text];
        }
        foreach ($message->images() as $image) {
            $parts[] = ['inlineData' => ['mimeType' => $image->mimeType, 'data' => base64_encode((string) $image->data)]];
        }

        return $parts;
    }

    /**
     * Bedrock Converse: a text block, then one `image` block per image. The
     * bytes go RAW - the AWS SDK base64-encodes a blob member itself, so
     * encoding here would send the model base64 of base64.
     *
     * @return list<array<string, mixed>>
     */
    public static function bedrockBlocks(UserMessage $message): array
    {
        $blocks = [['text' => self::withDeclinedDocuments($message->wireText(), $message)]];
        foreach ($message->images() as $image) {
            $format = self::bedrockFormat((string) $image->mimeType);
            if ($format === null) {
                // Converse knows four formats; the sniffer only admits those
                // four, so this is a foreign Attachment built by hand. Named,
                // not dropped.
                $blocks[0]['text'] .= "\n\n[Image attachment {$image->name()} was not sent: "
                    . "Bedrock does not accept {$image->mimeType}.]";

                continue;
            }
            $blocks[] = ['image' => ['format' => $format, 'source' => ['bytes' => (string) $image->data]]];
        }

        return $blocks;
    }

    private static function bedrockFormat(string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpeg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }

    /**
     * CTX-2: this wire family does not build document blocks. A sendable
     * PDF can only reach here past the upstream capability gate (a
     * hand-built message), and the never-silent rule says such an arrival
     * is named, not dropped - the bytes are never inlined as text either.
     */
    private static function withDeclinedDocuments(string $text, UserMessage $message): string
    {
        $documents = $message->documents();
        if ($documents === []) {
            return $text;
        }

        $line = '[Document attachment '
            . implode(', ', array_map(static fn (\SugarCraft\Code\Attachment $d): string => $d->name(), $documents))
            . ' was not sent: this wire does not accept PDF document blocks.]';

        return $text === '' ? $line : $text . "\n\n" . $line;
    }
}
