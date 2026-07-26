<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;

final class SystemCommandService
{
    private const ALLOWLIST = [
        'service.status' => ['command' => ['/usr/bin/systemctl', 'status'], 'args' => ['service']],
        'service.reload' => ['command' => ['/usr/bin/systemctl', 'reload'], 'args' => ['service']],
        'exim.validate' => ['command' => ['/usr/sbin/exim', '-bV'], 'args' => []],
        'dovecot.validate' => ['command' => ['/usr/sbin/dovecot', '-n'], 'args' => []],
        'nginx.validate' => ['command' => ['/usr/sbin/nginx', '-t'], 'args' => []],
        'rspamd.validate' => ['command' => ['/usr/bin/rspamadm', 'configtest'], 'args' => []],
        'fail2ban.validate' => ['command' => ['/usr/bin/fail2ban-client', '-t'], 'args' => []],
        'fail2ban.status' => ['command' => ['/usr/bin/fail2ban-client', 'status'], 'args' => []],
        'disk.usage' => ['command' => ['/usr/bin/du', '-sh'], 'args' => ['path']],
    ];

    public function build(string $action, array $input = []): array
    {
        if (!isset(self::ALLOWLIST[$action])) {
            throw new InvalidArgumentException('Command is not allowlisted.');
        }

        $definition = self::ALLOWLIST[$action];
        $command = $definition['command'];

        foreach ($definition['args'] as $argName) {
            if (!isset($input[$argName]) || $input[$argName] === '') {
                throw new InvalidArgumentException("Argument [$argName] is required.");
            }

            $command[] = $this->sanitizeArgument((string) $input[$argName]);
        }

        return [
            'action' => $action,
            'command' => $command,
            'dry_run' => (bool) ($input['dry_run'] ?? true),
            'timeout' => (int) ($input['timeout'] ?? 10),
        ];
    }

    public function validateConfigAction(string $service, ?string $renderedPath = null): array
    {
        return match ($service) {
            'nginx' => ['action' => 'nginx.validate', 'params' => ['rendered_path' => $renderedPath], 'dry_run' => true, 'timeout' => 20],
            'exim' => ['action' => 'exim.validate', 'params' => ['rendered_path' => $renderedPath], 'dry_run' => true, 'timeout' => 20],
            'dovecot' => ['action' => 'dovecot.validate', 'params' => ['rendered_path' => $renderedPath], 'dry_run' => true, 'timeout' => 20],
            'rspamd' => ['action' => 'rspamd.validate', 'params' => ['rendered_path' => $renderedPath], 'dry_run' => true, 'timeout' => 20],
            'fail2ban' => ['action' => 'fail2ban.validate', 'params' => ['rendered_path' => $renderedPath], 'dry_run' => true, 'timeout' => 20],
            default => throw new InvalidArgumentException('Unsupported service validator.'),
        };
    }

    private function sanitizeArgument(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9._\/:-]+$/', $value)) {
            throw new InvalidArgumentException('Unsafe command argument detected.');
        }

        return $value;
    }
}
