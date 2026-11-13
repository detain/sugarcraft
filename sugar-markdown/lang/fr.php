<?php

/**
 * French translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'impossible de lire le fichier de thème : {path}',
    'theme.file_too_large'         => 'le fichier de thème dépasse le plafond de {bytes} octets : {path}',
    'theme.json_invalid'           => 'JSON invalide dans le fichier de thème',
    'theme.json_object'            => 'le thème JSON doit décoder vers un objet',
    'theme.bad_color'              => 'spécification de couleur invalide : {spec}',
    'renderer.unknown_style'       => 'style standard inconnu : {name} — {styles}',
    'renderer.stream_invalid'      => 'write() exige une ressource de flux',
    'renderer.stream_not_writable' => 'write() exige un flux inscriptible, mode reçu « {mode} »',
    'renderer.write_failed'        => 'échec de l\'écriture de {bytes} octets dans le flux de sortie',
    'renderer.chunk_invalid'       => 'les fragments de stream() doivent être des chaînes',
    'sink.closed'                  => 'le sink est fermé',
    'sink.parent_missing'          => 'le répertoire de sortie n\'existe pas : {dir}',
    'sink.path_unwritable'         => 'le chemin de sortie n\'est pas inscriptible : {path}',
    'sink.open_failed'             => 'échec de l\'ouverture du chemin de sortie : {path}',
    'sink.flush_failed'            => 'échec de la vidange du flux de sortie',
    'writer.feed_after_close'      => 'feed() appelé après close() — le writer est terminé',
    'blockstack.pop_empty' => 'la pile de blocs est vide',
    'theme.mapper_not_style' => 'Theme::mapStyles() attend que le mapper retourne un Style, reçu : {type}',
    'theme.with_slot_not_style' => 'Theme::with(): « {slot} » n\'est pas un emplacement Style ; emplacements valides : {slots}',
];
