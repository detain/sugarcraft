<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Messages\UserMessage;

/**
 * The per-wire shapes of a user turn that carries attachments (audit 15b-15).
 *
 * ONE definition per wire family, shared by every provider speaking it, so the
 * OpenAI-compatible three (OpenAI, SGLang, Custom) cannot drift apart on what a
 * `image_url` part looks like. Files are already text by the time anything
 * here runs ({@see UserMessage::wireText()}); images become the family's own
 * image block. Which providers may receive an image at all is decided once,
 * upstream, by {@see ProviderInterface::supportsVision()} in
 * {@see \SugarCraft\Crush\Backend\EngineBackend::toTypedMessages()} - a
 * provider that answers false never has an image handed to it, and the
 * text-only encoders still name any that arrive ({@see UserMessage::textOnly()}).
 *
 * A turn with no sendable image keeps the exact pre-15b-15 shape on every wire
 * (a plain string `content` for the OpenAI family, one text block for the block
 * families), so attachments cost nothing - and change no cached prefix - for
 * the ordinary prompt.
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
            return $message->wireText();
        }

        $parts = [];
        $text = $message->wireText();
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
     * Anthropic Messages (Claude on Vertex's rawPredict): a text block, then
     * one base64 `image` block per image.
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
        $text = $message->wireText();
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
        $blocks = [['text' => $message->wireText()]];
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
}
