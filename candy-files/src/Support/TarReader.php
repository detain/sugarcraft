<?php

declare(strict_types=1);

namespace SugarCraft\Files\Support;

/**
 * Minimal forward-only ustar/GNU/pax tar reader for the CF-33 archive view.
 *
 * No upstream - house pattern. PharData was the obvious engine and was
 * rejected on purpose: the file manager must see each record's TYPEFLAG
 * (symlinks, hard links and devices are never materialised), stream bodies
 * in bounded chunks so extraction can yield to the event loop, and meter
 * every uncompressed byte against a decompression-bomb ceiling. A phar://
 * wrapper hides all three.
 *
 * Works over any readable stream: a plain `.tar` (seekable - skipping a
 * body is one fseek) or a `compress.zlib://` / `compress.bzip2://` wrapper
 * (not seekable - bodies are read and discarded, and every discarded byte
 * counts toward {@see $maxScan}).
 *
 * Understands: ustar prefix, GNU `L` long names, pax `x` records (path,
 * size) and base-256 sizes. Checksums are verified; a bad header or a short
 * read is a thrown RuntimeException, never a guess.
 */
final class TarReader
{
    private int $remaining = 0;
    private int $pad = 0;
    private int $scanned = 0;

    /** @param resource $fh */
    public function __construct(private $fh, private readonly bool $seekable, private readonly int $maxScan = 268435456)
    {
    }

    /** Uncompressed bytes consumed so far (the bomb meter). */
    public function scanned(): int
    {
        return $this->scanned;
    }

    /**
     * Advance to the next record, discarding any unread body of the
     * current one. Null at the end-of-archive marker or clean EOF.
     *
     * @return array{name:string,size:int,mtime:int,type:string}|null
     */
    public function next(): ?array
    {
        while (!$this->discard(1 << 20)) {
            // drain the previous record (bounded chunks keep memory flat)
        }
        $longName = null;
        $paxPath = null;
        $paxSize = null;
        while (true) {
            $h = $this->readExact(512, true);
            if ($h === null) {
                return null;
            }
            if (rtrim($h, "\0") === '') {
                return null;
            }
            $this->verifyChecksum($h);
            $type = $h[156] === "\0" ? '0' : $h[156];
            $size = $this->parseNumber(substr($h, 124, 12));
            $name = rtrim(substr($h, 0, 100), "\0");
            if (substr($h, 257, 5) === 'ustar') {
                $prefix = rtrim(substr($h, 345, 155), "\0");
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
            }
            $mtime = $this->parseNumber(substr($h, 136, 12));
            if ($type === 'L' || $type === 'x' || $type === 'g') {
                if ($size > 1048576) {
                    throw new \RuntimeException('tar metadata record too large');
                }
                $body = (string) $this->readExact($size, false);
                $this->skipPadding($size);
                if ($type === 'L') {
                    $longName = rtrim($body, "\0");
                } elseif ($type === 'x') {
                    foreach (self::paxRecords($body) as $k => $v) {
                        if ($k === 'path') {
                            $paxPath = $v;
                        } elseif ($k === 'size' && ctype_digit($v)) {
                            $paxSize = (int) $v;
                        }
                    }
                }
                continue;
            }
            $name = $paxPath ?? $longName ?? $name;
            $size = $paxSize ?? $size;
            $this->remaining = $size;
            $this->pad = (512 - $size % 512) % 512;
            return ['name' => $name, 'size' => $size, 'mtime' => $mtime, 'type' => $type];
        }
    }

    /** Up to $n bytes of the current record's body; '' once it is exhausted. */
    public function read(int $n): string
    {
        $n = min($n, $this->remaining);
        if ($n <= 0) {
            return '';
        }
        $chunk = (string) $this->readExact($n, false);
        $this->remaining -= $n;
        return $chunk;
    }

    /** True while the current record still has unread body bytes. */
    public function hasBody(): bool
    {
        return $this->remaining > 0;
    }

