<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class TwoFaController {

    // ── GET /2fa/status ───────────────────────────────────────
    public function getStatus($payload, $params = []) {
        $db   = Database::getConnection();
        $stmt = $db->prepare("SELECT totp_enabled FROM usuarios WHERE id_usuario = ?");
        $stmt->bind_param("i", $payload['id_usuario']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            Response::error("Usuario no encontrado", 404);
            return;
        }

        Response::success(['totp_enabled' => (bool) $row['totp_enabled']]);
    }

    // ── POST /2fa/setup ───────────────────────────────────────
    public function setup($payload, $params = []) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['secret']) || empty($data['code'])) {
            Response::error("secret y code son obligatorios", 400);
            return;
        }

        $secret = trim($data['secret']);
        $code   = trim($data['code']);

        if (!$this->verifyTotp($secret, $code)) {
            Response::error("Código incorrecto. Asegúrate de que tu autenticador esté sincronizado.", 401);
            return;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare("UPDATE usuarios SET totp_secret = ?, totp_enabled = 1 WHERE id_usuario = ?");
        $stmt->bind_param("si", $secret, $payload['id_usuario']);

        if ($stmt->execute()) {
            Response::success(["message" => "2FA activado correctamente"]);
        } else {
            Response::error("Error al guardar el secreto 2FA", 500);
        }
    }

    // ── POST /2fa/disable ─────────────────────────────────────
    public function disable($payload, $params = []) {
        $db   = Database::getConnection();
        $stmt = $db->prepare("UPDATE usuarios SET totp_secret = NULL, totp_enabled = 0 WHERE id_usuario = ?");
        $stmt->bind_param("i", $payload['id_usuario']);

        if ($stmt->execute()) {
            Response::success(["message" => "2FA desactivado correctamente"]);
        } else {
            Response::error("Error al desactivar 2FA", 500);
        }
    }

    // ── TOTP Verification ─────────────────────────────────────

    private function verifyTotp(string $secret, string $code, int $window = 1): bool {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $key      = $this->base32Decode($secret);
        $timeStep = intval(time() / 30);

        for ($i = -$window; $i <= $window; $i++) {
            $t    = $timeStep + $i;
            $msg  = $this->int64ToBytes($t);
            $hash = hash_hmac('sha1', $msg, $key, true);

            $offset = ord($hash[strlen($hash) - 1]) & 0xF;
            $otp    = (
                ((ord($hash[$offset])     & 0x7F) << 24) |
                ((ord($hash[$offset + 1]) & 0xFF) << 16) |
                ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
                 (ord($hash[$offset + 3]) & 0xFF)
            ) % 1000000;

            if (str_pad((string) $otp, 6, '0', STR_PAD_LEFT) === $code) {
                return true;
            }
        }

        return false;
    }

    private function int64ToBytes(int $value): string {
        $bytes = str_repeat("\0", 8);
        for ($i = 7; $i >= 0; $i--) {
            $bytes[$i] = chr($value & 0xFF);
            $value     = intdiv($value, 256);
        }
        return $bytes;
    }

    private function base32Decode(string $encoded): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded  = strtoupper(rtrim($encoded, '='));
        $output   = '';
        $v        = 0;
        $vbits    = 0;

        for ($i = 0, $len = strlen($encoded); $i < $len; $i++) {
            $pos = strpos($alphabet, $encoded[$i]);
            if ($pos === false) continue;
            $v      = ($v << 5) | $pos;
            $vbits += 5;
            if ($vbits >= 8) {
                $vbits  -= 8;
                $output .= chr(($v >> $vbits) & 0xFF);
            }
        }

        return $output;
    }
}
