<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Core\Util\Ansi;

/**
 * SC-1 pin for the pure-text short-circuit at the head of Ansi::strip().
 *
 * strip() is on the render hot path (Width::compute calls it once per
 * measured grapheme run), and its byte state machine previously walked
 * every single byte even of plain ASCII. The cure is a guard: if the
 * string contains neither ESC (0x1B) nor a C1 lead byte (0x80..0x9F),
 * nothing the machine can do has anything to remove, so the input is
 * returned unchanged. That claim is proven three ways here —
 *
 * 1. structural: the guard textually precedes the loop in the method
 *    source, so re-ordering it away from the top (or deleting it) fails
 *    loudly and cheaply, WITHOUT a wall-clock benchmark;
 * 2. trigger-set: the class constant holds EXACTLY ESC + the C1 range
 *    0x80..0x9F — a byte above 0x9F can never cause removal, because the
 *    machine treats well-formed UTF-8 (lead C2..F4, continuation 80..BF)
 *    and stray high bytes as verbatim pass-through, never as an escape
 *    lead; widening this set silently changes which strings take the slow
 *    path and must be a deliberate edit here too;
 * 3. behavioural: 18 hand-built trigger fixtures and 60 deterministic
 *    fuzz inputs recorded from the PRE-guard implementation still strip
 *    to the recorded bytes — the guard must not alter a single output.
 */
final class AnsiStripFastPathTest extends TestCase
{
    public function testTheGuardSitsBeforeTheScanLoop(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Util/Ansi.php');
        $start = \strpos($src, 'public static function strip(');
        self::assertIsInt($start);
        $loop = \strpos($src, 'while (', $start);
        $guard = \strpos($src, 'strcspn($s, self::STRIP_TRIGGER_BYTES)', $start);
        self::assertIsInt($guard, 'the pure-text guard must exist');
        self::assertIsInt($loop);
        self::assertLessThan($loop, $guard, 'the guard must precede the byte loop');
        // The guard body returns the input unchanged, not a copy artefact.
        $between = \substr($src, $guard, $loop - $guard);
        self::assertStringContainsString('return $s;', $between);
        self::assertStringContainsString('strlen($s)', $between);
    }

    public function testTheTriggerSetIsExactlyEscPlusC1(): void
    {
        $const = (new ReflectionClass(Ansi::class))->getConstant('STRIP_TRIGGER_BYTES');
        self::assertIsString($const);
        $want = "\u{001B}" . \implode('', \array_map('chr', \range(0x80, 0x9F)));
        self::assertSame($want, $const, 'ESC + 0x80..0x9F and nothing else');
        self::assertSame(33, \strlen($const));
    }

    public function testPureTextIsReturnedIdentically(): void
    {
        self::assertSame('', Ansi::strip(''));
        $ascii = 'The quick brown fox jumps over 42 lazy dogs — full ASCII 0x20..0x7E.';
        self::assertSame($ascii, Ansi::strip($ascii));
        self::assertSame("tabs\tand\nnewlines stay\rput", Ansi::strip("tabs\tand\nnewlines stay\rput"));
        $printables = \implode('', \array_map('chr', \range(0x20, 0x7E)));
        self::assertSame($printables, Ansi::strip($printables));
        // Multibyte UTF-8 with no escape/C1 lead returns untouched — byte-identical.
        foreach (["héllo wörld ✨", "\u{1F47F}\u{1F475} 👿👵", "\u{FF21}\u{FF22}\u{FF23} fullwidth", "é\x{A0}"] as $pure) {
            self::assertSame($pure, Ansi::strip($pure), 'pure UTF-8 must pass through the guard');
        }
        // Lone high bytes (>= 0xA0) never take the slow path at all.
        $highOnly = "\xA0\xA1\xFF";
        self::assertSame($highOnly, Ansi::strip($highOnly));
    }

