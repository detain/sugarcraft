<?php

/**
 * Brazilian Portuguese translations for sugar-gallery.
 *
 * Key set/order pinned identical to en.php by tests/LocaleParityTest.php.
 * The VALUES are a PARTIAL Brazilian voice, not a byte-for-byte copy of
 * lang/pt.php: 'grid.empty' carries the Brazilian '(sem pôsteres)' where
 * European Portuguese keeps '(sem cartazes)'; 'rail.empty' and 'card.failed'
 * are identical across the two because European pt already uses the same
 * wording there. Complete the voice when a translator lands it; keep the key
 * set. (sugar-core's T treats a regional file as its own locale, so any key
 * left pt-shaped here silently reads as European Portuguese.)
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'rail.empty' => '(sem itens)',
    'card.failed' => '(indisponível)',
    'grid.empty' => '(sem pôsteres)',
];
