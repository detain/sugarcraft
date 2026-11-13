<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Core\SgrState;
use SugarCraft\Core\Util\Token;
use SugarCraft\Flexbox\Table\TableRenderer;

/**
 * Differential pins between the renderer's private SGR attribute table and
 * sugar-core's SgrState (finding 070, FBX-33).
 *
 * WHY NOT DELEGATE: core's state is boolean flags plus RAW byte strings for
 * fg/bg (it exists to re-EMIT prefixes byte-exactly), wrapped in a Token
 * feed, and it tracks surfaces the cell model has no arms for at all
 * (underline colour 58, OSC 8 links). The renderer needs packed-integer
 * colors for cellbuffer Style objects. Delegating would be a lossy
 * int→raw→int round trip inside every parse, importing tracking the diff
 * renderer must never see. Two shapes, two truth-sources — so the pair is
 * welded by differential instead: over every SHARED arm the two tables must
 * agree cell-for-cell, and the documented divergence (58/links core-only)
 * is pinned so a future arm cannot sneak into one table and silently
 * impersonate the other.
 *
 * The adapter decodes core's raw sequences through the RENDERER's own
 * mapping functions (palette + component slots), so the test pins the
 * SEMANTICS (which code arms which channel, which parameter slots carry
 * which components, which codes reset) without re-typing either table.
 */
final class SgrStateDifferentialTest extends TestCase
{
    /** @return array{attrs:int,fg:int|null,bg:int|null} renderer view of one SGR */
    private function rendererState(string $params): array
    {
        $m = new ReflectionMethod(TableRenderer::class, 'splitStyledLine');
        $m->setAccessible(true);
        [$graphemes, $styles] = $m->invoke(null, "X\x1b[{$params}mY");
        $style = $styles[1]; // the cell AFTER the sequence
        return [
            'attrs' => $style?->attrs() ?? 0,
            'fg'    => $style?->fg() ?? null,
            'bg'    => $style?->bg() ?? null,
        ];
    }

    /** @return array{attrs:int,fg:int|null,bg:int|null} core view, decoded through the renderer's mapping */
    private function coreState(string $params): array
    {
        $state = SgrState::initial();
        $state->apply(new Token(Token::CSI, '', '', $params, 'm'));

        $prefix = $state->toPrefix();
        \preg_match_all('/\x1b\[([0-9;]*)m/', $prefix, $seqs);
        [$attrCodes, $channelSeqs] = [$seqs[1][0] ?? '', \array_slice($seqs[1], 1)];
        $codes = $attrCodes === '' ? [] : \array_map('intval', \explode(';', $attrCodes));

        $attrs = 0;
        foreach ([[1, Style::ATTR_BOLD], [2, Style::ATTR_FAINT], [3, Style::ATTR_ITALIC],
                  [4, Style::ATTR_UNDERLINE], [5, Style::ATTR_BLINK], [7, Style::ATTR_REVERSE],
                  [8, Style::ATTR_INVISIBLE], [9, Style::ATTR_STRIKE]] as [$code, $bit]) {
            if (\in_array($code, $codes, true)) {
                $attrs |= $bit;
            }
        }

        $fg = null;
        $bg = null;
        foreach ($channelSeqs as $raw) {
            $channel = $this->decodeCoreColor($raw);
            if ($this->isForegroundSeq($raw)) {
                $fg = $channel;
            } else {
                $bg = $channel;
            }
        }
        return ['attrs' => $attrs, 'fg' => $fg, 'bg' => $bg];
    }

    /** Core stores each channel as one raw sequence; classify it by its code space. */
    private function isForegroundSeq(string $raw): bool
    {
        $code = (int) $raw;
        if (\str_starts_with($raw, '38;')) {
            return true;
        }
        return ($code >= 30 && $code <= 37) || ($code >= 90 && $code <= 97);
    }

    /** Map one of core's raw SGR color sequences to the renderer's int form. */
    private function decodeCoreColor(string $raw): int
    {
        $codes = \array_map('intval', \explode(';', $raw));
        if (($codes[0] ?? 0) === 38 || ($codes[0] ?? 0) === 48) {
            if (($codes[1] ?? null) === 5) {
                return $this->xtermPalette($codes[2]);
            }
            return ($codes[2] << 16) | ($codes[3] << 8) | $codes[4];
        }
        $basic = static function (int $c): int {
            if ($c >= 30 && $c <= 37) {
                return $c - 30;
            }
            if ($c >= 90 && $c <= 97) {
                return $c - 82;
            }
            if ($c >= 40 && $c <= 47) {
                return $c - 40;
            }
            return $c - 92; // 100..107
        };
        $table = (new \ReflectionClass(TableRenderer::class))->getConstant('BASIC_ANSI_RGB');
        return $table[$basic($codes[0])];
    }

