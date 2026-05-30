<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class ReservaController {

    // ── POST /reservas ────────────────────────────────────────
    public function crearReserva($payload, $params = []) {
        $db   = Database::getConnection();
        $data = json_decode(file_get_contents("php://input"), true);

        if (!is_array($data)) {
            Response::error("Datos de reserva no válidos", 400);
            return;
        }

        $idHabitacion = isset($data['id_habitacion']) && $data['id_habitacion'] !== null
            ? intval($data['id_habitacion'])
            : null;

        // Verificar que la habitación pertenece al hotel
        if ($idHabitacion !== null) {
            $check = $db->prepare("
                SELECT id_habitacion FROM habitaciones
                WHERE id_habitacion = ? AND id_hotel = ? AND activo = 1
            ");
            $check->bind_param("ii", $idHabitacion, $data['id_hotel']);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                Response::error("La habitación no pertenece a este hotel o no está disponible", 400);
                return;
            }
        }

        $cuna     = ($data['necesita_cuna']   ?? false) ? 1 : 0;
        $reem     = ($data['es_reembolsable'] ?? true)  ? 1 : 0;
        $desayuno = ($data['con_desayuno']    ?? false) ? 1 : 0;

        // El campo en la BD es nombre_huesped; la app puede enviar 'nombre' o 'nombre_huesped'
        $nombreHuesped = $data['nombre_huesped'] ?? $data['nombre'] ?? '';
        $adultos       = intval($data['adultos'] ?? 1);
        $bebes         = intval($data['bebes']   ?? 0);

        // Detectar columnas reales
        $cols = [];
        $cr   = $db->query("SHOW COLUMNS FROM reservas");
        if ($cr) { while ($row = $cr->fetch_assoc()) { $cols[] = $row['Field']; } }

        $fInicio = in_array('fecha_entrada', $cols) ? 'fecha_entrada' : 'fecha_inicio';
        $fFin    = in_array('fecha_salida',  $cols) ? 'fecha_salida'  : 'fecha_fin';
        $fPrecio = in_array('precio_total',  $cols) ? 'precio_total'  : 'total_precio';

        // Accept both old field names (fecha_inicio) and new (fecha_entrada) from the client
        $fechaInicioVal = $data['fecha_inicio'] ?? $data['fecha_entrada'] ?? null;
        $fechaFinVal    = $data['fecha_fin']    ?? $data['fecha_salida']  ?? null;
        $precioVal      = $data['total_precio'] ?? $data['precio_total']  ?? 0;

        $db->begin_transaction();

        try {
            $stmt = $db->prepare("
                INSERT INTO reservas
                    (id_usuario, id_hotel, id_habitacion,
                     nombre_huesped, dni, telefono,
                     $fInicio, $fFin,
                     adultos, bebes, necesita_cuna, es_reembolsable, con_desayuno,
                     $fPrecio, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmada')
            ");

            $stmt->bind_param(
                "iiisssssiiiiid",
                $payload['id_usuario'],
                $data['id_hotel'],
                $idHabitacion,
                $nombreHuesped,
                $data['dni'],
                $data['telefono'],
                $fechaInicioVal,
                $fechaFinVal,
                $adultos,
                $bebes,
                $cuna,
                $reem,
                $desayuno,
                $precioVal
            );

            $stmt->execute();
            $db->commit();
            Response::success(["message" => "Reserva creada correctamente"]);
        } catch (\Throwable $e) {
            $db->rollback();
            Response::error("No se pudo crear la reserva: " . $e->getMessage(), 500);
        }
    }

    // ── PUT|POST /reservas/cancelar ───────────────────────────
    public function cancelarReserva($payload, $params = []) {
        $db   = Database::getConnection();
        $data = json_decode(file_get_contents("php://input"), true);

        if (!is_array($data) || empty($data['id_reserva'])) {
            Response::error("ID de reserva requerido", 400);
            return;
        }

        $idReserva = intval($data['id_reserva']);

        $stmtCheck = $db->prepare("SELECT id_usuario, estado FROM reservas WHERE id_reserva = ?");
        $stmtCheck->bind_param("i", $idReserva);
        $stmtCheck->execute();
        $reserva = $stmtCheck->get_result()->fetch_assoc();

        if (!$reserva) {
            Response::error("Reserva no encontrada", 404);
            return;
        }
        if ($reserva['id_usuario'] != $payload['id_usuario']) {
            Response::error("No tienes permiso para cancelar esta reserva", 403);
            return;
        }
        if ($reserva['estado'] === 'cancelada') {
            Response::error("La reserva ya está cancelada", 409);
            return;
        }

        $stmt = $db->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id_reserva = ?");
        $stmt->bind_param("i", $idReserva);

        if ($stmt->execute()) {
            Response::success(["message" => "Reserva cancelada correctamente"]);
        } else {
            Response::error("No se pudo cancelar la reserva", 500);
        }
    }

    // ── GET /reservas ─────────────────────────────────────────
    public function getReservasUsuario($payload, $params = []) {
        $db = Database::getConnection();

        // Detectar columnas reales (por si el esquema usa nombres distintos)
        $cols    = [];
        $cr      = $db->query("SHOW COLUMNS FROM reservas");
        if (!$cr) {
            // Tabla no existe todavía → devolver lista vacía en lugar de error
            Response::success([]);
            return;
        }
        while ($row = $cr->fetch_assoc()) { $cols[] = $row['Field']; }

        $fInicio = in_array('fecha_entrada', $cols) ? 'fecha_entrada' : 'fecha_inicio';
        $fFin    = in_array('fecha_salida',  $cols) ? 'fecha_salida'  : 'fecha_fin';
        $fPrecio = in_array('precio_total',  $cols) ? 'precio_total'  : 'total_precio';
        $orderBy = in_array('created_at',    $cols) ? 'r.created_at DESC' : 'r.id_reserva DESC';

        $stmt = $db->prepare("
            SELECT
                r.id_reserva, r.id_hotel, r.id_habitacion,
                r.nombre_huesped AS nombre,
                r.dni, r.telefono,
                r.$fInicio        AS fecha_inicio,
                r.$fFin           AS fecha_fin,
                r.adultos, r.bebes, r.necesita_cuna,
                r.es_reembolsable, r.con_desayuno,
                r.$fPrecio        AS total_precio,
                r.estado,
                h.nombre          AS nombre_hotel,
                c.nombre          AS ciudad,
                p.nombre          AS pais,
                ha.tipo_habitacion AS habitacion_tipo
            FROM reservas r
            JOIN hoteles     h  ON r.id_hotel        = h.id_hotel
            JOIN ciudades    c  ON h.id_ciudad        = c.id_ciudad
            JOIN paises      p  ON c.id_pais          = p.id_pais
            LEFT JOIN habitaciones ha ON r.id_habitacion = ha.id_habitacion
            WHERE r.id_usuario = ?
            ORDER BY $orderBy
        ");

        if (!$stmt) {
            Response::error("Error preparando consulta: " . $db->error, 500);
            return;
        }

        $stmt->bind_param("i", $payload['id_usuario']);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!$result) {
            Response::error("Error ejecutando consulta: " . $stmt->error, 500);
            return;
        }

        Response::success($result->fetch_all(MYSQLI_ASSOC));
    }
}