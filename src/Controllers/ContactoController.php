<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class ContactoController {

    // ── GET /contacto  (canales públicos) ─────────────────────
    public function getCanales($params = []) {
        $db     = Database::getConnection();
        $result = $db->query("SELECT id, tipo, etiqueta, valor, activo FROM canales_contacto ORDER BY orden ASC, id ASC");

        if (!$result) {
            // Tabla no existe todavía: devolver canales por defecto
            Response::success($this->defaultCanales());
            return;
        }

        $data = $result->fetch_all(MYSQLI_ASSOC);

        if (empty($data)) {
            Response::success($this->defaultCanales());
            return;
        }

        // Convertir activo a bool
        foreach ($data as &$row) {
            $row['activo'] = (bool) $row['activo'];
        }

        Response::success($data);
    }

    // ── POST /contacto  (admin: crear canal) ──────────────────
    public function crearCanal($payload, $params = []) {
        if ($payload['rol'] !== 'admin' && $payload['rol'] !== 1) {
            Response::error("Solo administradores pueden gestionar canales", 403);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['tipo']) || empty($data['etiqueta']) || empty($data['valor'])) {
            Response::error("tipo, etiqueta y valor son obligatorios", 400);
            return;
        }

        $db     = Database::getConnection();
        $tipo    = $data['tipo'];
        $etiq    = $data['etiqueta'];
        $valor   = $data['valor'];
        $activo  = isset($data['activo']) ? (int)(bool)$data['activo'] : 1;
        $orden   = isset($data['orden'])  ? intval($data['orden']) : 0;

        $stmt = $db->prepare("INSERT INTO canales_contacto (tipo, etiqueta, valor, activo, orden) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssii", $tipo, $etiq, $valor, $activo, $orden);

        if ($stmt->execute()) {
            Response::success(["message" => "Canal creado", "id" => $db->insert_id], 201);
        } else {
            Response::error("Error al crear el canal: " . $stmt->error, 500);
        }
    }

    // ── PUT /contacto  (admin: editar canal) ──────────────────
    public function actualizarCanal($payload, $params = []) {
        if ($payload['rol'] !== 'admin' && $payload['rol'] !== 1) {
            Response::error("Solo administradores pueden gestionar canales", 403);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['id'])) {
            Response::error("ID de canal requerido", 400);
            return;
        }

        $db = Database::getConnection();
        $id = intval($data['id']);

        $mapping = [
            'tipo'     => 's',
            'etiqueta' => 's',
            'valor'    => 's',
            'activo'   => 'i',
            'orden'    => 'i',
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

        if (empty($fields)) {
            Response::error("No hay campos para actualizar", 400);
            return;
        }

        $values[] = $id;
        $types   .= 'i';
        $stmt     = $db->prepare("UPDATE canales_contacto SET " . implode(', ', $fields) . " WHERE id = ?");
        $this->bindDynamic($stmt, $types, $values);

        if ($stmt->execute()) {
            Response::success(["message" => "Canal actualizado"]);
        } else {
            Response::error("Error al actualizar el canal", 500);
        }
    }

    // ── DELETE /contacto  (admin: eliminar canal) ─────────────
    public function eliminarCanal($payload, $params = []) {
        if ($payload['rol'] !== 'admin' && $payload['rol'] !== 1) {
            Response::error("Solo administradores pueden gestionar canales", 403);
            return;
        }

        $id   = intval($params['id'] ?? 0);

        if (!$id) {
            Response::error("ID de canal requerido", 400);
            return;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM canales_contacto WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            Response::success(["message" => "Canal eliminado"]);
        } else {
            Response::error("Canal no encontrado", 404);
        }
    }

    // ── POST /contacto/mensaje  (usuario: enviar mensaje) ─────
    public function enviarMensaje($params = []) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['nombre']) || empty($data['email']) || empty($data['asunto']) || empty($data['mensaje'])) {
            Response::error("nombre, email, asunto y mensaje son obligatorios", 400);
            return;
        }

        $db      = Database::getConnection();
        $nombre  = trim($data['nombre']);
        $email   = trim($data['email']);
        $asunto  = trim($data['asunto']);
        $mensaje = trim($data['mensaje']);

        $stmt = $db->prepare("
            INSERT INTO mensajes_contacto (nombre, email, asunto, mensaje)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssss", $nombre, $email, $asunto, $mensaje);

        if ($stmt->execute()) {
            Response::success(["message" => "Mensaje enviado correctamente"], 201);
        } else {
            Response::error("Error al guardar el mensaje: " . $stmt->error, 500);
        }
    }

    // ── GET /contacto/mensajes  (admin: ver mensajes) ─────────
    public function getMensajes($payload, $params = []) {
        if ($payload['rol'] !== 'admin' && $payload['rol'] !== 1) {
            Response::error("Solo administradores pueden ver los mensajes", 403);
            return;
        }

        $db     = Database::getConnection();
        $result = $db->query("SELECT * FROM mensajes_contacto ORDER BY fecha DESC");

        if (!$result) {
            Response::error("Error al obtener mensajes: " . $db->error, 500);
            return;
        }

        $data = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($data as &$row) {
            $row['leido'] = (bool) $row['leido'];
        }

        Response::success($data);
    }

    // ── DELETE /contacto/mensaje  (admin: eliminar mensaje) ───
    public function eliminarMensaje($payload, $params = []) {
        if ($payload['rol'] !== 'admin' && $payload['rol'] !== 1) {
            Response::error("Solo administradores pueden eliminar mensajes", 403);
            return;
        }

        $id   = intval($params['id_mensaje'] ?? 0);

        if (!$id) {
            Response::error("ID de mensaje requerido", 400);
            return;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM mensajes_contacto WHERE id_mensaje = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            Response::success(["message" => "Mensaje eliminado"]);
        } else {
            Response::error("Mensaje no encontrado", 404);
        }
    }

    // ── POST /contacto/mensaje/leido  (admin: marcar leído) ───
    public function marcarLeido($payload, $params = []) {
        if ($payload['rol'] !== 'admin' && $payload['rol'] !== 1) {
            Response::error("Solo administradores", 403);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        $id   = intval($data['id_mensaje'] ?? 0);

        if (!$id) {
            Response::error("ID de mensaje requerido", 400);
            return;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare("UPDATE mensajes_contacto SET leido = 1 WHERE id_mensaje = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            Response::success(["message" => "Mensaje marcado como leído"]);
        } else {
            Response::error("Error al actualizar el mensaje", 500);
        }
    }

    // ── Helpers ───────────────────────────────────────────────

    private function defaultCanales(): array {
        return [
            ['id' => null, 'tipo' => 'whatsapp',  'etiqueta' => 'WhatsApp',           'valor' => 'https://wa.me/34600000000', 'activo' => true],
            ['id' => null, 'tipo' => 'email',      'etiqueta' => 'Correo electrónico', 'valor' => 'mailto:soporte@skytrip.com', 'activo' => true],
            ['id' => null, 'tipo' => 'telefono',   'etiqueta' => 'Teléfono',           'valor' => 'tel:+34900000000', 'activo' => true],
        ];
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
