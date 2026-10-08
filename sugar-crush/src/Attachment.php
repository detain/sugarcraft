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
 *
 * `$skill` (lane B, skills-qa F2) names the skill a `$name` mention attached:
 * every skill file is named SKILL.md, so the basename chip was identical for
 * ALL skills and said nothing. The transcript chip shows `skill: <name>`
 * instead of the basename while the wire text keeps sending the body unchanged
 * - the label is display-only metadata, absent on every non-skill attachment
 * (and on snapshots persisted before it).
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
        /**
         * The skill name a `$name` mention attached ({@see Skills\SkillMentions}),
         * or null for every other attachment. Display-only: the chip reads it,
         * the wire never sees it.
         */
        public ?string $skill = null,
    ) {}

    /** The last path segment - what the transcript chip and notices show. */
    public function name(): string
    {
        $name = basename(str_replace('\\', '/', $this->path));

        return $name === '' ? $this->path : $name;
    }
}