    /**
     * Throw away up to $max bytes of the current record (body then
     * padding). True when the record is fully consumed.
     */
    public function discard(int $max): bool
    {
        $left = $this->remaining + $this->pad;
        if ($left === 0) {
            return true;
        }
        $take = min($max, $left);
        if ($this->seekable) {
            if (@fseek($this->fh, $take, SEEK_CUR) !== 0) {
                throw new \RuntimeException('tar seek failed');
            }
            // fseek happily lands past EOF; a truncated plain tar must not
            // read as a shorter-but-valid one. Only the BODY must exist - a
            // tar that stops right after its last body (padding missing) is
            // tolerated, so the check is against where the body ends.
            $fromBody = min($take, $this->remaining);
            $size = fstat($this->fh)['size'] ?? null;
            if ($size !== null && (int) ftell($this->fh) - ($take - $fromBody) > $size) {
                throw new \RuntimeException('truncated tar archive');
            }
            $this->remaining -= $fromBody;
            $this->pad -= $take - $fromBody;
        } else {
            $got = (string) $this->readExact($take, false);
            unset($got);
            $fromBody = min($take, $this->remaining);
            $this->remaining -= $fromBody;
            $this->pad -= $take - $fromBody;
        }
        return $this->remaining + $this->pad === 0;
    }

    private function skipPadding(int $size): void
    {
        $pad = (512 - $size % 512) % 512;
        if ($pad > 0) {
            $this->readExact($pad, false);
        }
    }

    /**
     * @param bool $eofOk true at a record boundary, where a clean EOF is a legal end
     */
    private function readExact(int $n, bool $eofOk): ?string
    {
        if ($n === 0) {
            return '';
        }
        $buf = '';
        while (strlen($buf) < $n) {
            $part = fread($this->fh, $n - strlen($buf));
            if ($part === false || $part === '') {
                if ($eofOk && $buf === '') {
                    return null;
                }
                throw new \RuntimeException('truncated tar archive');
            }
            $buf .= $part;
        }
        if (!$this->seekable) {
            $this->scanned += $n;
            if ($this->scanned > $this->maxScan) {
                throw new \RuntimeException('archive exceeds the uncompressed scan limit');
            }
        }
        return $buf;
    }

    private function verifyChecksum(string $h): void
    {
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ($i >= 148 && $i < 156) ? 32 : ord($h[$i]);
        }
        $field = trim(substr($h, 148, 8), " \0");
        if (preg_match('/^[0-7]+$/', $field) !== 1) {
            throw new \RuntimeException('tar header checksum mismatch');
        }
        $stored = (int) octdec($field);
        if ($sum !== $stored) {
            throw new \RuntimeException('tar header checksum mismatch');
        }
    }

    private function parseNumber(string $field): int
    {
        if ((ord($field[0]) & 0x80) !== 0) {
            // GNU base-256: big-endian, top bit is the marker.
            $n = ord($field[0]) & 0x7f;
            for ($i = 1, $len = strlen($field); $i < $len; $i++) {
                $n = ($n << 8) | ord($field[$i]);
                if ($n < 0) {
                    throw new \RuntimeException('tar number out of range');
                }
            }
            return $n;
        }
        $t = trim($field, " \0");
        if ($t === '') {
            return 0;
        }
        if (preg_match('/^[0-7]+$/', $t) !== 1) {
            throw new \RuntimeException('tar header number is not octal');
        }
        return (int) octdec($t);
    }

    /** @return array<string,string> */
    private static function paxRecords(string $body): array
    {
        $out = [];
        $pos = 0;
        $len = strlen($body);
        while ($pos < $len) {
            $sp = strpos($body, ' ', $pos);
            if ($sp === false) {
                break;
            }
            $recLen = (int) substr($body, $pos, $sp - $pos);
            if ($recLen <= 0 || $pos + $recLen > $len) {
                break;
            }
            $rec = substr($body, $sp + 1, $recLen - ($sp + 1 - $pos) - 1);
            $eq = strpos($rec, '=');
            if ($eq !== false) {
                $out[substr($rec, 0, $eq)] = substr($rec, $eq + 1);
            }
            $pos += $recLen;
        }
        return $out;
    }
}
