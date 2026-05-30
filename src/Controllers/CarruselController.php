<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class CarruselController {

    // ── GET /carrusel ─────────────────────────────────────────
    // Devuelve {fivestar: [...], centric: [...], bestvalue: [...]}
    public function getCarrusel($params = []) {
        $db = Database::getConnection();

        $result = $db->query("
            SELECT hc.id, hc.seccion, hc.id_hotel, hc.posicion,
                   h.nombre, h.precio_noche, h.estrellas, h.puntuacion,
                   c.nombre AS ciudad_nombre, p.nombre AS pais_nombre
            FROM   home_carrusel hc
            JOIN   hoteles  h ON hc.id_hotel  = h.id_hotel
            LEFT JOIN ciudades c ON h.id_ciudad = c.id_ciudad
            LEFT JOIN paises   p ON c.id_pais   = p.id_pais
            ORDER  BY hc.seccion, hc.posicion ASC
        ");

        if (!$result) {
            Response::error("Error al obtener carrusel: " . $db->error, 500);
            return;
        }

        $data = ['fivestar' => [], 'centric' => [], 'bestvalue' => []];

        while ($row = $result->fetch_assoc()) {
            $seccion = $row['seccion'];
            if (array_key_exists($seccion, $data)) {
                $data[$seccion][] = $row;
            }
        }

        Response::json($data);
    }

    // ── POST /carrusel  (admin) ───────────────────────────────
    public function addToCarrusel($payload, $params = []) {
        $this->requireAdmin($payload);

        $data    = json_decode(file_get_contents("php://input"), true);
        $seccion = $data['seccion'] ?? '';
        $idHotel = intval($data['id_hotel'] ?? 0);

        if (!in_array($seccion, ['fivestar', 'centric', 'bestvalue']) || !$idHotel) {
            Response::error("seccion (fivestar|centric|bestvalue) e id_hotel son obligatorios", 400);
            return;
        }

        $db = Database::getConnection();

        // Comprobar si ya existe
        $check = $db->prepare("SELECT id FROM home_carrusel WHERE seccion = ? AND id_hotel = ?");
        $check->bind_param("si", $seccion, $idHotel);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            Response::error("Este hotel ya está en esa sección del carrusel", 409);
            return;
        }

        // Calcular siguiente posición
        $pos = $db->query("SELECT COALESCE(MAX(posicion), 0) + 1 AS next FROM home_carrusel WHERE seccion = '$seccion'")->fetch_assoc()['next'];

        $stmt = $db->prepare("INSERT INTO home_carrusel (seccion, id_hotel, posicion) VALUES (?, ?, ?)");
        $stmt->bind_param("sii", $seccion, $idHotel, $pos);

        if ($stmt->execute()) {
            Response::success(["message" => "Hotel añadido al carrusel", "id" => $db->insert_id], 201);
        } else {
            Response::error("Error al añadir al carrusel: " . $stmt->error, 500);
        }
    }

    // ── DELETE /carrusel?id=X  (admin) ────────────────────────
    public function removeFromCarrusel($payload, $params = []) {
        $this->requireAdmin($payload);

        $id = intval($params['id'] ?? 0);
        if (!$id) {
            Response::error("ID de entrada requerido", 400);
            return;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM home_carrusel WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            Response::success(["message" => "Eliminado del carrusel"]);
        } else {
            Response::error("Entrada no encontrada", 404);
        }
    }

    private function requireAdmin($payload) {
        if (($payload['rol'] ?? '') !== 'admin' && ($payload['rol'] ?? 0) !== 1) {
            Response::error("Solo administradores", 403);
            exit;
        }
    }
}
