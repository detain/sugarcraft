<?php

declare(strict_types=1);

namespace SugarCraft\Overlay;

/**
 * The compositor's ONE grapheme-cluster splitter.
 *
 * Hoisted from Veil's private nextCluster() (SO-02 / sugar-overlay-002):
 * the paint side walked this cascade while the diff model's tokenizer walked
 * core `Width::nextCluster()` (full UAX#29 via grapheme_extract when ext-intl
 * is loaded) — one diff engine, two tokenizers. A 👨‍👩‍👧 family measured 2 cells
 * through the grapheme arm and laid 5 clusters through the byte arm, so the
 * background suffix was spliced at the wrong column: backdrop bytes overdraw
 * emoji (phantom churn) or the row exceeds the canvas (wrap/cursor desync).
 *
 * Both sides now consume THIS walk, per paintRow's original argument (kept
 * verbatim in spirit): grapheme_* is intentionally NOT used — full UAX#29
 * clustering disagrees with `Width::of()`'s East-Asian model on emoji ZWJ
 * sequences, and the diff must agree with what the emulator paints. The
 * alternative (paint side adopting `Width::nextCluster`) would make cluster
 * counts ext-intl-dependent — a byte-parity trap across hosts.
 *
 * @internal Compositor plumbing; the public surface is Veil.
 */
final class Cluster
{
    private function __construct()
    {
    }

    /**
     * Next user-perceived grapheme cluster starting at byte $pos.
     *
     * Pure UTF-8 lead-byte walk (same cascade sugar-charts' BufferHelper
     * uses): a 1-4 byte base character, then following combining marks
     * (U+0300–U+036F) and variation selectors (U+FE00–U+FE2F — the regex
     * deliberately covers the whole Variation Selectors block, a benign
     * superset of the U+FE00–U+FE0F core) ride along so one cell = one
     * rendered glyph rather than stray cells.
     *
     * Contract: always returns at least one byte.
     */
    public static function next(string $s, int $pos): string
    {
        $b = \ord($s[$pos]);
        $len = $b < 0x80 ? 1 : (($b & 0xE0) === 0xC0 ? 2 : (($b & 0xF0) === 0xE0 ? 3 : (($b & 0xF8) === 0xF0 ? 4 : 1)));
        $cluster = \substr($s, $pos, $len);

        // Attach following combining marks (U+0300–U+036F) and variation
        // selectors (U+FE00–U+FE2F) so one cell = one rendered glyph.
        $i = $pos + $len;
        $sLen = \strlen($s);
        while ($i < $sLen) {
            $nb = \ord($s[$i]);
            if ($nb === 0xCC || $nb === 0xCD) { // U+0300–U+036F (2-byte 0xCC 0x80–0xBF, 0xCD 0x80–0xAF)
                $cp = (($nb & 0x1F) << 6) | (\ord($s[$i + 1] ?? "\x80") & 0x3F);
                if ($cp >= 0x0300 && $cp <= 0x036F) {
                    $cluster .= \substr($s, $i, 2);
                    $i += 2;
                    continue;
                }
                break;
            }
            if ($nb === 0xEF) { // 3-byte U+FE00–U+FE2F: EF B8 80–BF / EF B8 90–AF
                $cpSub = \substr($s, $i, 3);
                if (\preg_match('/\A\xEF\xB8[\x80-\xAF]\z/', $cpSub) === 1) {
                    $cluster .= $cpSub;
                    $i += 3;
                    continue;
                }
                break;
            }
            break;
        }

        return $cluster;
    }
}
