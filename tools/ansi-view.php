<?php

declare(strict_types=1);

/**
 * tools/ansi-view.php — interactive TUI viewer and tagger over the ANSI corpus
 * indexed by ansi/ansi.json, the human-side companion to tools/gen-ansi-readme.php.
 *
 * WHY A VIEWER AT ALL. The corpus is 3,932 raw-escape .ansi files whose review
 * workflow so far was `cat` per file with no memory: nothing in the tree let a
 * human flip through the art, score it, and leave the verdict somewhere durable.
 * ansi.json already carries `rating` and `tags` fields that are empty everywhere
 * precisely because no producer existed. This tool is that producer — one image
 * at a time, sorted by category then depth then filename, with single-keystroke
 * tag and rating flags that persist to ansi.json IMMEDIATELY, so a half-reviewed
 * session is never lost. `rating` is null until a reviewer chooses a value: there
 * is no default rating, 0-9 are all real scores, and pressing the already-shown
 * digit clears the rating back to unset.
 *
 * WHY IT WRITES THE JSON AND NOT A SIDE-CAR. rating/tags have no column in the
 * generated ansi/README.md tables — verified against gen-ansi-readme.php's own
 * ansi_row(): slot tables emit resolution/depth/colors/description, original
 * tables emit resolution/colors/description, nothing else — and --check only
 * re-derives resolution/depth/colors/description against the art. So tag writes
 * cannot drift the CI guard, and one canonical store stays the source of truth.
 *
 * THE ENCODER IS THE CANONICAL ONE. The JSON this tool writes is byte-identical
 * in shape to what gen-ansi-readme.php --remeasure produces: decode true, re-encode
 * with JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE, one trailing
 * newline. Round-tripping the untouched real ansi.json through it is cmp-silent,
 * which is the precondition for pointing an editing tool at a committed data file.
 * Writes are atomic — temp in the target directory, mode copied, rename — because
 * a kill mid-save must not cost the whole corpus.
 *
 * NO ENVIRONMENT KNOBS, ON PURPOSE. The script takes --root/--file instead of a
 * SUGARCRAFT_ANSI_ROOT-style variable: a viewer is never run by CI, so a knob
 * nobody scripts with is a roster row nobody reads. Every path defaults from
 * __DIR__, so the CWD never matters.
 */
