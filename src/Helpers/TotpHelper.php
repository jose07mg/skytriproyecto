<?php

namespace App\Helpers;

class TotpHelper {

    public static function verify(string $secret, string $code, int $window = 1): bool {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $key      = self::base32Decode($secret);
        $timeStep = intval(time() / 30);

        for ($i = -$window; $i <= $window; $i++) {
            $t    = $timeStep + $i;
            $msg  = self::int64ToBytes($t);
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

    private static function int64ToBytes(int $value): string {
        $bytes = str_repeat("\0", 8);
        for ($i = 7; $i >= 0; $i--) {
            $bytes[$i] = chr($value & 0xFF);
            $value     = intdiv($value, 256);
        }
        return $bytes;
    }

    private static function base32Decode(string $encoded): string {
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