    public function testTriggerInputsStillStripExactlyAsBefore(): void
    {
        // Hex in / hex out, recorded against the pre-guard implementation.
        $cases = [
            ['6162631b5b33316d646566', '616263646566'],
            ['781b5d303b7469746c650779', '7879'],
            ['781b5d303b7469746c651b5c7a', '787a'],
            ['61629c6364', '61626364'],
            ['68c3a96c6c6f2077c3b6726c6420e29ca8f09f91bff09f91b5', '68c3a96c6c6f2077c3b6726c6420e29ca8f09f91bff09f91b5'],
            ['c3a91b5b316dc3bc', 'c3a9c3bc'],
            ['1b50311b5c646f6e65', '646f6e65'],
            ['6162631b5b', '616263'],
            ['1b', ''],
            ['74657874c2a06e627370', '74657874c2a06e627370'],
            ['6162631b5331323334351b5c36', '616263313233343536'],
            ['1b5f431b5c', ''],
            ['1b5b3f32356c611b5b3f32356862', '6162'],
            ['efbbbf78', 'efbbbf78'],
            ['610962', '610962'],
            ['617f62', '617f62'],
            ['9b', ''],
            ['1b5d383b3b687474703a2f2f78', ''],
        ];
        foreach ($cases as [$in, $out]) {
            self::assertSame($out, \bin2hex(Ansi::strip((string) \hex2bin($in))), 'input ' . $in);
        }
    }