const USAGE = <<<'TXT'
    usage: ansi-view.php [--depth=V ...] [--category=V ...] [--size=s|m|l ...]
                         [--untagged] [--file=PATH] [--root=DIR] [--help|-h]

      View and tag the ANSI art corpus (ansi/ansi.json plus the .ansi files beside it),
      one image per screen, ordered by category, then depth (16 < 256 <
      truecolor), then filename. Filters are repeatable and AND together; every key
      is confined to the filtered set.

        --depth=V      keep only this depth (16|256|truecolor); repeatable
        --category=V   keep only this category; repeatable
        --size=TIER    keep only this size tier (s|m|l); repeatable. Tiers are
                       computed from the measured width x height in ansi.json —
                       never the filename letter: s 40..70 x 5..16, m 70..90 x
                       18..26, l 120..225 x 28..50, all inclusive. A piece inside
                       no box takes the nearest one by summed axis gap, ties
                       toward the larger tier.
        --untagged     keep only items with no tags AND no rating set
        --check-fit    non-interactive: print this terminal's size and the largest
                       art dimensions in the (filtered) corpus, then say whether
                       the terminal is big enough for every piece. Exit 0 when it
                       fits, 1 when at least one piece is too large.
        --file=PATH    ansi.json to read and write (default: <root>/ansi.json)
        --root=DIR     directory holding ansi.json and the category art
                       subdirectories (default: the repo's own ansi/ under this script)
        --help, -h     print this text and exit 0

      Keys (no vi aliases — letters are reserved for the flag toggles):

        Up        previous item          Down       next item
        Right     next depth within the category; at the deepest one it moves to
                  the next category at its shallowest depth
        Left      previous depth; at the shallowest one it moves to the previous
                  category at its deepest depth
        PgDn      next category (keeps this depth if present, else the nearest)
        PgUp      previous category (same depth rule)
        a         toggle tag alignment-fix     d  toggle tag delete
        k         toggle tag keep              b  toggle tag border-fix
        0-9       set rating to that digit; the digit already shown clears the
                  rating back to unset (unset renders as "unset", not "0")
        q         quit
        Esc       quit (bare Esc; arrow keys and PgUp/PgDn arrive as Esc-prefixed
                  sequences and are disambiguated within ~50 ms)
        Ctrl-C    restore the terminal and quit

      Every flag key writes a MARKER into ansi.json and nothing else — no .ansi
      file, no index entry, and no tag is ever removed by a key except the one
      the same key toggles back off. The "delete" tag is a review marker that
      marks a piece for later human attention; this tool never acts on it.

    Every flag and rating key writes ansi.json immediately. Under --untagged, a
    tag or rating pins the image on screen; the filtered set is re-applied on
    the next navigation that moves. A navigation that cannot move keeps both
    image and pin. Art taller than the terminal scrolls over the key bar —
    accepted for v1; resize the window.

    Exit codes:
      0  quit normally, or --check-fit found every piece fitting this terminal
      1  --check-fit only: the terminal is too small for at least one piece
      2  bad arguments, or the resolved root/ansi.json is missing or unreadable
      3  ansi.json is not valid JSON or carries an entry this viewer cannot interpret
    TXT;

// --help is answered before anything is resolved, exactly as gen-ansi-readme.php does
// it: refusing to explain the flags because a path is set wrong is the least useful
// possible moment to fail.
foreach (array_slice($argv, 1) as $early) {
    if ($early === '--help' || $early === '-h') {
        fwrite(STDOUT, USAGE . "\n");
        exit(0);
    }
}

/**
 * Depth order for sorting and for the Left/Right/PgUp/PgDn landing rules.
 * mono is absent from the corpus (verified 2026-09-30: no entry carries it) and
 * gen-ansi-readme's measurer only emits it for art with zero SGR colour at all;
 * such a piece would be refused loudly here rather than silently ranked.
 *
 * @var array<string, int>
 */
const DEPTH_RANK = ['16' => 1, '256' => 2, 'truecolor' => 3];

/**
 * The tag a flag key toggles. Letters, not vi aliases, by design.
 *
 * @var array<string, string>
 */
const FLAG_TAGS = [
    'a' => 'alignment-fix',
    'd' => 'delete',
    'k' => 'keep',
    'b' => 'border-fix',
];

// ---------------------------------------------------------------------------
// Terminal
// ---------------------------------------------------------------------------

/** @var string|null the `stty <saved>` restore command, set only once raw mode is armed */
$GLOBALS['ansi_view_restore_cmd'] = null;
$GLOBALS['ansi_view_restored'] = false;

/**
 * Put the terminal into raw mode when STDIN is one, arming a shutdown restore.
 *
 * The restore closure is guarded against double-fire (explicit call, then the
 * shutdown function) and never throws — teardown must not mask the real exit.
 * With a non-tty STDIN nothing is touched, so piped keystroke scripts (the test
 * harness shape) run the viewer without a terminal to corrupt.
 */
function setupTerminal(): void
{
    if (!stream_isatty(STDIN)) {
        return;
    }

    // The saved state reaches this command string via a redirect, never via
    // shell word-splitting, so no metacharacter in it can be reinterpreted.
    $saved = trim((string) shell_exec('stty -g 2>/dev/null'));
    if ($saved === '') {
        return; // cannot save what we cannot restore — leave the terminal alone
    }
    shell_exec('stty raw -echo opost onlcr 2>/dev/null');
    // Trailing opost/onlcr RE-ARM output translation that `raw` switches off:
    // the art files end lines with bare \n and no \r (verified across the corpus),
    // so under full raw each line would begin at the previous line's end column —
    // the staircase misalignment `cat` never shows. Input-side raw (no isig,
    // min 0 time 1, no echo) is untouched, so key reading behaves identically.
    $restore = 'stty ' . escapeshellarg($saved) . ' 2>/dev/null';

    register_shutdown_function(static function () use ($restore): void {
        restoreTerminal($restore);
    });

    $GLOBALS['ansi_view_restore_cmd'] = $restore;
}

/**
 * Apply the saved stty state exactly once, whatever the number of callers.
 */
function restoreTerminal(string $restore): void
{
    if ($restore === '' || !empty($GLOBALS['ansi_view_restored'])) {
        return;
    }
    $GLOBALS['ansi_view_restored'] = true;
    shell_exec($restore);
}

/**
 * Terminal geometry from `stty size`, falling back to 80x24 — never from the
 * environment, so LINES/COLUMNS cannot lie to the layout.
 *
 * @return array{0: int, 1: int} rows, cols
 */
function terminalSize(): array
{
    $out = trim((string) shell_exec('stty size 2>/dev/null'));
    if (preg_match('/^(\d+) (\d+)$/', $out, $m) === 1) {
        $rows = (int) $m[1];
        $cols = (int) $m[2];
        if ($rows > 0 && $cols > 0) {
            return [$rows, $cols];
        }
    }

    return [24, 80];
}

// ---------------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------------

/**
 * Read and decode one keypress.
 *
 * Returns 'up' 'down' 'left' 'right' 'pgup' 'pgdn' 'quit' 'intr' (Ctrl-C), or a
 * single-character string for the printable bindings. Half-recognised escape
 * junk returns null so the caller simply re-reads. STDIN is blocking, so an
 * empty first read is EOF — the scripted-keys harness shape of "user is done",
 * answered with 'quit' rather than a re-read that would spin.
 *
 * Bare Esc versus an escape sequence cannot be told apart by the leading byte, so
 * after \x1b the reader gives the terminal ~50 ms to deliver the rest of the
 * sequence (one stream_select slice, then a second only while the first proved
 * the producer is still active). Silence means the user hit Esc, which quits.
 */
function readKey(): ?string
{
    $byte = fread(STDIN, 1);
    if ($byte === '' || $byte === false) {
        return 'quit';
    }
    if ($byte === "\x03") {
        return 'intr';
    }
    if ($byte !== "\x1b") {
        return $byte;
    }

    $seq = '';
    $slice = 50_000;
    for ($pass = 0; $pass < 3; $pass++) {
        $read = [STDIN];
        $write = null;
        $except = null;
        if (@stream_select($read, $write, $except, 0, $slice) !== 1) {
            break;
        }
        $more = fread(STDIN, 1);
        if ($more === '' || $more === false) {
            break; // EOF — nothing more will ever follow this Esc
        }
        $seq .= $more;
        if ($seq !== '[' && $seq !== '[5' && $seq !== '[6') {
            break; // complete enough to decide
        }
        $slice = 10_000;
    }

    return match ($seq) {
        '[A'    => 'up',
        '[B'    => 'down',
        '[C'    => 'right',
        '[D'    => 'left',
        '[5~'   => 'pgup',
        '[6~'   => 'pgdn',
        default => $seq === '' ? 'quit' : null,
    };
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------

/**
 * Load ansi.json and refuse, by key, anything this viewer cannot honour.
 *
 * Parse-don't-validate at the boundary: everything downstream trusts an entry that
 * passed here. Fail fast and loud — a viewer over a half-parsed corpus would tag
 * the wrong file.
 *
 * @return array<string, array<string, mixed>>
 */
function loadIndex(string $jsonPath): array
{
    $raw = @file_get_contents($jsonPath);
    if ($raw === false) {
        fwrite(STDERR, "ansi-view: cannot read $jsonPath\n");
        exit(2);
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "ansi-view: $jsonPath is not valid JSON: {$e->getMessage()}\n");
        exit(3);
    }
    if (!is_array($decoded)) {
        fwrite(STDERR, "ansi-view: $jsonPath must hold a JSON object\n");
        exit(3);
    }

    foreach ($decoded as $key => $entry) {
        if (!is_string($key) || !is_array($entry)) {
            fwrite(STDERR, "ansi-view: entry " . (is_string($key) ? "'$key'" : "(numeric key)")
                . " is not an object\n");
            exit(3);
        }
        foreach (['name', 'category', 'depth', 'description'] as $field) {
            if (!isset($entry[$field]) || !is_string($entry[$field])) {
                fwrite(STDERR, "ansi-view: entry '$key' has no string '$field'\n");
                exit(3);
            }
        }
        if (!isset(DEPTH_RANK[$entry['depth']])) {
            fwrite(STDERR, "ansi-view: entry '$key' has unknown depth '{$entry['depth']}'\n");
            exit(3);
        }
        if (!array_key_exists('rating', $entry)) {
            fwrite(STDERR, "ansi-view: entry '$key' has no rating field (null means unset)\n");
            exit(3);
        }
        if ($entry['rating'] !== null && (!is_int($entry['rating']) || $entry['rating'] < 0 || $entry['rating'] > 9)) {
            fwrite(STDERR, "ansi-view: entry '$key' rating must be null (unset) or an integer 0-9\n");
            exit(3);
        }
        if (!isset($entry['tags']) || !is_array($entry['tags']) || !array_is_list($entry['tags'])) {
            fwrite(STDERR, "ansi-view: entry '$key' tags must be a JSON array\n");
            exit(3);
        }
        foreach ($entry['tags'] as $tag) {
            if (!is_string($tag)) {
                fwrite(STDERR, "ansi-view: entry '$key' carries a non-string tag\n");
                exit(3);
            }
        }
    }

    return $decoded;
}

/**
 * The size tier boxes, inclusive, as [minW, maxW, minH, maxH], small to large.
 *
 * @var array<string, array{0: int, 1: int, 2: int, 3: int}>
 */
const TIER_BOXES = [
    's' => [40, 70, 5, 16],
    'm' => [70, 90, 18, 26],
    'l' => [120, 225, 28, 50],
];

/**
 * Size tier of one piece, computed purely from the measured dimensions the index
 * carries — the filename tier letter is neither always present nor authoritative,
 * so it is not consulted at all.
 *
 * THE CLASSES ARE MUTUALLY EXCLUSIVE BY CONSTRUCTION: the s and m height bands
 * (5..16, 18..26) do not overlap and the m and l width bands (70..90, 120..225)
 * do not either, so at most one box can ever fit and the first match is the only
 * match. THE GAP-DISTANCE FALLBACK: a piece inside no box — the 91..119 and
 * 71..119 width middles, the 17 and 27 height gaps, dimensions beyond every
 * edge — is assigned to the box with the smallest summed axis gap,
 * per axis max(0, min−v, v−max), over width and height. Boxes are scanned small
 * to large and only a STRICTLY smaller gap replaces the incumbent, so a tie
 * resolves toward the larger tier. Every (w,h) therefore lands in exactly one
 * tier, deterministically.
 */
function tierOf(int $width, int $height): string
{
    foreach (TIER_BOXES as $tier => [$minW, $maxW, $minH, $maxH]) {
        if ($width >= $minW && $width <= $maxW && $height >= $minH && $height <= $maxH) {
            return $tier;
        }
    }

    $best = 's';
    $bestGap = null;
    foreach (TIER_BOXES as $tier => [$minW, $maxW, $minH, $maxH]) {
        $gap = max(0, $minW - $width, $width - $maxW) + max(0, $minH - $height, $height - $maxH);
        // <= not <: boxes are scanned small to large, and a LATER (larger) tier
        // must replace an incumbent on a tie, so ties resolve toward the larger
        // tier as the docblock and the usage text promise.
        if ($bestGap === null || $gap <= $bestGap) {
            $best = $tier;
            $bestGap = $gap;
        }
    }

    return $best;
}

/**
 * One viewable row per entry, carrying everything sort, filter, render and
 * navigate need so none of them reaches back into the raw index.
 *
 * @param  array<string, array<string, mixed>> $index
 * @return list<array<string, mixed>>
 */
function buildItems(array $index): array
{
    $items = [];
    foreach ($index as $key => $entry) {
        $tier = tierOf((int) ($entry['width'] ?? 0), (int) ($entry['height'] ?? 0));
        $items[] = [
            'key'         => $key,
            'name'        => $entry['name'],
            'category'    => $entry['category'],
            'depth'       => $entry['depth'],
            'rank'        => DEPTH_RANK[$entry['depth']],
            'resolution'  => isset($entry['resolution']) ? (string) $entry['resolution'] : '',
            'width'       => (int) ($entry['width'] ?? 0),
            'height'      => (int) ($entry['height'] ?? 0),
            'colors'      => isset($entry['colors']) ? (int) $entry['colors'] : 0,
            'description' => $entry['description'],
            'tier'        => $tier,
        ];
    }
    usort($items, static function (array $a, array $b): int {
        return [$a['category'], $a['rank'], $a['name'], $a['key']]
            <=> [$b['category'], $b['rank'], $b['name'], $b['key']];
    });

    return $items;
}

/**
 * Categories present in a set, in order.
 *
 * @param  list<array<string, mixed>> $items
 * @return list<string>
 */
function categoriesIn(array $items): array
{
    return array_values(array_unique(array_column($items, 'category')));
}

/**
 * Depth ranks present for one category, ascending.
 *
 * @param  list<array<string, mixed>> $items
 * @return list<int>
 */
function ranksFor(array $items, string $category): array
{
    $ranks = [];
    foreach ($items as $item) {
        if ($item['category'] === $category) {
            $ranks[(int) $item['rank']] = true;
        }
    }
    $list = array_keys($ranks);
    sort($list);

    return $list;
}

/**
 * Index of the first item of (category, rank), or null when the bucket is empty.
 *
 * @param  list<array<string, mixed>> $items
 */
function firstIndexAt(array $items, string $category, int $rank): ?int
{
    foreach ($items as $i => $item) {
        if ($item['category'] === $category && (int) $item['rank'] === $rank) {
            return $i;
        }
    }

    return null;
}

/**
 * The nearest present rank to $want in $present (ties resolve shallower, so the
 * jump is predictable). The caller knows $present is non-empty.
 *
 * @param  list<int> $present
 */
function nearestRank(array $present, int $want): int
{
    $best = $present[0];
    foreach ($present as $rank) {
        if (abs($rank - $want) < abs($best - $want)) {
            $best = $rank;
        }
    }

    return $best;
}

// ---------------------------------------------------------------------------
// Mutation + persistence
// ---------------------------------------------------------------------------

/**
 * Toggle one flag tag on an entry, in place.
 *
 * @param array<string, mixed> $entry
 */
function toggleTag(array &$entry, string $tag): void
{
    $at = array_search($tag, $entry['tags'], true);
    if ($at === false) {
        $entry['tags'][] = $tag;
    } else {
        array_splice($entry['tags'], (int) $at, 1);
    }
}

/**
 * Digits 0-9 set the rating; the digit already shown clears it back to unset.
 * null IS the unset state — there is no default rating, and 0 is a real score.
 *
 * @param array<string, mixed> $entry
 */
function pressRating(array &$entry, int $digit): void
{
    $entry['rating'] = $entry['rating'] === $digit ? null : $digit;
}

/**
 * Write the index back to disk, atomically, in the canonical encoding.
 *
 * THE BYTE-IDENTITY CLAIM: a decode-true/re-encode of the untouched corpus file is
 * cmp-silent against itself — the same flags gen-ansi-readme.php writes with, plus
 * the single trailing newline it appends. Key order and per-entry field order ride
 * PHP's insertion-ordered arrays, so an edit touches exactly the edited entry.
 * The temp file lives in the target directory (rename must not cross devices) and
 * takes the original's mode, so a 0600 index never widens to 0644 on first tag.
 *
 * @param array<string, array<string, mixed>> $index
 */
function persistIndex(array $index, string $jsonPath): void
{
    $json = json_encode(
        $index,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($json === false) {
        fwrite(STDERR, "ansi-view: cannot encode the index: " . json_last_error_msg() . "\n");
        exit(3);
    }
    $payload = $json . "\n";

    $mode = fileperms($jsonPath);
    $tmp = $jsonPath . '.ansi-view.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $payload) === false) {
        @unlink($tmp);
        fwrite(STDERR, "ansi-view: cannot write $tmp\n");
        exit(2);
    }
    if ($mode !== false) {
        @chmod($tmp, $mode & 0777);
    }
    if (!@rename($tmp, $jsonPath)) {
        @unlink($tmp);
        fwrite(STDERR, "ansi-view: cannot move the new index into place at $jsonPath\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/**
 * The one-screen key bar. Printed inverse at the terminal's last row, after the
 * art; taller art scrolls over it, which the usage text declares.
 */
function keyBar(): string
{
    $plain = "\x1b[0m";
    $stand = "\x1b[7m";

    return $stand
        . ' Up/Dn item  Lt/Rt depth  PgUp/PgDn category '
        . '| a alignment-fix  d delete  k keep  b border-fix '
        . '| 0-9 rating  q quit '
        . $plain;
}

/**
 * Paint one item: clear, meta header, the raw art bytes, key bar at the last row.
 *
 * The art is emitted verbatim by design — the file IS its own renderer; whatever
 * SGR state it ends in is wiped by the next frame's clear-screen anyway.
 *
 * @param array<string, mixed>|null      $item   null renders the empty-set notice
 * @param array<string, array<string,mixed>> $index
 * @param list<array<string, mixed>>     $items
 */
function render(?array $item, array $index, array $items, string $root, int $rows): void
{
    $out = "\x1b[2J\x1b[H";

    if ($item === null) {
        $out .= "no items match the filters — quit with q\r\n";
        fwrite(STDOUT, $out);

        return;
    }

    $entry = $index[$item['key']];
    $position = 0;
    foreach ($items as $i => $candidate) {
        if ($candidate['key'] === $item['key']) {
            $position = $i + 1;
            break;
        }
    }

    $tags = $entry['tags'] === [] ? '-' : implode(',', $entry['tags']);
    $tier = (string) $item['tier'];
    $rating = $entry['rating'] === null ? 'unset' : (string) $entry['rating'];

    $out .= sprintf("[item %d of %d] %s\r\n", $position, count($items), (string) $item['key']);
    $out .= sprintf(
        "category: %s | depth: %s | colors: %d | tier: %s | resolution: %s\r\n",
        (string) $item['category'],
        (string) $item['depth'],
        (int) $item['colors'],
        $tier,
        $item['resolution'] === '' ? '-' : $item['resolution']
    );
    $out .= sprintf("description: %s\r\n", $item['description'] === '' ? '-' : $item['description']);
    $out .= sprintf("tags: %s | rating: %s\r\n\r\n", $tags, $rating);

    $art = @file_get_contents($root . '/' . $item['key']);
    $out .= $art === false ? "\x1b[31m<missing art file: " . $item['key'] . ">\x1b[0m\r\n" : $art;

    // Key bar on the LAST row regardless of how far the art scrolled, then 0J so
    // stale columns of any earlier, longer bar cannot survive beneath it.
    $out .= sprintf("\x1b[%d;1H", $rows) . keyBar() . "\x1b[0J";

    fwrite(STDOUT, $out);
}

// ---------------------------------------------------------------------------
// Navigation — every move lands inside the filtered set by construction
// ---------------------------------------------------------------------------

/**
 * Where Up/Down land: neighbour items, clamped at both ends.
 *
 * @param  list<array<string, mixed>> $items
 */
function navItem(array $items, int $current, int $delta): int
{
    return max(0, min(count($items) - 1, $current + $delta));
}

/**
 * Position the anchor sorts at within the sorted filtered set — its virtual
 * row when the --untagged filter has just dropped it. Down from there lands
 * on the next surviving piece (the element at this index), Up on the last
 * survivor before it (this index - 1, clamped) — exactly the neighbours the
 * pinned image sat between. The spaceship is on the sort tuple itself: PHP
 * array ordering is a partial order, so `$a < $b` on arrays would lie here.
 *
 * @param  list<array<string, mixed>> $items
 * @param  array<string, mixed>       $anchor
 */
function insertionIndex(array $items, array $anchor): int
{
    $a = [$anchor['category'], $anchor['rank'], $anchor['name'], $anchor['key']];
    foreach ($items as $i => $item) {
        if (($a <=> [$item['category'], $item['rank'], $item['name'], $item['key']]) < 0) {
            return $i;
        }
    }

    return count($items);
}

/**
 * Up/Down pressed while a mutation pins the screen: re-apply the filters, then
 * take the step from where the pinned image sits (or, once dropped from the
 * set, sorts). Returns the fresh set WITH its cursor — pure, so the caller
 * commits both (and clears the pin) as one act of navigation.
 *
 * @param  array<string, array<string, mixed>> $index
 * @param  array<string, mixed>                $opts
 * @param  array<string, mixed>                $anchor the pinned item, still
 *         describing exactly what the user was looking at
 * @return array{0: list<array<string, mixed>>, 1: int}
 */
function stepFromPin(array $index, array $opts, array $anchor, int $delta): array
{
    $items = applyFilters($index, $opts);
    $at = array_search($anchor['key'], array_column($items, 'key'), true);
    if ($at !== false) {
        return [$items, navItem($items, (int) $at, $delta)];
    }
    if ($items === []) {
        return [$items, 0]; // the emptied set's "no items match" notice is the honest destination
    }
    $gap = insertionIndex($items, $anchor);

    return [$items, $delta > 0 ? min($gap, count($items) - 1) : max($gap - 1, 0)];
}

/**
 * Virtual category row of an anchor the --untagged pin has left stranded:
 * its category emptied out of the filtered set, so array_search finds
 * nothing — but the ladder it sat between still determines Left/Right.
 * Categories arrive name-sorted (buildItems' usort tuple leads with them),
 * so the count of strictly-smaller names IS the row it sorted at.
 */
function virtualCatIndex(array $cats, string $category): int
{
    $below = 0;
    foreach ($cats as $cat) {
        if ((string) $cat < $category) {
            $below++;
        }
    }

    return $below;
}

/**
 * Right/Left: one depth step inside the current category; at the edge of the
 * category's depth ladder the move crosses to the neighbouring category and
 * lands at its far end (Right → shallowest, Left → deepest).
 *
 * A stranded anchor — its whole category dropped out of the filtered set
 * since the pin — takes the cross move directly: its $present ladder is
 * necessarily empty (ranksFor only sees surviving items), and the virtual
 * row names the survivor ABOVE it, so Right steps nowhere extra while Left
 * steps one under. Clamped ends return null and the pin survives.
 *
 * @param  list<array<string, mixed>> $items
 * @return array{0: string, 1: int}|null category|null when nothing moves
 */
function navDepth(array $items, string $category, int $rank, int $direction): ?array
{
    $cats = categoriesIn($items);
    $catAt = array_search($category, $cats, true);
    $present = ranksFor($items, $category);

    if ($direction > 0) {
        foreach ($present as $candidate) {
            if ($candidate > $rank) {
                return [$category, $candidate];
            }
        }
        $row = $catAt === false ? virtualCatIndex($cats, $category) : (int) $catAt + 1;
        $next = $cats[$row] ?? null;
        if ($next === null) {
            return null;
        }
        $landing = ranksFor($items, $next);

        return [$next, $landing[0]];
    }

    $lower = null;
    foreach ($present as $candidate) {
        if ($candidate >= $rank) {
            break;
        }
        $lower = $candidate;
    }
    if ($lower !== null) {
        return [$category, $lower];
    }
    $row = $catAt === false ? virtualCatIndex($cats, $category) - 1 : (int) $catAt - 1;
    $prev = $cats[$row] ?? null;
    if ($prev === null) {
        return null;
    }
    $landing = ranksFor($items, $prev);

    return [$prev, $landing[count($landing) - 1]];
}

/**
 * PgDn/PgUp: one category over, keeping the current depth when the target has it
 * and falling back to the nearest rank when it does not. A stranded anchor (its
 * category emptied by the --untagged pin) crosses to the survivors nearest its
 * virtual row instead of teleporting to a fixed head neighbour.
 *
 * @param  list<array<string, mixed>> $items
 * @return array{0: string, 1: int}|null
 */
function navCategory(array $items, string $category, int $rank, int $direction): ?array
{
    $cats = categoriesIn($items);
    $catAt = array_search($category, $cats, true);
    if ($catAt === false) {
        $below = virtualCatIndex($cats, $category);
        $row = $direction > 0 ? $below : $below - 1;
    } else {
        $row = (int) $catAt + $direction;
    }
    $target = $cats[$row] ?? null;
    if ($target === null) {
        return null;
    }

    return [$target, nearestRank(ranksFor($items, $target), $rank)];
}

/**
 * --check-fit: does this terminal show every (filtered) piece at full size?
 *
 * Reports the terminal geometry, the widest and tallest art in the set (each
 * naming its file), the three largest by area, and the verdict. "Needs at
 * least" is the per-axis maximum across the set — the honest requirement even
 * when no single piece carries both maxima.
 *
 * @param array<string, array<string, mixed>> $index
 */
function checkFit(array $index, array $opts): int
{
    [$rows, $cols] = terminalSize();
    $items = applyFilters($index, $opts);

    fwrite(STDOUT, sprintf("terminal:  %d rows x %d cols\r\n", $rows, $cols));
    if ($items === []) {
        fwrite(STDOUT, "no items match the filters\r\n");

        return 0;
    }

    $widest = $items[0];
    $tallest = $items[0];
    foreach ($items as $item) {
        if ((int) $item['width'] > (int) $widest['width']) {
            $widest = $item;
        }
        if ((int) $item['height'] > (int) $tallest['height']) {
            $tallest = $item;
        }
    }
    usort($items, static fn (array $a, array $b): int =>
        ((int) $b['width'] * (int) $b['height']) <=> ((int) $a['width'] * (int) $a['height']));

    fwrite(STDOUT, sprintf("widest:    %dx%d  %s\r\n", (int) $widest['width'], (int) $widest['height'], $widest['key']));
    fwrite(STDOUT, sprintf("tallest:   %dx%d  %s\r\n", (int) $tallest['width'], (int) $tallest['height'], $tallest['key']));
    fwrite(STDOUT, "three largest by area (WxH):\r\n");
    foreach (array_slice($items, 0, 3) as $item) {
        fwrite(STDOUT, sprintf("  %dx%d  %s\r\n", (int) $item['width'], (int) $item['height'], $item['key']));
    }

    $tooBig = array_filter(
        $items,
        static fn (array $item): bool => (int) $item['width'] > $cols || (int) $item['height'] > $rows,
    );
    if ($tooBig === []) {
        fwrite(STDOUT, sprintf("VERDICT: large enough — all %d pieces fit at full size\r\n", count($items)));

        return 0;
    }

    fwrite(STDOUT, sprintf(
        "VERDICT: TOO SMALL — art needs at least %d rows x %d cols, the terminal has %d x %d; %d of %d pieces overflow\r\n",
        (int) $tallest['height'],
        (int) $widest['width'],
        $rows,
        $cols,
        count($tooBig),
        count($items),
    ));

    return 1;
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

/**
 * Parse argv into options. --help was already answered at the top of the file.
 *
 * @return array{depths: list<string>, categories: list<string>, sizes: list<string>, untagged: bool, checkFit: bool, root: string, file: ?string}
 */
function parseArgs(array $args): array
{
    $opts = ['depths' => [], 'categories' => [], 'sizes' => [], 'untagged' => false,
             'checkFit' => false,
             'root' => dirname(__DIR__) . '/ansi', 'file' => null];

    $fail = static function (string $why): never {
        fwrite(STDERR, "ansi-view: $why (see --help)\n");
        exit(2);
    };

    foreach ($args as $arg) {
        if ($arg === '--untagged') {
            $opts['untagged'] = true;
            continue;
        }
        if ($arg === '--check-fit') {
            $opts['checkFit'] = true;
            continue;
        }
        if (!preg_match('/^--(depth|category|size|file|root)=(.*)$/s', $arg, $m)) {
            $fail("unrecognised argument '$arg'");
        }
        $value = $m[2];
        switch ($m[1]) {
            case 'depth':
                if (!isset(DEPTH_RANK[$value])) {
                    $fail("--depth must be 16|256|truecolor, got '$value'");
                }
                $opts['depths'][] = $value;
                break;
            case 'category':
                if ($value === '') {
                    $fail('--category needs a value');
                }
                $opts['categories'][] = $value;
                break;
            case 'size':
                if (!in_array($value, ['s', 'm', 'l'], true)) {
                    $fail("--size must be s|m|l, got '$value'");
                }
                $opts['sizes'][] = $value;
                break;
            case 'file':
                if ($value === '') {
                    $fail('--file needs a path');
                }
                $opts['file'] = $value;
                break;
            case 'root':
                if ($value === '') {
                    $fail('--root needs a path');
                }
                $opts['root'] = $value;
                break;
        }
    }

    return $opts;
}

/**
 * Filter set for the row list. Recomputed at session start and on the FIRST
 * navigation after a tag/rating mutation — never at mutation time: the mutated
 * image stays pinned on screen (it drops out of the set under --untagged, and
 * switching it away mid-review would answer a question the reviewer hasn't
 * finished asking). A resync lands on the anchor's own index while it still
 * matches, its sorting position once dropped — never past the end; an emptied
 * set paints the "no items match" notice.
 *
 * @param  array<string, array<string, mixed>> $index
 * @return list<array<string, mixed>>
 */
function applyFilters(array $index, array $opts): array
{
    $items = buildItems($index);
    $in = static fn (array $list, string $value): bool => $list === [] || in_array($value, $list, true);

    return array_values(array_filter(
        $items,
        static function (array $item) use ($in, $opts, $index): bool {
            if (!$in($opts['depths'], (string) $item['depth'])) {
                return false;
            }
            if (!$in($opts['categories'], (string) $item['category'])) {
                return false;
            }
            if (!$in($opts['sizes'], (string) $item['tier'])) {
                return false;
            }
            if ($opts['untagged']) {
                $entry = $index[$item['key']];
                if ($entry['tags'] !== [] || $entry['rating'] !== null) {
                    return false;
                }
            }

            return true;
        },
    ));
}

/**
 * The interactive loop: paint, read a key, act, persist on every mutation.
 *
 * @param array<string, array<string, mixed>> $index        in-memory ansi.json, edited live
 * @param array<string, mixed>                $opts         parsed CLI options
 */
function runViewer(array &$index, array $opts): void
{
    // Rows are re-read every frame so a mid-session resize moves the key bar to
    // the NEW last row instead of stranding it at a stale one (round-1 review N1).
    $items = applyFilters($index, $opts);
    $cursor = 0;
    // True between a tag/rating keypress and the next navigation that actually
    // moves. While set, $items is deliberately stale: it still carries the
    // mutated image so it stays on screen — render reads $index live, so its
    // header lines refresh every frame anyway.
    $pinned = false;

    while (true) {
        [$rows] = terminalSize();
        $item = $items[$cursor] ?? null;
        render($item, $index, $items, $opts['root'], $rows);
        $key = readKey();

        if ($key === null) {
            continue; // partial garbage between keys — re-read; the loop repaints the identical frame
        }
        if ($key === 'quit' || $key === 'q' || $key === 'Q') {
            return;
        }
        if ($key === 'intr') {
            restoreTerminal((string) $GLOBALS['ansi_view_restore_cmd']);
            exit(0);
        }
        if ($item === null) {
            continue; // empty set: only q (and the no-ops) can happen
        }

        $category = (string) $item['category'];
        $rank = (int) $item['rank'];
        $land = null;
        // The set the bucket keys navigate: the pinned list until a landing
        // commits the resync — a failed nav must change nothing, pin included.
        $next = $items;

        switch ($key) {
            case 'up':
                if ($pinned) {
                    [$items, $cursor] = stepFromPin($index, $opts, $item, -1);
                    $pinned = false;
                } else {
                    $cursor = navItem($items, $cursor, -1);
                }
                continue 2;
            case 'down':
                if ($pinned) {
                    [$items, $cursor] = stepFromPin($index, $opts, $item, 1);
                    $pinned = false;
                } else {
                    $cursor = navItem($items, $cursor, 1);
                }
                continue 2;
            case 'right':
                if ($pinned) {
                    $next = applyFilters($index, $opts);
                }
                $land = navDepth($next, $category, $rank, 1);
                break;
            case 'left':
                if ($pinned) {
                    $next = applyFilters($index, $opts);
                }
                $land = navDepth($next, $category, $rank, -1);
                break;
            case 'pgdn':
                if ($pinned) {
                    $next = applyFilters($index, $opts);
                }
                $land = navCategory($next, $category, $rank, 1);
                break;
            case 'pgup':
                if ($pinned) {
                    $next = applyFilters($index, $opts);
                }
                $land = navCategory($next, $category, $rank, -1);
                break;
            default:
                if (isset(FLAG_TAGS[$key])) {
                    toggleTag($index[$item['key']], FLAG_TAGS[$key]);
                    persistIndex($index, $opts['file']);
                } elseif (preg_match('/^[0-9]$/', (string) $key) === 1) {
                    pressRating($index[$item['key']], (int) $key);
                    persistIndex($index, $opts['file']);
                } else {
                    continue 2; // unbound key — no repaint churn
                }
                // Pin, don't re-filter: the header refreshes from $index on the
                // next paint, and the image under review stays put until the
                // reviewer navigates.
                $pinned = true;
                continue 2;
        }

        if ($land !== null) {
            $at = firstIndexAt($next, $land[0], $land[1]);
            if ($at !== null) {
                // The move lands: commit the resynced set with it, consuming the pin.
                $items = $next;
                $cursor = $at;
                $pinned = false;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$opts = parseArgs(array_slice($argv, 1));

if (!is_dir($opts['root'])) {
    fwrite(STDERR, "ansi-view: no such directory: {$opts['root']}\n");
    exit(2);
}
$jsonPath = $opts['file'] ?? $opts['root'] . '/ansi.json';
$opts['file'] = $jsonPath;

$index = loadIndex($jsonPath);

if ($opts['checkFit']) {
    exit(checkFit($index, $opts));
}

setupTerminal();
runViewer($index, $opts);
restoreTerminal((string) $GLOBALS['ansi_view_restore_cmd']);
exit(0);
