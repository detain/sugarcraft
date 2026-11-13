<?php

/**
 * Brazilian Portuguese translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'arquivo SQLite não encontrado: {path}',
    'complete.in_literal' => 'nada a completar dentro de uma string ou comentário',
    'complete.nothing' => 'nada a completar — digite o início de uma palavra-chave',
    'complete.none_keyword' => 'nenhuma palavra-chave completa "{word}"',
    'complete.none_table' => 'nenhuma tabela completa "{word}"',
    'complete.none_column' => 'nenhuma coluna completa "{word}"',
    'complete.none_qualified' => 'nenhuma coluna de {qualifier} completa "{word}"',
    'complete.list' => '{count} completam "{word}": {list}{more}',
    'complete.done' => 'completado {word} → {insert}',
    'complete.inserted' => 'inserido {insert}',
    'edit.refuse_readonly' => 'sessão somente leitura — a edição está desativada',
    'edit.refuse_no_table' => 'abra primeiro a prévia de uma tabela — resultados de consulta não podem ser editados no local',
    'edit.refuse_no_rows' => 'nenhuma linha sob o cursor',
    'edit.refuse_no_pk' => 'esta tabela não tem chave primária — as linhas não podem ser endereçadas com segurança; escreva o comando no painel de consulta',
    'edit.refuse_pk_hidden' => 'a chave primária desta linha é NULL ou um marcador binário — não pode ser endereçada',
    'edit.refuse_binary' => 'células binárias ou grandes não são editáveis aqui — use um comando no painel de consulta',
    'edit.refuse_schema' => 'não foi possível ler o esquema de {table} — edição recusada',
    'edit.prompt_cell' => 'editar {table}.{column} = {value}  (enter pré-visualiza · ctrl+n NULL · esc cancela)',
    'edit.prompt_insert' => 'nova linha em {table} — {column} ({type}) [{n}/{total}] = {value}  (enter próximo · vazio = DEFAULT · ctrl+n NULL · esc cancela)',
    'edit.prompt_confirm' => '{sql}  (enter confirma · esc cancela)',
    'edit.cancelled' => 'edição cancelada',
    'edit.unchanged' => 'valor inalterado — nada gravado',
    'edit.done_update' => '1 linha atualizada em {table}',
    'edit.done_insert' => '1 linha inserida em {table}',
    'edit.done_delete' => '1 linha excluída de {table}',
    'edit.none_changed' => 'nenhuma linha alterada — o valor já estava lá ou a linha sumiu; prévia atualizada',
    'edit.failed' => 'falha na gravação — revertida, nada mudou: {reason}',
    'edit.too_many' => 'recusado: o comando afetaria {count} linhas — revertido, nada mudou',
];
