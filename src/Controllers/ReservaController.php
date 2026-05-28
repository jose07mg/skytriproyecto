<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class ReservaController {

    public function crearReserva($payload, $params = []) {
        $db = Database::getConnection();

        $data = json_decode(file_get_contents("php://input"), true);

        $stmt = $db->prepare("
            INSERT INTO reservas(
                id_usuario, id_hotel, nombre, dni, telefono, fecha_inicio, fecha_fin,
                personas, total_precio, estado, adultos, bebes, necesita_cuna,
                con_desayuno, es_reembolsable
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmada', ?, ?, ?, ?, ?)
        ");

        $nombre = $data['nombre'] ?? null;
        $dni = $data['dni'] ?? null;
        $telefono = $data['telefono'] ?? null;
        $adultos = (int) ($data['adultos'] ?? $data['personas'] ?? 1);
        $bebes = (int) ($data['bebes'] ?? 0);
        $necesitaCuna = !empty($data['necesita_cuna']) ? 1 : 0;
        $conDesayuno = !empty($data['con_desayuno']) ? 1 : 0;
        $esReembolsable = array_key_exists('es_reembolsable', $data)
            ? (!empty($data['es_reembolsable']) ? 1 : 0)
            : 1;

        $stmt->bind_param(
            "iisssssidiiiii",
            $payload['id_usuario'],
            $data['id_hotel'],
            $nombre,
            $dni,
            $telefono,
            $data['fecha_inicio'],
            $data['fecha_fin'],
            $data['personas'],
            $data['total_precio'],
            $adultos,
            $bebes,
            $necesitaCuna,
            $conDesayuno,
            $esReembolsable
        );

        $stmt->execute();

        Response::success(["message" => "Reserva creada", "id_reserva" => $db->insert_id], 201);
    }

    public function getReservasUsuario($payload, $params = []) {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT
                r.*,
                h.nombre AS hotel_nombre,
                h.imagen AS hotel_imagen,
                c.nombre AS ciudad_nombre,
                p.nombre AS pais_nombre
            FROM reservas r
            LEFT JOIN hoteles h ON r.id_hotel = h.id_hotel
            LEFT JOIN ciudades c ON h.id_ciudad = c.id_ciudad
            LEFT JOIN paises p ON c.id_pais = p.id_pais
            WHERE r.id_usuario = ?
            ORDER BY r.fecha_creacion DESC
        ");
        $stmt->bind_param("i", $payload['id_usuario']);
        $stmt->execute();

        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        Response::success($data);
    }

    public function cancelarReserva($payload, $params = []) {
        $db = Database::getConnection();
        $data = json_decode(file_get_contents("php://input"), true);
        $idReserva = (int) ($data['id_reserva'] ?? $data['idReserva'] ?? 0);

        if ($idReserva <= 0) {
            Response::error("id_reserva es obligatorio", 400);
        }

        $stmt = $db->prepare("
            UPDATE reservas
            SET estado = 'cancelada'
            WHERE id_reserva = ? AND id_usuario = ?
        ");
        $stmt->bind_param("ii", $idReserva, $payload['id_usuario']);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            Response::error("Reserva no encontrada", 404);
        }

        Response::success(["message" => "Reserva cancelada"]);
    }
}
