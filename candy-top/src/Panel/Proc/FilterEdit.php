<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Util\Width;

/**
 * The proc filter's inline editor: text plus a grapheme-cluster caret,
 * drawn with an UNDERLINED caret cell (no hardware cursor).
 *
 * Same contract as sugar-bits `Input\TextEdit` (the L8 lane primitive,
 * btop Draw::TextEdit); candy-top does not depend on sugar-bits yet, so
 * the proc box carries this small port — swapping it for
 * `SugarCraft\Bits\Input\TextEdit` once candy-top requires sugar-bits is a
 * mechanical change.
 *
 * Mirrors aristocratos/btop Draw::TextEdit::command / operator()
 * (src/btop_draw.cpp).
 */
final class FilterEdit
{
    public const UL = "\x1b[4m";

    public const UUL = "\x1b[24m";

    private function __construct(
        public readonly string $text,
        public readonly int $caret,
    ) {
    }

    /** btop TextEdit{text}: caret at the end. */
    public static function new(string $text = ''): self
    {
        return new self($text, count(self::clusters($text)));
    }

    /**
     * btop TextEdit::command: left / right / home / end / backspace /
     * delete / space / one printable character. Null when the key is not
     * an edit (btop returns false and the key is dropped).
     */
    public function command(KeyMsg $key): ?self
    {
        $c = self::clusters($this->text);
        $n = count($c);

        return match (true) {
            $key->type === KeyType::Left => new self($this->text, max(0, $this->caret - 1)),
            $key->type === KeyType::Right => new self($this->text, min($n, $this->caret + 1)),
            $key->type === KeyType::Home => new self($this->text, 0),
            $key->type === KeyType::End => new self($this->text, $n),
            $key->type === KeyType::Backspace && !$key->ctrl && !$key->alt => $this->caret === 0
                ? $this
                : new self(implode('', [...array_slice($c, 0, $this->caret - 1), ...array_slice($c, $this->caret)]), $this->caret - 1),
            $key->type === KeyType::Delete => $this->caret >= $n
                ? $this
                : new self(implode('', [...array_slice($c, 0, $this->caret), ...array_slice($c, $this->caret + 1)]), $this->caret),
            $key->type === KeyType::Space && !$key->ctrl && !$key->alt => $this->insert(' '),
            $key->type === KeyType::Char && !$key->ctrl && !$key->alt && $key->rune !== ''
                && count(self::clusters($key->rune)) === 1 && preg_match('/[\x00-\x1f\x7f]/', $key->rune) !== 1 => $this->insert($key->rune),
            default => null,
        };
    }

    /**
     * The text within `$limit` cells with the caret cell underlined; a long
     * text keeps the caret in view (the window ends at the caret when it is
     * past the visible half, as btop's half-budget law does).
     */
    public function view(int $limit): string
    {
        $c = self::clusters($this->text);
        $n = count($c);
        $limit = max(1, $limit);
        $from = 0;
        $widths = array_map(static fn (string $g): int => Width::string($g), $c);
        // Cells needed to show everything up to and including the caret cell.
        $need = array_sum(array_slice($widths, 0, $this->caret)) + ($this->caret < $n ? $widths[$this->caret] : 1);
        while ($need > $limit && $from < $this->caret) {
            $need -= $widths[$from];
            $from++;
        }
        $out = '';
        $used = 0;
        for ($i = $from; $i <= $n; $i++) {
            $g = $i < $n ? $c[$i] : ' ';
            $w = $i < $n ? $widths[$i] : 1;
            if ($used + $w > $limit) {
                break;
            }
            $out .= $i === $this->caret ? self::UL . $g . self::UUL : $g;
            $used += $w;
            if ($i === $n - 1 && $this->caret < $n) {
                break;
            }
        }

        return $out;
    }

    private function insert(string $chars): self
    {
        $c = self::clusters($this->text);
        $head = implode('', array_slice($c, 0, $this->caret)) . $chars;
        $text = $head . implode('', array_slice($c, $this->caret));

        return new self($text, count(self::clusters($head)));
    }

    /** @return list<string> */
    private static function clusters(string $s): array
    {
        $out = [];
        $len = strlen($s);
        for ($i = 0; $i < $len;) {
            $g = Width::nextCluster($s, $i);
            if ($g === '') {
                break;
            }
            $out[] = $g;
            $i += strlen($g);
        }

        return $out;
    }
}
