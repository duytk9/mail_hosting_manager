<?php

declare(strict_types=1);

namespace MailPanel\Services;

use RuntimeException;

final class PasswordResetMailService
{
    /** @var resource|null */
    private $smtpConnection = null;

    public function __construct(
        private readonly array $config = [],
        private readonly string $secretKey = ''
    ) {
    }

    public function send(string $recipientEmail, string $recipientName, string $resetUrl, int $ttlSeconds): void
    {
        $recipientEmail = strtolower(trim($recipientEmail));
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Password reset recipient email is invalid.');
        }

        $fromEmail = strtolower(trim((string) ($this->config['from_email'] ?? '')));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Password reset sender email is not configured.');
        }

        $fromName = $this->safeHeaderText((string) ($this->config['from_name'] ?? 'MailPanel'));
        $subject = $this->safeHeaderText((string) ($this->config['subject'] ?? 'Đặt lại mật khẩu MailPanel'));
        $message = $this->buildMessage($recipientEmail, $recipientName, $resetUrl, $ttlSeconds, $fromEmail, $fromName, $subject);

        if ($this->transport() === 'smtp') {
            $this->sendViaSmtp($recipientEmail, $fromEmail, $message);
            return;
        }

        $headers = $this->messageHeaders($fromEmail, $fromName);
        if (!mail($recipientEmail, $subject, $message['body'], implode("\r\n", $headers))) {
            throw new RuntimeException('Unable to send password reset email.');
        }
    }

    private function transport(): string
    {
        return (string) ($this->config['transport'] ?? 'mail') === 'smtp' ? 'smtp' : 'mail';
    }

    /**
     * @return array{subject: string, body: string, data: string}
     */
    private function buildMessage(
        string $recipientEmail,
        string $recipientName,
        string $resetUrl,
        int $ttlSeconds,
        string $fromEmail,
        string $fromName,
        string $subject
    ): array {
        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        $safeName = $this->safeBodyText(trim($recipientName) !== '' ? trim($recipientName) : 'Admin');

        $body = implode("\n", [
            'Xin chào ' . $safeName . ',',
            '',
            'Bạn vừa yêu cầu đặt lại mật khẩu quản trị MailPanel.',
            'Đường dẫn này có hiệu lực trong ' . $minutes . ' phút và chỉ dùng được một lần:',
            '',
            $resetUrl,
            '',
            'Nếu bạn không yêu cầu thao tác này, hãy bỏ qua email và kiểm tra lại bảo mật tài khoản.',
            '',
            'MailPanel',
        ]);

        $headers = $this->messageHeaders($fromEmail, $fromName);
        $headers[] = 'To: ' . $recipientEmail;
        $headers[] = 'Subject: ' . $this->encodedHeader($subject);

        return [
            'subject' => $subject,
            'body' => $body,
            'data' => implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n", "\r"], "\n", $body) . "\r\n",
        ];
    }

    /**
     * @return array<int, string>
     */
    private function messageHeaders(string $fromEmail, string $fromName): array
    {
        return [
            'From: ' . $this->encodedHeader($fromName) . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Auto-Response-Suppress: All',
        ];
    }

    /**
     * @param array{subject: string, body: string, data: string} $message
     */
    private function sendViaSmtp(string $recipientEmail, string $fromEmail, array $message): void
    {
        $smtp = is_array($this->config['smtp'] ?? null) ? $this->config['smtp'] : [];
        $host = strtolower(trim((string) ($smtp['host'] ?? '')));
        if ($host === '' || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $host) !== 1) {
            throw new RuntimeException('Password reset SMTP host is invalid.');
        }

        $port = max(1, min(65535, (int) ($smtp['port'] ?? 587)));
        $timeout = max(3, min(30, (int) ($smtp['timeout_seconds'] ?? 15)));
        $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'starttls')));
        if (!in_array($encryption, ['none', 'ssl', 'tls', 'starttls'], true)) {
            throw new RuntimeException('Password reset SMTP encryption mode is invalid.');
        }

        $username = trim((string) ($smtp['username'] ?? ''));
        $password = $this->smtpPassword($smtp);
        if ($username !== '' && $encryption === 'none') {
            throw new RuntimeException('Password reset SMTP AUTH requires TLS or SSL.');
        }

        $remote = ($encryption === 'ssl' || $encryption === 'tls' ? 'ssl://' : '') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);

        $connection = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($connection)) {
            throw new RuntimeException('Unable to connect to password reset SMTP server.');
        }

        $this->smtpConnection = $connection;
        stream_set_timeout($connection, $timeout);

        try {
            $this->expect([220]);
            $this->command('EHLO ' . $this->smtpLocalName(), [250]);

            if ($encryption === 'starttls') {
                $this->command('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Unable to enable STARTTLS for password reset SMTP.');
                }
                $this->command('EHLO ' . $this->smtpLocalName(), [250]);
            }

            if ($username !== '') {
                if ($password === '') {
                    throw new RuntimeException('Password reset SMTP password is not configured.');
                }
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($username), [334]);
                $this->command(base64_encode($password), [235]);
            }

            $this->command('MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command('RCPT TO:<' . $recipientEmail . '>', [250, 251]);
            $this->command('DATA', [354]);
            $this->command($this->smtpDotStuff($message['data']) . "\r\n.", [250]);
            $this->command('QUIT', [221]);
        } finally {
            fclose($connection);
            $this->smtpConnection = null;
        }
    }

    /**
     * @param array<string, mixed> $smtp
     */
    private function smtpPassword(array $smtp): string
    {
        $encrypted = trim((string) ($smtp['password_encrypted'] ?? ''));
        if ($encrypted === '') {
            return '';
        }

        if ($this->secretKey === '') {
            throw new RuntimeException('Password reset SMTP secret key is not configured.');
        }

        $payload = json_decode((string) base64_decode($encrypted, true), true);
        if (!is_array($payload) || ($payload['alg'] ?? '') !== 'aes-256-gcm') {
            throw new RuntimeException('Password reset SMTP password payload is invalid.');
        }

        $nonce = base64_decode((string) ($payload['nonce'] ?? ''), true);
        $tag = base64_decode((string) ($payload['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($payload['ciphertext'] ?? ''), true);
        if ($nonce === false || $tag === false || $ciphertext === false) {
            throw new RuntimeException('Password reset SMTP password payload is invalid.');
        }

        $password = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            hash('sha256', $this->secretKey, true),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
        if (!is_string($password)) {
            throw new RuntimeException('Unable to decrypt password reset SMTP password.');
        }

        return $password;
    }

    private function expect(array $codes): string
    {
        if (!is_resource($this->smtpConnection)) {
            throw new RuntimeException('Password reset SMTP connection is not open.');
        }

        $response = '';
        do {
            $line = fgets($this->smtpConnection, 2048);
            if ($line === false) {
                throw new RuntimeException('Password reset SMTP server did not respond.');
            }
            $response .= $line;
            $code = (int) substr($line, 0, 3);
            $more = isset($line[3]) && $line[3] === '-';
        } while ($more);

        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('Password reset SMTP command failed.');
        }

        return $response;
    }

    private function command(string $command, array $expectedCodes): string
    {
        if (!is_resource($this->smtpConnection)) {
            throw new RuntimeException('Password reset SMTP connection is not open.');
        }

        fwrite($this->smtpConnection, $command . "\r\n");

        return $this->expect($expectedCodes);
    }

    private function smtpLocalName(): string
    {
        $hostname = gethostname();
        $hostname = is_string($hostname) ? strtolower($hostname) : 'localhost';

        return preg_match('/\A[a-z0-9.-]+\z/', $hostname) === 1 ? $hostname : 'localhost';
    }

    private function smtpDotStuff(string $data): string
    {
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        $lines = explode("\n", $data);
        $lines = array_map(static fn (string $line): string => str_starts_with($line, '.') ? '.' . $line : $line, $lines);

        return implode("\r\n", $lines);
    }

    private function safeHeaderText(string $value): string
    {
        $value = trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $value) ?? '');

        return $value !== '' ? mb_substr($value, 0, 120) : 'MailPanel';
    }

    private function safeBodyText(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $value) ?? '';

        return trim($value) !== '' ? mb_substr(trim($value), 0, 120) : 'Admin';
    }

    private function encodedHeader(string $value): string
    {
        $value = $this->safeHeaderText($value);
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
