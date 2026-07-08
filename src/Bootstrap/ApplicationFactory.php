<?php

declare(strict_types=1);

namespace MailPanel\Bootstrap;

use MailPanel\Contracts\MailboxPasswordManager;
use MailPanel\Contracts\MailStorageProvisioner;
use MailPanel\Contracts\MailStoragePurger;
use MailPanel\Contracts\SuperAdminLinuxAccountManager;
use MailPanel\Core\Application;
use MailPanel\Core\Config;
use MailPanel\Core\Container;
use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\SpamPolicyRepository;
use MailPanel\Services\SpamPolicyService;
use MailPanel\Http\Controllers\SpamPolicyController;

use MailPanel\Core\Router;
use MailPanel\Http\Controllers\AdminController;
use MailPanel\Http\Controllers\AdminAuthController;
use MailPanel\Http\Controllers\AdminDashboardController;
use MailPanel\Http\Controllers\AdminSecurityController;
use MailPanel\Http\Controllers\AdminPackageController;
use MailPanel\Http\Controllers\AdminTenantController;
use MailPanel\Http\Controllers\AdminDomainController;
use MailPanel\Http\Controllers\AdminMailboxController;
use MailPanel\Http\Controllers\AdminRoutingController;
use MailPanel\Http\Controllers\AdminConfigDeploymentController;
use MailPanel\Http\Controllers\AuthController;
use MailPanel\Http\Controllers\UserController;
use MailPanel\Repositories\Pdo\AdminRepository;
use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\AuthRepository;
use MailPanel\Repositories\Pdo\ApiTokenRepository;
use MailPanel\Repositories\Pdo\ConfigVersionRepository;
use MailPanel\Repositories\Pdo\DkimKeyRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\ForwardRepository;
use MailPanel\Repositories\Pdo\MailboxPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\PackageRepository;
use MailPanel\Repositories\Pdo\PasswordResetTokenRepository;
use MailPanel\Repositories\Pdo\QuotaUsageRepository;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Repositories\Pdo\MailGroupRepository;
use MailPanel\Repositories\Pdo\MailGroupMemberRepository;
use MailPanel\Security\CsrfService;
use MailPanel\Security\SessionManager;
use MailPanel\Security\TotpService;
use MailPanel\Security\TokenGuard;
use MailPanel\Services\AdminSecurityService;
use MailPanel\Services\AdminPasswordVerifier;
use MailPanel\Services\AppSecuritySettingsService;
use MailPanel\Services\AliasService;
use MailPanel\Services\AgentClientService;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\AuthService;
use MailPanel\Services\ApiTokenService;
use MailPanel\Services\ConfigDeploymentService;
use MailPanel\Services\DashboardService;
use MailPanel\Services\DnsCheckService;
use MailPanel\Services\DomainService;
use MailPanel\Services\EximConfigRenderer;
use MailPanel\Services\Fail2banConfigRenderer;
use MailPanel\Services\ForwardService;
use MailPanel\Services\MailboxService;
use MailPanel\Services\AcmeTlsService;
use MailPanel\Services\LinuxPasswordHashService;
use MailPanel\Services\PackageService;
use MailPanel\Services\NginxConfigRenderer;
use MailPanel\Services\PasswordHashingService;
use MailPanel\Services\PasswordPolicyService;
use MailPanel\Services\PasswordResetMailService;
use MailPanel\Services\PasswordResetService;
use MailPanel\Services\QuotaService;
use MailPanel\Services\RateLimiterService;
use MailPanel\Services\RspamdConfigRenderer;
use MailPanel\Services\TenantService;
use MailPanel\Services\DovecotConfigRenderer;
use MailPanel\Services\ConfigValidatorService;
use MailPanel\Services\SystemCommandService;
use MailPanel\Services\MailGroupService;
use MailPanel\Services\MailStorageProvisionService;
use MailPanel\Services\MailStoragePurgeService;
use MailPanel\Services\SuperAdminService;
use MailPanel\Services\SuperAdminLinuxAccountService;
use MailPanel\Services\TenantAdminService;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\RequestActorResolver;
use MailPanel\Support\View;
use MailPanel\Services\TlsCertificateInventory;
use MailPanel\Services\WebmailApplicationConfigService;
use MailPanel\Services\WebmailDomainConfigService;
use MailPanel\Services\WebmailHealthService;
use MailPanel\Services\WebmailPluginDeploymentService;
use MailPanel\Services\WebmailUserStorageService;

