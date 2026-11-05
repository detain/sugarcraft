<?php

/**
 * French UI strings for candy-tetris.
 *
 * Keys, key order and every {placeholder} / sprintf token mirror
 * lang/en.php, which stays the source of truth. Flag spellings in the
 * usage screen are kept verbatim, so a refusal is never half-translated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card' => 'score :  %d
lignes : %d
niveau : %d',
    'help_card'  => 'déplacer
rotation horaire
rotation anti-hor.
chute lente
chute rapide
réserve
pause
relancer (fin)
quitter',
    'next_label' => 'suivante :',
    'hold_label' => 'réserve :',
    'paused'     => '[ en pause — appuyez sur p pour reprendre ]',

    // Game-over banner
    'over.title'                => 'PARTIE TERMINÉE',
    'over.final_score'          => 'score final : {points}',
    'over.restart_hint'         => 'appuyez sur r pour relancer — sur q pour quitter',
    'over.reason'               => 'motif : {reason}',
    'over.reason.spawn_blocked' => 'plus de place pour la pièce suivante',
    'over.reason.top_out'       => 'la pile a atteint le plafond',
    'over.reason.crushed'       => 'écrasé par les déchets',

    // On-frame cues
    'hold_used'  => '(utilisée)',
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B armé',
    'over.best'    => 'record : {points}',
    'over.new_best' => 'nouveau record !',

    // Versus renderer
    'label.player'   => 'JOUEUR',
    'label.computer' => 'ORDINATEUR',
    'win.player'     => 'VOUS GAGNEZ !',
    'win.computer'   => "L'ORDINATEUR GAGNE !",
    'over.quit_hint' => 'appuyez sur q pour quitter',
    'vs_keys'        => 'chute
réserve
pause
relance
quitter',
    'vs.record' => 'bilan : {wins} gagnés, {losses} perdus',
    'intro.title' => 'bienvenue dans candy-tetris',
    'intro.goal' => 'effacez des lignes - ne pas atteindre le sommet',
    'intro.press' => 'appuyez sur {keys} pour jouer',
    'intro.vs' => 'mode duel : candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris : mode inconnu "{flag}"',
    'help_text'    => "candy-tetris - clone de Tetris\n\nUtilisation : candy-tetris [options]\n  (aucun)    Démarrer une partie solo\n  -v, --vs   Mode contre l'ordinateur\n  -h, --help Afficher cette aide\n  --version  Afficher la version\n"
];
