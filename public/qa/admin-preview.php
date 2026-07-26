<?php

declare(strict_types=1);

use MailPanel\Bootstrap\Environment;
use MailPanel\Support\View;

require_once __DIR__ . '/../../vendor/autoload.php';
Environment::load(__DIR__ . '/../..');

$appEnv = strtolower(trim((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local'))) ?: 'local';
$previewEnabled = filter_var($_ENV['MAILPANEL_ENABLE_QA_PREVIEW'] ?? getenv('MAILPANEL_ENABLE_QA_PREVIEW') ?: false, FILTER_VALIDATE_BOOL);
$previewKey = trim((string) ($_ENV['MAILPANEL_QA_PREVIEW_KEY'] ?? getenv('MAILPANEL_QA_PREVIEW_KEY') ?: ''));
$requestPreviewKey = trim((string) ($_GET['preview_key'] ?? $_SERVER['HTTP_X_MAILPANEL_QA_PREVIEW_KEY'] ?? ''));
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
$forwardedHost = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
$isLocalRequest = $clientIp === ''
    || in_array($clientIp, ['127.0.0.1', '::1'], true)
    || str_starts_with($clientIp, '::ffff:127.');
$hasForwardedClient = $forwardedFor !== '' || $forwardedHost !== '';
$previewKeyConfigured = strlen($previewKey) >= 32 && preg_match('/\A[A-Za-z0-9._~:-]{32,256}\z/', $previewKey) === 1;
$previewKeyMatches = $previewKeyConfigured && hash_equals($previewKey, $requestPreviewKey);
$strictLocalPreview = $appEnv === 'local' && $isLocalRequest && !$hasForwardedClient;

if (!$previewEnabled || $appEnv === 'production' || (!$strictLocalPreview && !$previewKeyMatches)) {
    http_response_code(404);
    echo 'Not found';
    return;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$view = new View(__DIR__ . '/../../src/Views');
$pageKey = (string) ($_GET['page'] ?? 'dashboard');

$identity = [
    'name' => 'Ops Admin',
    'role' => 'super_admin',
    'linux_username' => 'mp-admin-ops',
    'email' => 'ops@example.com',
    'force_password_change' => false,
];

$csrfToken = 'preview-token';
$canAccess = static fn (string|array $permissions, bool $matchAny = false): bool => true;
$title = 'Admin Preview';
$active = $pageKey;
$flashSuccess = '';
$flashError = '';
$sharedAdminContext = [
    'identity' => $identity,
    'csrfToken' => $csrfToken,
    'canAccess' => $canAccess,
    'view' => $view,
];

$pagination = [
    'current_page' => 2,
    'total_pages' => 8,
    'total_items' => 86,
    'from' => 11,
    'to' => 20,
    'query_key' => 'page',
    'base_path' => '/admin/' . $pageKey,
    'params' => [],
];

$packages = [
    ['id' => 1, 'name' => 'Starter', 'description' => 'Gói cơ bản', 'max_domains' => 3, 'max_mailboxes' => 25, 'max_aliases' => 50, 'max_forwarders' => 20, 'max_total_quota_mb' => 10240, 'default_mailbox_quota_mb' => 1024, 'max_mailbox_quota_mb' => 4096, 'max_message_size_mb' => 25, 'outbound_per_hour' => 300, 'outbound_per_day' => 3000, 'retention_days' => 30, 'enable_imap' => 1, 'enable_pop3' => 1, 'enable_managesieve' => 1, 'enable_catchall' => 0, 'enable_external_forwarding' => 1, 'quarantine_enabled' => 1, 'antivirus_enabled' => 1, 'dkim_enabled' => 1],
    ['id' => 2, 'name' => 'Business', 'description' => 'Gói mở rộng', 'max_domains' => 10, 'max_mailboxes' => 150, 'max_aliases' => 300, 'max_forwarders' => 120, 'max_total_quota_mb' => 102400, 'default_mailbox_quota_mb' => 2048, 'max_mailbox_quota_mb' => 10240, 'max_message_size_mb' => 50, 'outbound_per_hour' => 1200, 'outbound_per_day' => 12000, 'retention_days' => 90, 'enable_imap' => 1, 'enable_pop3' => 1, 'enable_managesieve' => 1, 'enable_catchall' => 1, 'enable_external_forwarding' => 1, 'quarantine_enabled' => 1, 'antivirus_enabled' => 1, 'dkim_enabled' => 1],
];

$tenants = [
    ['id' => 1, 'name' => 'Acme Foods', 'slug' => 'acme-foods', 'status' => 'active', 'package_id' => 2, 'package_label' => 'Business', 'admin_user_id' => 101, 'note' => 'Tenant chính cho khối sales.', 'max_domains' => 12, 'max_mailboxes' => 180, 'max_total_quota_mb' => 120000, 'extra_total_quota_mb' => 20480, 'package_base_total_quota_mb' => 102400, 'is_custom_limits' => false, 'extra_allocations_summary' => '+2 domains, +20 mailboxes, +20480 MB'],
    ['id' => 2, 'name' => 'Nova Support', 'slug' => 'nova-support', 'status' => 'suspended', 'package_id' => 1, 'package_label' => 'Starter', 'admin_user_id' => 102, 'note' => 'Tạm khóa vì vượt rate limit.', 'max_domains' => 3, 'max_mailboxes' => 25, 'max_total_quota_mb' => 10240, 'extra_total_quota_mb' => 0, 'package_base_total_quota_mb' => 10240, 'is_custom_limits' => true, 'extra_allocations_summary' => 'Custom limits'],
];

$tenantAdmins = [
    ['id' => 101, 'tenant_id' => 1, 'name' => 'Duy Trần', 'email' => 'admin@acme.test', 'linux_username' => 'acme-owner'],
    ['id' => 102, 'tenant_id' => 2, 'name' => 'Mai Lê', 'email' => 'owner@nova.test', 'linux_username' => 'nova-owner'],
];

$domains = [
    ['id' => 10, 'tenant_id' => 1, 'domain' => 'acme.test', 'status' => 'active', 'is_primary' => true, 'inbound_enabled' => true, 'outbound_enabled' => true, 'dkim_enabled' => true],
    ['id' => 11, 'tenant_id' => 1, 'domain' => 'mail.acme.test', 'status' => 'pending_dns', 'is_primary' => false, 'inbound_enabled' => true, 'outbound_enabled' => false, 'dkim_enabled' => true],
    ['id' => 12, 'tenant_id' => 2, 'domain' => 'nova.test', 'status' => 'suspended', 'is_primary' => true, 'inbound_enabled' => false, 'outbound_enabled' => false, 'dkim_enabled' => false],
];

$mailboxes = [
    ['id' => 201, 'tenant_id' => 1, 'domain_id' => 10, 'email' => 'sales@acme.test', 'display_name' => 'Sales Team', 'status' => 'active', 'quota_mb' => 4096, 'imap_enabled' => true, 'pop3_enabled' => false, 'smtp_enabled' => true],
    ['id' => 202, 'tenant_id' => 1, 'domain_id' => 10, 'email' => 'support@acme.test', 'display_name' => 'Support Desk', 'status' => 'disabled', 'quota_mb' => 2048, 'imap_enabled' => true, 'pop3_enabled' => true, 'smtp_enabled' => false],
    ['id' => 203, 'tenant_id' => 2, 'domain_id' => 12, 'email' => 'hello@nova.test', 'display_name' => 'Nova Inbox', 'status' => 'suspended', 'quota_mb' => 1024, 'imap_enabled' => true, 'pop3_enabled' => false, 'smtp_enabled' => false],
];

$configVersions = [
    ['id' => 1, 'service' => 'exim', 'version' => '2026.07.02.01', 'status' => 'applied', 'checksum' => '1234567890abcdef123456', 'applied_at' => '2026-07-02 08:00:00', 'previous_version_id' => null],
    ['id' => 2, 'service' => 'nginx', 'version' => '2026.07.02.02', 'status' => 'generated', 'checksum' => 'abcdef1234567890abcdef', 'applied_at' => '-', 'previous_version_id' => 1],
];

$tenantUsage = [
    1 => ['domains' => 2, 'mailboxes' => 32, 'quota_mb' => 55240],
    2 => ['domains' => 1, 'mailboxes' => 12, 'quota_mb' => 8110],
];

$tenantQuotaProfiles = [
    1 => ['tenant_name' => 'Acme Foods', 'allocated_quota_mb' => 120000, 'assigned_quota_mb' => 64000, 'assigned_overage_mb' => 0, 'used_quota_mb' => 55240, 'remaining_quota_mb' => 64760, 'max_single_mailbox_quota_mb' => 10240, 'default_mailbox_quota_mb' => 2048, 'recommended_quota_mb' => 4096, 'remaining_mailbox_slots' => 148],
    2 => ['tenant_name' => 'Nova Support', 'allocated_quota_mb' => 10240, 'assigned_quota_mb' => 9216, 'assigned_overage_mb' => 0, 'used_quota_mb' => 8110, 'remaining_quota_mb' => 2130, 'max_single_mailbox_quota_mb' => 4096, 'default_mailbox_quota_mb' => 1024, 'recommended_quota_mb' => 1024, 'remaining_mailbox_slots' => 13],
];

$domainSslSummary = [
    10 => ['status' => 'ok', 'status_label' => 'ready', 'hostname' => 'mail.acme.test', 'expires_at' => strtotime('+73 days'), 'expires_in_days' => 73],
    11 => ['status' => 'warning', 'status_label' => 'pending', 'hostname' => 'mail2.acme.test', 'expires_at' => null, 'expires_in_days' => null],
    12 => ['status' => 'failed', 'status_label' => 'missing', 'hostname' => 'mail.nova.test', 'expires_at' => null, 'expires_in_days' => null],
];

$stats = ['tenants' => 18, 'domains' => 44, 'mailboxes' => 632, 'aliases' => 87, 'forwards' => 29, 'quota_used_mb' => 384220];
$superAdmins = [
    ['id' => 1, 'name' => 'Ops Admin', 'email' => 'ops@example.com', 'linux_username' => 'mp-admin-ops', 'ssh_enabled' => true, 'ssh_sudo_enabled' => true],
    ['id' => 2, 'name' => 'Sec Admin', 'email' => 'sec@example.com', 'linux_username' => 'mp-admin-sec', 'ssh_enabled' => true, 'ssh_sudo_enabled' => false],
];

$aliases = [
    ['id' => 1, 'source_address' => 'sales-team@acme.test', 'destination_mailbox_id' => 201, 'keep_copy' => true],
];
$forwards = [
    ['id' => 1, 'source_address' => 'ceo@acme.test', 'destination_address' => 'director@example.net', 'keep_copy' => false],
];
$mailGroups = [
    ['id' => 1, 'email' => 'all@acme.test', 'display_name' => 'All Staff', 'members' => ['sales@acme.test', 'support@acme.test', 'director@example.net'], 'status' => 'active'],
];

$securityUser = ['linux_username' => 'mp-admin-ops', 'email' => 'ops@example.com', 'totp_enabled' => true, 'totp_confirmed_at' => '2026-07-01 09:21:00'];
$totpSetup = ['secret' => 'JBSWY3DPEHPK3PXP', 'otpauth_uri' => 'otpauth://totp/MailPanel:ops@example.com'];

$snapshot = [
    'version' => '2.38.1',
    'title' => 'SnappyMail',
    'enabled' => true,
    'config_path' => '/var/www/webmail/data/_data_/_default_/configs/application.ini',
    'auth_log_exists' => true,
    'auth_log_path' => '/var/www/webmail/data/_data_/_default_/logs/auth.log',
    'auth_log_size_bytes' => 18420,
    'webmail_root_realpath' => '/var/www/webmail',
    'managed_domains_count' => 3,
    'short_login_drift_count' => 1,
    'settings' => [
        'force_https' => true,
        'allow_admin_panel' => false,
        'auth_logging' => true,
        'mail_use_threads' => true,
        'allow_spellcheck' => true,
        'messages_per_page' => 50,
        'attachment_size_limit' => 25,
    ],
    'plugin' => [
        'enabled' => true,
        'managed_plugin_present' => true,
        'managed_plugin_enabled' => true,
        'managed_plugin_id' => 'mailpanel-change-password',
        'managed_plugin_root' => '/var/www/snappymail/data/_data_/_default_/plugins/mailpanel-change-password',
    ],
    'mailbox_storage' => ['ready' => 631, 'total' => 632, 'missing' => 1],
    'managed_domains' => [
        ['domain' => 'acme.test', 'short_login' => false, 'smtp_short_login' => false, 'sieve_enabled' => true, 'config_path' => '/var/www/webmail/data/_data_/_default_/domains/acme.test.ini'],
        ['domain' => 'nova.test', 'short_login' => true, 'smtp_short_login' => true, 'sieve_enabled' => false, 'config_path' => '/var/www/webmail/data/_data_/_default_/domains/nova.test.ini'],
    ],
    'auth_log_tail' => [
        '2026-07-02 08:02:11 AUTH failed user=sales@acme.test ip=203.0.113.40 reason=bad password',
        '2026-07-02 08:04:19 AUTH ok user=ops@example.com ip=198.51.100.12',
    ],
];

$fail2ban = ['jails' => ['roundcube-auth' => ['203.0.113.40'], 'dovecot-auth' => []], 'error' => null];
$statusData = ['jails' => ['sshd' => ['198.51.100.77'], 'roundcube-auth' => ['203.0.113.40'], 'dovecot-auth' => []], 'raw' => 'Status for the jail: sshd ...'];
$scores = ['reject' => 15, 'add_header' => 6, 'greylist' => 4, 'raw' => "actions {\n  reject = 15;\n  add_header = 6;\n  greylist = 4;\n}\n"];
$items = [
    ['id' => '1x2y3z-000A', 'time' => '2026-07-02 08:11', 'size' => '84 KB', 'sender' => 'sender@acme.test', 'status' => 'retry timeout', 'recipients' => ['external-recipient@example.net', 'ops@example.com']],
    ['id' => '1x2y3z-000B', 'time' => '2026-07-02 08:14', 'size' => '21 KB', 'sender' => 'alerts@nova.test', 'status' => '', 'recipients' => ['owner@nova.test']],
];
$services = ['exim', 'dovecot', 'nginx', 'rspamd', 'agent'];
$current_service = 'exim';
$lines = 120;
$keyword = 'defer';
$log_content = "[2026-07-02 08:11:14] exim defer - retry timeout for example.net\n[2026-07-02 08:11:30] exim queue run start\n";

$pageMap = [
    'dashboard' => fn () => ['template' => 'admin/pages/dashboard.php', 'data' => array_merge($sharedAdminContext, ['stats' => $stats, 'configVersions' => $configVersions])],
    'tenants' => fn () => ['template' => 'admin/pages/tenants.php', 'data' => array_merge($sharedAdminContext, ['filters' => [], 'tenantRows' => $tenants, 'tenants' => $tenants, 'tenantAdmins' => $tenantAdmins, 'tenantAdminRows' => $tenantAdmins, 'tenantUsage' => $tenantUsage, 'tenantAdminLookup' => $tenantAdmins, 'packages' => $packages, 'isSuperAdmin' => true, 'tenantsPagination' => $pagination, 'tenantAdminsPagination' => $pagination])],
    'domains' => fn () => ['template' => 'admin/pages/domains.php', 'data' => array_merge($sharedAdminContext, ['filters' => [], 'domains' => $domains, 'domainRows' => $domains, 'tenants' => $tenants, 'domainSslSummary' => $domainSslSummary, 'domainsPagination' => $pagination])],
    'mailboxes' => fn () => ['template' => 'admin/pages/mailboxes.php', 'data' => array_merge($sharedAdminContext, ['filters' => [], 'mailboxes' => $mailboxes, 'mailboxRows' => $mailboxes, 'domains' => $domains, 'tenants' => $tenants, 'tenantQuotaProfiles' => $tenantQuotaProfiles, 'mailboxesPagination' => $pagination])],
    'packages' => fn () => ['template' => 'admin/pages/packages.php', 'data' => array_merge($sharedAdminContext, ['filters' => [], 'packages' => $packages, 'packageRows' => $packages, 'packageTenantCounts' => [1 => 4, 2 => 12], 'packagesPagination' => $pagination])],
    'super_admins' => fn () => ['template' => 'admin/pages/super_admins.php', 'data' => array_merge($sharedAdminContext, ['filters' => [], 'superAdmins' => $superAdmins, 'superAdminRows' => $superAdmins, 'superAdminsPagination' => $pagination, 'superAdminIpAllowlist' => ['enabled' => true, 'raw' => "198.51.100.12\n203.0.113.0/24"], 'currentClientIp' => '198.51.100.12'])],
    'routing' => fn () => ['template' => 'admin/pages/routing.php', 'data' => array_merge($sharedAdminContext, ['filters' => [], 'aliases' => $aliases, 'aliasRows' => $aliases, 'forwards' => $forwards, 'forwardRows' => $forwards, 'mailGroups' => $mailGroups, 'mailGroupRows' => $mailGroups, 'mailboxes' => $mailboxes, 'domains' => $domains, 'aliasesPagination' => $pagination, 'forwardsPagination' => $pagination, 'mailGroupsPagination' => $pagination])],
    'dns_checks' => fn () => ['template' => 'admin/pages/dns_checks.php', 'data' => array_merge($sharedAdminContext, ['domains' => $domains, 'selectedDomain' => $domains[0], 'report' => ['domain' => 'acme.test', 'mail_host' => 'mail.acme.test', 'dkim_selector' => 'default', 'checked_at' => '2026-07-02 08:23:00', 'summary' => ['total' => 8, 'ok' => 5, 'failed' => 2, 'skipped' => 1], 'checks' => [['label' => 'MX', 'hostname' => 'acme.test', 'status' => 'ok', 'expected' => 'mail.acme.test', 'observed' => 'mail.acme.test'], ['label' => 'SPF', 'hostname' => 'acme.test', 'status' => 'failed', 'expected' => 'include:_spf.mailpanel.test', 'observed' => 'v=spf1 -all']]], 'certificateProfiles' => ['profiles' => [['key' => 'mail_only', 'label' => 'Mail only', 'description' => 'mail + submission hostnames', 'recommended' => true, 'dns_ready' => true, 'certificate_ready' => true, 'hosts' => [['hostname' => 'mail.acme.test', 'dns_observed' => 'A 161.248.4.210', 'certificate_status' => 'ok', 'certificate_label' => 'ready', 'certificate_observed' => 'expires in 73 days']]]]]])],
    'config_versions' => fn () => ['template' => 'admin/pages/config_versions.php', 'data' => array_merge($sharedAdminContext, ['filters' => [], 'configVersions' => $configVersions, 'configVersionRows' => $configVersions, 'configVersionsPagination' => $pagination])],
    'security' => fn () => ['template' => 'admin/pages/security.php', 'data' => array_merge($sharedAdminContext, ['securityUser' => $securityUser, 'securityLogin' => 'mp-admin-ops', 'totpSetup' => $totpSetup])],
    'webmail' => fn () => ['template' => 'admin/pages/webmail.php', 'data' => array_merge($sharedAdminContext, ['snapshot' => $snapshot, 'fail2ban' => $fail2ban])],
    'fail2ban' => fn () => ['template' => 'admin/pages/fail2ban.php', 'data' => array_merge($sharedAdminContext, ['statusData' => $statusData])],
    'rspamd' => fn () => ['template' => 'admin/pages/rspamd.php', 'data' => array_merge($sharedAdminContext, ['scores' => $scores])],
    'queue' => fn () => ['template' => 'admin/pages/queue.php', 'data' => array_merge($sharedAdminContext, ['items' => $items])],
    'queue_view' => fn () => ['template' => 'admin/pages/queue_view.php', 'data' => ['msgId' => '1x2y3z-000A', 'content' => "From: sender@acme.test\nTo: external-recipient@example.net\nSubject: Queue preview\n\nThis is a preview message body."]],
    'logs' => fn () => ['template' => 'admin/pages/logs.php', 'data' => array_merge($sharedAdminContext, ['services' => $services, 'current_service' => $current_service, 'lines' => $lines, 'keyword' => $keyword, 'log_content' => $log_content])],
];

if (!isset($pageMap[$pageKey])) {
    http_response_code(404);
    echo 'Unknown preview page.';
    exit;
}

$result = $pageMap[$pageKey]();
$content = $view->render($result['template'], $result['data']);

$pageMeta = [
    'dashboard' => ['title' => 'Dashboard', 'description' => 'Tổng quan hệ thống mail hosting', 'breadcrumbs' => [['label' => 'Dashboard']]],
    'tenants' => ['title' => 'Users / User Level', 'description' => 'Quản lý tenant và owner account', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Users / User Level']]],
    'domains' => ['title' => 'Managed Domains', 'description' => 'Danh sách domain và TLS', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Managed Domains']]],
    'mailboxes' => ['title' => 'Mail Accounts', 'description' => 'Mailbox, quota và SMTP status', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Mail Accounts']]],
    'packages' => ['title' => 'Packages', 'description' => 'Thiết lập quota nền', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Packages']]],
    'super_admins' => ['title' => 'Admin Level Accounts', 'description' => 'Quản trị SSH, sudo và allowlist', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Admin Level Accounts']]],
    'routing' => ['title' => 'Mail Routing', 'description' => 'Alias, forward và mail group', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Mail Routing']]],
    'dns_checks' => ['title' => 'DNS / TLS Checks', 'description' => 'Kiểm tra MX, SPF, DKIM, DMARC và ACME', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'DNS / TLS Checks']]],
    'config_versions' => ['title' => 'Service Config Revisions', 'description' => 'Versioning, apply và rollback', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Service Config Revisions']]],
    'security' => ['title' => 'Account Security', 'description' => 'Password và TOTP', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Account Security']]],
    'webmail' => ['title' => 'Webmail Health', 'description' => 'SnappyMail runtime và plugin bridge', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Webmail Health']]],
    'fail2ban' => ['title' => 'Fail2ban', 'description' => 'Theo dõi IP đang bị cấm', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Fail2ban']]],
    'rspamd' => ['title' => 'Rspamd', 'description' => 'Cấu hình spam score', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Rspamd']]],
    'queue' => ['title' => 'Mail Queue', 'description' => 'Theo dõi email đang kẹt', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Mail Queue']]],
    'queue_view' => ['title' => 'Queue Message', 'description' => 'Chi tiết raw message', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'Mail Queue', 'href' => '/admin/queue'], ['label' => 'Queue Message']]],
    'logs' => ['title' => 'System Logs', 'description' => 'Theo dõi log dịch vụ', 'breadcrumbs' => [['label' => 'Dashboard', 'href' => '/admin/dashboard'], ['label' => 'System Logs']]],
];

$page = $pageMeta[$pageKey];
$page['quick_actions'] = [
    ['label' => 'Dashboard', 'href' => '/qa/admin-preview.php?page=dashboard', 'variant' => 'secondary'],
    ['label' => 'Tenants', 'href' => '/qa/admin-preview.php?page=tenants', 'variant' => 'secondary'],
    ['label' => 'Mailboxes', 'href' => '/qa/admin-preview.php?page=mailboxes', 'variant' => 'primary'],
];

echo $view->render('admin/layout.php', compact(
    'title',
    'page',
    'content',
    'identity',
    'csrfToken',
    'canAccess',
    'view',
    'active',
    'flashSuccess',
    'flashError'
));
