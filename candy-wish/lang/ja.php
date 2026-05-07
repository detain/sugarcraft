<?php

/**
 * Japanese translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'php://stderr を開けません',
    'middleware.stderr_not_resource' => 'stderr はリソースである必要があります',
    'logger.cannot_open_target'      => 'ログターゲットを開けません：{target}',
    'logger.invalid_target'          => 'ロガーターゲットはパス、リソース、または null である必要があります',
    'bubbletea.bad_factory'          => 'BubbleTea ファクトリーは run() メソッドを持つオブジェクトを返す必要があります；取得：{got}',
];
