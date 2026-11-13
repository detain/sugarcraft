<?php

/**
 * French translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'score : {score}',
    'ui.best'                => ' record : {best}',
    'ui.new_best'            => ' 🏆 NOUVEAU RECORD : {best}',
    'ui.plain_new_best'      => ' NOUVEAU RECORD : {best}',
    'ui.crash_full'          => '💥 {why} — appuyez sur r pour rejouer · q pour quitter',
    'ui.crash_short'         => '💥 {why} — r rejouer · q quitter',
    'ui.crash_floor'         => 'r rejouer · q quitter',
    'ui.ready_full'          => 'espace/⏎/↑/w pour démarrer · q quitter',
    'ui.ready_short'         => 'espace pour démarrer · q quitter',
    'ui.live_full'           => 'espace/⏎/↑/w battement · r rejouer · q quitter',
    'ui.live_short'          => 'espace bat l’aile · q quitter',
    'ui.plain_crash_full'    => 'x {why} - appuyez sur r pour rejouer - q pour quitter',
    'ui.plain_crash_short'   => 'x {why} - r rejouer - q quitter',
    'ui.plain_crash_floor'   => 'r rejouer - q quitter',
    'ui.why_splat'            => 'splat',
    'ui.why_ceiling'          => 'touché le plafond',
    'ui.why_ground'           => 'touché le sol',
    'ui.why_pipe'             => 'touché un tuyau',
    'ui.plain_why_splat'      => 'splat',
    'ui.plain_why_ceiling'    => 'touché le plafond',
    'ui.plain_why_ground'     => 'touché le sol',
    'ui.plain_why_pipe'       => 'touché un tuyau',
    'ui.plain_ready_full'    => 'espace/Enter/up/w pour demarrer . q quitter',
    'ui.plain_ready_short'   => 'espace pour demarrer . q quitter',
    'ui.plain_live_full'     => 'espace/Enter/up/w battement . r rejouer . q quitter',
    'ui.plain_live_short'    => 'espace bat l\'aile . q quitter',
    'ui.quit_hint' => 'appuyez sur q pour quitter.',
    'ui.quarantine'           => '⚠ L’ancien classement était illisible — archivé sous {file}',
    'ui.plain_quarantine'     => 'x L’ancien classement était illisible - archivé sous {file}',
    'notty.line1'            => 'candy-flappy a besoin d’un terminal capable de restituer sa sortie.',
    'notty.line2'            => 'stdout n’est pas un terminal interactif ou TERM=dumb a été détecté.',
    'size.notice'            => 'candy-flappy exige un terminal {need} ; celui-ci fait {have}.',
    'size.resume'            => 'Redimensionnez la fenêtre et le jeu reprend ici même.',
];
