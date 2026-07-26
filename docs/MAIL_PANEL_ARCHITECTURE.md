# MailPanel Architecture

## Stack hiện tại

- Runtime: `PHP 8.3`
- App style: PHP thuần theo mô hình MVC nhẹ
- Router: `src/Core/Router.php`
- DI container: `src/Core/Container.php`
- Config bootstrap: `src/Bootstrap/ApplicationFactory.php`
- Database: `PDO` qua repository pattern
- Test: `PHPUnit 11`

## Tầng ứng dụng

- `src/Core`
  - request/response/router/container/database/config
- `src/Http/Controllers`
  - web admin controller
  - JSON API controller
  - auth controller
  - mailbox user controller
- `src/Services`
  - business logic
  - config renderers
  - deployment/config apply
  - auth/password/quota/security
- `src/Repositories/Pdo`
  - truy cập DB bằng SQL tường minh
- `src/Security`
  - session
  - CSRF
  - token guard
  - actor resolver
  - authorization

## Auth & RBAC

- Session auth cho admin/mailbox.
- API token scope qua `TokenGuard` + `AuthorizationService`.
- TOTP cho admin qua `TotpService`.
- Rate limit login/API qua `RateLimiterService`.
- Tenant scope được enforce ở:
  - router meta
  - controller scope helpers
  - test coverage

## Dữ liệu chính

- Core: `users`, `packages`, `tenants`, `audit_logs`, `config_versions`
- Mail: `domains`, `mailboxes`, `aliases`, `forwards`, `quota_usage`
- Security/auth: `api_tokens`, `user_password_history`, `mailbox_password_history`
- Admin/ops: `mail_groups`, `mail_group_members`

## Mail system integration

- Exim config renderer
- Dovecot config renderer
- Rspamd config renderer
- Fail2ban config renderer
- Nginx/TLS renderer cho web + ACME
- Agent wrapper để validate/apply/reload/rollback

## Điều giữ nguyên

- Không thay framework.
- Không scaffold project mới.
- Giữ router, service layer, repository layer và migration hiện có.
- Nâng dần UI + module thay vì đập đi viết lại.

## Điều cần nâng tiếp

- CRUD đầy đủ hơn cho từng module admin.
- Module DNS checker / mail queue / logs / fail2ban / webmail settings.
- Reusable UI component nhiều hơn.
- Pagination/sort/export.