    private function xtermPalette(int $index): int
    {
        $m = new ReflectionMethod(TableRenderer::class, 'xterm256ToRgb');
        $m->setAccessible(true);
        return $m->invoke(null, $index);
    }

    /**
     * Shared arms: every SGR parameter string both tables claim to speak must
     * land the two states on the SAME attrs/fg/bg. Component-slot drift (a
     * future arm reading G where the other reads B), channel mispatch (48
     * landing on fg), reset-set drift (39/49/0) all reddens here.
     */
    public static function sharedArmProvider(): array
    {
        return [
            'bold'            => ['1'],
            'faint'           => ['2'],
            'italic'          => ['3'],
            'underline'       => ['4'],
            'blink'           => ['5'],
            'reverse'         => ['7'],
            'invisible'       => ['8'],
            'strike'          => ['9'],
            'clear-bold-faint'=> ['1;2;22'],
            'clear-italic'    => ['3;23'],
            'clear-under'     => ['4;24'],
            'clear-blink'     => ['5;25'],
            'clear-reverse'   => ['7;27'],
            'clear-invisible' => ['8;28'],
            'clear-strike'    => ['9;29'],
            'undefined-26'    => ['26'],
            'full-reset'      => ['1;31;0'],
            'empty-resets'    => ['1;'],
            'basic-fg'        => ['31'],
            'bright-fg'       => ['93'],
            'basic-bg'        => ['44'],
            'bright-bg'       => ['105'],
            'fg-and-bg'       => ['31;44'],
            'mixed-attrs'     => ['1;4;9;32;43'],
            'xterm-fg'        => ['38;5;244'],
            'xterm-fg-red'    => ['38;5;196'],
            'xterm-bg'        => ['48;5;226'],
            'xterm-both'      => ['38;5;1;48;5;200'],
            'true-fg'         => ['38;2;10;20;30'],
            'true-bg'         => ['48;2;200;100;50'],
            'true-both'       => ['38;2;1;2;3;48;2;4;5;6'],
            'fg-reset-39'     => ['38;5;196;39'],
            'bg-reset-49'     => ['48;5;196;49'],
            'attr-plus-color' => ['1;38;2;255;0;0;49'],
            'ignored-53'      => ['53'],
            'ignored-font-10' => ['10'],
            'ignored-53-live' => ['53;1;31'],
        ];
    }

    /** @dataProvider sharedArmProvider */
    public function testSharedArmsAgree(string $params): void
    {
        $this->assertSame(
            $this->coreState($params),
            $this->rendererState($params),
            "table drift on shared arm: CSI[{$params}]m"
        );
    }

    public function testSequencedStateEvolutionAgrees(): void
    {
        // Multi-sequence evolution: attr, color, partial clear, reset — the
        // renderer walks one line, core folds tokens; end-state must match.
        $seq = ['1', '38;2;7;8;9', '22', '48;5;42', '39'];
        $m = new ReflectionMethod(TableRenderer::class, 'splitStyledLine');
        $m->setAccessible(true);
        $line = '';
        $core = SgrState::initial();
        foreach ($seq as $p) {
            $line .= "\x1b[{$p}m";
            $core->apply(new Token(Token::CSI, '', '', $p, 'm'));
        }
        [$g, $styles] = $m->invoke(null, $line . 'X');
        /** @var Style $style */
        $style = $styles[0];
        $this->assertSame($this->coreState(\implode(';', $seq))['attrs'], $style->attrs(), 'attrs after the folded sequence');
        $this->assertNull($style->fg(), '39 cleared the fg on both sides');
        $this->assertSame($this->xtermPalette(42), $style->bg(), 'bg 48;5;42 survives the fg reset on both sides');
    }

    public function testDocumentedDivergenceStaysInItsLane(): void
    {
        // 58 (underline colour) is core-TRACKED but renderer-INVISIBLE by
        // design: it must not materialize a cell Style (a phantom style
        // churns every diff), and it must not move core's fg/bg/attrs.
        // This is the divergence line — pinned, so 58 can never silently
        // become a shared arm in one table only.
        $renderer = $this->rendererState('58;5;196');
        $this->assertSame(0, $renderer['attrs']);
        $this->assertNull($renderer['fg']);
        $this->assertNull($renderer['bg']);

        $before = $this->rendererState('1;31');
        $corePlain = SgrState::initial();
        $corePlain->apply(new Token(Token::CSI, '', '', '1;31', 'm'));
        $coreUc = SgrState::initial();
        $coreUc->apply(new Token(Token::CSI, '', '', '1;31;58;5;196', 'm'));
        $this->assertSame(
            $corePlain->toPrefix(),
            $coreUc->toPrefix(),
            'core keeps 58 out of the shared prefix surface too'
        );
        $this->assertNotSame(
            $coreUc->underlineColor(),
            $corePlain->underlineColor(),
            '…it rides the underline-colour channel core alone owns'
        );
    }
}
