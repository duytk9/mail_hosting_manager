---
last_mapped: 2026-07-26T14:45:49Z
total_files: 170
total_tokens: unknown
---

# Codebase Map

> Auto-generated from the current local tree plus verified live-server runtime notes. Last mapped: 2026-07-26T14:45:49Z

## System Overview

```mermaid
graph TB
    Browser[Admin Browser]
    Webmail[Webmail at /webmail]
    Nginx[Nginx]
    PHP[PHP-FPM / MailPanel]
    DB[(MariaDB)]
    Agent[mailpanel-web-agent]
    Wrapper[mailpanel-system-wrapper]
    Exim[Exim]
    Dovecot[Dovecot]
    Rspamd[Rspamd]
    Fail2ban[Fail2ban]
    VMail[/var/vmail/]

    Browser --> Nginx
    Webmail --> Nginx
    Nginx --> PHP
    PHP --> DB
    PHP --> Agent
    Agent --> Wrapper
    Wrapper --> Exim
    Wrapper --> Dovecot
    Wrapper --> Rspamd
    Wrapper --> Fail2ban
    Dovecot --> DB
    Dovecot --> VMail
    Exim --> Dovecot
```

## Directory Structure

- `public/`
  - HTTP entrypoint and `/webmail` symlink mount
- `routes/`
  - `web.php` for admin UI
  - `api.php` for admin and mailbox APIs
- `src/Bootstrap/`
  - app wiring, DI, config loading
- `src/Core/`
  - request, response, router, database primitives
- `src/Security/`
  - session, CSRF, TOTP, RBAC, token guard
- `src/Repositories/Pdo/`
  - PDO-backed repositories per resource
- `src/Services/`
  - business services, renderers, deployment orchestration
- `src/Http/Controllers/`
  - admin web, admin API, auth, monitoring, security UI
- `src/Views/admin/`
  - layout, dashboard, CRUD pages, security pages
- `database/migrations/`
  - schema bootstrap and later security/auth additions
- `deploy/`
  - agent install, ACME sync, webmail wiring
- `tests/`
  - focused unit coverage for auth, routing, renderers, validators

## Module Guide

### Entry / Boot

**Purpose**: Start the PHP app and session securely.

- `public/index.php`
- `bootstrap/app.php`
- `src/Bootstrap/ApplicationFactory.php`

### Security Layer

**Purpose**: Resolve actors, enforce permissions, manage sessions and TOTP.

- `src/Security/SessionManager.php`
- `src/Security/CsrfService.php`
- `src/Security/AuthorizationService.php`
- `src/Security/RequestActorResolver.php`
- `src/Security/TokenGuard.php`
- `src/Security/TotpService.php`

### Admin Web Layer

**Purpose**: Render the admin console and handle form flows.

The single `AdminWebController` was split into one controller per resource. Shared
rendering, guards and list helpers live in `Traits/AdminWebLayoutTrait.php`; do not
copy them back into individual controllers.

- `src/Http/Controllers/AdminAuthController.php` — login, logout, impersonation
- `src/Http/Controllers/AdminDashboardController.php`
- `src/Http/Controllers/AdminTenantController.php`
- `src/Http/Controllers/AdminDomainController.php` — domains + DNS checks
- `src/Http/Controllers/AdminMailboxController.php`
- `src/Http/Controllers/AdminRoutingController.php` — aliases, forwards, groups
- `src/Http/Controllers/AdminPackageController.php`
- `src/Http/Controllers/AdminSecurityController.php` — account security, super admins
- `src/Http/Controllers/AdminConfigDeploymentController.php`
- `src/Http/Controllers/SecuritySystemController.php` — fail2ban, rspamd, webmail
- `src/Http/Controllers/MonitorController.php` — queue, logs
- `src/Http/Controllers/SpamPolicyController.php`
- `src/Http/Controllers/Traits/AdminWebLayoutTrait.php` — shared guards/helpers
- `src/Views/admin/layout.php`
- `src/Views/admin/pages/*.php`

### Admin API Layer

**Purpose**: Expose machine-friendly CRUD and config deployment endpoints.

