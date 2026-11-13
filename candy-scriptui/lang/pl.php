<?php

/**
 * Polish translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'Pusty kolor.',
    'style.unrecognised_color' => 'Nierozpoznany kolor: {value} — Dozwolone: {allowed}.',
    'style.hex_digit_count' => "Kolor szesnastkowy musi mieć 3 lub 6 cyfr szesnastkowych (np. '#f80' lub '#ff8800'); otrzymano '{value}'.",
    'style.padding_token_int' => "Token padding/margin musi być liczbą całkowitą; otrzymano: '{token}'.",
    'style.padding_count'     => 'Padding/margin wymaga 1, 2 lub 4 liczb całkowitych; otrzymano: {count}.',
    'style.bad_entry'         => "--style wpisy muszą mieć formę 'klucz=wartość' lub 'element.prop=wartość'; otrzymano: '{raw}'.",
    'style.unknown_prop'      => "Nieznana właściwość stylu: '{prop}'.",
    'process.spawn_failed'    => 'Nie udało się uruchomić procesu potomnego',
    'border.unknown'          => 'Nieznany styl obramowania: {name}.',
    'log.unknown_level'       => 'Nieznany poziom logowania: {name}.',
    'log.encode_failed' => 'Nie udało się zakodować wpisu logu do json.',
    'log.bad_format' => 'Nieprawidłowy ciąg --format: {format}.',
    'spinner.unknown_style'   => 'Nieznany styl spinera: {name}.',
    'spinner.windows_unsupported' => 'Spin nie może uruchamiać poleceń na natywnym systemie Windows: backend procesów nie jest tu obsługiwany — użyj zamiast tego WSL.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'Nieznany motyw: {name}.',
    'io.read_failed' => 'Nie można odczytać pliku: {path}.',
    'io.write_failed' => 'Nie można zapisać do pliku: {path}.',
    'runtime.no_terminal' => '{command} wymaga interaktywnego terminala; brak dostępnego terminala sterującego.',
    'runtime.bad_timeout' => "Nieprawidłowa wartość --timeout '{value}' — oczekiwano nieujemnej liczby sekund lub czasu jak 5s/250ms/1m30s (0 wyłącza limit).",
    'runtime.bad_number' => "Nieprawidłowa wartość {flag}: '{value}' — oczekowano liczby całkowitej nieujemnej.",
    'runtime.pcntl_missing' => 'ext-pcntl nie jest załadowany: SIGINT/SIGTERM zakończą ten program bez mapowania kodów wyjścia 130/143.',
    'runtime.unknown_choice' => "Nieprawidłowa wartość dla {option}: '{value}'. Dozwolone: {allowed}.",
    'hints.choose' => '↑/↓ ruch · / filtruj · Enter wybierz · Esc anuluj',
    'hints.choose.multi' => '↑/↓ ruch · Spacja zaznacz · / filtruj · Enter wybierz · Esc anuluj',
    'hints.filter' => 'filtruj · ↑/↓ ruch · Tab zaznacza · Enter · Esc',
    'hints.pager' => '↑/↓ j/k ruch · ←/→ h/l przewijaj · PgUp/b PgDn/Spacja strona · Ctrl+U/D poł.',
    'hints.pager.more' => 'g/G lub Home/End początek/koniec · q/Esc wyjdź',
    'format.template_unresolved' => 'Szablon: {count} symboli zastępczych rozwinięto do pustych: {names} — zmienna musi być ustawiona i dozwolona przez --allow-env.',
    'runtime.empty_input' => 'Wejście nie zawierało linii; nic do zrobienia.',
    'runtime.empty_input_filter' => 'Brak czego filtrować; wejście nie zawierało linii.',
    'runtime.empty_input_pager' => 'Brak treści do przeglądania; wejście nie zawierało linii.',
    'runtime.empty_items' => 'Nie podano elementów — przekaż je jako argumenty lub opcją --item.',
    'runtime.conflicting_flags' => 'Opcji {first} i {second} nie można łączyć z różnymi wartościami.',
    'runtime.empty_selection' => 'Nie wybrano żadnego elementu — naciśnij Spację, aby zaznaczyć co najmniej jeden element przed Enterem.',
    'runtime.legacy_env'        => '{var} jest przestarzałe; ustaw zamiast tego {replacement}.',
];