final class ApplicationFactory
{
    public static function create(string $basePath): Application
    {
        $config = new Config($basePath, [
            'app' => require $basePath . '/config/app.php',
            'database' => require $basePath . '/config/database.php',
            'mailpanel' => require $basePath . '/config/mailpanel.php',
        ]);

        $container = new Container();
        $container->set(Config::class, fn () => $config);
        $container->set(Database::class, fn () => new Database($config->get('database')));
        $container->set(View::class, fn () => new View($basePath . '/src/Views'));
        $container->set(AuditLogService::class, fn (Container $c) => new AuditLogService($c->get(Database::class)));
        $container->set(AuthorizationService::class, fn () => new AuthorizationService());
        $container->set(PasswordPolicyService::class, fn () => new PasswordPolicyService($config->get('app.password_policy', [])));
        $container->set(PasswordHashingService::class, fn () => new PasswordHashingService((string) $config->get('mailpanel.password_algorithm', 'bcrypt')));
        $container->set(LinuxPasswordHashService::class, fn () => new LinuxPasswordHashService());
        $container->set(RateLimiterService::class, fn (Container $c) => new RateLimiterService($c->get(\MailPanel\Core\Database::class)->connection()));
        $container->set(TotpService::class, fn () => new TotpService(
            (string) $config->get('app.totp.issuer', 'MailPanel'),
            (int) $config->get('app.totp.window', 1),
            (string) $config->get('app.totp.encryption_key', '')
        ));
        $container->set(RequestActorResolver::class, fn (Container $c) => new RequestActorResolver(
            $c->get(SessionManager::class),
            $c->get(TokenGuard::class),
            $c->get(UserRepository::class),
            $c->get(MailboxRepository::class),
            $c->get(TenantRepository::class),
            $c->get(DomainRepository::class)
        ));
        $container->set(SessionManager::class, fn () => new SessionManager((int) $config->get('app.session.timeout_seconds', 1800)));
        $container->set(CsrfService::class, fn (Container $c) => new CsrfService(
            $c->get(SessionManager::class),
            (string) $config->get('app.csrf.header', 'X-CSRF-Token')
        ));

        $container->set(TenantRepository::class, fn (Container $c) => new TenantRepository($c->get(Database::class)));
        $container->set(TenantPurgeRepository::class, fn (Container $c) => new TenantPurgeRepository($c->get(Database::class)));
        $container->set(PackageRepository::class, fn (Container $c) => new PackageRepository($c->get(Database::class)));
        $container->set(DomainRepository::class, fn (Container $c) => new DomainRepository($c->get(Database::class)));
        $container->set(MailboxRepository::class, fn (Container $c) => new MailboxRepository($c->get(Database::class)));
        $container->set(AliasRepository::class, fn (Container $c) => new AliasRepository($c->get(Database::class)));
        $container->set(ForwardRepository::class, fn (Container $c) => new ForwardRepository($c->get(Database::class)));
        $container->set(ConfigVersionRepository::class, fn (Container $c) => new ConfigVersionRepository($c->get(Database::class)));
        $container->set(ApiTokenRepository::class, fn (Container $c) => new ApiTokenRepository($c->get(Database::class)));
        $container->set(AuthRepository::class, fn (Container $c) => new AuthRepository($c->get(Database::class)));
        $container->set(AdminRepository::class, fn (Container $c) => new AdminRepository($c->get(Database::class)));
        $container->set(QuotaUsageRepository::class, fn (Container $c) => new QuotaUsageRepository($c->get(Database::class)));
        
        $container->set(SpamPolicyRepository::class, fn (Container $c) => new SpamPolicyRepository($c->get(Database::class)));
        $container->set(UserRepository::class, fn (Container $c) => new UserRepository($c->get(Database::class)));
        $container->set(MailGroupRepository::class, fn (Container $c) => new MailGroupRepository($c->get(Database::class)));
        $container->set(MailGroupMemberRepository::class, fn (Container $c) => new MailGroupMemberRepository($c->get(Database::class)));
        $container->set(DkimKeyRepository::class, fn (Container $c) => new DkimKeyRepository($c->get(Database::class)));
        $container->set(UserPasswordHistoryRepository::class, fn (Container $c) => new UserPasswordHistoryRepository($c->get(Database::class)));
        $container->set(PasswordResetTokenRepository::class, fn (Container $c) => new PasswordResetTokenRepository($c->get(Database::class)));
        $container->set(MailboxPasswordHistoryRepository::class, fn (Container $c) => new MailboxPasswordHistoryRepository($c->get(Database::class)));

        $container->set(PackageService::class, fn (Container $c) => new PackageService(
            $c->get(PackageRepository::class),
            $c->get(TenantRepository::class),
            $c->get(TenantService::class),
            $c->get(Database::class),
            $c->get(AuditLogService::class),
            $c->get(TenantPurgeRepository::class)
        ));
        
        $container->set(SpamPolicyService::class, fn (Container $c) => new SpamPolicyService(
            $c->get(SpamPolicyRepository::class),
            $c->get(DomainRepository::class),
            $c->get(AgentClientService::class),
            $c->get(AuditLogService::class)
        ));
        $container->set(TenantService::class, fn (Container $c) => new TenantService(
            $c->get(TenantRepository::class),
            $c->get(PackageRepository::class),
            $c->get(DomainRepository::class),
            $c->get(MailboxRepository::class),
            $c->get(AliasRepository::class),
            $c->get(ForwardRepository::class),
            $c->get(AuditLogService::class),
            $c->get(TenantPurgeRepository::class),
            $c->get(MailStoragePurger::class),
            $c->get(QuotaUsageRepository::class),
            $c->get(WebmailDomainConfigService::class)
        ));
        $container->set(DomainService::class, fn (Container $c) => new DomainService(
            $c->get(DomainRepository::class),
            $c->get(MailboxRepository::class),
            $c->get(TenantRepository::class),
            $c->get(AuditLogService::class),
            $c->get(\MailPanel\Services\AgentClientService::class),
            $c->get(\MailPanel\Core\Database::class),
            $c->get(TenantPurgeRepository::class),
            $c->get(MailStoragePurger::class),
            $c->get(WebmailDomainConfigService::class)
        ));
        $container->set(MailboxService::class, fn (Container $c) => new MailboxService(
            $c->get(MailboxRepository::class),
            $c->get(DomainRepository::class),
            $c->get(TenantRepository::class),
            $c->get(PackageRepository::class),
            $c->get(AuditLogService::class),
            $c->get(PasswordPolicyService::class),
            $c->get(PasswordHashingService::class),
            $c->get(MailboxPasswordHistoryRepository::class),
            $c->get(TenantPurgeRepository::class),
            $c->get(MailStoragePurger::class),
            $c->get(QuotaUsageRepository::class),
            $c->get(MailStorageProvisioner::class),
            $c->get(WebmailUserStorageService::class)
        ));
        $container->set(MailboxPasswordManager::class, fn (Container $c) => $c->get(MailboxService::class));
        $container->set(AliasService::class, fn (Container $c) => new AliasService(
            $c->get(AliasRepository::class),
            $c->get(MailboxRepository::class),
            $c->get(ForwardRepository::class),
            $c->get(DomainRepository::class),
            $c->get(TenantRepository::class),
            $c->get(PackageRepository::class),
            $c->get(AuditLogService::class)
        ));
        $container->set(ForwardService::class, fn (Container $c) => new ForwardService(
            $c->get(ForwardRepository::class),
            $c->get(MailboxRepository::class),
            $c->get(AliasRepository::class),
            $c->get(DomainRepository::class),
            $c->get(TenantRepository::class),
            $c->get(PackageRepository::class),
            $c->get(AuditLogService::class)
        ));
        $container->set(EximConfigRenderer::class, fn (Container $c) => new EximConfigRenderer(
            $config->get('mailpanel.generated_root'),
            $c->get(DomainRepository::class),
            $c->get(MailboxRepository::class),
            $c->get(AliasRepository::class),
            $c->get(TenantRepository::class),
            $config->get('mailpanel.exim_tls_certificate'),
            $config->get('mailpanel.exim_tls_privatekey'),
            $config->get('mailpanel.exim_submission_ports'),
            $config->get('mailpanel.exim_tls_on_connect_ports'),
            $c->get(TlsCertificateInventory::class),
            $c->get(MailGroupRepository::class),
            $c->get(MailGroupMemberRepository::class),
            $c->get(QuotaUsageRepository::class),
            $c->get(PackageRepository::class),
            $c->get(DkimKeyRepository::class),
            $c->get(ForwardRepository::class),
            $config->get('mailpanel.exim_auth_ports')
        ));
        $container->set(DovecotConfigRenderer::class, fn (Container $c) => new DovecotConfigRenderer(
            $config->get('mailpanel.generated_root'),
            $config->get('mailpanel.vmail_root'),
            $config->get('database'),
            $config->get('mailpanel.vmail_uid'),
            $config->get('mailpanel.vmail_gid'),
            $config->get('mailpanel.dovecot_pass_scheme'),
            $config->get('mailpanel.exim_tls_certificate'),
            $config->get('mailpanel.exim_tls_privatekey'),
            $c->get(TlsCertificateInventory::class)
        ));
        $container->set(TlsCertificateInventory::class, fn () => new TlsCertificateInventory(
            (string) $config->get('mailpanel.tls_sni_root', '/etc/mailpanel/tls/sni')
        ));
        $container->set(NginxConfigRenderer::class, fn (Container $c) => new NginxConfigRenderer(
            $config->get('mailpanel.generated_root'),
            $config->get('mailpanel.nginx_root'),
            $config->get('mailpanel.nginx_server_name'),
            $config->get('mailpanel.webmail_path'),
            $config->get('mailpanel.webmail_public_root'),
            $config->get('mailpanel.nginx_php_fpm_socket'),
            $config->get('mailpanel.nginx_tls_certificate'),
            $config->get('mailpanel.nginx_tls_privatekey'),
            $config->get('mailpanel.acme_webroot'),
            $c->get(TlsCertificateInventory::class)
        ));
        $container->set(RspamdConfigRenderer::class, fn (Container $c) => new RspamdConfigRenderer(
            $config->get('mailpanel.generated_root')
        ));
        $container->set(Fail2banConfigRenderer::class, fn (Container $c) => new Fail2banConfigRenderer(
            $config->get('mailpanel.generated_root'),
            (bool) $config->get('mailpanel.webmail_enabled', false),
            (string) $config->get('mailpanel.webmail_log_path', '/var/www/webmail/data/_data_/_default_/logs/auth.log')
        ));
        $container->set(ConfigDeploymentService::class, fn (Container $c) => new ConfigDeploymentService(
            $config->get('mailpanel.generated_root'),
            $c->get(ConfigVersionRepository::class),
            $c->get(NginxConfigRenderer::class),
            $c->get(EximConfigRenderer::class),
            $c->get(DovecotConfigRenderer::class),
            $c->get(RspamdConfigRenderer::class),
            $c->get(Fail2banConfigRenderer::class),
            $c->get(ConfigValidatorService::class),
            $c->get(AgentClientService::class),
            $c->get(AuditLogService::class)
        ));
        $container->set(AgentClientService::class, fn (Container $c) => new AgentClientService(
            $config->get('mailpanel.agent_binary'),
            $config->get('mailpanel.system_user'),
            (string) $config->get('mailpanel.web_agent_binary', '/usr/local/bin/mailpanel-web-agent'),
            (string) $config->get('mailpanel.sudo_binary', 'sudo'),
            (int) $config->get('mailpanel.agent_timeout_seconds', 60)
        ));
        $container->set(SystemCommandService::class, fn () => new SystemCommandService());
        $container->set(MailStoragePurger::class, fn (Container $c) => new MailStoragePurgeService(
            $c->get(AgentClientService::class),
            (string) $config->get('mailpanel.vmail_root', '/var/vmail')
        ));
        $container->set(MailStorageProvisioner::class, fn (Container $c) => new MailStorageProvisionService(
            $c->get(AgentClientService::class),
            (string) $config->get('mailpanel.vmail_root', '/var/vmail'),
            (int) $config->get('mailpanel.vmail_uid', 2000),
            (int) $config->get('mailpanel.vmail_gid', 2000)
        ));
        $container->set(WebmailHealthService::class, fn (Container $c) => new WebmailHealthService(
            (bool) $config->get('mailpanel.webmail_enabled', false),
            (string) $config->get('mailpanel.webmail_public_root', '/var/www/webmail'),
            (string) $config->get('mailpanel.webmail_log_path', '/var/www/webmail/logs/userlogins.log')
        ));
        $container->set(WebmailDomainConfigService::class, fn () => new WebmailDomainConfigService(
            (bool) $config->get('mailpanel.webmail_enabled', false),
            (string) $config->get('mailpanel.webmail_public_root', '/var/www/webmail'),
            993,
            465,
            4190,
            true
        ));
        $container->set(WebmailUserStorageService::class, fn () => new WebmailUserStorageService(
            (bool) $config->get('mailpanel.webmail_enabled', false),
            (string) $config->get('mailpanel.webmail_public_root', '/var/www/webmail')
        ));
        $container->set(WebmailPluginDeploymentService::class, fn () => new WebmailPluginDeploymentService(
            (bool) $config->get('mailpanel.webmail_enabled', false),
            (string) $config->get('mailpanel.webmail_public_root', '/var/www/webmail'),
            '/api/webmail/password-change'
        ));
        $container->set(WebmailApplicationConfigService::class, fn () => new WebmailApplicationConfigService(
            (bool) $config->get('mailpanel.webmail_enabled', false),
            (string) $config->get('mailpanel.webmail_public_root', '/var/www/webmail'),
            (string) $config->get('mailpanel.webmail_log_path', '/var/www/webmail/logs/userlogins.log'),
            (string) $config->get('mailpanel.webmail_display_name', 'Webmail')
        ));
        $container->set(ConfigValidatorService::class, fn (Container $c) => new ConfigValidatorService(
            $c->get(SystemCommandService::class)
        ));
        $container->set(AdminPasswordVerifier::class, fn (Container $c) => new AdminPasswordVerifier(
            $c->get(PasswordHashingService::class),
            $config->get('app'),
            $c->get(SuperAdminLinuxAccountManager::class)
        ));
        $container->set(AuthService::class, fn (Container $c) => new AuthService(
            $c->get(AuthRepository::class),
            $c->get(UserRepository::class),
            $c->get(UserPasswordHistoryRepository::class),
            $c->get(AuditLogService::class),
            $c->get(SessionManager::class),
            $c->get(PasswordHashingService::class),
            $c->get(MailboxPasswordManager::class),
            $c->get(RateLimiterService::class),
            $c->get(TotpService::class),
            $c->get(AdminPasswordVerifier::class),
            $config->get('app')
        ));
        $container->set(AdminSecurityService::class, fn (Container $c) => new AdminSecurityService(
            $c->get(UserRepository::class),
            $c->get(UserPasswordHistoryRepository::class),
            $c->get(PasswordPolicyService::class),
            $c->get(PasswordHashingService::class),
            $c->get(TotpService::class),
            $c->get(AuditLogService::class),
            $c->get(SuperAdminLinuxAccountManager::class),
            $c->get(AdminPasswordVerifier::class),
            $c->get(SessionManager::class),
            (string) $config->get('app.key', '')
        ));
        $container->set(PasswordResetMailService::class, fn () => new PasswordResetMailService(
            (array) $config->get('app.password_reset.mail', []),
            (string) $config->get('app.key', '')
        ));
        $container->set(PasswordResetService::class, fn (Container $c) => new PasswordResetService(
            $c->get(UserRepository::class),
            $c->get(PasswordResetTokenRepository::class),
            $c->get(UserPasswordHistoryRepository::class),
            $c->get(PasswordPolicyService::class),
            $c->get(PasswordHashingService::class),
            $c->get(SuperAdminLinuxAccountManager::class),
            $c->get(PasswordResetMailService::class),
            $c->get(AuditLogService::class),
            $c->get(RateLimiterService::class),
            (array) $config->get('app.password_reset', []),
            (string) $config->get('app.key', '')
        ));
        $container->set(AppSecuritySettingsService::class, fn (Container $c) => new AppSecuritySettingsService(
            $basePath,
            $config->get('app'),
            $c->get(AuditLogService::class)
        ));
        $container->set(SuperAdminLinuxAccountManager::class, fn (Container $c) => new SuperAdminLinuxAccountService(
            $c->get(AgentClientService::class),
            $c->get(LinuxPasswordHashService::class)
        ));
        $container->set(ApiTokenService::class, fn (Container $c) => new ApiTokenService(
            $c->get(ApiTokenRepository::class),
            $c->get(AuditLogService::class)
        ));
        $container->set(QuotaService::class, fn (Container $c) => new QuotaService(
            $c->get(QuotaUsageRepository::class),
            $c->get(MailboxRepository::class),
            $c->get(AuditLogService::class),
            $c->get(AgentClientService::class),
            (string) $config->get('mailpanel.vmail_root', '/var/vmail')
        ));
        $container->set(DashboardService::class, fn (Container $c) => new DashboardService(
            $c->get(AdminRepository::class),
            $c->get(QuotaUsageRepository::class)
        ));
        $container->set(DnsCheckService::class, fn (Container $c) => new DnsCheckService(
            (string) $config->get('mailpanel.dkim_selector', 'mail'),
            (string) $config->get('mailpanel.mail_host_label', 'mail')
        ));
        $container->set(AcmeTlsService::class, fn (Container $c) => new AcmeTlsService(
            $c->get(AuditLogService::class),
            $c->get(TlsCertificateInventory::class),
            $basePath,
            static fn (array $payload): array => $c->get(AgentClientService::class)->manageAcmeTls($payload)
        ));
        $container->set(MailGroupService::class, fn (Container $c) => new MailGroupService(
            $c->get(MailGroupRepository::class),
            $c->get(MailGroupMemberRepository::class),
            $c->get(DomainRepository::class),
            $c->get(AliasRepository::class),
            $c->get(ForwardRepository::class),
            $c->get(MailboxRepository::class),
            $c->get(AuditLogService::class),
            $c->get(TenantPurgeRepository::class),
            $c->get(TenantRepository::class)
        ));
        $container->set(TenantAdminService::class, fn (Container $c) => new TenantAdminService(
            $c->get(TenantRepository::class),
            $c->get(DomainRepository::class),
            $c->get(UserRepository::class),
            $c->get(UserPasswordHistoryRepository::class),
            $c->get(AuditLogService::class),
            $c->get(PasswordPolicyService::class),
            $c->get(PasswordHashingService::class),
            $c->get(SuperAdminLinuxAccountManager::class),
            $c->get(TenantPurgeRepository::class)
        ));
        $container->set(SuperAdminService::class, fn (Container $c) => new SuperAdminService(
            $c->get(UserRepository::class),
            $c->get(UserPasswordHistoryRepository::class),
            $c->get(SuperAdminLinuxAccountManager::class),
            $c->get(AuditLogService::class),
            $c->get(PasswordPolicyService::class),
            $c->get(PasswordHashingService::class),
            $c->get(TenantPurgeRepository::class)
        ));
        $container->set(TokenGuard::class, fn (Container $c) => new TokenGuard($c->get(ApiTokenRepository::class)));
        $container->set(AdminController::class, fn (Container $c) => new AdminController(
            $c->get(RequestActorResolver::class),
            $c->get(AuthorizationService::class),
            $c->get(DashboardService::class),
            $c->get(PackageService::class),
            $c->get(TenantService::class),
            $c->get(DomainService::class),
            $c->get(MailboxService::class),
            $c->get(AliasService::class),
            $c->get(ForwardService::class),
            $c->get(ConfigDeploymentService::class),
            $c->get(QuotaService::class),
            $c->get(AdminSecurityService::class)
        ));
                $container->set(AdminAuthController::class, fn (Container $c) => new AdminAuthController(
            $c->get(AuthService::class),
            $c->get(SessionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(View::class),
            $c->get(UserRepository::class),
            $c->get(AuditLogService::class),
            $c->get(PasswordResetService::class),
            (array) $config->get('app', [])
        ));
        $container->set(AdminDashboardController::class, fn (Container $c) => new AdminDashboardController(
            $c->get(SessionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(View::class),
            $c->get(DashboardService::class),
            $c->get(ConfigDeploymentService::class),
            $c->get(TenantService::class),
            $c->get(DomainService::class),
            $c->get(MailboxService::class),
            $c->get(AliasService::class),
            $c->get(ForwardService::class)
        ));
        $container->set(AdminPackageController::class, fn (Container $c) => new AdminPackageController(
            $c->get(PackageService::class),
            $c->get(TenantService::class),
            $c->get(SessionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(View::class)
        ));
        $container->set(AdminTenantController::class, fn (Container $c) => new AdminTenantController(
            $c->get(TenantService::class),
            $c->get(TenantAdminService::class),
            $c->get(PackageService::class),
            $c->get(UserRepository::class),
            $c->get(DomainService::class),
            $c->get(AcmeTlsService::class),
            $c->get(AdminSecurityService::class),
            $c->get(SessionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(View::class),
            $c->get(AuditLogService::class),
            (array) $config->get('mailpanel', [])
        ));
        $container->set(AdminDomainController::class, fn (Container $c) => new AdminDomainController(
            $c->get(DomainService::class),
            $c->get(DnsCheckService::class),
            $c->get(AcmeTlsService::class),
            $c->get(TenantService::class),
            $c->get(AdminSecurityService::class),
            $c->get(SessionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(View::class),
            $c->get(AuditLogService::class),
            (array) $config->get('mailpanel', [])
        ));
        $container->set(AdminMailboxController::class, fn (Container $c) => new AdminMailboxController(
            $c->get(MailboxService::class),
            $c->get(DomainService::class),
            $c->get(TenantService::class),
            $c->get(QuotaService::class),
            $c->get(AdminSecurityService::class),
            $c->get(ConfigDeploymentService::class),
            $c->get(SessionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(View::class)
        ));
        $container->set(AdminRoutingController::class, fn (Container $c) => new AdminRoutingController(
            $c->get(AliasService::class),
            $c->get(ForwardService::class),
            $c->get(MailGroupService::class),
            $c->get(DomainService::class),
            $c->get(MailboxService::class),
            $c->get(TenantService::class),
            $c->get(ConfigDeploymentService::class),
            $c->get(SessionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(View::class)
        ));
        $container->set(AdminSecurityController::class, fn (Container $c) => new AdminSecurityController(
            $c->get(AdminSecurityService::class),
            $c->get(AppSecuritySettingsService::class),
            $c->get(SuperAdminService::class),
            $c->get(UserRepository::class),
            $c->get(SessionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(View::class),
            $c->get(AuditLogService::class),
            $c->get(AcmeTlsService::class),
            $c->get(ConfigDeploymentService::class),
            (array) $config->get('app', [])
        ));
        $container->set(AdminConfigDeploymentController::class, fn (Container $c) => new AdminConfigDeploymentController(
            $c->get(ConfigDeploymentService::class),
            $c->get(AdminSecurityService::class),
            $c->get(SessionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(View::class)
        ));
        $container->set(AuthController::class, fn (Container $c) => new AuthController(
            $c->get(AuthService::class),
            $c->get(SessionManager::class),
            $c->get(RequestActorResolver::class),
            $c->get(AuthorizationService::class),
            $c->get(ApiTokenService::class),
            $c->get(AdminSecurityService::class)
        ));
        $container->set(UserController::class, fn (Container $c) => new UserController(
            $c->get(RequestActorResolver::class),
            $c->get(AuthorizationService::class),
            $c->get(MailboxPasswordManager::class),
            $c->get(ForwardService::class),
            $c->get(QuotaService::class)
        ));

        
        $container->set(SpamPolicyController::class, fn (Container $c) => new SpamPolicyController(
            $c->get(AuthorizationService::class),
            $c->get(SessionManager::class),
            $c->get(SpamPolicyService::class),
            $c->get(TenantRepository::class),
            $c->get(View::class)
        ));

        $container->set(\MailPanel\Http\Controllers\MonitorController::class, fn (Container $c) => new \MailPanel\Http\Controllers\MonitorController(
            $c->get(View::class),
            $c->get(AgentClientService::class),
            $c->get(SessionManager::class),
            $c->get(\MailPanel\Security\AuthorizationService::class)
        ));

        $container->set(\MailPanel\Http\Controllers\SecuritySystemController::class, fn (Container $c) => new \MailPanel\Http\Controllers\SecuritySystemController(
            $c->get(View::class),
            $c->get(AgentClientService::class),
            $c->get(SessionManager::class),
            $c->get(\MailPanel\Security\AuthorizationService::class),
            $c->get(WebmailHealthService::class),
            $c->get(DomainRepository::class),
            $c->get(MailboxRepository::class),
            $c->get(AuditLogService::class),
            $c->get(WebmailDomainConfigService::class),
            $c->get(WebmailUserStorageService::class),
            $c->get(WebmailApplicationConfigService::class),
            $c->get(WebmailPluginDeploymentService::class)
        ));

        $router = new Router($container);
        (require $basePath . '/routes/web.php')($router);
        (require $basePath . '/routes/api.php')($router);

        return new Application($container, $router);
    }
}
