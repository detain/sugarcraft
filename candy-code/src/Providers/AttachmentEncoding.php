<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use SugarCraft\Code\Attachment;
use SugarCraft\Code\Attachments\FileMentions;
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
 * Tool results are the exception to "once": the tool cannot know the wire,
 * so SHL-3 T2 re-checks the capability at render time inside
 * {@see \SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire::toolResultImage()},
 * through {@see anthropicImage()} shared with this class's user-attachment leg.
 *
 * A turn with no sendable image keeps the exact pre-15b-15 shape on every wire
 * (a plain string `content` for the OpenAI family, one text block for the block
 * families), so attachments cost nothing - and change no cached prefix - for
 * the ordinary prompt.
 *
 * CTX-2 adds the Anthropic family's `document` block for PDF attachments;
 * capability is decided the same upstream way by
 * {@see ProviderInterface::supportsDocuments()}. C-TOOL-2.e opens the three
 * native user-attachment wires: Bedrock Converse carries a well-formed PDF as
 * a `document` block (format `pdf`, RAW bytes like the image leg, and a name
 * shaved to the Converse charset), Gemini — and Vertex's Gemini arm through
 * the same shared encoders — carries it as `application/pdf` `inlineData`,
 * and the OpenAI chat arm carries it as a `file` part with a
 * `data:application/pdf` URL (opt-in by caller, so the SGLang/Custom-compatible
 * servers that share these encoders, and the Responses arm's USER rows, keep
 * declining — the Responses tool-row leg carries a native `input_file` part
 * since C-TOOL-2.g, through {@see responsesInputFile()}).
 * On the native legs a document is carried only when its bytes are a real
 * `%PDF-` head within the inbound capture ceiling; what the wire cannot carry
 * — a forged or oversized PDF, and every document on a still-declining
 * encoder — is named in the text, never dropped and never inlined as bytes.
 */
final class AttachmentEncoding
{
    /**
     * OpenAI chat/completions: a string, or text + `image_url` parts carrying a
     * `data:` URL. SGLang's OpenAI-compatible server and Anthropic's OpenAI
     * compatibility endpoint read the same shape.
     *
     * C-TOOL-2.e: `documentsAsBlocks` opts the caller's wire into the
     * chat/completions `file` part (`file_data` rides a `data:application/pdf`
     * URL, the documented pasting form). The flag names the CALLER's wire, not
     * the model — only {@see \SugarCraft\Code\Providers\OpenAIProvider}'s
     * chat arm passes it, so SGLang/Custom and the OpenAI Responses USER rows
     * stay on the default and keep naming declined documents in the text.
     * (C-TOOL-2.g corrected an earlier note here claiming `input_file` was a
     * file-ID-only construct: it is this API's pasted-PDF part shape too, and
     * the Responses TOOL-ROW leg builds it natively through
     * {@see responsesInputFile()} — a different door than this user-attachment
     * encoder, which is why the flag law stands while the premise fell.)
     * A well-formedness check guards the native leg either way: a forged or
     * oversized PDF is named, never inlined.
     *
     * @return string|list<array<string, mixed>>
     */
    public static function openAiContent(UserMessage $message, bool $documentsAsBlocks = false): string|array
    {
        $images = $message->images();
        $refused = [];
        $carried = [];
        if ($documentsAsBlocks) {
            [$carried, $refused] = self::partitionDocuments($message);
            $text = self::nameUndelivered($message->wireText(), $refused);
        } else {
            $text = self::withDeclinedDocuments($message->wireText(), $message);
        }
        if ($images === [] && $carried === []) {
            return $text;
        }

        $parts = [];
        if ($text !== '') {
            $parts[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($images as $image) {
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => self::dataUrl((string) $image->mimeType, (string) $image->data)],
            ];
        }
        foreach ($carried as $document) {
            $parts[] = [
                'type' => 'file',
                'file' => [
                    'filename' => self::filePartName($document->name()),
                    'file_data' => self::dataUrl('application/pdf', (string) $document->data),
                ],
            ];
        }

        return $parts;
    }

