# MailPanel Architecture

## Tổng quan
- Web app PHP MVC thuần, chạy bằng user không phải `root`
- Controller không gọi shell trực tiếp; mọi thao tác hệ thống đi qua `SystemCommandService` hoặc `mailpanel-agent`
- Repository layer truy cập MariaDB/MySQL; mọi dữ liệu nghiệp vụ đều mang `tenant_id`
- Generated config tách khỏi `/etc/*`, apply/reload/rollback qua agent có allowlist
- Admin dùng PHP session; automation dùng bearer token có scope và `actor_role`

## Security architecture
- Session có timeout cấu hình được, cookie `HttpOnly` + `SameSite`, CSRF token cho mọi form web
- API có rate limit nền; login admin/mailbox có rate limit riêng
- `RequestActorResolver` không còn fallback về `super_admin`; token giữ nguyên `actor_role`
- Audit log tự redact secret như password, token, TOTP secret, private key
- Tenant isolation được enforce ở controller/service cho các danh sách và thao tác ghi
- Super admin có thể bật IP allowlist toàn cục

## Auth & identity
- Panel theo mô hình `username-first`: đăng nhập bằng username hệ thống; UI dùng `username`, còn `linux_username` là storage field nội bộ
- Phân tầng hiển thị theo vai trò vận hành: `super_admin` ~ `Admin level`, `tenant_admin` ~ `User level`
- Admin web auth ưu tiên `password_hash` trong panel; Linux account chỉ được đồng bộ để phục vụ SSH/sudo và có thể fallback một lần khi `ADMIN_AUTH_MODE=hybrid`
- `super_admin` có thể impersonate `tenant_admin` để nhìn đúng scope của user level, và luôn có audit log cho bắt đầu/kết thúc phiên impersonation
- Admin login hỗ trợ TOTP khi account đã bật 2FA
- Mailbox login chỉ thành công khi mailbox/domain/tenant đều ở trạng thái active
- Password policy áp dụng cho mailbox, tenant admin, super admin
- Password history có thể bật bằng config
- Force password change được hỗ trợ cho admin và mailbox

## Inbound flow
- Exim nhận mail
- ACL/generated policy kiểm tra local domain
- Rspamd/ClamAV xử lý spam/virus
- Exim chuyển LMTP sang Dovecot
- Dovecot lưu Maildir vào `/var/vmail`

## Outbound flow
- SMTP AUTH đi qua Dovecot auth socket
- Exim kiểm tra policy tenant/domain/mailbox
- DKIM sign theo domain
- Rate-limit outbound theo package/tenant/domain/mailbox là lớp business tiếp theo cần hoàn thiện sâu hơn ở Exim ACL

## Dovecot security
- Dùng virtual users, SQL `passdb` / `userdb`
- `disable_plaintext_auth = yes`
- Auth query chỉ trả mailbox active thuộc domain active và tenant active
- Quota plugin, LMTP, ManageSieve vẫn đi qua Dovecot
- Auth socket chỉ expose nội bộ cho Exim

## Config deployment
- `ConfigDeploymentService` render draft vào generated path
- `config_versions` lưu version/checksum/trạng thái
- Agent validate trước, rồi activate, reload và rollback nếu lỗi
- `SystemCommandService` chỉ sinh command allowlist; không nhận shell raw từ request

## Fail2ban
- TLS / ACME:
  - `certbot` dùng `HTTP-01` qua webroot riêng
  - Inventory cert theo hostname nằm ở `TLS_SNI_ROOT`
  - `nginx`, `exim`, `dovecot` cùng đọc inventory này để phát cert theo SNI
  - Sau khi issue/renew, script đồng bộ inventory rồi apply lại `nginx`, `exim`, `dovecot` qua `config_versions`
- Jail template được render từ generated config
- Hiện có template cho `dovecot`, `exim-smtp-auth`, `exim-reject`, `webmail-auth`, `sshd` optional
- UI/agent chỉ nên expose tham số an toàn như `maxretry`, `findtime`, `bantime`, whitelist

## Webmail integration
- Webmail phải chạy tách biệt web admin panel
- Webmail chỉ làm webmail: đọc/gửi mail, folder, address book, sieve/vacation, đổi mật khẩu qua API panel
- Không cho webmail ghi trực tiếp vào bảng mail core
- Dữ liệu webmail nên tách khỏi DB/mail core khi khả thi
- Mọi luồng đổi mật khẩu từ webmail phải gọi endpoint đổi mật khẩu của panel để giữ audit log

## Multi-tenant isolation
- `AuthorizationService` enforce role + tenant scope
- API list của tenant admin chỉ trả dữ liệu tenant hiện tại
- Mailbox user chỉ đổi mật khẩu cho mailbox hiện tại
- Forwarding của mailbox user chỉ thao tác trên chính địa chỉ của mailbox đó
- Tenant quota vận hành theo mô hình package nền: `package_id` là gói nền, còn `extra_*` là phần cộng thêm theo tenant; chỉ khi bật custom limits mới ghi đè trực tiếp effective limits

## Rollback
- Mỗi render tạo version mới
- Validate pass mới được apply
- Nếu reload fail thì rollback về version trước và ghi audit log
