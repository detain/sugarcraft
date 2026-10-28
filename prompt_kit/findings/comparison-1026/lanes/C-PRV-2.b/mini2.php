<?php
declare(strict_types=1);
final class Msg { public function __construct(string $c = '', array $t = [], array $thinkingBlocks = []) {} }
function req(array $m, string $model = 'x', ?string $e = null): array { return [$m, $model, $e]; }
function complete(array $r): string { return 'ok'; }
final class T {
    public function a(): void {
        complete(req([
            'user',
            new Msg(
                'answered',
                [],
                thinkingBlocks: [
                    ['a'],
                ],
            ),
        ]), model: 'o3-mini')));
    }
}