    /**
     * The one `data:` URL spelling shared by every leg that pastes base64
     * bytes into a URL string: the chat image and chat `file` legs of
     * {@see openAiContent()} above, and both media parts of the Responses
     * tool row in {@see \SugarCraft\Code\Providers\Concerns\OpenAiResponsesWire}
     * (C-PRV-2.b folded the image return through it, C-TOOL-2.g the file
     * part) — one format for pasted bytes no matter which wire emits them.
     */
    public static function dataUrl(string $mediaType, string $bytes): string
    {
        return 'data:' . $mediaType . ';base64,' . base64_encode($bytes);
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
            $blocks[] = self::anthropicImage((string) $image->mimeType, (string) $image->data);
        }
        foreach ($message->documents() as $document) {
            $blocks[] = self::anthropicDocument(
                (string) $document->mimeType,
                (string) $document->data,
                $document->name(),
            );
        }

        return $blocks;
    }

    /**
     * The Anthropic family's base64 `image` block, in exactly one spelling:
     * the user-attachment leg above and the vision-gated `tool_result` leg in
     * {@see \SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire} share it,
     * so a pixel entering through either door presents identically (SHL-3 T2).
     *
     * @return array{type: string, source: array{type: string, media_type: string, data: string}}
     */
    public static function anthropicImage(string $mediaType, string $bytes): array
    {
        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => base64_encode($bytes),
            ],
        ];
    }

    /**
     * The Anthropic family's base64 `document` block, in exactly one
     * spelling: the user-attachment leg above (CTX-2) and the
     * document-gated `tool_result` leg in {@see
     * \SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire} share it (it
     * replaced the inline array this leg used to hand-build — byte-identical
     * output), so a PDF entering through either door presents identically
     * (C-TOOL-2).
     *
     * @return array{type: string, title: string, source: array{type: string, media_type: string, data: string}}
     */
    public static function anthropicDocument(string $mediaType, string $bytes, string $title): array
    {
        return [
            'type' => 'document',
            'title' => $title,
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => base64_encode($bytes),
            ],
        ];
    }

    /**
     * C-TOOL-2.g: the Responses `function_call_output` parts list's document
     * member, in exactly one spelling — the tool-result leg in {@see
     * \SugarCraft\Code\Providers\Concerns\OpenAiResponsesWire} is its only
     * builder, mirroring how {@see anthropicDocument()} is the single Anthropic
     * spelling shared by that wire's two doors. Unlike the Anthropic block (and
     * the chat `file` part, which nests), the Responses part carries its keys
     * flat, and the filename passes the same control-byte hygiene as
     * {@see filePartName()} — the JSON-string hazard is wire-independent.
     *
     * @return array{type: string, filename: string, file_data: string}
     */
    public static function responsesInputFile(string $mediaType, string $bytes, string $name): array
    {
        return [
            'type' => 'input_file',
            'filename' => self::filePartName($name),
            'file_data' => self::dataUrl($mediaType, $bytes),
        ];
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
        [$carried, $refused] = self::partitionDocuments($message);
        $text = self::nameUndelivered($message->wireText(), $refused);
        if ($text !== '') {
            $parts[] = ['text' => $text];
        }
        foreach ($message->images() as $image) {
            $parts[] = ['inlineData' => ['mimeType' => $image->mimeType, 'data' => base64_encode((string) $image->data)]];
        }
        // C-TOOL-2.e: a carried PDF rides the same inlineData part family as
        // the image leg, tagged `application/pdf` — the form the Gemini files
        // API documents for inline PDF uploads. The shared wire covers Vertex's
        // Gemini arm unchanged.
        foreach ($carried as $document) {
            $parts[] = ['inlineData' => ['mimeType' => 'application/pdf', 'data' => base64_encode((string) $document->data)]];
        }

        return $parts;
    }

    /**
     * Bedrock Converse: a text block, then one `image` block per image, then
     * one `document` block per carryable PDF (C-TOOL-2.e). The bytes go RAW -
     * the AWS SDK base64-encodes a blob member itself, so encoding here would
     * send the model base64 of base64.
     *
     * @return list<array<string, mixed>>
     */
    public static function bedrockBlocks(UserMessage $message): array
    {
        [$carried, $refused] = self::partitionDocuments($message);
        $blocks = [['text' => self::nameUndelivered($message->wireText(), $refused)]];
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
        foreach ($carried as $document) {
            $blocks[] = ['document' => [
                'format' => 'pdf',
                'name' => self::bedrockDocumentName($document->name()),
                'source' => ['bytes' => (string) $document->data],
            ]];
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
     * C-TOOL-2.e split for the native document wires: documents whose bytes
     * are an actual PDF within the inbound capture ceiling become blocks,
     * everything else is named. The declared mime is ignored on purpose -
     * the bytes decide the presentation, exactly as the tool-result leg's
     * re-sniff: forged PDF bytes under any mime name themselves instead of
     * poisoning the block, and the block itself always spells its own
     * `application/pdf` / `format => pdf`.
     *
     * @return array{0: list<Attachment>, 1: list<Attachment>}
     */
    private static function partitionDocuments(UserMessage $message): array
    {
        $carried = [];
        $refused = [];
        foreach ($message->documents() as $document) {
            $bytes = (string) $document->data;
            if (str_starts_with($bytes, '%PDF-') && strlen($bytes) <= FileMentions::DOCUMENT_MAX_BYTES) {
                $carried[] = $document;
            } else {
                $refused[] = $document;
            }
        }

        return [$carried, $refused];
    }

    /**
     * The never-silent naming line for documents a native wire cannot carry.
     * Same shape as {@see withDeclinedDocuments()}, true wording: the wire
     * does build document blocks - these bytes are what it refuses.
     *
     * @param list<Attachment> $refused
     */
    private static function nameUndelivered(string $text, array $refused): string
    {
        if ($refused === []) {
            return $text;
        }

        $line = '[Document attachment '
            . implode(', ', array_map(static fn (Attachment $d): string => $d->name(), $refused))
            . ' was not sent: the wire carries only well-formed PDFs up to '
            . number_format(FileMentions::DOCUMENT_MAX_BYTES) . ' bytes as blocks.]';

        return $text === '' ? $line : $text . "\n\n" . $line;
    }

    /**
     * Converse wants the document name to match `[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}`;
     * a filename is not that, so it is shaved into the charset: every foreign
     * byte becomes `_`, a leading `_`/`-` is trimmed (first character must be
     * alphanumeric), and the result is capped at 64. An empty outcome falls
     * back to `document`.
     */
    private static function bedrockDocumentName(string $name): string
    {
        $safe = ltrim(substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $name), 0, 64), '-_');

        return $safe === '' ? 'document' : $safe;
    }

    /**
     * C-TOOL-2.e: the chat/completions `file.filename` is free text, but a
     * control byte in a JSON string is a wire hazard, so control characters
     * and whitespace runs collapse to single spaces and the ends trim; an
     * empty outcome falls back to `document.pdf`. Unlike the Converse name,
     * dots and spaces are legal here and stay (the model reads the filename
     * back, and `spec.pdf` is what the user attached).
     */
    private static function filePartName(string $name): string
    {
        $safe = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $name));

        return $safe === '' ? 'document.pdf' : $safe;
    }

    /**
     * CTX-2: this wire family does not build document blocks. A sendable
     * PDF can only reach here past the upstream capability gate (a
     * hand-built message), and the never-silent rule says such an arrival
     * is named, not dropped - the bytes are never inlined as text either.
     * C-TOOL-2.e keeps this leg for the wires that still decline; the
     * native legs name only their refused documents, through
     * {@see nameUndelivered()}.
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
