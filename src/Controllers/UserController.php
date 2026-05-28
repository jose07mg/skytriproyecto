<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class UserController {

    // ==========================
    // OBTENER USUARIO LOGUEADO
    // ==========================
    public function me($payload, $params = []) {
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT id_usuario, usuario, email, fecha_registro FROM usuarios WHERE id_usuario=?");
        $stmt->bind_param("i", $payload['id_usuario']);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $isAdmin = strtolower($user['usuario'] ?? '') === 'admin'
                || strtolower($user['email'] ?? '') === 'admin@gmail.com';
            $user['role'] = $isAdmin ? 'admin' : 'user';
            $user['rol'] = $user['role'];
            Response::success($user);
        } else {
            Response::error("Usuario no encontrado", 404);
        }
    }

    // ==========================
    // ACTUALIZAR PERFIL
    // ==========================
    public function update($payload, $params = []) {
        $db = Database::getConnection();

        $data = json_decode(file_get_contents("php://input"), true);

        $stmt = $db->prepare("
            UPDATE usuarios 
            SET usuario = ?, email = ?
            WHERE id_usuario = ?
        ");

        $stmt->bind_param(
            "ssi",
            $data['usuario'],
            $data['email'],
            $payload['id_usuario']
        );

        $stmt->execute();

        Response::success(["message" => "Usuario actualizado"]);
    }

    // ==========================
    // CAMBIAR PASSWORD
    // ==========================
    public function changePassword($payload, $params = []) {
        $db = Database::getConnection();

        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['password'])) {
            Response::error("La contraseÃ±a es obligatoria", 400);
        }

        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("
            UPDATE usuarios 
            SET password = ?
            WHERE id_usuario = ?
        ");

        $stmt->bind_param(
            "si",
            $passwordHash,
            $payload['id_usuario']
        );

        $stmt->execute();

        Response::success(["message" => "ContraseÃ±a actualizada"]);
    }
}
