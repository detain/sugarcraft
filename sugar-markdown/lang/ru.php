<?php

/**
 * Russian translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'невозможно прочитать файл темы: {path}',
    'theme.json_invalid'           => 'некорректный JSON в файле темы',
    'theme.json_object'            => 'JSON темы должен декодироваться в объект',
    'theme.bad_color'              => 'некорректное описание цвета: {spec}',
    'renderer.unknown_style'       => 'неизвестный стандартный стиль: {name}',
    'renderer.stream_invalid'      => 'write() требует ресурс потока',
    'renderer.stream_not_writable' => 'write() требует доступный для записи поток; получен режим "{mode}"',
    'renderer.write_failed'        => 'не удалось записать {bytes} байт в выходной поток',
    'renderer.chunk_invalid'       => 'фрагменты stream() должны быть строками',
    'sink.closed'                  => 'приёмник закрыт',
    'sink.parent_missing'          => 'каталог вывода не существует: {dir}',
    'sink.path_unwritable'         => 'путь вывода недоступен для записи: {path}',
    'sink.open_failed'             => 'не удалось открыть путь вывода: {path}',
    'sink.flush_failed'            => 'не удалось сбросить (flush) выходной поток',
    'writer.feed_after_close'      => 'feed() вызван после close() — запись завершена',
];
