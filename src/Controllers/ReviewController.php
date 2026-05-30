<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class ReviewController {

    // ── GET /reviews  ──────────────────────────────────────────
    // Acepta ?id_hotel=X para filtrar por hotel
    public function getReviews($params = []) {
        $db = Database::getConnection();

        if (!empty($params['id_hotel'])) {
            $idHotel = intval($params['id_hotel']);
            $stmt    = $db->prepare("
                SELECT r.id_review, r.id_hotel, r.id_usuario,
                       u.usuario AS usuario_nombre,
                       r.puntuacion, r.comentario, r.fecha
                FROM   reviews r
                JOIN   usuarios u ON r.id_usuario = u.id_usuario
                WHERE  r.id_hotel = ?
                ORDER  BY r.fecha DESC
            ");
            $stmt->bind_param("i", $idHotel);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $result = $db->query("
                SELECT r.id_review, r.id_hotel, r.id_usuario,
                       u.usuario AS usuario_nombre,
                       r.puntuacion, r.comentario, r.fecha
                FROM   reviews r
                JOIN   usuarios u ON r.id_usuario = u.id_usuario
                ORDER  BY r.fecha DESC
            ");
            $data = $result->fetch_all(MYSQLI_ASSOC);
        }

        Response::success($data);
    }

    // ── POST /reviews ─────────────────────────────────────────
    public function crearReview($payload, $params = []) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['id_hotel']) || !isset($data['puntuacion'])) {
            Response::error("id_hotel y puntuacion son obligatorios", 400);
            return;
        }

        $puntuacion = floatval($data['puntuacion']);
        if ($puntuacion < 0.5 || $puntuacion > 5.0) {
            Response::error("La puntuación debe estar entre 0.5 y 5.0", 400);
            return;
        }

        $db         = Database::getConnection();
        $idHotel    = intval($data['id_hotel']);
        $idUsuario  = $payload['id_usuario'];
        $comentario = $data['comentario'] ?? null;

        // Un usuario solo puede reseñar un hotel una vez (UNIQUE en la BD)
        $check = $db->prepare("SELECT id_review FROM reviews WHERE id_hotel = ? AND id_usuario = ?");
        $check->bind_param("ii", $idHotel, $idUsuario);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            Response::error("Ya has reseñado este hotel", 409);
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO reviews (id_hotel, id_usuario, puntuacion, comentario)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("iids", $idHotel, $idUsuario, $puntuacion, $comentario);

        if ($stmt->execute()) {
            Response::success(["message" => "Reseña guardada correctamente"], 201);
        } else {
            Response::error("Error al guardar la reseña", 500);
        }
    }

    // ── DELETE /reviews?id_review=X ───────────────────────────
    public function eliminarReview($payload, $params = []) {
        $idReview  = intval($params['id_review'] ?? 0);
        $idUsuario = $payload['id_usuario'];

        if (!$idReview) {
            Response::error("ID de reseña requerido", 400);
            return;
        }

        $db    = Database::getConnection();
        $check = $db->prepare("SELECT id_usuario FROM reviews WHERE id_review = ?");
        $check->bind_param("i", $idReview);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();

        if (!$row) {
            Response::error("Reseña no encontrada", 404);
            return;
        }

        // Solo el autor o un admin puede borrar
        $esAdmin = ($payload['rol'] ?? '') === 'admin';
        if ($row['id_usuario'] != $idUsuario && !$esAdmin) {
            Response::error("Sin permiso para eliminar esta reseña", 403);
            return;
        }

        $stmt = $db->prepare("DELETE FROM reviews WHERE id_review = ?");
        $stmt->bind_param("i", $idReview);

        if ($stmt->execute()) {
            Response::success(["message" => "Reseña eliminada"]);
        } else {
            Response::error("Error al eliminar la reseña", 500);
        }
    }
}
