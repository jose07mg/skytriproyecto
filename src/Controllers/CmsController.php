<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class CmsController {

    // ── GET /cms ──────────────────────────────────────────────
    public function getCms($params = []) {
        $db     = Database::getConnection();
        $result = $db->query("SELECT datos FROM cms_contenido WHERE id = 1 LIMIT 1");

        if ($result && $result->num_rows > 0) {
            $row  = $result->fetch_assoc();
            $data = json_decode($row['datos'], true);
            if ($data !== null) {
                Response::success($data);
                return;
            }
        }

        // Sin datos → respuesta vacía para que el cliente use defaults
        Response::success([]);
    }

    // ── POST /cms  (admin) ────────────────────────────────────
    public function saveCms($payload, $params = []) {
        if (($payload['rol'] ?? '') !== 'admin' && ($payload['rol'] ?? 0) !== 1) {
            Response::error("Solo administradores pueden modificar el CMS", 403);
            return;
        }

        $raw = file_get_contents("php://input");
        if (empty($raw)) {
            Response::error("Cuerpo vacío", 400);
            return;
        }

        // Verificar que es JSON válido
        $data = json_decode($raw, true);
        if ($data === null) {
            Response::error("JSON inválido", 400);
            return;
        }

        $db     = Database::getConnection();
        $json   = json_encode($data, JSON_UNESCAPED_UNICODE);

        // UPSERT: insertar o actualizar el singleton (id = 1)
        $stmt = $db->prepare("
            INSERT INTO cms_contenido (id, datos) VALUES (1, ?)
            ON DUPLICATE KEY UPDATE datos = VALUES(datos)
        ");
        $stmt->bind_param("s", $json);

        if ($stmt->execute()) {
            Response::success(["message" => "CMS guardado"]);
        } else {
            Response::error("Error al guardar el CMS: " . $stmt->error, 500);
        }
    }
}