    public function testDeterministicFuzzCorpusMatchesPreGuardGoldens(): void
    {
        // Generated by the SC-1 lane from the BASE (pre-guard) class with
        // mt_srand(20261105); the post-guard class must reproduce all 60.
        $corpus = [
            ['f38ba9a1673d4df38092b7f3aa9b838af48dbb8e2ef194a39bbf41e7a996f096a096f097ab9478f38fbf9958f19f83b99bf18b99b1', 'f38ba9a1673d4df38092b7f3aa9b83f48dbb8e2ef194a39bbf41e7a996f096a096f097ab9478f38fbf9958f19f83b9'],
            ['f1b583ae727654f18faa84f190ab8f3c4ff19582b3a96adb512879f2adacb3', 'f1b583ae727654f18faa84f190ab8f3c4ff19582b3a96adb512879f2adacb3'],
            ['f283a8a7f3bfabbd', 'f283a8a7f3bfabbd'],
            ['26255ef0a999bd99695af1928caf51f48699a4fd42f3aa83a4f29d98b06e46f2bab4a5', '26255ef0a999bd695af1928caf51f48699a4fd42f3aa83a4f29d98b06e46f2bab4a5'],
            ['2e68f0bba4b5', '2e68f0bba4b5'],
            ['35f288b09e2ff38b91a4f1b98f9e7e213138eaf3bc9aa4f38c91b137f1beb082f0a0b3ad', '35f288b09e2ff38b91a4f1b98f9e7e213138eaf3bc9aa4f38c91b137f1beb082f0a0b3ad'],
            ['245f4df285a1885cf1b5b7b34637f1a6a6b320f0a7a8916ff185b8abf29e86866524f39794a6d3f1a1b3a7f1b4809ff28086a7f2aca4a37c5c4ff39fad96f1a5b798f487a7bc63', '245f4df285a1885cf1b5b7b34637f1a6a6b320f0a7a8916ff185b8abf29e86866524f39794a6d3f1a1b3a7f1b4809ff28086a7f2aca4a37c5c4ff39fad96f1a5b798f487a7bc63'],
            ['3035745e31245e37f2a7a8a1', '3035745e31245e37f2a7a8a1'],
            ['f28ba58179', 'f28ba58179'],
            ['2f7b6f39f3', '2f7b6f39f3'],
            ['56abf0aa9cb2f2898c8f28f3bf979f36f2b792ab1126', '56abf0aa9cb2f2898c8f28f3bf979f36f2b792ab1126'],
            ['416886f3bf9084f1b2a0acfd', '4168f3bf9084f1b2a0acfd'],
            ['89f19c82a02a6af19696a13c4c227bf1abad90f2a594bf06f1aa808a', 'f19c82a02a6af19696a13c4c227bf1abad90f2a594bf06f1aa808a'],
            ['5c6a4df3b4b0abf18aa8b1f1868483bb42f3ac93963853f193a29ff0bda1a260f1a7ba8c9531f180b6a36d4651f286959152', '5c6a4df3b4b0abf18aa8b1f1868483bb42f3ac93963853f193a29ff0bda1a260f1a7ba8c31f180b6a36d4651f286959152'],
            ['70f0acb8a5', '70f0acb8a5'],
            ['f18caea0f3a48189f3b182a1f19aa1b16df0b8aa82ab4075cfe7b0ba4670f39c9c90d1f29d8eb9f18ca898f297b0a26231f0949eaef2a19cabf3b78cbdf18183886638f2a688b4e6a59377', 'f18caea0f3a48189f3b182a1f19aa1b16df0b8aa82ab4075cfe7b0ba4670f39c9c90d1f29d8eb9f18ca898f297b0a26231f0949eaef2a19cabf3b78cbdf18183886638f2a688b4e6a59377'],
            ['e54bf0a0acb7542e306df298a6b267f19bad8755fc854159f39bb0aef1b49493f1958ebc', 'e54bf0a0acb7542e306df298a6b267f19bad8755fc4159f39bb0aef1b49493f1958ebc'],
            ['f3b28baaf388b6bbf099839cf0bd8c862630', 'f3b28baaf388b6bbf099839cf0bd8c862630'],
            ['e9bc8d48f3a4acb46223dff0babeaff1a88689f3a29faf3deca496f18dafbc', 'e9bc8d48f3a4acb46223dff0babeaff1a88689f3a29faf3deca496f18dafbc'],
            ['54f48b80b64f4c854af391ab9d36f0bca1ac43f38b8aa57cf097bf87f2aab3b3', '54f48b80b64f4c4af391ab9d36f0bca1ac43f38b8aa57cf097bf87f2aab3b3'],
            ['4ff0b9bf8e95f285989270f19188af33f48ba3b40f48f2bc9386f1898f8029f2a0b885383046c769f48a958852f3a896a155f1ad83b5', '4ff0b9bf8ef285989270f19188af33f48ba3b40f48f2bc9386f1898f8029f2a0b885383046c769f48a958852f3a896a155f1ad83b5'],
            ['2cf1a1bda4f48c91857351077bf2ab809d1864565f20696a42f0a29893e984b342', '2cf1a1bda4f48c91857351077bf2ab809d1864565f20696a42f0a29893e984b342'],
            ['55f1b6a0a556f1a180bad9f38083a623', '55f1b6a0a556f1a180bad9f38083a623'],
            ['7c42dff48d9e9552f190a0b6f399afb6d2bc6f4ff1b3a098e99dba32', '7c42dff48d9e9552f190a0b6f399afb6d2bc6f4ff1b3a098e99dba32'],
            ['706cf3aea3914b42f28eac8d39f1baa9887bf2889794435ef38985b1f189a38171f480a9bd5ef19c869da02df2baadbb6bf3b8a3a3f3b98ba4443ff194b48e', '706cf3aea3914b42f28eac8d39f1baa9887bf2889794435ef38985b1f189a38171f480a9bd5ef19c869da02df2baadbb6bf3b8a3a3f3b98ba4443ff194b48e'],
            ['f1ae96ab6af3b8adb87e', 'f1ae96ab6af3b8adb87e'],
            ['2141f3b18fa7f0abb7883fd99ef3baa588f19fa396f18385ac', '2141f3b18fa7f0abb7883fd99ef3baa588f19fa396f18385ac'],
            ['b347f197af92f4809e9af193b49ec17b3436f481bd8942f289b392232c39f481aaab40586bf3ba809061f3b3a295bef2a0a49535f29aab9d6677f39cb195f2ac9f90', 'b347f197af92f4809e9af193b49ec17b3436f481bd8942f289b392232c39f481aaab40586bf3ba809061f3b3a295bef2a0a49535f29aab9d6677f39cb195f2ac9f90'],
            ['f39aaea766d77df38da6bb6ee686bef2979688f398969133a5ec8a9cf18a8cbe71', 'f39aaea766d77df38da6bb6ee686bef2979688f398969133a5ec8a9cf18a8cbe71'],
            ['4259aef09cbd807f4ef393ababf3aeb1a6f09892ba6f344af385b88e', '4259aef09cbd807f4ef393ababf3aeb1a6f09892ba6f344af385b88e'],
            ['f0b68e86f19cacb0f1a3ba90a4f1a2989cf29f96b1f0ab96ad49e998aff19c9e99e7f286a299596e7d83b97765f1b0baace5a2b0', 'f0b68e86f19cacb0f1a3ba90a4f1a2989cf29f96b1f0ab96ad49e998aff19c9e99e7f286a299596e7db97765f1b0baace5a2b0'],
            ['f0a79988f199a0bb669ff393b99040f4888581f3bcaca569f0a7b69f45f383b8ba96f4869e827929f1b09491dcf1a98e8b', 'f0a79988f199a0bb66'],
            ['5c90800b415d7e705061f2b1aca2f096a2b56de8bfbd3da279f3b098b55b667bf190a9ad', '5c'],
            ['45f2b289af085d33f3b58e918b6af2b0b8ac5cf29ab09478f193919608f291a4bff38ca7bff19c96aa4e7cf480a2aa4d7df3b8978675f1be9ea2f28aaf99', '45f2b289af085d33f3b58e916af2b0b8ac5cf29ab09478f193919608f291a4bff38ca7bff19c96aa4e7cf480a2aa4d7df3b8978675f1be9ea2f28aaf99'],
            ['29ee10f0a78c9f2e55c8f0a0b4b0b56cbb4979d8', '29ee10f0a78c9f2e55c8f0a0b4b0b56cbb4979d8'],
            ['654f8e7e', '654f7e'],
            ['252f8e43f183b3af62663ef0a698a4f2b5a5bd45f0b09e9df0a8b0a7c8', '252f43f183b3af62663ef0a698a4f2b5a5bd45f0b09e9df0a8b0a7c8'],
            ['ddf1b7958a4bd32081f481bf92f188bc8ff1b1a8a3f3b08ba4ebbba4b5e8bc884165f099879af39bac8cf185a89e3a77', 'ddf1b7958a4bd320f481bf92f188bc8ff1b1a8a3f3b08ba4ebbba4b5e8bc884165f099879af39bac8cf185a89e3a77'],
            ['4af2ad8a92f48ba6b0f198b2aff09d9f8e22293279e9a384f48fbf91cf317957f39ab7a0f299bca3f09287a770', '4af2ad8a92f48ba6b0f198b2aff09d9f8e22293279e9a384f48fbf91cf317957f39ab7a0f299bca3f09287a770'],
            ['767cf48090954af18a848ef0a18f9540f1a3b9a88764f48194913465f18fb8894df2bfa4bdf48c9ca75e6527f46460', '767cf48090954af18a848ef0a18f9540f1a3b9a864f48194913465f18fb8894df2bfa4bdf48c9ca75e6527f46460'],
            ['daf3aba2ac73f09aaea85ff3a2ba8ef399a8b0f39f9fa253f38eada4f39e9d8e', 'daf3aba2ac73f09aaea85ff3a2ba8ef399a8b0f39f9fa253f38eada4f39e9d8e'],
            ['56f3a3acabf1a193a6f1b19e8c203d77', '56f3a3acabf1a193a6f1b19e8c203d77'],
            ['f2b193a3e88e896922f0a2b383f1abada905b7f19187aaf397a5baf18aa1b36df0aebda67bd0b8f1b0b1ad4a', 'f2b193a3e88e896922f0a2b383f1abada905b7f19187aaf397a5baf18aa1b36df0aebda67bd0b8f1b0b1ad4a'],
            ['f0a1bd8df29da4bd5df39b9796e5b6b9f281bf9fecbb8b4290f28cbd88f097ae852c65383d28f0a3bfa562f29697b0292848f280bc8df1b78488e5b58dcc435b', 'f0a1bd8df29da4bd5df39b9796e5b6b9f281bf9fecbb8b42'],
            ['f3b09d8c5e424af2999e9120f38e8f8f40f0b59a9ef3bd9b94f1a5a7ba7d70f48283944f56677960332069b4', 'f3b09d8c5e424af2999e9120f38e8f8f40f0b59a9ef3bd9b94f1a5a7ba7d70f48283944f56677960332069b4'],
            ['495348ecf2b7aaa4f3a59c9672f3b2a4b65ce6f1a69fbc47f0a380aa', '495348ecf2b7aaa4f3a59c9672f3b2a4b65ce6f1a69fbc47f0a380aa'],
            ['2e19c62a694a3d2e20f289b9bc43', '2e19c62a694a3d2e20f289b9bc43'],
            ['f66ef3bab8a87a63240de0bea270', 'f66ef3bab8a87a63240de0bea270'],
            ['54f1b6b0b842f1aaacaaf28aa697715af38b8f9a65e39989eba3a5a549f18dbe86f09682ab', '54f1b6b0b842f1aaacaaf28aa697715af38b8f9a65e39989eba3a5a549f18dbe86f09682ab'],
            ['f3be9d924e51', 'f3be9d924e51'],
            ['456441703232f3888cb2f0a894a85ef397bc9b', '456441703232f3888cb2f0a894a85ef397bc9b'],
            ['4d35f28bae902968327af3939285f18fb7b0f3a4ae816bf395b5a8f1bc969432f1a2bc80f1bf9b88f1aba68d', '4d35f28bae902968327af3939285f18fb7b0f3a4ae816bf395b5a8f1bc969432f1a2bc80f1bf9b88f1aba68d'],
            ['f486a9bd6d625172f0abb09c676b652c', 'f486a9bd6d625172f0abb09c676b652c'],
            ['745cf2869bbe', '745cf2869bbe'],
            ['3af3bcadb26b66f389b1855a297e', '3af3bcadb26b66f389b1855a297e'],
            ['595c6b62f1bd9baf70f2a5a3af4441ace42470f3bc9eaaf29a978a', '595c6b62f1bd9baf70f2a5a3af4441ace42470f3bc9eaaf29a978a'],
            ['29f099809675f2bd8d85f2b9b8bbf0919a858f', '29f099809675f2bd8d85f2b9b8bbf0919a85'],
            ['5121f094a0b2f18aa588f399b48ef483b0a2bcf09182a5f3868c9b2fc3f19d88a71d3cf1a4b4a8324ff38a8191255424f3b28b8ff38798ae1e2ca6f38b93a847', '5121f094a0b2f18aa588f399b48ef483b0a2bcf09182a5f3868c9b2fc3f19d88a71d3cf1a4b4a8324ff38a8191255424f3b28b8ff38798ae1e2ca6f38b93a847'],
            ['f18dad99582bf392b3bcf2b6b5adf09ba59d29e2b08945f096a0baf1a5909fe58297f3f2849084f285baa522f0a482a9f1908d85c74ff28e9d90f48c95ae', 'f18dad99582bf392b3bcf2b6b5adf09ba59d29e2b08945f096a0baf1a5909fe58297f3f2849084f285baa522f0a482a9f1908d85c74ff28e9d90f48c95ae'],
            ['d14b493302f1a2bfbbf38c8fad3a', 'd14b493302f1a2bfbbf38c8fad3a']
        ];
        self::assertCount(60, $corpus);
        foreach ($corpus as [$in, $out]) {
            self::assertSame($out, \bin2hex(Ansi::strip((string) \hex2bin($in))), 'input ' . $in);
        }
    }
}
