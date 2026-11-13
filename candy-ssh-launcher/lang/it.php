<?php

/**
 * Italian translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'esecuzione di {bin} fallita',
    'config.not_found'         => 'Configurazione wishlist non trovata: {path}',
    'config.json_top_level'    => 'wishlist json: il valore di primo livello deve essere un array',
    'config.json_entry_object' => 'wishlist json: ogni voce deve essere un oggetto',
    'config.yaml_continuation' => "wishlist yaml: continuazione prima di qualsiasi blocco '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: riga non analizzabile {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: mappatura a blocchi sotto `{field}:` alla riga {line}: {found} — questo campo deve essere un elenco di voci `- value`',
    'config.yaml_unterminated_quote' => 'wishlist yaml: virgoletta non chiusa nel valore: {found}',
    'config.entry_missing_field' => 'voce wishlist #{index}: campo obbligatorio mancante: {field}',
    'config.unknown_key' => 'voce wishlist: campo sconosciuto `{key}` ignorato — chiave non documentata',
    'config.unknown_key_nearmiss' => 'campo sconosciuto `{key}` ignorato — intendevi `{field}`?',
    'config.duplicate_name' => 'wishlist: nome endpoint duplicato `{name}` — tutte le righe vengono caricate; i nomi sono identificatori',
    'config.field_not_scalar'  => 'voce wishlist: il campo [{field}] deve essere uno scalare, ottenuto {type}',
    'config.too_large'          => 'la configurazione wishlist supera il limite di {limit} byte: {path}',
    'config.list_too_long'      => 'voce wishlist: il campo [{field}] contiene più dei {limit} elementi consentiti dal caricatore',
    'config.unreadable'       => 'impossibile leggere la configurazione wishlist: {path}',
    'config.home_unset'       => 'impossibile espandere ~ senza $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: raccolta di flusso inline non supportata: {value} (usa un elenco annidato)',

    // endpoint security
    'endpoint.option_injection' => 'endpoint wishlist: il campo [{field}] ha un valore non sicuro con trattino iniziale [{value}]',
    'endpoint.host_empty' => "l'endpoint `{name}` ha un host vuoto — una riga selezionabile ha bisogno di un hostname",
    'endpoint.name_empty' => "l'endpoint ha un nome vuoto — una riga selezionabile richiede un nome",
    'endpoint.option_empty' => "l'endpoint `{name}` ha una voce options vuota — un valore -o vuoto è rifiutato",
    'endpoint.port_invalid'     => 'endpoint wishlist [{host}]: porta non valida [{port}] — deve essere un intero da 1 a 65535',

    'cli.usage'         => 'Uso: candy-ssh-launcher [--config <percorso>] [--ssh <binario-ssh>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: argomento sconosciuto: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: nessuna configurazione trovata. Creare ~/.config/wishlist.yml (vedi l\'avvio rapido nel README) oppure passare --config <percorso>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: annullato.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binario ssh non trovato o non eseguibile: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} richiede un valore',
    'cli.no_endpoints'   => 'candy-ssh-launcher: nessun endpoint in {path}',
    'cli.not_a_terminal' => 'candy-ssh-launcher: il selettore richiede un terminale interattivo: stdin e stdout devono essere TTY. Usa --list per output machine-readable.',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtro: ',
    'picker.no_matches'  => '  (nessuna corrispondenza — Backspace o Esc)',
    'picker.help'        => '↑/↓ seleziona · Invio connetti · Esc esci · digita per filtrare',
    'picker.help_keys'   => '⌫ elimina · Ctrl+U/W cancella · PagSu/PagGiù pagina · Home/Fine salta',
    'picker.count'       => '  ({shown} di {total})',
];
