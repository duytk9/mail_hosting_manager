<?php

declare(strict_types=1);

namespace MailPanel\Support;

use InvalidArgumentException;

final class Validator
{
    private const RESERVED_LINUX_USERNAMES = [
        'root',
        'daemon',
        'bin',
        'sys',
        'sync',
        'games',
        'man',
        'lp',
        'mail',
        'news',
        'uucp',
        'proxy',
        'www-data',
        'backup',
        'list',
        'irc',
        'gnats',
        'nobody',
        'systemd-network',
        'systemd-resolve',
        'sshd',
        'mysql',
        'postgres',
        'redis',
        'nginx',
        'apache',
        'postfix',
        'dovecot',
        'exim',
        'clamav',
        'rspamd',
        'fail2ban',
        'vmail',
        'mailpanel',
        'mailpanel-agent',
    ];

    public static function required(array $input, array $fields): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $input) || $input[$field] === '' || $input[$field] === null) {
                throw new InvalidArgumentException("Field [$field] is required.");
            }
        }
    }

    public static function fqdn(string $domain): void
    {
        if (!preg_match('/^(?=.{1,253}$)(?!-)([a-z0-9-]{1,63}\.)+[a-z]{2,63}$/', $domain)) {
            throw new InvalidArgumentException('Domain must be a valid FQDN.');
        }
    }

    public static function localPart(string $localPart): void
    {
        if (!preg_match('/^[a-z0-9._%+-]+$/', $localPart)) {
            throw new InvalidArgumentException('Mailbox local_part contains unsupported characters.');
        }
    }

    public static function linuxUsername(string $username): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username)) {
            throw new InvalidArgumentException('Linux username contains unsupported characters.');
        }

        if (in_array($username, self::RESERVED_LINUX_USERNAMES, true)) {
            throw new InvalidArgumentException('Linux username is reserved.');
        }
    }
}
