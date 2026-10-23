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
    'config.yaml_unparseable'  => 'wishlist yaml: riga non analizzabile: {line}',
    'config.entry_missing_field' => 'voce wishlist con campo obbligatorio mancante: name o host',
    'config.field_not_scalar'  => 'voce wishlist: il campo [{field}] deve essere uno scalare, ottenuto {type}',
    'config.unreadable'       => 'impossibile leggere la configurazione wishlist: {path}',
    'config.home_unset'       => 'impossibile espandere ~ senza $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: raccolta di flusso inline non supportata: {value} (usa un elenco annidato)',

    // endpoint security
    'endpoint.option_injection' => 'endpoint wishlist: il campo [{field}] ha un valore non sicuro con trattino iniziale [{value}]',
    'endpoint.port_invalid'     => 'endpoint wishlist [{host}]: porta non valida [{port}] — deve essere un intero da 1 a 65535',

    'cli.usage'         => 'Uso: candy-ssh-launcher [--config <percorso>] [--ssh <binario-ssh>] [--list] [-h|--help]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: argomento sconosciuto: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: nessuna configurazione trovata. Passare --config <percorso>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: annullato.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binario ssh non trovato o non eseguibile: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} richiede un valore',
    'cli.no_endpoints'   => 'candy-ssh-launcher: nessun endpoint in {path}',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtro: ',
    'picker.no_matches'  => '  (nessuna corrispondenza)',
    'picker.help'        => '  ↑/↓ seleziona · Invio connetti · Esc esci · digita per filtrare',
    'picker.count'       => '  ({shown} di {total})',
];