- `src/Http/Controllers/AdminController.php`
- `routes/api.php`

### Mail Domain Logic

**Purpose**: CRUD and policy for tenants, domains, mailboxes, aliases, forwards, and groups.

- `src/Services/TenantService.php`
- `src/Services/DomainService.php`
- `src/Services/MailboxService.php`
- `src/Services/AliasService.php`
- `src/Services/ForwardService.php`
- `src/Services/MailGroupService.php`

### Config Rendering / Deployment

**Purpose**: Generate versioned configs, validate, apply, and roll back service configs.

- `src/Services/NginxConfigRenderer.php`
- `src/Services/EximConfigRenderer.php`
- `src/Services/DovecotConfigRenderer.php`
- `src/Services/RspamdConfigRenderer.php`
- `src/Services/Fail2banConfigRenderer.php`
- `src/Services/ConfigDeploymentService.php`
- `src/Services/AgentClientService.php`

## Data Flow

### Admin Login

```mermaid
sequenceDiagram
    participant U as Admin
    participant W as AdminAuthController
    participant A as AuthService
    participant D as DB
    U->>W: POST /admin/login
    W->>A: loginAdmin(email,password,otp)
    A->>D: load user + validate policy
    D-->>A: user record
    A-->>W: session identity
    W-->>U: redirect /admin/dashboard
```

### Config Apply

```mermaid
sequenceDiagram
    participant U as Admin
    participant W as AdminConfigDeploymentController
    participant C as ConfigDeploymentService
    participant G as Generated config store
    participant A as AgentClientService
    participant S as System wrapper
    U->>W: POST apply config version
    W->>C: applyVersion(id,false)
    C->>A: apply-config payload
    A->>S: sudo web agent -> root wrapper
    S->>G: activate version + validate + reload
    S-->>A: result
    A-->>C: stage/result
    C-->>W: applied or failed
    W-->>U: flash + redirect
```

## Live Runtime Notes

- Canonical app root on live: `/opt/mailpanel`
- Canonical generated root on live: `/var/lib/mailpanel/generated`
- Live services verified listening: `25`, `80`, `443`, `465`, `587`, `993`, `995`, `4190`, `8686`
- Current drift found on live: Nginx was rewriting `/webmail` to the wrong upstream even though the webmail app still existed at its configured root

## Gotchas

- Route access level is fail-closed: `Router::add()` rejects any route that does not
  declare exactly one of `'auth' => true` or `'public' => true`
- Tenant scoping happens in SQL. Pass `currentTenantId()` into `Service::list()`;
  the old array-side `scopeByTenant()` helpers were removed on purpose
- Migrations are tracked in `schema_migrations` and run one statement at a time.
  Never put `DELETE`/`TRUNCATE` in a migration — `MigrationRunner` rejects it.
  Row cleanup belongs in `scripts/cleanup_orphans.php`
- Templates are not auto-escaped. Use the `$e(...)` closure injected by `View::render()`
- Several docs and templates still carry legacy webmail naming
- Some admin UI files still contain mojibake and need a broader UTF-8 cleanup pass
- `public/webmail` in the local Windows workspace is a symlink target placeholder, not bundled webmail source

## Navigation Guide

- To change auth/session behavior: `config/app.php`, `src/Security/*`, `src/Services/AuthService.php`
- To change tenant/domain/mailbox rules: `src/Services/*Service.php`, `src/Repositories/Pdo/*Repository.php`
- To change live config generation: `src/Services/*ConfigRenderer.php`
- To change admin UI shell: `src/Views/admin/layout.php`
- To change route access control: `routes/web.php`, `routes/api.php`, `src/Core/Router.php`
- To change schema: add a file under `database/migrations/`, then `php scripts/migrate.php`
- To deploy: `deploy/deploy.sh` and `docs/DEPLOY_RUNBOOK.md`
- To change live webmail behavior: `config/mailpanel.php`, `deploy/install_webmail_stack.sh`, `src/Services/NginxConfigRenderer.php`, `src/Services/Fail2banConfigRenderer.php`
