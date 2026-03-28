<?php
/**
 * TOTP (Time-based One-Time Password) Library
 * Compatible with Google Authenticator
 * No external dependencies required
 */

class TOTP
{
    private static $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a random secret key (base32 encoded)
     */
    public static function generateSecret($length = 16)
    {
        $secret = '';
        $chars = self::$base32Chars;
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Get the current TOTP code for a given secret
     */
    public static function getCode($secret, $timeSlice = null)
    {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        $secretKey = self::base32Decode($secret);

        // Pack time into 8 bytes
        $time = chr(0) . chr(0) . chr(0) . chr(0) . pack('N*', $timeSlice);

        // HMAC-SHA1
        $hmac = hash_hmac('sha1', $time, $secretKey, true);

        // Extract 4 bytes from HMAC
        $offset = ord($hmac[19]) & 0xf;
        $code = (
            ((ord($hmac[$offset]) & 0x7f) << 24) |
            ((ord($hmac[$offset + 1]) & 0xff) << 16) |
            ((ord($hmac[$offset + 2]) & 0xff) << 8) |
            (ord($hmac[$offset + 3]) & 0xff)
        ) % pow(10, 6);

        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a TOTP code (allows 1 time window tolerance)
     */
    public static function verifyCode($secret, $code, $discrepancy = 1)
    {
        $currentTimeSlice = floor(time() / 30);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = self::getCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate a QR code URL for Google Authenticator
     */
    public static function getQRCodeUrl($label, $secret, $issuer = '11labs Admin')
    {
        $url = 'otpauth://totp/' . urlencode($issuer . ':' . $label)
            . '?secret=' . $secret
            . '&issuer=' . urlencode($issuer)
            . '&digits=6'
            . '&period=30';

        // Use Google Charts API to generate QR code
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($url);
    }

    /**
     * Decode base32 string
     */
    private static function base32Decode($input)
    {
        $input = strtoupper($input);
        $chars = self::$base32Chars;
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $val = strpos($chars, $input[$i]);
            if ($val === false)
                continue;

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $output;
    }
}
