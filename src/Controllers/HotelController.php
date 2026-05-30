<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class HotelController {

    // ── Helper: check if biografia_en column exists ───────────
    private function bioEnField($db): string {
        $r = $db->query("SHOW COLUMNS FROM hoteles LIKE 'biografia_en'");
        return ($r && $r->num_rows > 0) ? 'h.biografia_en' : 'NULL AS biografia_en';
    }

    // ── GET /hoteles ──────────────────────────────────────────
    public function getHoteles($params = []) {
        $db = Database::getConnection();
        $bioEn = $this->bioEnField($db);

        $result = $db->query("
            SELECT
                h.id_hotel, h.nombre, h.biografia, $bioEn, h.precio_noche,
                h.puntuacion, h.estrellas, h.capacidad_personas,
                h.distancia_centro_km, h.distancia_aeropuerto_km,
                h.latitud, h.longitud, h.imagen, h.activo,
                c.nombre  AS ciudad_nombre,
                p.nombre  AS pais_nombre,
                GROUP_CONCAT(DISTINCT s.nombre ORDER BY s.nombre SEPARATOR ',') AS servicios
            FROM hoteles h
            LEFT JOIN ciudades       c  ON h.id_ciudad    = c.id_ciudad
            LEFT JOIN paises         p  ON c.id_pais      = p.id_pais
            LEFT JOIN hotel_servicios hs ON h.id_hotel    = hs.id_hotel
            LEFT JOIN servicios      s  ON hs.id_servicio = s.id_servicio
            WHERE h.activo = 1
            GROUP BY h.id_hotel
        ");

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $row['servicios'] = $row['servicios'] ? explode(',', $row['servicios']) : [];
            $data[] = $row;
        }

        Response::success($data);
    }

    // ── GET /hoteles/ciudad ───────────────────────────────────
    public function getHotelesPorCiudad($params) {
        $db  = Database::getConnection();
        $id  = $params['id_ciudad'] ?? null;
        $bioEn = $this->bioEnField($db);

        $stmt = $db->prepare("
            SELECT
                h.id_hotel, h.nombre, h.biografia, $bioEn, h.precio_noche,
                h.puntuacion, h.estrellas, h.capacidad_personas,
                h.distancia_centro_km, h.distancia_aeropuerto_km,
                h.latitud, h.longitud, h.imagen,
                c.nombre  AS ciudad_nombre,
                p.nombre  AS pais_nombre,
                GROUP_CONCAT(DISTINCT s.nombre ORDER BY s.nombre SEPARATOR ',') AS servicios
            FROM hoteles h
            LEFT JOIN ciudades       c  ON h.id_ciudad    = c.id_ciudad
            LEFT JOIN paises         p  ON c.id_pais      = p.id_pais
            LEFT JOIN hotel_servicios hs ON h.id_hotel    = hs.id_hotel
            LEFT JOIN servicios      s  ON hs.id_servicio = s.id_servicio
            WHERE h.id_ciudad = ? AND h.activo = 1
            GROUP BY h.id_hotel
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $data = [];
        while ($row = $stmt->get_result()->fetch_assoc()) {
            $row['servicios'] = $row['servicios'] ? explode(',', $row['servicios']) : [];
            $data[] = $row;
        }

        Response::success($data);
    }

    // ── GET /habitaciones?id_hotel=X[&includeAll=1] ──────────
    public function getHabitacionesPorHotel($params) {
        $db         = Database::getConnection();
        $id_hotel   = $params['id_hotel'] ?? null;
        $includeAll = !empty($params['includeAll']);

        if (!$id_hotel) {
            Response::error("ID de hotel requerido", 400);
            return;
        }

        $id_hotel   = intval($id_hotel);
        $whereExtra = $includeAll ? '' : ' AND activo = 1';

        $sql  = "
            SELECT
                id_habitacion, id_hotel, tipo_habitacion,
                capacidad, precio_noche, descripcion,
                activo AS disponible
            FROM habitaciones
            WHERE id_hotel = ?$whereExtra
            ORDER BY capacidad ASC, precio_noche ASC
        ";

        $stmt = $db->prepare($sql);

        if (!$stmt) {
            Response::error("Error preparando la consulta: " . $db->error, 500);
            return;
        }

        $stmt->bind_param("i", $id_hotel);

        if (!$stmt->execute()) {
            Response::error("Error ejecutando la consulta: " . $stmt->error, 500);
            return;
        }

        $result = $stmt->get_result();

        if (!$result) {
            Response::error("Error obteniendo resultados: " . $stmt->error, 500);
            return;
        }

        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Si el hotel no tiene habitaciones activas, crear unas por defecto
        if (empty($data) && !$includeAll) {
            $this->crearHabitacionesDefecto($db, $id_hotel);
            // Nueva consulta independiente para evitar problemas de estado MySQLi
            $stmt2 = $db->prepare($sql);
            if ($stmt2) {
                $stmt2->bind_param("i", $id_hotel);
                $stmt2->execute();
                $r2   = $stmt2->get_result();
                $data = $r2 ? $r2->fetch_all(MYSQLI_ASSOC) : [];
                $stmt2->close();
            }
        }

        Response::success($data);
    }

    // ── POST /hoteles  (admin) ────────────────────────────────
    public function crearHotel($payload, $params = []) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['nombre']) || empty($data['id_ciudad']) || empty($data['precio_noche'])) {
            Response::error("nombre, id_ciudad y precio_noche son obligatorios", 400);
            return;
        }

        $db      = Database::getConnection();
        $hasBioEn = $this->bioEnField($db) !== 'NULL AS biografia_en';

        $nombre     = $data['nombre'];
        $bio        = $data['biografia']           ?? null;
        $bioEn      = $data['biografia_en']        ?? null;
        $idCiudad   = intval($data['id_ciudad']);
        $precio     = floatval($data['precio_noche']);
        $estrellas  = intval($data['estrellas']    ?? 3);
        $puntuacion = floatval($data['puntuacion'] ?? 0.0);
        $capacidad  = intval($data['capacidad_personas'] ?? 2);
        $distCentro = isset($data['distancia_centro_km'])    ? floatval($data['distancia_centro_km'])    : null;
        $distAero   = isset($data['distancia_aeropuerto_km']) ? floatval($data['distancia_aeropuerto_km']) : null;
        $lat        = isset($data['latitud'])  ? floatval($data['latitud'])  : null;
        $lng        = isset($data['longitud']) ? floatval($data['longitud']) : null;
        $imagen     = $data['imagen'] ?? null;

        if ($hasBioEn) {
            $stmt = $db->prepare("
                INSERT INTO hoteles
                    (nombre, biografia, biografia_en, id_ciudad, precio_noche, estrellas,
                     puntuacion, capacidad_personas, distancia_centro_km,
                     distancia_aeropuerto_km, latitud, longitud, imagen)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "sssidididddds",
                $nombre, $bio, $bioEn, $idCiudad, $precio, $estrellas,
                $puntuacion, $capacidad, $distCentro, $distAero,
                $lat, $lng, $imagen
            );
        } else {
            $stmt = $db->prepare("
                INSERT INTO hoteles
                    (nombre, biografia, id_ciudad, precio_noche, estrellas,
                     puntuacion, capacidad_personas, distancia_centro_km,
                     distancia_aeropuerto_km, latitud, longitud, imagen)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "ssidididddds",
                $nombre, $bio, $idCiudad, $precio, $estrellas,
                $puntuacion, $capacidad, $distCentro, $distAero,
                $lat, $lng, $imagen
            );
        }

        if (!$stmt || !$stmt->execute()) {
            Response::error("Error al crear el hotel: " . ($stmt ? $stmt->error : $db->error), 500);
            return;
        }

        $idHotel = $db->insert_id;

        // Sincronizar servicios si se envían
        if (!empty($data['servicios'])) {
            $this->sincronizarServicios($db, $idHotel, $data['servicios']);
        }

        Response::success(["message" => "Hotel creado", "id_hotel" => $idHotel], 201);
    }

    // ── POST /hoteles/update  (admin) ─────────────────────────
    public function actualizarHotel($payload, $params = []) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['id_hotel'])) {
            Response::error("ID de hotel requerido", 400);
            return;
        }

        $db      = Database::getConnection();
        $idHotel = intval($data['id_hotel']);

        $check = $db->prepare("SELECT id_hotel FROM hoteles WHERE id_hotel = ?");
        $check->bind_param("i", $idHotel);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            Response::error("Hotel no encontrado", 404);
            return;
        }

        $mapping = [
            'nombre'                  => 's',
            'biografia'               => 's',
            'biografia_en'            => 's',
            'imagen'                  => 's',
            'id_ciudad'               => 'i',
            'precio_noche'            => 'd',
            'estrellas'               => 'i',
            'puntuacion'              => 'd',
            'capacidad_personas'      => 'i',
            'distancia_centro_km'     => 'd',
            'distancia_aeropuerto_km' => 'd',
            'latitud'                 => 'd',
            'longitud'                => 'd',
            'activo'                  => 'i',
        ];

        $fields = [];
        $types  = '';
        $values = [];

        foreach ($mapping as $field => $type) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $types   .= $type;
                $values[] = $data[$field];
            }
        }

        if (!empty($fields)) {
            $values[] = $idHotel;
            $types   .= 'i';
            $stmt = $db->prepare("UPDATE hoteles SET " . implode(', ', $fields) . " WHERE id_hotel = ?");
            $this->bindDynamic($stmt, $types, $values);
            if (!$stmt->execute()) {
                Response::error("Error al actualizar el hotel: " . $stmt->error, 500);
                return;
            }
        }

        // Sincronizar servicios si se envían
        if (isset($data['servicios'])) {
            $this->sincronizarServicios($db, $idHotel, $data['servicios']);
        }

        Response::success(["message" => "Hotel actualizado", "id_hotel" => $idHotel]);
    }

    // ── DELETE /hoteles?id_hotel=X  (admin) ───────────────────
    public function eliminarHotel($payload, $params = []) {
        $idHotel = intval($params['id_hotel'] ?? 0);

        if (!$idHotel) {
            Response::error("ID de hotel requerido", 400);
            return;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM hoteles WHERE id_hotel = ?");
        $stmt->bind_param("i", $idHotel);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            Response::success(["message" => "Hotel eliminado"]);
        } else {
            Response::error("Hotel no encontrado", 404);
        }
    }

    // ── POST /habitaciones  (admin) ───────────────────────────
    public function crearHabitacion($payload, $params = []) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['id_hotel']) || empty($data['tipo_habitacion']) || empty($data['precio_noche'])) {
            Response::error("id_hotel, tipo_habitacion y precio_noche son obligatorios", 400);
            return;
        }

        $db        = Database::getConnection();
        $idHotel   = intval($data['id_hotel']);
        $tipo      = $data['tipo_habitacion'];
        $capacidad = intval($data['capacidad']   ?? 2);
        $precio    = floatval($data['precio_noche']);
        $desc      = $data['descripcion'] ?? null;

        $stmt = $db->prepare("
            INSERT INTO habitaciones (id_hotel, tipo_habitacion, capacidad, precio_noche, descripcion)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isids", $idHotel, $tipo, $capacidad, $precio, $desc);

        if ($stmt->execute()) {
            Response::success(["message" => "Habitación creada", "id_habitacion" => $db->insert_id], 201);
        } else {
            Response::error("Error al crear la habitación", 500);
        }
    }

    // ── POST /habitaciones/update  (admin) ────────────────────
    public function actualizarHabitacion($payload, $params = []) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['id_habitacion'])) {
            Response::error("ID de habitación requerido", 400);
            return;
        }

        $db           = Database::getConnection();
        $idHabitacion = intval($data['id_habitacion']);

        $check = $db->prepare("SELECT id_habitacion FROM habitaciones WHERE id_habitacion = ?");
        $check->bind_param("i", $idHabitacion);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            Response::error("Habitación no encontrada", 404);
            return;
        }

        $mapping = [
            'tipo_habitacion' => 's',
            'capacidad'       => 'i',
            'precio_noche'    => 'd',
            'descripcion'     => 's',
            'activo'          => 'i',  // columna real en BD (alias 'disponible' en SELECT)
        ];
        // Si el cliente envía 'disponible', mapearlo a 'activo'
        if (isset($data['disponible']) && !isset($data['activo'])) {
            $data['activo'] = $data['disponible'];
        }

        $fields = [];
        $types  = '';
        $values = [];

        foreach ($mapping as $field => $type) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $types   .= $type;
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            Response::error("No hay campos válidos para actualizar", 400);
            return;
        }

        $values[] = $idHabitacion;
        $types   .= 'i';
        $stmt     = $db->prepare("UPDATE habitaciones SET " . implode(', ', $fields) . " WHERE id_habitacion = ?");
        $this->bindDynamic($stmt, $types, $values);

        if ($stmt->execute()) {
            Response::success(["message" => "Habitación actualizada"]);
        } else {
            Response::error("Error al actualizar la habitación", 500);
        }
    }

    // ── DELETE /habitaciones?id_habitacion=X  (admin) ─────────
    public function eliminarHabitacion($payload, $params = []) {
        $idHabitacion = intval($params['id_habitacion'] ?? 0);

        if (!$idHabitacion) {
            Response::error("ID de habitación requerido", 400);
            return;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM habitaciones WHERE id_habitacion = ?");
        $stmt->bind_param("i", $idHabitacion);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            Response::success(["message" => "Habitación eliminada"]);
        } else {
            Response::error("Habitación no encontrada", 404);
        }
    }

    // ── HELPERS ───────────────────────────────────────────────

    private function sincronizarServicios($db, int $idHotel, $servicios) {
        // Acepta array de nombres o string separado por comas
        if (is_string($servicios)) {
            $servicios = array_map('trim', explode(',', $servicios));
        }

        $db->query("DELETE FROM hotel_servicios WHERE id_hotel = $idHotel");

        foreach ($servicios as $nombre) {
            $nombre = trim($nombre);
            if ($nombre === '') continue;

            $stmt = $db->prepare("SELECT id_servicio FROM servicios WHERE nombre = ? LIMIT 1");
            $stmt->bind_param("s", $nombre);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if ($row) {
                $ins = $db->prepare("INSERT IGNORE INTO hotel_servicios (id_hotel, id_servicio) VALUES (?, ?)");
                $ins->bind_param("ii", $idHotel, $row['id_servicio']);
                $ins->execute();
            }
        }
    }

    private function crearHabitacionesDefecto($db, int $idHotel) {
        $defaults = [
            ['Individual', 1, 75.00],
            ['Doble',      2, 95.00],
            ['Suite',      4, 150.00],
        ];

        foreach ($defaults as [$tipo, $cap, $precio]) {
            $stmt = $db->prepare("
                INSERT INTO habitaciones (id_hotel, tipo_habitacion, capacidad, precio_noche)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param("isid", $idHotel, $tipo, $cap, $precio);
            $stmt->execute();
        }
    }

    private function bindDynamic($stmt, string $types, array $values) {
        $params = array_merge([$types], $values);
        $refs   = [];
        foreach ($params as $k => $v) {
            $refs[$k] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}
