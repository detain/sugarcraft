<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests\Support;

use DOMDocument;
use PHPUnit\Framework\Assert;

/**
 * Well-formedness oracle for rendered SVG (cs-03, -004 family).
 *
 * The fleet's escape assertions were substring-only, so a document that
 * could never parse stayed green as long as the pinned literal happened
 * to sit somewhere inside it — one U+FFFE killed the whole render (-015)
 * and every string pin passed. This helper turns every libxml complaint
 * into a test failure: the suite's contract is "this parses", not
 * "this contains".
 */
final class SvgOracle
{
    /**
     * Every XML parse error in `$document` as human-readable strings; an
     * empty list means the document parses. Deliberately a non-throwing
     * probe: the test-of-the-test pins against this shape, so an oracle
     * that silently disabled itself cannot pass its own harness.
     *
     * @return list<string>
     */
    public static function errors(string $document): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $parsed = (new DOMDocument())->loadXML($document);

            $errors = [];
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message) . ' (line ' . $error->line . ')';
            }

            if ($parsed !== false) {
                return $errors;
            }

            // loadXML can refuse without a levelled error — surface that
            // status so "unparseable" never reads as "no errors found".
            return $errors === [] ? ['DOMDocument::loadXML() returned false with no libxml error'] : $errors;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Assert a rendered document parses; the libxml complaints become the failure text. */
    public static function assertWellFormed(string $document, string $context = ''): void
    {
        $errors = self::errors($document);

        Assert::assertSame(
            [],
            $errors,
            ($context === '' ? 'rendered SVG must be well-formed XML' : $context)
                . ($errors === [] ? '' : '; libxml: ' . implode(' | ', $errors)),
        );
    }

    /**
     * The golden byte-scan of cs-04's contract: the swept bytes must never
     * survive into a document, whatever entered the input. Covers DEL, the
     * C1 controls in their two-byte UTF-8 form, U+FFFE/FFFF and the
     * U+FDD0-U+FDEF noncharacters — each the byte pattern its channel
     * would emit if the sweep were removed.
     */
    public static function assertNoSurvivorBytes(string $document): void
    {
        Assert::assertSame(0, preg_match('/[\x7f]|\xC2[\x80-\x9F]/', $document), 'DEL/C1 byte survived into the document');
        Assert::assertSame(0, preg_match('/\xEF\xBF[\xBE\xBF]/', $document), 'U+FFFE/FFFF survived');
        Assert::assertSame(0, preg_match('/\xEF\xB7[\x90-\xAF]/', $document), 'U+FDD0-FDEF noncharacter survived');
    }
}
