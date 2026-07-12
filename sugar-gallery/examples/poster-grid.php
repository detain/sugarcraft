<?php

declare(strict_types=1);

/**
 * PosterGrid — a virtualized media-library grid of poster tiles.
 *
 * Builds a handful of PosterCards (truecolour poster art, titles, and
 * continue-watching progress bars), drops them into a PosterGrid, and prints
 * one focused frame. The ▸ tile is the cursor; the ░ tile is an index whose
 * page has not been fetched yet, so the grid draws a skeleton in its place —
 * the header of the lib's sparse, owner-paged virtualization.
 *
 *   php examples/poster-grid.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;

/** A $h×$w block of truecolour "poster art" — a soft vertical gradient. */
function poster(int $r, int $g, int $b, int $w, int $h): string
{
    $rows = [];
    for ($y = 0; $y < $h; $y++) {
        $f = 1.0 - 0.45 * ($h <= 1 ? 0.0 : $y / ($h - 1));
        $rows[] = sprintf(
            "\x1b[38;2;%d;%d;%dm%s\x1b[0m",
            (int) round($r * $f),
            (int) round($g * $f),
            (int) round($b * $f),
            str_repeat('█', $w),
        );
    }

    return implode("\n", $rows);
}

const CARD_W = 18;   // tile width in cells
const POSTER_H = 6;  // poster rows (title + progress add two more)

// Five loaded cards; index 5 is intentionally left unfetched so the grid
// renders it as a ░ skeleton — the placeholder for a not-yet-loaded page.
$cards = [
    PosterCard::new('0', 'The Matrix')->withPoster(poster(0xB5, 0x17, 0x9E, CARD_W, POSTER_H))->withProgress(0.80),
    PosterCard::new('1', 'Blade Runner 2049')->withPoster(poster(0x3A, 0x0C, 0xA3, CARD_W, POSTER_H)),
    PosterCard::new('2', 'Interstellar')->withPoster(poster(0x43, 0x61, 0xEE, CARD_W, POSTER_H))->withProgress(0.35),
    PosterCard::new('3', 'Arrival')->withPoster(poster(0x4C, 0xC9, 0xF0, CARD_W, POSTER_H)),
    PosterCard::new('4', 'Dune: Part Two')->withPoster(poster(0xF7, 0x25, 0x85, CARD_W, POSTER_H))->withProgress(0.60),
];

$grid = PosterGrid::new(cardWidth: CARD_W, posterHeight: POSTER_H, hSpacing: 2, vSpacing: 1)
    ->withViewport(60, 18)   // → 3 columns × 2 rows
    ->reset(total: 6)
    ->withItems($cards)
    ->moveTo(2);             // put the cursor on "Interstellar"

$selected = $grid->cursorCard()?->title ?? '—';

echo "\x1b[1;36m sugar-gallery — PosterGrid\x1b[0m  ";
echo "\x1b[2m" . $grid->total() . " items · 3×2 viewport · ▸ cursor · ░ unfetched skeleton\x1b[0m\n\n";
echo $grid->render(focused: true) . "\n\n";
echo "\x1b[2m ←→ move · ↑↓ rows · Home/End jump\x1b[0m   ";
echo "\x1b[1mselected:\x1b[0m \x1b[35m" . $selected . "\x1b[0m\n";
