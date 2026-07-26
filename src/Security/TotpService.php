<?php

declare(strict_types=1);

namespace MailPanel\Security;

use InvalidArgumentException;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const MIN_SECRET_LENGTH = 16;
    private const MAX_SECRET_LENGTH = 64;
    private const MAX_VERIFICATION_WINDOW = 2;

    private readonly string $issuer;
    private readonly int $window;
    private readonly string $encryptionKey;

    public function __construct(
        string $issuer = 'MailPanel',
        int $window = 1,
        string $encryptionKey = '',
    ) {
        $issuer = trim($issuer);

        $this->issuer = $issuer !== '' ? substr($issuer, 0, 64) : 'MailPanel';
        $this->window = max(0, min($window, self::MAX_VERIFICATION_WINDOW));
        $this->encryptionKey = $encryptionKey;
    }

    public function generateSecret(int $length = 32): string
    {
        $length = max(self::MIN_SECRET_LENGTH, min($length, self::MAX_SECRET_LENGTH));
        $secret = '';

        for ($index = 0; $index < $length; $index++) {
            $secret .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $secret;
    }

    public function otpauthUri(string $label, string $secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s',
            rawurlencode($this->issuer),
            rawurlencode($label),
            rawurlencode($secret),
            rawurlencode($this->issuer)
        );
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        try {
            $secret = $this->revealSecret($secret);
        } catch (InvalidArgumentException) {
            return false;
        }

        $normalizedCode = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $normalizedCode)) {
            return false;
        }

        $timestamp ??= time();
        $slice = (int) floor($timestamp / 30);

        for ($offset = -$this->window; $offset <= $this->window; $offset++) {
            if (hash_equals($this->codeForSlice($secret, $slice + $offset), $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    public function protectSecret(string $secret): string
    {
        $key = $this->normalizedEncryptionKey();
        if ($key === null || str_starts_with($secret, 'enc:v1:')) {
            return $secret;
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext)) {
            throw new InvalidArgumentException('Unable to encrypt TOTP secret.');
        }

        return 'enc:v1:' . base64_encode(json_encode([
            'n' => base64_encode($nonce),
            't' => base64_encode($tag),
            'c' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR));
    }

    public function revealSecret(string $secret): string
    {
        if (!str_starts_with($secret, 'enc:v1:')) {
            return $secret;
        }

        $key = $this->normalizedEncryptionKey();
        if ($key === null) {
            throw new InvalidArgumentException('TOTP encryption key is not configured.');
        }

        $payload = json_decode((string) base64_decode(substr($secret, 7), true), true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Invalid encrypted TOTP secret.');
        }

        $nonce = base64_decode((string) ($payload['n'] ?? ''), true);
        $tag = base64_decode((string) ($payload['t'] ?? ''), true);
        $ciphertext = base64_decode((string) ($payload['c'] ?? ''), true);
        if (!is_string($nonce) || !is_string($tag) || !is_string($ciphertext) || strlen($nonce) !== 12 || strlen($tag) !== 16) {
            throw new InvalidArgumentException('Invalid encrypted TOTP secret.');
        }

        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($plain) || $plain === '') {
            throw new InvalidArgumentException('Unable to decrypt TOTP secret.');
        }

        return $plain;
    }

    private function codeForSlice(string $secret, int $slice): string
    {
        $binarySecret = $this->decodeBase32($secret);
        $counter = pack('N*', 0) . pack('N*', $slice);
        $hash = hash_hmac('sha1', $counter, $binarySecret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $chunk = substr($hash, $offset, 4);
        $value = unpack('N', $chunk)[1] & 0x7fffffff;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function decodeBase32(string $secret): string
    {
        $clean = strtoupper(trim($secret));
        if ($clean === '' || preg_match('/[^A-Z2-7]/', $clean)) {
            throw new InvalidArgumentException('Invalid TOTP secret.');
        }

        $bits = '';
        foreach (str_split($clean) as $char) {
            $bits .= str_pad((string) decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        for ($index = 0; $index + 8 <= strlen($bits); $index += 8) {
            $binary .= chr(bindec(substr($bits, $index, 8)));
        }

        return $binary;
    }

    private function normalizedEncryptionKey(): ?string
    {
        $key = trim($this->encryptionKey);
        if ($key === '') {
            return null;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                $key = $decoded;
            }
        }

        return strlen($key) === 32 ? $key : hash('sha256', $key, true);
    }
}
