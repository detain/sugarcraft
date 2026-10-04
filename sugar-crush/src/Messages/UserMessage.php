<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Messages;

use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Attachments\ContextMentions;

final readonly class UserMessage implements Message
{
    /**
     * @param list<Attachment> $attachments
     */
    public function __construct(
        private string $content,
        private array $attachments = [],
    ) {}

    public function role(): string
    {
        return 'user';
    }

    public function content(): string
    {
        return $this->content;
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    public function withAttachment(Attachment $attachment): self
    {
        return new self($this->content, [...$this->attachments, $attachment]);
    }

    public function withFile(string $path): self
    {
        return $this->withAttachment(new Attachment($path, AttachmentType::File));
    }

    public function withImage(string $path): self
    {
        return $this->withAttachment(new Attachment($path, AttachmentType::Image));
    }

    /**
     * The text a provider sends for this turn: the prompt, then every FILE
     * attachment inlined as a `<file>` block (audit 15b-15) — or a
     * `<context source="@diff">` block for a keyword mention (roadmap 5.8).
     *
     * Files travel as text on every provider - none of the wire formats this
     * app speaks has a portable "file" part, and an `@src/x.php` mention means
     * "read this", which is what the inlined snapshot lets the model do. A
     * file attachment with no snapshot (built from a bare path, or persisted
     * before snapshots existed) is NAMED rather than dropped, so the model is
     * never told about a file it was silently not shown.
     *
     * Images are not part of this text; a vision-capable encoder sends
     * {@see images()} as their own content parts, and a text-only one uses
     * {@see textOnly()}.
     */
    public function wireText(): string
    {
        $blocks = [];
        foreach ($this->attachments as $attachment) {
            $path = self::attributeValue($attachment->path);
            if ($attachment->type === AttachmentType::Image) {
                // An image with no captured bytes can ride no wire at all, so
                // it is named here, where EVERY encoder reads.
                if (!self::isSendableImage($attachment)) {
                    $blocks[] = "[Attached image {$path} could not be included: its bytes were not captured.]";
                }

                continue;
            }
            // A keyword mention (`@diff`, `@session:<id>`, `@https://…`,
            // roadmap 5.8) is context, not a file on disk: it travels as a FILE
            // snapshot so it survives a resume, and is named for what it is.
            if (ContextMentions::isSourceLabel($attachment->path)) {
                $blocks[] = $attachment->data === null
                    ? "[Attached context {$path} could not be included: its contents were not captured.]"
                    : "<context source=\"{$path}\">\n" . rtrim($attachment->data, "\n") . "\n</context>";

                continue;
            }
            $blocks[] = $attachment->data === null
                ? "[Attached file {$path} could not be included: its contents were not captured.]"
                : "<file path=\"{$path}\">\n" . rtrim($attachment->data, "\n") . "\n</file>";
        }

        if ($blocks === []) {
            return $this->content;
        }

        return ($this->content === '' ? '' : $this->content . "\n\n") . implode("\n\n", $blocks);
    }

    /**
     * {@see wireText()} plus one line per image, for an encoder that can only
     * send text. The NEVER-SILENT rule of audit 15b-15 at the last seam: an
     * image reaching a text-only wire is named in the text it does send.
     */
    public function textOnly(): string
    {
        $text = $this->wireText();
        foreach ($this->attachments as $attachment) {
            if (self::isSendableImage($attachment)) {
                $line = '[Image attachment ' . self::attributeValue($attachment->path)
                    . ' was not sent: this request carries text only.]';
                $text = $text === '' ? $line : $text . "\n\n" . $line;
            }
        }

        return $text;
    }

    /**
     * The image attachments whose bytes were captured, in attach order - what
     * a vision-capable encoder sends as image parts.
     *
     * @return list<Attachment>
     */
    public function images(): array
    {
        return array_values(array_filter(
            $this->attachments,
            self::isSendableImage(...),
        ));
    }

    private static function isSendableImage(Attachment $a): bool
    {
        return $a->type === AttachmentType::Image
            && $a->data !== null
            && $a->data !== ''
            && $a->mimeType !== null;
    }

    public function toArray(): array
    {
        $array = ['role' => 'user', 'content' => $this->content];

        // Only surface attachments when present so the common text-only case
        // stays a clean two-key {role, content} payload.
        if ($this->attachments !== []) {
            $array['attachments'] = array_map(
                static fn (Attachment $a): array => ['type' => $a->type->name, 'path' => $a->path],
                $this->attachments,
            );
        }

        return $array;
    }

    /**
     * A path as it is quoted inside the wire text: one line, no `"` to break
     * the `<file path="…">` attribute out of.
     */
    private static function attributeValue(string $path): string
    {
        return str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $path);
    }
}
