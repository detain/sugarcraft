<?php

/**
 * Russian translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Использование: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: невозможно найти composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: неизвестный параметр --{flag}',
    'cli.bad_value'   => 'candy-gifview: недопустимое значение \'{value}\' для {flag} (ожидается положительное число)',
    'cli.too_many'    => 'candy-gifview: неожиданный аргумент \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} не принимает значение',
    'cli.bad_preset' => 'candy-gifview: неизвестный пресет \'{preset}\' (ожидается solid или density)',
    'cli.no_frames' => 'candy-gifview: нет кадров для воспроизведения в \'{path}\'',
    'cli.cannot_play' => 'candy-gifview: не удалось воспроизвести \'{path}\': {reason}',
    'cli.reason_frames_over' => 'анимация превышает предел в {max} кадров; загрузка отклонена',
    'cli.reason_bytes_over' => 'файл превышает предел в {max} MiB',
    'cli.reason_cells_over' => 'анимации нужно больше бюджета в {max} ячеек для этого окна',
    'cli.duration_static' => 'candy-gifview: --duration требует не менее двух кадров; файл декодируется в {count} кадров',
    'cli.need_gd' => 'candy-gifview: для декодирования GIF требуется ext-gd (установите php-gd)',
    'cli.decoding' => 'candy-gifview: декодирование \'{path}\'...',
    'cli.truncated' => 'candy-gifview: показаны первые {max} кадров — более длинные анимации этот просмотрщик не читает',
];
