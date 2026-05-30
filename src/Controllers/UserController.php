<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class UserController {

    public function me($payload, $params = []) {
        $db = Database::getConnection();

        // Check if saldo column exists
        $hasSaldo = false;
        $r = $db->query("SHOW COLUMNS FROM usuarios LIKE 'saldo'");
        if ($r && $r->num_rows > 0) $hasSaldo = true;

        $saldoField = $hasSaldo ? ', saldo' : '';

        $stmt = $db->prepare("
            SELECT id_usuario, usuario, email, rol,
                   direccion, pais_nacimiento, fecha_nacimiento,
                   notifications_enabled, totp_enabled, created_at$saldoField
            FROM   usuarios
            WHERE  id_usuario = ?
        ");
        $stmt->bind_param("i", $payload['id_usuario']);
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

        $db   = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE usuarios
            SET    usuario          = ?,
                   email            = ?,
                   direccion        = ?,
                   pais_nacimiento  = ?,
                   fecha_nacimiento = ?
            WHERE  id_usuario = ?
        ");
        $stmt->bind_param(
            "sssssi",
            $data['usuario'],
            $data['email'],
            $data['direccion']       ?? null,
            $data['pais_nacimiento'] ?? null,
            $data['fecha_nacimiento']?? null,
            $payload['id_usuario']
        );

        if ($stmt->execute()) {
            Response::success(["message" => "Usuario actualizado"]);
        } else {
            Response::error("Error al actualizar el usuario", 500);
        }
    }

    public function updatePreferences($payload, $params = []) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['notifications_enabled'])) {
            Response::error("Parámetro notifications_enabled requerido", 400);
            return;
        }

        $enabled = $data['notifications_enabled'] ? 1 : 0;
        $db      = Database::getConnection();
        $stmt    = $db->prepare("UPDATE usuarios SET notifications_enabled = ? WHERE id_usuario = ?");
        $stmt->bind_param("ii", $enabled, $payload['id_usuario']);

        if ($stmt->execute()) {
            Response::success(["message" => "Preferencias actualizadas"]);
        } else {
            Response::error("Error al actualizar preferencias", 500);
        }
    }

    public function deleteAccount($payload, $params = []) {
        $db = Database::getConnection();

        // Bloquear si tiene reservas activas
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM   reservas
            WHERE  id_usuario = ? AND estado = 'confirmada'
        ");
        $stmt->bind_param("i", $payload['id_usuario']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row['total'] > 0) {
            Response::error("No puedes eliminar la cuenta con reservas activas", 409);
            return;
        }

        $stmt = $db->prepare("DELETE FROM usuarios WHERE id_usuario = ?");
        $stmt->bind_param("i", $payload['id_usuario']);

        if ($stmt->execute()) {
            Response::success(["message" => "Cuenta eliminada correctamente"]);
        } else {
            Response::error("Error al eliminar la cuenta", 500);
        }
    }
}
