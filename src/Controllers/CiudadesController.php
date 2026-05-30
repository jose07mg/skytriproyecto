<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class CiudadesController {

    // ── GET /ciudades ─────────────────────────────────────────
    public function getCiudades($params = []) {
        $db = Database::getConnection();

        $result = $db->query("
            SELECT c.id_ciudad, c.nombre, p.nombre AS pais_nombre, p.id_pais
            FROM   ciudades c
            JOIN   paises   p ON c.id_pais = p.id_pais
            ORDER  BY p.nombre ASC, c.nombre ASC
        ");

        if (!$result) {
            Response::error("Error al obtener ciudades: " . $db->error, 500);
            return;
        }

        Response::success($result->fetch_all(MYSQLI_ASSOC));
    }
}
