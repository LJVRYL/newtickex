<?php

function tickex_repair_original_event_date(PDO $pdo)
{
    $stmt = $pdo->prepare(
        "UPDATE eventos
         SET fecha_desde = :fecha_desde,
             fecha_hasta = :fecha_hasta,
             actualizado_en = CURRENT_TIMESTAMP
         WHERE id = 1
           AND slug = 'str'
           AND (fecha_desde IS NULL OR trim(fecha_desde) = '')
           AND (fecha_hasta IS NULL OR trim(fecha_hasta) = '')"
    );
    $stmt->execute(array(
        ':fecha_desde' => '2025-11-15',
        ':fecha_hasta' => '2025-11-16',
    ));

    return $stmt->rowCount() === 1;
}

