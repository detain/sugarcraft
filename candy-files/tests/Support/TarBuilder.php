<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests\Support;

/**
 * Hand-rolled ustar writer for the CF-33 fixtures. PharData cannot emit the
 * hostile shapes the safety tests need (`../` names, symlink records, GNU
 * long names, pax paths, hard links), so the bytes are built directly.
 */
final class TarBuilder
{
    /**
     * @param list<array{name:string,type?:string,body?:string,link?:string,mtime?:int,longname?:bool,pax?:bool,prefix?:string}> $records
     */
    public static function build(array $records): string
    {
        $out = '';
        foreach ($records as $r) {
            $type = $r['type'] ?? '0';
            $body = $r['body'] ?? '';
            $name = $r['name'];
            if (!empty($r['longname'])) {
                $out .= self::header('././@LongLink', 'L', strlen($name) + 1, 0) . self::pad($name . "\0");
                $name = substr($name, 0, 90);
            }
            if (!empty($r['pax'])) {
                $rec = ' path=' . $r['name'] . "\n";
                $len = strlen($rec) + 1;
                while (strlen($rec) + strlen((string) $len) !== $len) {
                    $len = strlen($rec) + strlen((string) $len);
                }
                $paxBody = $len . $rec;
                $out .= self::header('PaxHeader', 'x', strlen($paxBody), 0) . self::pad($paxBody);
                $name = 'short';
            }
            $out .= self::header($name, $type, $type === '0' ? strlen($body) : 0, $r['mtime'] ?? 1700000000, $r['link'] ?? '', $r['prefix'] ?? '');
            if ($type === '0') {
                $out .= self::pad($body);
            }
        }
        return $out . str_repeat("\0", 1024);
    }

    private static function pad(string $data): string
    {
        $rem = strlen($data) % 512;
        return $rem === 0 ? $data : $data . str_repeat("\0", 512 - $rem);
    }

    private static function header(string $name, string $type, int $size, int $mtime, string $link = '', string $prefix = ''): string
    {
        $h = str_pad($name, 100, "\0")
            . sprintf('%07o', $type === '5' ? 0755 : 0644) . "\0"
            . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $size) . "\0"
            . sprintf('%011o', $mtime) . "\0"
            . '        '
            . $type
            . str_pad($link, 100, "\0")
            . "ustar\0" . '00'
            . str_pad('', 32, "\0") . str_pad('', 32, "\0")
            . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
            . str_pad($prefix, 155, "\0");
        $h = str_pad($h, 512, "\0");
        $sum = array_sum(array_map('ord', str_split($h)));
        return substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
    }
}
