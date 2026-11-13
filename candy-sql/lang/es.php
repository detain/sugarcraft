<?php

/**
 * Spanish translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'archivo SQLite no encontrado: {path}',
    'complete.in_literal' => 'nada que completar dentro de una cadena o comentario',
    'complete.nothing' => 'nada que completar — escribe el inicio de una palabra clave',
    'complete.none_keyword' => 'ninguna palabra clave completa "{word}"',
    'complete.none_table' => 'ninguna tabla completa "{word}"',
    'complete.none_column' => 'ninguna columna completa "{word}"',
    'complete.none_qualified' => 'ninguna columna de {qualifier} completa "{word}"',
    'complete.list' => '{count} completan "{word}": {list}{more}',
    'complete.done' => 'completado {word} → {insert}',
    'complete.inserted' => 'insertado {insert}',
    'edit.refuse_readonly' => 'sesión de solo lectura — la edición está desactivada',
    'edit.refuse_no_table' => 'abre primero la vista previa de una tabla — los resultados de consultas no se pueden editar en sitio',
    'edit.refuse_no_rows' => 'no hay ninguna fila bajo el cursor',
    'edit.refuse_no_pk' => 'esta tabla no tiene clave primaria — las filas no se pueden direccionar con seguridad; escribe la sentencia en el panel de consulta',
    'edit.refuse_pk_hidden' => 'la clave primaria de esta fila es NULL o un marcador binario — no se puede direccionar',
    'edit.refuse_binary' => 'las celdas binarias o grandes no se pueden editar aquí — usa una sentencia en el panel de consulta',
    'edit.refuse_schema' => 'no se puede leer el esquema de {table} — edición rechazada',
    'edit.prompt_cell' => 'editar {table}.{column} = {value}  (enter previsualiza · ctrl+n NULL · esc cancela)',
    'edit.prompt_insert' => 'nueva fila en {table} — {column} ({type}) [{n}/{total}] = {value}  (enter siguiente · vacío = DEFAULT · ctrl+n NULL · esc cancela)',
    'edit.prompt_confirm' => '{sql}  (enter confirma · esc cancela)',
    'edit.cancelled' => 'edición cancelada',
    'edit.unchanged' => 'valor sin cambios — nada escrito',
    'edit.done_update' => 'actualizada 1 fila en {table}',
    'edit.done_insert' => 'insertada 1 fila en {table}',
    'edit.done_delete' => 'eliminada 1 fila de {table}',
    'edit.none_changed' => 'ninguna fila cambió — el valor ya estaba o la fila ya no existe; vista previa actualizada',
    'edit.failed' => 'la escritura falló — revertida, nada cambió: {reason}',
    'edit.too_many' => 'rechazado: la sentencia afectaría a {count} filas — revertida, nada cambió',
];
