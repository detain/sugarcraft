<?php

/**
 * Polish translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'wykonanie {bin} nie powiodło się',
    'config.not_found'         => 'Nie znaleziono konfiguracji wishlist: {path}',
    'config.json_top_level'    => 'wishlist json: wartość najwyższego poziomu musi być tablicą',
    'config.json_entry_object' => 'wishlist json: każdy wpis musi być obiektem',
    'config.yaml_continuation' => "wishlist yaml: Continuation przed dowolnym blokiem '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: nierozpoznawalna linia {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: mapowanie bloku pod `{field}:` w linii {line}: {found} — to pole musi być listą elementów `- value`',
    'config.yaml_unterminated_quote' => 'wishlist yaml: niezamknięty cudzysłów w wartości: {found}',
    'config.entry_missing_field' => 'wpis wishlist #{index}: brakuje wymaganego pola: {field}',
    'config.unknown_key' => 'nieznane pole `{key}` zignorowane — nieudokumentowany klucz',
    'config.unknown_key_nearmiss' => 'nieznane pole `{key}` zignorowane — chodziło o `{field}`?',
    'config.duplicate_name' => 'zdublowana nazwa endpointu `{name}` — wszystkie wiersze się ładują; nazwy są identyfikatorami',
    'config.field_not_scalar'  => 'wpis wishlist: pole [{field}] musi być skalarem, otrzymano {type}',
    'config.too_large'          => 'plik konfiguracji wishlistu przekracza limit {limit} bajtów: {path}',
    'config.list_too_long'      => 'wpis wishlistu: pole [{field}] zawiera więcej niż {limit} elementów dozwolonych przez loader',
    'config.field_not_list'       => 'wpis wishlistu: pole [{field}] musi być listą wartości, nie mapą klucz-wartość',
    'config.encoding_utf16'       => 'plik {path} ma kodowanie UTF-16 — zapisz konfigurację ponownie jako UTF-8',
    'config.unreadable'       => 'nie można odczytać konfiguracji wishlist: {path}',
    'config.home_unset'       => 'nie można rozwinąć ~ bez $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: kolekcja flow w linii nie jest obsługiwana: {value} (użyj listy zagnieżdżonej)',

    // endpoint security
    'endpoint.option_injection' => 'punkt końcowy wishlist: pole [{field}] zawiera niebezpieczną wartość zaczynającą się od myślnika [{value}]',
    'endpoint.host_empty' => 'punkt `{name}` ma pusty host — wiersz do wyboru wymaga nazwy hosta',
    'endpoint.name_empty' => 'endpoint ma pustą nazwę — wybieralny wiersz wymaga nazwy',
    'endpoint.option_empty' => 'punkt `{name}` ma pustą pozycję options — pusta wartość -o jest odrzucana',
    'endpoint.port_invalid'     => 'punkt końcowy wishlist [{host}]: nieprawidłowy port [{port}] — musi to być liczba całkowita od 1 do 65535',

    'cli.usage'         => 'Użycie: candy-ssh-launcher [--config <ścieżka>] [--ssh <ssh-binarny>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: nieznany argument: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: nie znaleziono konfiguracji. Utwórz ~/.config/wishlist.yml (patrz szybki start w README) lub podaj --config <ścieżka>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: anulowano.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: plik binarny ssh nie znaleziony lub nie wykonywalny: {bin} — użyj --ssh <ścieżka>, jeśli ssh znajduje się gdzieś indziej',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} wymaga wartości',
    'cli.no_endpoints'   => 'candy-ssh-launcher: brak punktów końcowych w {path}',
    'cli.not_a_terminal' => 'wybieranie wymaga interaktywnego terminala — stdin i stdout muszą być TTY. Użyj --list dla wyjścia maszynowego.',
    'cli.config_unowned'   => 'candy-ssh-launcher: odrzucono konfigurację należącą do innego użytkownika: {path} — konfiguracje są danymi zaufanymi; patrz Bezpieczeństwo w README.',
    'cli.config_writable'  => 'candy-ssh-launcher: odrzucono konfigurację zapisywalną przez grupę lub wszystkich: {path} — ustaw 0644 albo 0600 lub podaj jawnie --config.',
    'cli.project_local'    => 'candy-ssh-launcher: używana lokalna wishlist projektu: {path}',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtr: ',
    'picker.no_matches'  => '  (brak dopasowań — Backspace lub Esc)',
    'picker.help'        => '↑/↓ wybierz · Enter połącz · Esc wyjdź · pisz, aby filtrować',
    'picker.help_keys'   => '⌫ usuń · Ctrl+U/W wyczyść · PgUp/PgDn strona · Home/End skok',
    'picker.count'       => '  ({shown} z {total})',
];
