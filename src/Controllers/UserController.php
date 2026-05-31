<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class UserController {

    public function me($payload, $params = []) {
        $db = Database::getConnection();

        $hasSaldo = false;
        $r = $db->query("SHOW COLUMNS FROM usuarios LIKE 'saldo'");
        if ($r && $r->num_rows > 0) $hasSaldo = true;

        $saldoField = $hasSaldo ? ', saldo' : '';

        $idUsuario = intval($payload['id_usuario']);
        $stmt = $db->prepare("
            SELECT id_usuario, usuario, email, rol,
                   direccion, pais_nacimiento, fecha_nacimiento,
                   notifications_enabled, totp_enabled, created_at$saldoField
            FROM   usuarios
            WHERE  id_usuario = ?
        ");
        $stmt->bind_param("i", $idUsuario);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $row['notifications_enabled'] = (bool) $row['notifications_enabled'];
            $row['totp_enabled']          = (bool) $row['totp_enabled'];
            if (!$hasSaldo) {
                $row['saldo'] = 5000.00;
            } else {
                $row['saldo'] = (float) ($row['saldo'] ?? 5000.00);
            }
            Response::success($row);
        } else {
            Response::error("Usuario no encontrado", 404);
        }
    }

    public function update($payload, $params = []) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (!is_array($data)) {
            Response::error("Datos no válidos", 400);
            return;
        }

        $usuario = trim($data['usuario'] ?? '');
        $email   = trim($data['email']   ?? '');

        if ($usuario === '' || $email === '') {
            Response::error("Usuario y email son obligatorios", 400);
            return;
        }

        $idUsuario      = intval($payload['id_usuario'] ?? 0);
        $direccion      = trim($data['direccion']        ?? '');
        $paisNacimiento = trim($data['pais_nacimiento']  ?? '');
        $fechaNac       = trim($data['fecha_nacimiento'] ?? '');

        $db   = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE usuarios
            SET    usuario          = ?,
                   email            = ?,
                   direccion        = NULLIF(?, ''),
                   pais_nacimiento  = NULLIF(?, ''),
                   fecha_nacimiento = NULLIF(?, '')
            WHERE  id_usuario = ?
        ");

        if (!$stmt) {
            Response::error("Error preparando consulta: " . $db->error, 500);
            return;
        }

        $stmt->bind_param("sssssi", $usuario, $email, $direccion, $paisNacimiento, $fechaNac, $idUsuario);

        if ($stmt->execute()) {
            $stmt->close();
            Response::success(["message" => "Usuario actualizado"]);
        } else {
            $err = $stmt->error;
            $stmt->close();
            Response::error("Error al actualizar: " . $err, 500);
        }
    }

    public function updatePreferences($payload, $params = []) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['notifications_enabled'])) {
            Response::error("Parámetro notifications_enabled requerido", 400);
            return;
        }

        $enabled   = $data['notifications_enabled'] ? 1 : 0;
        $idUsuario = intval($payload['id_usuario']);
        $db        = Database::getConnection();
        $stmt      = $db->prepare("UPDATE usuarios SET notifications_enabled = ? WHERE id_usuario = ?");
        $stmt->bind_param("ii", $enabled, $idUsuario);

        if ($stmt->execute()) {
            Response::success(["message" => "Preferencias actualizadas"]);
        } else {
            Response::error("Error al actualizar preferencias", 500);
        }
    }

    public function deleteAccount($payload, $params = []) {
        $db        = Database::getConnection();
        $idUsuario = intval($payload['id_usuario']);

        $stmt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM   reservas
            WHERE  id_usuario = ? AND estado = 'confirmada'
        ");
        $stmt->bind_param("i", $idUsuario);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row['total'] > 0) {
            Response::error("No puedes eliminar la cuenta con reservas activas", 409);
            return;
        }

        $stmt = $db->prepare("DELETE FROM usuarios WHERE id_usuario = ?");
        $stmt->bind_param("i", $idUsuario);

        if ($stmt->execute()) {
            Response::success(["message" => "Cuenta eliminada correctamente"]);
        } else {
            Response::error("Error al eliminar la cuenta", 500);
        }
    }
}