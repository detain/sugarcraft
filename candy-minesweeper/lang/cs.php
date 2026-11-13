<?php

/**
 * Czech translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'deska je příliš malá',
    'board.minecount_out_of_range' => 'počet min mimo rozsah',
    'board.rows_shape_mismatch' => 'tvar řádků neodpovídá šířce/výšce',
    'custom.min_rows' => 'řádků musí být alespoň 2',
    'custom.min_cols' => 'sloupců musí být alespoň 2',
    'custom.max_rows' => 'řádků musí být nejvýše 50',
    'custom.max_cols' => 'sloupců musí být nejvýše 50',
    'custom.min_mines' => 'je vyžadováno alespoň 1 mina',
    'custom.max_mines' => 'příliš mnoho min — nechte bezpečnou výchozí oblast',
    'ui.status' => 'miny: {mines}  ·  vlajky: {flags}  ·  zbývá: {remaining}  ·  čas: {time}',
    'ui.boom' => '💥 bouch — stiskněte r pro restart',
    'ui.cleared' => '★ vyčištěno — stiskněte r pro novou hru',
    'ui.help_rules' => 'číslo udává počet min v okolí',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  pohyb  ·  mezerník / enter  odkrytí',
    'ui.help_board' => 'levé tlačítko  odkryt · f / pravé  vlajka · c / prostřední  okolí',
    'ui.help_session' => 'r  restart  ·  S  uložit  ·  L  načíst  ·  q / Esc / Ctrl+C  konec',
    'ui.saved' => 'Hra uložena',
    'ui.restarted' => 'Plocha restartedova',
    'ui.loaded' => 'Hra načtena',
    'ui.no_save' => 'Žádná uložená hra k načtení',
    'ui.load_refused' => 'Uložení zamítnuto (nečitelné nebo padělané)',
    'ui.save_failed' => 'Uložení selhalo',
    'ui.resume' => 'Uložená hra čeká — stiskni L pro načtení',
    'ui.new_best' => 'NOVÝ REKORD',
    'ui.fit_notice' => 'candy-minesweeper potřebuje terminál vysoký {need} řádků; tento má {have}. Zvětšete okno — hra se obnoví, jakmile se board vejde. Q skončí.',
];
