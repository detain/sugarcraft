<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * A file attachment for a message. Immutable value object.
 *
 * Audit 15b-15: an attachment carries a SNAPSHOT of what it names, taken when
 * the user attached it ({@see Attachments\FileMentions::resolve()}), not just a
 * path to re-read on every turn. The whole history is replayed on each request,
 * so a path read at send time would show the model a file that had changed
 * under a turn it already answered, and would change the request's prefix on
 * every edit - which is exactly what a provider's prompt cache keys on. The
 * snapshot is also what lets a resumed session send the image it attached even
 * after the paste file in the temp directory is gone.
 *
 * `$data` is null on an attachment built from a bare path (the pre-15b-15
 * shape, and a transcript row persisted before it); the encoders say so on the
 * wire rather than dropping it ({@see Messages\UserMessage::wireText()}).
 */
final readonly class Attachment
{
    public function __construct(
        public string $path,
        public AttachmentType $type,
        /**
         * The file's text (a {@see AttachmentType::File}) or the image's raw
         * bytes (a {@see AttachmentType::Image}), as captured at attach time.
         */
        public ?string $data = null,
        /**
         * The sniffed media type of an image's bytes (`image/png`, …); null for
         * a file, and for an image whose bytes were not captured.
         */
        public ?string $mimeType = null,
    ) {}

    /** The last path segment - what the transcript chip and notices show. */
    public function name(): string
    {
        $name = basename(str_replace('\\', '/', $this->path));

        return $name === '' ? $this->path : $name;
    }
}
