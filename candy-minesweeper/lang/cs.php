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
    'board.rows_shape_mismatch' => 'data mřížky neodpovídají desce {width}×{height}',
    'custom.min_rows' => 'řádků musí být alespoň 2',
    'custom.min_cols' => 'sloupců musí být alespoň 2',
    'custom.max_rows' => 'řádků musí být nejvýše 50',
    'custom.max_cols' => 'sloupců musí být nejvýše 50',
    'custom.min_mines' => 'je vyžadováno alespoň 1 mina',
    'custom.max_mines' => 'příliš mnoho min — nechte bezpečnou výchozí oblast',
    'custom.min_area'            => 'vlastní deska potřebuje alespoň 10 polí (řádky × sloupce)',
    'custom.label' => '{width}×{height} · {mines} min',
    'ui.status' => 'miny: {mines}  ·  vlajky: {flags}  ·  zbývá: {remaining}  ·  čas: {time}',
    'ui.status_stats' => '{preset}  ·  vlajky: {flags}  ·  zbývá: {remaining}  ·  čas: {time}  ·  nejlepší: {best}',
    'ui.preset_easy' => 'snadný',
    'ui.preset_standard' => 'standardní',
    'ui.preset_medium' => 'střední',
    'ui.preset_expert' => 'expert',
    'ui.boom' => '💥 bouch — stiskni r pro restart · n pro jiné pole',
    'ui.cleared' => '★ vyčištěno — stiskni r pro novou hru · n pro jiné pole',
    'ui.help_rules' => 'číslo = miny v okolí · označ podezřelé · odkryj vše bezpečné',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  pohyb  ·  mezerník / enter  odkrytí',
    'ui.help_board' => 'levé tlačítko  odkryt · f / pravé  vlajka · c / prostřední  okolí',
    'ui.help_loss' => '{mark} špatná vlajka · * mine přehlédnutá',
    'ui.help_session' => 'r  restart  ·  S  uložit  ·  L  načíst  ·  q / Esc / Ctrl+C  konec',
    'ui.saved' => 'Hra uložena',
    'ui.restarted' => 'Deska restartována',
    'ui.loaded' => 'Hra načtena',
    'ui.no_save' => 'Žádná uložená hra k načtení',
    'ui.load_refused' => 'Uložení zamítnuto (nečitelné nebo padělané)',
    'ui.save_failed' => 'Uložení selhalo',
    'ui.chord_not_ready' => 'pro odkrytí okolí musí vlajky odpovídat číslu',
    'ui.confirm_quit' => 'stiskni znovu q pro konec — hra není uložena',
    'ui.resume' => 'Uložená hra čeká — stiskni L pro načtení',
    'ui.new_best' => 'NOVÝ REKORD',
    'ui.beat_best' => '{prev} → NOVÝ REKORD',
    'ui.picker_title' => 'vyber pole',
    'ui.picker_custom' => 'vlastní',
    'ui.picker_hint' => '1-4 start · c vlastní pole · q / Esc zpět',
    'ui.form_width' => 'šířka',
    'ui.form_height' => 'výška',
    'ui.form_mines' => 'miny',
    'ui.form_hint' => 'zadej číslice · enter další · enter u min startuje · esc zpět',
    'ui.fit_notice' => 'candy-minesweeper potřebuje terminál vysoký {need} řádků; tento má {have}. Zvětši okno — hra se obnoví, jakmile se board vejde. Q skončí.',
];
