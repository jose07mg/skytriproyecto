<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class HotelController {

    public function getHoteles($params = []) {
        $db = Database::getConnection();

        $result = $db->query("
            SELECT
                h.*,
                c.nombre as ciudad_nombre,
                p.nombre as pais_nombre,
                GROUP_CONCAT(DISTINCT s.nombre) as servicios
            FROM hoteles h
            LEFT JOIN ciudades c ON h.id_ciudad = c.id_ciudad
            LEFT JOIN paises p ON c.id_pais = p.id_pais
            LEFT JOIN hotel_servicios hs ON h.id_hotel = hs.id_hotel
            LEFT JOIN servicios s ON hs.id_servicio = s.id_servicio
            GROUP BY h.id_hotel
        ");

        $data = [];
        while ($row = $result->fetch_assoc()) {
            // Convertir servicios a array
            $servicios = $row['servicios'] ? explode(',', $row['servicios']) : [];
            $row['servicios'] = $servicios;
            $data[] = $row;
        }

        Response::success($data);
    }

    public function getHotelesPorCiudad($params) {
        $db = Database::getConnection();

        $id = $params['id_ciudad'] ?? null;

        $stmt = $db->prepare("
            SELECT
                h.*,
                c.nombre as ciudad_nombre,
                p.nombre as pais_nombre,
                GROUP_CONCAT(DISTINCT s.nombre) as servicios
            FROM hoteles h
            LEFT JOIN ciudades c ON h.id_ciudad = c.id_ciudad
            LEFT JOIN paises p ON c.id_pais = p.id_pais
            LEFT JOIN hotel_servicios hs ON h.id_hotel = hs.id_hotel
            LEFT JOIN servicios s ON hs.id_servicio = s.id_servicio
            WHERE h.id_ciudad=?
            GROUP BY h.id_hotel
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            // Convertir servicios a array
            $servicios = $row['servicios'] ? explode(',', $row['servicios']) : [];
            $row['servicios'] = $servicios;
            $data[] = $row;
        }

        Response::success($data);
    }

    public function getHabitaciones($params = []) {
        $db = Database::getConnection();
        $idHotel = isset($params['id_hotel']) ? (int) $params['id_hotel'] : 0;

        if ($idHotel <= 0) {
            Response::error("id_hotel es obligatorio", 400);
        }

        $includeAll = !empty($params['include_all']) || !empty($params['admin']);
        $whereDisponibilidad = $includeAll ? "" : "AND disponible = 1";

        $stmt = $db->prepare("
            SELECT
                id_habitacion,
                id_hotel,
                tipo,
                COALESCE(NULLIF(tipo_habitacion, ''), tipo, 'Habitacion') AS tipo_habitacion,
                capacidad,
                COALESCE(NULLIF(precio_noche, 0), precio, 0) AS precio_noche,
                desayuno_incluido,
                cancelacion,
                disponible
            FROM habitaciones
            WHERE id_hotel = ? $whereDisponibilidad
            ORDER BY precio_noche ASC, precio ASC
        ");
        $stmt->bind_param("i", $idHotel);
        $stmt->execute();

        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            if ((float) $row['precio_noche'] <= 0 && isset($row['precio'])) {
                $row['precio_noche'] = $row['precio'];
            }
            $data[] = $row;
        }

        Response::success($data);
    }

    public function crearHotel($payload, $params = []) {
        $this->requireAdmin($payload);
        $db = Database::getConnection();
        $data = $this->getRequestData();

        $nombre = $data['nombre'] ?? 'Nuevo hotel';
        $idCiudad = (int) ($data['id_ciudad'] ?? 1);
        $estrellas = (int) ($data['estrellas'] ?? 3);
        $puntuacion = (float) ($data['puntuacion'] ?? 0);
        $precio = (float) ($data['precio_noche'] ?? 0);
        $capacidad = (int) ($data['capacidad_personas'] ?? 1);
        $distanciaCentro = (float) ($data['distancia_centro_km'] ?? 0);
        $distanciaAeropuerto = (float) ($data['distancia_aeropuerto_km'] ?? 0);
        $imagen = $data['imagen'] ?? null;
        $biografia = $data['biografia'] ?? '';
        $pais = $data['pais'] ?? $this->getPaisPorCiudad($db, $idCiudad);

        $stmt = $db->prepare("
            INSERT INTO hoteles(
                nombre, id_ciudad, estrellas, puntuacion, precio_noche,
                capacidad_personas, distancia_centro_km, distancia_aeropuerto_km,
                imagen, biografia, pais
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "siiddiddsss",
            $nombre,
            $idCiudad,
            $estrellas,
            $puntuacion,
            $precio,
            $capacidad,
            $distanciaCentro,
            $distanciaAeropuerto,
            $imagen,
            $biografia,
            $pais
        );
        $stmt->execute();

        $idHotel = $db->insert_id;
        $this->syncServicios($db, $idHotel, $data['servicios'] ?? []);

        Response::success(["message" => "Hotel creado", "id_hotel" => $idHotel], 201);
    }

    public function actualizarHotel($payload, $params = []) {
        $this->requireAdmin($payload);
        $db = Database::getConnection();
        $idHotel = isset($params['id_hotel']) ? (int) $params['id_hotel'] : 0;
        $data = $this->getRequestData();

        if ($idHotel <= 0) {
            Response::error("id_hotel es obligatorio", 400);
        }

        $actual = $this->getHotelRow($db, $idHotel);
        if (!$actual) {
            Response::error("Hotel no encontrado", 404);
        }

        $nombre = $data['nombre'] ?? $actual['nombre'];
        $idCiudad = (int) ($data['id_ciudad'] ?? $actual['id_ciudad']);
        $estrellas = (int) ($data['estrellas'] ?? $actual['estrellas']);
        $puntuacion = (float) ($data['puntuacion'] ?? $actual['puntuacion']);
        $precio = (float) ($data['precio_noche'] ?? $actual['precio_noche']);
        $capacidad = (int) ($data['capacidad_personas'] ?? $actual['capacidad_personas']);
        $distanciaCentro = (float) ($data['distancia_centro_km'] ?? $actual['distancia_centro_km']);
        $distanciaAeropuerto = (float) ($data['distancia_aeropuerto_km'] ?? $actual['distancia_aeropuerto_km']);
        $imagen = array_key_exists('imagen', $data) ? $data['imagen'] : $actual['imagen'];
        $biografia = $data['biografia'] ?? $actual['biografia'];
        $pais = $data['pais'] ?? $actual['pais'] ?? $this->getPaisPorCiudad($db, $idCiudad);

        $stmt = $db->prepare("
            UPDATE hoteles
            SET nombre = ?, id_ciudad = ?, estrellas = ?, puntuacion = ?, precio_noche = ?,
                capacidad_personas = ?, distancia_centro_km = ?, distancia_aeropuerto_km = ?,
                imagen = ?, biografia = ?, pais = ?
            WHERE id_hotel = ?
        ");
        $stmt->bind_param(
            "siiddiddsssi",
            $nombre,
            $idCiudad,
            $estrellas,
            $puntuacion,
            $precio,
            $capacidad,
            $distanciaCentro,
            $distanciaAeropuerto,
            $imagen,
            $biografia,
            $pais,
            $idHotel
        );
        $stmt->execute();

        if (array_key_exists('servicios', $data)) {
            $this->syncServicios($db, $idHotel, $data['servicios']);
        }

        Response::success(["message" => "Hotel actualizado"]);
    }

    public function eliminarHotel($payload, $params = []) {
        $this->requireAdmin($payload);
        $db = Database::getConnection();
        $idHotel = isset($params['id_hotel']) ? (int) $params['id_hotel'] : 0;

        if ($idHotel <= 0) {
            Response::error("id_hotel es obligatorio", 400);
        }

        $deleteServices = $db->prepare("DELETE FROM hotel_servicios WHERE id_hotel = ?");
        $deleteServices->bind_param("i", $idHotel);
        $deleteServices->execute();

        $deleteRooms = $db->prepare("DELETE FROM habitaciones WHERE id_hotel = ?");
        $deleteRooms->bind_param("i", $idHotel);
        $deleteRooms->execute();

        $stmt = $db->prepare("DELETE FROM hoteles WHERE id_hotel = ?");
        $stmt->bind_param("i", $idHotel);
        $stmt->execute();

        Response::success(["message" => "Hotel eliminado"]);
    }

    public function crearHabitacion($payload, $params = []) {
        $this->requireAdmin($payload);
        $db = Database::getConnection();
        $data = $this->getRequestData();

        $idHotel = (int) ($data['id_hotel'] ?? 0);
        if ($idHotel <= 0) {
            Response::error("id_hotel es obligatorio", 400);
        }

        $tipo = $data['tipo'] ?? 'standard';
        $tipoHabitacion = $data['tipo_habitacion'] ?? ucfirst($tipo);
        $precio = (float) ($data['precio_noche'] ?? $data['precio'] ?? 0);
        $capacidad = (int) ($data['capacidad'] ?? 1);
        $desayuno = !empty($data['desayuno_incluido']) ? 1 : 0;
        $cancelacion = $data['cancelacion'] ?? 'gratis';
        $disponible = array_key_exists('disponible', $data)
            ? (!empty($data['disponible']) ? 1 : 0)
            : 1;

        $stmt = $db->prepare("
            INSERT INTO habitaciones(
                id_hotel, tipo, precio, capacidad, desayuno_incluido,
                cancelacion, tipo_habitacion, precio_noche, disponible
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "isdiissdi",
            $idHotel,
            $tipo,
            $precio,
            $capacidad,
            $desayuno,
            $cancelacion,
            $tipoHabitacion,
            $precio,
            $disponible
        );
        $stmt->execute();

        Response::success([
            "message" => "Habitacion creada",
            "id_habitacion" => $db->insert_id,
        ], 201);
    }

    public function actualizarHabitacion($payload, $params = []) {
        $this->requireAdmin($payload);
        $db = Database::getConnection();
        $idHabitacion = isset($params['id_habitacion']) ? (int) $params['id_habitacion'] : 0;
        $data = $this->getRequestData();

        if ($idHabitacion <= 0) {
            Response::error("id_habitacion es obligatorio", 400);
        }

        $actual = $this->getHabitacionRow($db, $idHabitacion);
        if (!$actual) {
            Response::error("Habitacion no encontrada", 404);
        }

        $idHotel = (int) ($data['id_hotel'] ?? $actual['id_hotel']);
        $tipo = $data['tipo'] ?? $actual['tipo'];
        $tipoHabitacion = $data['tipo_habitacion'] ?? $actual['tipo_habitacion'];
        $precio = (float) ($data['precio_noche'] ?? $data['precio'] ?? $actual['precio_noche'] ?? $actual['precio']);
        $capacidad = (int) ($data['capacidad'] ?? $actual['capacidad']);
        $desayuno = array_key_exists('desayuno_incluido', $data)
            ? (!empty($data['desayuno_incluido']) ? 1 : 0)
            : (int) $actual['desayuno_incluido'];
        $cancelacion = $data['cancelacion'] ?? $actual['cancelacion'];
        $disponible = array_key_exists('disponible', $data)
            ? (!empty($data['disponible']) ? 1 : 0)
            : (int) $actual['disponible'];

        $stmt = $db->prepare("
            UPDATE habitaciones
            SET id_hotel = ?, tipo = ?, precio = ?, capacidad = ?,
                desayuno_incluido = ?, cancelacion = ?, tipo_habitacion = ?,
                precio_noche = ?, disponible = ?
            WHERE id_habitacion = ?
        ");
        $stmt->bind_param(
            "isdiissdii",
            $idHotel,
            $tipo,
            $precio,
            $capacidad,
            $desayuno,
            $cancelacion,
            $tipoHabitacion,
            $precio,
            $disponible,
            $idHabitacion
        );
        $stmt->execute();

        Response::success(["message" => "Habitacion actualizada"]);
    }

    public function eliminarHabitacion($payload, $params = []) {
        $this->requireAdmin($payload);
        $db = Database::getConnection();
        $idHabitacion = isset($params['id_habitacion']) ? (int) $params['id_habitacion'] : 0;

        if ($idHabitacion <= 0) {
            Response::error("id_habitacion es obligatorio", 400);
        }

        $stmt = $db->prepare("DELETE FROM habitaciones WHERE id_habitacion = ?");
        $stmt->bind_param("i", $idHabitacion);
        $stmt->execute();

        Response::success(["message" => "Habitacion eliminada"]);
    }

    private function getRequestData() {
        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            Response::error("JSON invalido", 400);
        }
        return $data;
    }

    private function requireAdmin($payload) {
        $role = strtolower($payload['role'] ?? $payload['rol'] ?? 'user');
        if ($role === 'admin') {
            return;
        }

        $idUsuario = isset($payload['id_usuario']) ? (int) $payload['id_usuario'] : 0;
        if ($idUsuario > 0) {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT usuario, email FROM usuarios WHERE id_usuario = ?");
            $stmt->bind_param("i", $idUsuario);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $username = strtolower($user['usuario'] ?? '');
                $email = strtolower($user['email'] ?? '');
                if ($username === 'admin' || $email === 'admin@gmail.com') {
                    return;
                }
            }
        }

        Response::error("Solo los administradores pueden realizar esta accion", 403);
    }

    private function getHotelRow($db, $idHotel) {
        $stmt = $db->prepare("SELECT * FROM hoteles WHERE id_hotel = ?");
        $stmt->bind_param("i", $idHotel);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    private function getHabitacionRow($db, $idHabitacion) {
        $stmt = $db->prepare("SELECT * FROM habitaciones WHERE id_habitacion = ?");
        $stmt->bind_param("i", $idHabitacion);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    private function getPaisPorCiudad($db, $idCiudad) {
        $stmt = $db->prepare("
            SELECT p.nombre
            FROM ciudades c
            LEFT JOIN paises p ON c.id_pais = p.id_pais
            WHERE c.id_ciudad = ?
        ");
        $stmt->bind_param("i", $idCiudad);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            return '';
        }
        return $result->fetch_assoc()['nombre'] ?? '';
    }

    private function syncServicios($db, $idHotel, $servicios) {
        if (!is_array($servicios)) {
            $servicios = [];
        }

        $delete = $db->prepare("DELETE FROM hotel_servicios WHERE id_hotel = ?");
        $delete->bind_param("i", $idHotel);
        $delete->execute();

        foreach ($servicios as $servicio) {
            $nombre = trim((string) $servicio);
            if ($nombre === '') {
                continue;
            }

            $serviceId = $this->getOrCreateServicio($db, $nombre);
            $insert = $db->prepare("INSERT IGNORE INTO hotel_servicios(id_hotel, id_servicio) VALUES (?, ?)");
            $insert->bind_param("ii", $idHotel, $serviceId);
            $insert->execute();
        }
    }

    private function getOrCreateServicio($db, $nombre) {
        $stmt = $db->prepare("SELECT id_servicio FROM servicios WHERE nombre = ?");
        $stmt->bind_param("s", $nombre);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            return (int) $result->fetch_assoc()['id_servicio'];
        }

        $insert = $db->prepare("INSERT INTO servicios(nombre) VALUES (?)");
        $insert->bind_param("s", $nombre);
        $insert->execute();
        return (int) $db->insert_id;
    }
}
