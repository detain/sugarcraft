<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

use SugarCraft\Code\Lang;

/**
 * The typed "this transcript will not be imported" answer.
 *
 * Every message in this family is built from a `session.import.refusal.*`
 * catalogue row, so the reason a person reads is translatable and the catch
 * site (C-SES-7.b's CLI, C-SES-7.e's RPC) can tell an import refusal apart
 * from an accident without string matching.
 */
final class ImportRefusedException extends \RuntimeException
{
    /** The file is past the byte budget the importer was configured with. */
    public static function tooLarge(int $bytes, int $max): self
    {
        return new self(Lang::t('session.import.refusal.too_large', [
            'bytes' => $bytes,
            'max' => $max,
        ]));
    }

    /** The transcript carries more rows than the item budget allows. */
    public static function tooManyItems(int $max): self
    {
        return new self(Lang::t('session.import.refusal.too_many_items', ['max' => $max]));
    }

    /** No reader recognised the shape of the file. */
    public static function unknownFormat(): self
    {
        return new self(Lang::t('session.import.refusal.unknown_format'));
    }

    /** The named path is not a readable regular file. */
    public static function notReadable(string $path): self
    {
        return new self(Lang::t('session.import.refusal.not_readable', ['path' => $path]));
    }

    /**
     * A recognised format whose rows deviate from the pinned schema.
     *
     * $detail names the offending shape in plain lowercase words; guessing
     * what a drifted producer meant is the failure mode this refuses.
     */
    public static function shape(string $detail): self
    {
        return new self(Lang::t('session.import.refusal.unsupported_shape', ['detail' => $detail]));
    }

    /** A re-import whose source holds fewer rows than the ledger already stored. */
    public static function sourceShrank(): self
    {
        return new self(Lang::t('session.import.refusal.source_shrank'));
    }
}
