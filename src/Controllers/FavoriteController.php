<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use Exception;

class FavoriteController {

    public function toggleHotelFavorite($payload) {
        try {
            $data = json_decode(file_get_contents("php://input"), true);

            if (!isset($data['id_hotel'])) {
                Response::error("Falta el parámetro id_hotel", 400);
                return;
            }

            $userId  = $payload['id_usuario'];
            $hotelId = intval($data['id_hotel']);
            $conn    = Database::getConnection();

            $stmt = $conn->prepare("SELECT 1 FROM favoritos_hoteles WHERE id_usuario = ? AND id_hotel = ?");
            $stmt->bind_param("ii", $userId, $hotelId);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;

            if ($exists) {
                $stmt = $conn->prepare("DELETE FROM favoritos_hoteles WHERE id_usuario = ? AND id_hotel = ?");
                $stmt->bind_param("ii", $userId, $hotelId);
                $stmt->execute();
                Response::success(["action" => "removed"]);
            } else {
                $stmt = $conn->prepare("INSERT INTO favoritos_hoteles (id_usuario, id_hotel) VALUES (?, ?)");
                $stmt->bind_param("ii", $userId, $hotelId);
                if ($stmt->execute()) {
                    Response::success(["action" => "added"]);
                } else {
                    Response::error("Error al guardar favorito", 500);
                }
            }
        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function getHotelFavorites($payload) {
        $userId = $payload['id_usuario'];
        $conn   = Database::getConnection();

        $stmt = $conn->prepare("SELECT id_hotel FROM favoritos_hoteles WHERE id_usuario = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        Response::success($data);
    }
}
