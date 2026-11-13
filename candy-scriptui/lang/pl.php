<?php

/**
 * Polish translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'pusty kolor',
    'style.unrecognised_color' => 'nierozpoznany kolor: {value} — allowed: {allowed}',
    'style.hex_digit_count' => "kolor szesnastkowy musi mieć 3 lub 6 cyfr szesnastkowych (np. '#f80' lub '#ff8800'); otrzymano '{value}'",
    'style.padding_token_int' => "token padding/margin musi być liczbą całkowitą; otrzymano: '{token}'",
    'style.padding_count'     => 'padding/margin wymaga 1, 2 lub 4 liczb całkowitych; otrzymano: {count}',
    'style.bad_entry'         => "--style wpisy muszą mieć formę 'klucz=wartość' lub 'element.prop=wartość'; otrzymano: '{raw}'",
    'style.unknown_prop'      => "nieznana właściwość stylu: '{prop}'",
    'process.spawn_failed'    => 'nie udało się uruchomić procesu potomnego',
    'border.unknown'          => 'nieznany styl obramowania: {name}',
    'log.unknown_level'       => 'nieznany poziom logowania: {name}',
    'log.encode_failed' => 'nie udało się zakodować wpisu logu do json',
    'log.bad_format' => 'nieprawidłowy ciąg --format: {format}',
    'spinner.unknown_style'   => 'nieznany styl spinera: {name}',
    'spinner.windows_unsupported' => 'spin nie może uruchamiać poleceń na natywnym systemie Windows: backend procesów nie jest tu obsługiwany — użyj zamiast tego WSL.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'nieznany motyw: {name}',
    'io.read_failed' => 'nie można odczytać pliku: {path}',
    'io.write_failed' => 'nie można zapisać do pliku: {path}',
    'log.unknown_formatter' => 'nieznany --formatter: {name}',
    'completion.unknown_shell' => 'nieobsługiwana powłoka: {shell}',
    'confirm.unknown_default' => 'nieznana wartość --default: {value}',
    'cursor_mode.unknown' => 'nieznany tryb kursora: {mode}',
    'runtime.no_terminal' => '{command} wymaga interaktywnego terminala; brak dostępnego terminala sterującego.',
    'runtime.bad_timeout' => 'Nieprawidłowa wartość --timeout \'{value}\' — oczekowano liczby sekund nieujemnej (0 wyłącza limit).',
    'runtime.bad_number' => 'Nieprawidłowa wartość {flag}: \'{value}\' — oczekowano liczby całkowitej nieujemnej.',
    'runtime.pcntl_missing' => 'ext-pcntl nie jest załadowany: SIGINT/SIGTERM zakończą ten program bez mapowania kodów wyjścia 130/143.',
    'runtime.unknown_choice' => 'Nieprawidłowa wartość dla {option}: \'{value}\'. Dozwolone: {allowed}.',
    'hints.choose' => '↑/↓ przejdź · spacja przełącza (wiele) · enter wybierz · esc anuluj',
    'hints.filter' => 'wpisuj aby filtrować · ↑/↓ przejdź · tab przełącza (wiele) · enter wybierz · esc anuluj',
    'hints.pager' => 'space/PgDn w dół · PgUp/b do góry · g początek · G koniec · q wyjdź',
    'format.template_unresolved' => 'szablon: {count} symboli zastępczych rozwinięto do pustych: {names} — zmienna musi być ustawiona i dozwolona przez --allow-env.',
    'runtime.empty_input' => 'Wejście nie zawierało linii; nic do zrobienia.',
    'runtime.empty_input_filter' => 'brak czego filtrować; wejście nie zawierało linii.',
    'runtime.conflicting_flags' => 'Opcji {first} i {second} nie można łączyć z różnymi wartościami.',
    'runtime.empty_selection' => 'Nie wybrano żadnego elementu — naciśnij Spację, aby zaznaczyć co najmniej jeden element przed Enterem.',
];
