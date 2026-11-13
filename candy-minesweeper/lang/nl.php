<?php

/**
 * Dutch translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'Bord is te klein',
    'board.minecount_out_of_range' => 'aantal mijnen buiten bereik',
    'board.rows_shape_mismatch' => 'rijstructuur komt niet overeen met breedte/hoogte',
    'custom.min_rows' => 'rijen moeten minimaal 2 zijn',
    'custom.min_cols' => 'kolommen moeten minimaal 2 zijn',
    'custom.max_rows' => 'rijen mogen maximaal 50 zijn',
    'custom.max_cols' => 'kolommen mogen maximaal 50 zijn',
    'custom.min_mines' => 'minimaal 1 mijn vereist',
    'custom.max_mines' => 'te veel mijnen — laat een veilig startgebied over',
    'ui.status' => 'mijnen: {mines}  ·  vlaggen: {flags}  ·  over: {remaining}  ·  tijd: {time}',
    'ui.boom' => '💥 boem — druk op r om opnieuw te starten',
    'ui.cleared' => '★ gewonnen — druk op r om opnieuw te spelen',
    'ui.help_rules' => 'een getal telt de mijnen eromheen',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  bewegen  ·  spatie / enter  onthullen',
    'ui.help_board' => 'klik links  openen · f / klik rechts  vlaggen · c / middelklik  rondom',
    'ui.help_session' => 'r  herstart  ·  S  opslaan  ·  L  laden  ·  q / Esc / Ctrl+C  afsluiten',
    'ui.saved' => 'Spel opgeslagen',
    'ui.restarted' => 'Bord opnieuw gestart',
    'ui.loaded' => 'Spel geladen',
    'ui.no_save' => 'Geen opgeslagen spel om te laden',
    'ui.load_refused' => 'Opslag geweigerd (onleesbaar of gemanipuleerd)',
    'ui.save_failed' => 'Opslaan mislukt',
    'ui.resume' => 'Er wacht een opgeslagen spel — druk op L om het te laden',
    'ui.new_best' => 'NIEUW RECORD',
    'ui.fit_notice' => 'candy-minesweeper heeft een terminal van {need} regels nodig; deze heeft {have}. Vergroot het venster — het spel hervat zodra het past. Druk op q om te stoppen.',
];
