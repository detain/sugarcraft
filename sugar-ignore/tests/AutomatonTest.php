<?php

declare(strict_types=1);

namespace SugarCraft\Ignore\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ignore\Automaton;
use SugarCraft\Ignore\Pattern;

final class AutomatonTest extends TestCase
{
    public function testForBuildsAnAutomatonThatMatchesTheWholeSubject(): void
    {
        $a = Automaton::for(Pattern::parse('*a*a*c'));

        $this->assertTrue($a->match('xaxac'));
        $this->assertFalse($a->match('xaxacx'));
        $this->assertFalse($a->match('ac'));
    }

    public function testItAgreesWithPcreOnTheSharedLanguage(): void
    {
        foreach (['**/foo', 'a/**/b', 'l?g[0-9]*.t*t', '***/x', 'foo/**'] as $line) {
            $p = Pattern::parse($line);
            $a = Automaton::for($p);
            foreach (['foo', 'a/b', 'a/x/b', 'log7a.txt', 'a/x', 'foo/x', 'x/foo', ''] as $subject) {
                $this->assertSame(preg_match($p->regex, $subject) === 1, $a->match($subject), "$line vs '$subject'");
            }
        }
    }

    public function testAnOverBudgetInputIsRefusedNotGuessed(): void
    {
        $a = Automaton::for(Pattern::parse(str_repeat('*a', 1000) . '*c'));

        $this->assertNull($a->match(str_repeat('x', 600)));
        $this->assertFalse($a->match('short'));
    }

    public function testTheTrailingNewlineQuirkMatchesPcreDollar(): void
    {
        $p = Pattern::parse('/a');

        $this->assertSame(preg_match($p->regex, "a\n") === 1, Automaton::for($p)->match("a\n"));
    }

    public function testRepeatMatchesReuseTheInternedStates(): void
    {
        $a = Automaton::for(Pattern::parse('*a*a*c'));

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($a->match('xaxac'));
        }
    }
}
