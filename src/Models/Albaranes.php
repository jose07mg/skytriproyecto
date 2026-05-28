<?php

namespace App\Models;

use App\Config\Database;

class Albaranes
{
    public static function getAllAlbaranes()
    {
        $conn = Database::getInstance();

        $query = "
            SELECT
    aa.numalbaran,
    aa.fechaalbaran,
    aa.entrega,
    aa.devolucion,
    aa.numpto,
    aa.albaranpdf,
    aa.albaranfirmado,
    aa.estado,
    ec.nombre,
    dc.direccionenvio,
    pc.telefono1,
    pc.telefono2,
    pc.telefono3
FROM albaranes_alquiler aa
LEFT JOIN empresas_clientes ec 
    ON ec.id = aa.idcliente
LEFT JOIN direnvioclientes dc 
    ON dc.idcliente = aa.idcliente
LEFT JOIN pcontactos pc 
    ON pc.id = aa.idcontacto
WHERE aa.albaranfirmado IS NULL
AND EXISTS (
    SELECT 1
    FROM lineas_albaran_alquiler la
    WHERE la.numpto = aa.numpto
      AND la.servicio_transporte = 7
);
        ";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $albaranes = [];
        while ($row = $result->fetch_assoc()) {
            $albaranes[] = $row;
        }

        return $albaranes;
    }
}
