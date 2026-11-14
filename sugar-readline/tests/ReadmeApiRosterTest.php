<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * S-27 (finding 029): the README API reference must cover every public
 * symbol in src/ (roster diff = 0). The scan mirrors the real surface via
 * reflection; a new public method, constant, enum case, or property without
 * a row in the `## API reference` block goes red here. Examples may only
 * use knobs that the README names.
 */
final class ReadmeApiRosterTest extends TestCase
{
    private const BLOCK_START = '<!-- api:begin -->';
    private const BLOCK_END = '<!-- api:end -->';

    /** @return list<string> public symbol names of the lib, deduped */
    private function publicSymbols(): array
    {
        $bs = chr(92);
        $root = \dirname(__DIR__);
        $files = array_merge(\glob($root . '/src/*.php') ?: [], \glob($root . '/src/*/*.php') ?: []);
        $names = [];
        foreach ($files as $f) {
            $code = (string) \file_get_contents($f);
            \preg_match('/^namespace ([^;]+);/m', $code, $m);
            $ns = $m[1] ?? '';
            \preg_match_all('/(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/', $code, $cm, PREG_SET_ORDER);
            foreach ($cm as $c) {
                $fq = $ns . $bs . $c[1];
                if (!class_exists($fq) && !interface_exists($fq) && !trait_exists($fq) && !enum_exists($fq)) {
                    continue; // docblock false positive
                }
                $r = new ReflectionClass($fq);
                if ($r->getFileName() !== realpath($f)) {
                    continue; // alias or secondary mention
                }
                foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $mm) {
                    if ($mm->getDeclaringClass()->getName() !== $fq) {
                        continue;
                    }
                    $names[] = $mm->getName();
                }
                foreach ($r->getConstants() as $k => $v) {
                    $names[] = (string) $k;
                }
                foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) as $pp) {
                    if ($pp->getDeclaringClass()->getName() !== $fq || $pp->isStatic()) {
                        continue;
                    }
                    $names[] = $pp->getName();
                }
            }
        }
        return array_values(array_unique($names));
    }

    private function apiBlock(): string
    {
        $readme = (string) \file_get_contents(\dirname(__DIR__) . '/README.md');
        $start = \strpos($readme, self::BLOCK_START);
        $end = \strpos($readme, self::BLOCK_END);
        self::assertIsInt($start, 'README lost its api:begin marker');
        self::assertIsInt($end, 'README lost its api:end marker');
        self::assertGreaterThan($start, $end);
        return \substr($readme, $start, $end - $start);
    }

    /** @return array<string,true> identifiers named in backticks inside a block */
    private function backtickTokens(string $block): array
    {
        \preg_match_all('/`([^`]+)`/', $block, $m);
        $set = [];
        foreach ($m[1] as $token) {
            foreach (\preg_split('/[\s,\/]+/', $token) ?: [] as $word) {
                $pos = \strrpos($word, ':');
                if ($pos !== false) {
                    $word = \substr($word, $pos + 1); // `Foo::bar()` names symbol `bar`
                }
                $word = \str_replace('()', '', $word);
                if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $word)) {
                    $set[$word] = true;
                }
            }
        }
        return $set;
    }

    public function testEveryPublicSymbolIsNamedInTheApiBlock(): void
    {
        $named = $this->backtickTokens($this->apiBlock());
        $missing = [];
        foreach ($this->publicSymbols() as $name) {
            if (!isset($named[$name])) {
                $missing[] = $name;
            }
        }
        self::assertSame([], $missing, 'README API reference lost its roster-diff-0 property');
    }

    public function testExamplesOnlyUseNamedKnobs(): void
    {
        $named = $this->backtickTokens($this->apiBlock());
        $symbols = \array_flip($this->publicSymbols());
        $root = \dirname(__DIR__);
        $unlabelled = [];
        foreach (\glob($root . '/examples/*.php') ?: [] as $f) {
            $code = (string) \file_get_contents($f);
            \preg_match_all('/->([a-zA-Z_][A-Za-z0-9_]*)\s*\(/', $code, $m);
            foreach ($m[1] as $call) {
                if (isset($symbols[$call]) && !isset($named[$call])) {
                    $unlabelled[] = \basename($f) . ':' . $call;
                }
            }
        }
        self::assertSame([], $unlabelled, 'examples drive knobs the README never names');
    }
}
