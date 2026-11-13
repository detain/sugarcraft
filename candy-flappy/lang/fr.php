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
    'ui.crash_full'          => '💥 splat — appuyez sur r pour rejouer, q pour quitter',
    'ui.crash_short'         => '💥 splat — r rejouer · q quitter',
    'ui.crash_floor'         => 'r rejouer · q quitter',
    'ui.ready_full'          => 'espace/⏎/↑/w pour démarrer · r rejouer · q quitter',
    'ui.ready_short'         => 'espace pour démarrer · q quitter',
    'ui.live_full'           => 'espace/⏎/↑/w battement · r rejouer · q quitter',
    'ui.live_short'          => 'espace bat l’aile · q quitter',
    'ui.plain_crash_full'    => 'x splat - appuyez sur r pour rejouer, q pour quitter',
    'ui.plain_crash_short'   => 'x splat - r rejouer - q quitter',
    'ui.plain_crash_floor'   => 'r rejouer - q quitter',
    'ui.plain_ready_full'    => 'espace/Enter/^/w pour demarrer . r rejouer . q quitter',
    'ui.plain_ready_short'   => 'espace pour demarrer . q quitter',
    'ui.plain_live_full'     => 'espace/Enter/^/w battement . r rejouer . q quitter',
    'ui.plain_live_short'    => 'espace bat l\'aile . q quitter',
    'ui.quit_hint' => 'appuyez sur q pour quitter.',
    'notty.line1'            => 'candy-flappy a besoin d’un terminal capable de restituer sa sortie.',
    'notty.line2'            => 'stdout n’est pas un terminal interactif ou TERM=dumb a été détecté.',
    'size.notice'            => 'candy-flappy exige un terminal {need} ; celui-ci fait {have}.',
    'size.resume'            => 'Redimensionnez la fenêtre et le jeu reprend ici même.',
];
