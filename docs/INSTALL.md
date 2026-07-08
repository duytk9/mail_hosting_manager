# Hướng dẫn cài đặt và cấu hình MailPanel từ đầu đến cuối

Tài liệu này mô tả cách dựng MailPanel trên Ubuntu production, cấu hình mail stack, bật agent hệ thống, TLS Let's Encrypt/SNI, webmail và các bước kiểm thử bắt buộc trước khi đưa vào vận hành.

> Ví dụ trong tài liệu dùng đường dẫn `/opt/mailpanel`, user web `www-data`, user agent `mailpanel-agent` và user mail ảo `vmail:vmail` với `uid/gid` là `2000:2000`. Hãy thay domain, IP và mật khẩu bằng giá trị thật của hệ thống.

## 1. Kiến trúc triển khai

- **App root**: `/opt/mailpanel`
- **Public root**: `/opt/mailpanel/public`
- **Generated config root**: `/var/lib/mailpanel/generated`
- **Mail storage**: `/var/vmail`
- **TLS SNI root**: `/etc/mailpanel/tls/sni`
- **ACME webroot**: `/var/www/acme`
- **Agent log**: `/var/log/mailpanel/agent.log`
- **Webmail root**: `/var/www/webmail`
- **Database**: MariaDB/MySQL, schema mặc định `mailpanel`

MailPanel được thiết kế theo nguyên tắc:

- Web app không chạy quyền `root`.
- Controller không gọi shell command trực tiếp.
- Command hệ thống đi qua mailpanel-agent hoặc service có allowlist, timeout và audit log.
- Mọi cấu hình service sinh ra đều có version, validate trước khi apply và có rollback.
- Mailbox là virtual user, không tạo Linux user cho từng hộp thư.

## 2. Yêu cầu hệ thống

Khuyến nghị:

- Ubuntu `22.04+`
- PHP `8.3+`
- MariaDB/MySQL `10.6+`
- Nginx + PHP-FPM
- Exim4
- Dovecot + SQL passdb/userdb + LMTP + ManageSieve
- Rspamd
- ClamAV
- Fail2ban
- Certbot
- Composer
- Git

Cài package nền:

```bash
apt update
apt install -y \
  git curl ca-certificates unzip sudo acl \
  nginx mariadb-server \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-intl php8.3-gd php8.3-zip php8.3-sqlite3 \
  exim4 dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-sieve dovecot-managesieved dovecot-mysql \
  rspamd clamav clamav-daemon fail2ban certbot composer
```

## 3. DNS và port cần chuẩn bị

Mở firewall/security group tối thiểu:

- `22` hoặc port SSH riêng của bạn.
- `80/tcp`: HTTP challenge cho Let's Encrypt.
- `443/tcp`: Admin portal và webmail HTTPS.
- `25/tcp`: SMTP inbound server-to-server.
- `465/tcp`: SMTPS/submission TLS-on-connect.
- `587/tcp`: Submission STARTTLS.
- `993/tcp`: IMAPS.
- `995/tcp`: POP3S nếu gói dịch vụ cho phép POP3.
- `4190/tcp`: ManageSieve nếu bật filter/vacation.

DNS khuyến nghị cho mỗi domain mail:

- `A mail.example.com -> <server-ip>`
- `MX example.com -> mail.example.com`
- `TXT SPF`: `v=spf1 mx -all` hoặc policy phù hợp.
- `TXT DKIM`: lấy từ DNS checker trong panel sau khi tạo domain.
- `TXT DMARC`: ví dụ `v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com`
- `A webmail.example.com -> <server-ip>` nếu dùng subdomain webmail.
- `A portal.example.com -> <server-ip>` nếu portal dùng domain riêng.

## 4. Lấy source code

```bash
git clone https://github.com/duytk9/quan_ly_mail_hosting.git /opt/mailpanel
cd /opt/mailpanel
```

Nếu deploy từ source đã có sẵn:

```bash
cd /opt/mailpanel
git pull --ff-only origin main
```

Không commit các file sau lên Git:

- `.env`
- private key/certificate riêng tư
- DKIM private key
- backup runtime
- log, session, cache, generated config thật

## 5. Tạo user và thư mục hệ thống

Tạo user lưu mail ảo:

```bash
groupadd -g 2000 vmail || true
useradd -u 2000 -g 2000 -d /var/vmail -s /usr/sbin/nologin vmail || true
install -d -o vmail -g vmail -m 0750 /var/vmail
```

Tạo thư mục runtime:

```bash
install -d -m 0750 /var/lib/mailpanel/generated
install -d -m 0755 /var/log/mailpanel
install -d -m 0755 /var/www/acme/.well-known/acme-challenge
chown -R www-data:www-data /opt/mailpanel/storage
chmod -R ug+rwX /opt/mailpanel/storage
```

## 6. Cấu hình database

Tạo database và user:

```bash
mysql -uroot -p
```

Trong MariaDB/MySQL:

```sql
CREATE DATABASE mailpanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mailpanel'@'127.0.0.1' IDENTIFIED BY '<mat-khau-db-manh>';
GRANT ALL PRIVILEGES ON mailpanel.* TO 'mailpanel'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Thoát MySQL rồi cấu hình `.env`.

## 7. Cấu hình `.env`

```bash
cd /opt/mailpanel
cp .env.example .env
nano .env
```

Các biến bắt buộc nên chỉnh:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://portal.example.com
APP_KEY=base64:<random-32-byte-base64>

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mailpanel
DB_USERNAME=mailpanel
DB_PASSWORD=<mat-khau-db-manh>

GENERATED_ROOT=/var/lib/mailpanel/generated
VMAIL_ROOT=/var/vmail
VMAIL_UID=2000
VMAIL_GID=2000

NGINX_ROOT=/opt/mailpanel/public
NGINX_SERVER_NAME=portal.example.com
NGINX_PHP_FPM_SOCKET=/run/php/php8.3-fpm.sock

ACME_EMAIL=admin@example.com
ACME_WEBROOT=/var/www/acme
ACME_AUTO_ISSUE_ON_DOMAIN_CREATE=true
TLS_SNI_ROOT=/etc/mailpanel/tls/sni

WEBMAIL_ENABLED=1
WEBMAIL_DRIVER=webmail
WEBMAIL_PUBLIC_ROOT=/var/www/webmail
WEBMAIL_PATH=/webmail

SESSION_TIMEOUT_SECONDS=1800
SESSION_COOKIE_SECURE=true
SESSION_COOKIE_SAMESITE=Strict

PASSWORD_MIN_LENGTH=12
PASSWORD_REQUIRE_UPPERCASE=true
PASSWORD_REQUIRE_LOWERCASE=true
PASSWORD_REQUIRE_NUMBER=true
PASSWORD_REQUIRE_SYMBOL=true

RATE_LIMIT_ADMIN_LOGIN_MAX_ATTEMPTS=5
RATE_LIMIT_ADMIN_LOGIN_WINDOW_SECONDS=900
RATE_LIMIT_API_MAX_ATTEMPTS=120
RATE_LIMIT_API_WINDOW_SECONDS=60

SUPER_ADMIN_IP_ALLOWLIST_ENABLED=false
SUPER_ADMIN_IP_ALLOWLIST=0.0.0.0/0
```

Tạo `APP_KEY`:

```bash
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

Nếu dùng TOTP, nên đặt `TOTP_ENCRYPTION_KEY` ổn định ngay từ đầu. Không đổi key này tùy tiện khi đã có admin bật 2FA.

## 8. Cài dependency và migrate database

Production:

```bash
cd /opt/mailpanel
composer install --no-dev --optimize-autoloader
php scripts/migrate.php
```

Môi trường phát triển/test:

```bash
composer install
php scripts/migrate.php
php vendor/bin/phpunit --testdox
```

Nếu database đã có bảng MailPanel từ trước nhưng chưa có `schema_migrations`, chỉ baseline sau khi kiểm tra schema:

```bash
php scripts/migrate.php --baseline-existing
php scripts/migrate.php --status
```

## 9. Khởi tạo dữ liệu quản trị

Seed demo chỉ dùng khi dựng mới hoặc lab:

```bash
MAILPANEL_SEED_ADMIN_PASSWORD='<mat-khau-admin-manh>' php scripts/seed.php
```

Sau khi seed, đổi mật khẩu admin theo cách không lộ trong process list:

```bash
printf '%s\n' '<mat-khau-admin-moi>' | php admin_account.php reset --email=admin@example.test --password-stdin --disable-totp --force-password-change
```

Kiểm tra tài khoản:

```bash
php admin_account.php status --email=admin@example.test
```

Trong production nên:

- Đổi email/admin mặc định.
- Tạo super admin thật trong giao diện.
- Bật TOTP ngay sau lần đăng nhập đầu tiên.
- Bật IP allowlist cho super admin sau khi đã xác nhận IP quản trị ổn định.

## 10. Cài mailpanel-agent

Agent giúp web app yêu cầu thao tác hệ thống mà không chạy root trực tiếp.

```bash
cd /opt/mailpanel
bash deploy/install_agent.sh /opt/mailpanel mailpanel-agent www-data
```

Script sẽ:

- Tạo user `mailpanel-agent` nếu chưa có.
- Tạo `/var/lib/mailpanel/generated`.
- Cài `/usr/local/bin/mailpanel-system-wrapper`.
- Cài `/usr/local/bin/mailpanel-agent`.
- Cài `/usr/local/bin/mailpanel-web-agent`.
- Tạo sudoers allowlist cho agent và web user.
- Cấu hình log `/var/log/mailpanel/agent.log`.

Kiểm tra quyền:

```bash
ls -l /usr/local/bin/mailpanel-*
sudo -u www-data sudo -n /usr/local/bin/mailpanel-web-agent service-status nginx
tail -n 50 /var/log/mailpanel/agent.log
```

Nếu log bị `Permission denied`, sửa lại:

```bash
install -d -m 0755 /var/log/mailpanel
touch /var/log/mailpanel/agent.log
chown mailpanel-agent:mailpanel-agent /var/log/mailpanel/agent.log
chmod 0640 /var/log/mailpanel/agent.log
```

## 11. Cấu hình Nginx/PHP-FPM

Tạo server block tạm cho portal hoặc để MailPanel sinh cấu hình qua config version.

Ví dụ tối thiểu:

```nginx
server {
    listen 80;
    server_name portal.example.com;

    root /opt/mailpanel/public;
    index index.php;

    location /.well-known/acme-challenge/ {
        root /var/www/acme;
    }

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Kiểm tra:

```bash
nginx -t
systemctl reload nginx
curl -I http://portal.example.com/admin/login
```

Sau khi có TLS, chỉ phục vụ admin qua HTTPS.

## 12. Cấu hình TLS Let's Encrypt và SNI

Bootstrap thư mục ACME/TLS:

```bash
cd /opt/mailpanel
ACME_EMAIL=admin@example.com bash deploy/manage_acme_tls.sh bootstrap /opt/mailpanel
```

Cấp chứng chỉ cho domain:

```bash
ACME_EMAIL=admin@example.com bash deploy/manage_acme_tls.sh provision-domain /opt/mailpanel example.com
```

Đồng bộ certificate vào SNI root:

```bash
bash deploy/manage_acme_tls.sh sync-domain /opt/mailpanel example.com
bash deploy/manage_acme_tls.sh sync-all /opt/mailpanel
```

Kiểm tra file:

```bash
ls -l /etc/mailpanel/tls/sni
```

MailPanel hỗ trợ auto SNI cho Exim khi domain có TLS tương ứng trong `TLS_SNI_ROOT`. Sau khi thêm domain, cần sinh/apply config để Exim nhận mapping mới.

## 13. Cấu hình Exim/Dovecot/Rspamd/ClamAV/Fail2ban

Luồng chuẩn:

1. Đăng nhập admin portal.
2. Tạo package.
3. Tạo tenant.
4. Tạo domain chính.
5. Tạo mailbox/alias/forward/group nếu cần.
6. Vào **Lịch sử cấu hình**.
7. Sinh bản cấu hình mới.
8. Validate.
9. Apply.
10. Kiểm tra trạng thái service và rollback nếu health check lỗi.

Các service cần reload sau apply:

- `nginx`
- `exim4`
- `dovecot`
- `rspamd`
- `clamav-daemon`
- `fail2ban`

Kiểm tra service:

```bash
systemctl status nginx exim4 dovecot rspamd clamav-daemon fail2ban --no-pager
ss -lntp | egrep ':(25|80|443|465|587|993|995|4190)\s'
```

Kiểm tra Fail2ban:

```bash
fail2ban-client ping
fail2ban-client -t
fail2ban-client status
fail2ban-client status dovecot
fail2ban-client status exim-smtp-auth
fail2ban-client status exim-reject
fail2ban-client status webmail-auth
fail2ban-client get exim-smtp-auth logpath
fail2ban-client get exim-reject logpath
fail2ban-client get webmail-auth logpath
```

Kỳ vọng `exim-smtp-auth` và `exim-reject` đọc `/var/log/exim4/mainlog`; `webmail-auth` đọc đúng `WEBMAIL_LOG_PATH`. Xem thêm `docs/FAIL2BAN.md` để test regex bằng log thật.

Kiểm tra SMTP AUTH không bị mở plaintext:

```bash
openssl s_client -starttls smtp -connect mail.example.com:587 -servername mail.example.com
openssl s_client -connect mail.example.com:465 -servername mail.example.com
```

Kiểm tra IMAPS/POP3S:

```bash
openssl s_client -connect mail.example.com:993 -servername mail.example.com
openssl s_client -connect mail.example.com:995 -servername mail.example.com
```

## 14. Cấu hình webmail

MailPanel không tự viết webmail trong MVP. Webmail được mount tại `/webmail` hoặc domain riêng, kết nối IMAPS tới Dovecot và gửi qua Exim Submission.

Yêu cầu:

- Webmail root có sẵn tại `/var/www/webmail`.
- `index.php` tồn tại trong webmail root.
- Config/log/temp/upload của webmail không public trực tiếp.
- Plugin password nếu dùng phải gọi API đổi mật khẩu của MailPanel, không update DB core trực tiếp.

Cài tích hợp:

```bash
cd /opt/mailpanel
bash deploy/install_webmail_stack.sh /opt/mailpanel /var/www/webmail
```

Script sẽ:

- Cấu hình `.env` liên quan webmail.
- Tạo log auth riêng cho Fail2ban.
- Mount `public/webmail` tới webmail root.
- Dọn artifact deploy cũ nếu bị flatten sai chỗ.
- Apply lại Nginx/Fail2ban config qua agent.

Kiểm tra:

```bash
curl -I https://portal.example.com/webmail/
tail -n 50 /var/www/webmail/data/_data_/_default_/logs/auth.log
fail2ban-client status
```

## 15. Cấu hình trong giao diện quản trị

Truy cập:

```text
https://portal.example.com/admin/login
```

Luồng cấu hình khuyến nghị:

1. **Bảo mật**: bật TOTP, kiểm tra session timeout, password policy, rate limit.
2. **Super Admins**: tạo tài khoản quản trị thật, giới hạn IP nếu cần.
3. **Packages**: tạo gói dịch vụ với giới hạn domain, mailbox, alias, forward, quota, message size và tính năng POP3/IMAP/ManageSieve.
4. **Tenants**: tạo khách hàng, gán package, owner account, ngày hết hạn và grace date.
5. **Domains**: thêm domain, bật inbound/outbound/DKIM.
6. **DNS/TLS Checks**: kiểm tra MX, SPF, DKIM, DMARC, TLS và cấp Let's Encrypt nếu DNS đã đúng.
7. **Mailboxes**: tạo hộp thư, quota, bật/tắt IMAP/POP3/SMTP/ManageSieve.
8. **Routing**: tạo alias nội bộ, forward ra ngoài, group mail.
9. **Spam Policies**: cấu hình allowlist/blocklist, spam level, quarantine.
10. **Config Versions**: generate, validate, apply và rollback khi cần.
11. **Queue/Logs/Fail2ban/Rspamd/Webmail**: giám sát vận hành.

Tenant admin chỉ nên thấy các chức năng cần thiết trong scope tenant của họ. Super admin quản lý toàn hệ thống.

## 16. Ngày hết hạn và grace date

Mỗi tenant có:

- **Ngày hết hạn**: ngày kết thúc dịch vụ.
- **Grace date**: ngày ân hạn sau ngày hết hạn, bắt buộc lớn hơn ngày hết hạn tối thiểu 1 ngày.

Khuyến nghị vận hành:

- Trước ngày hết hạn: tenant hoạt động bình thường.
- Từ ngày hết hạn đến grace date: hiển thị cảnh báo, có thể vẫn cho nhận mail theo policy.
- Sau grace date: chặn hành động quản trị, outbound hoặc suspend tenant theo policy.

Biến môi trường liên quan:

```dotenv
TENANT_EXPIRY_WARNING_DAYS=14
TENANT_DEFAULT_GRACE_DAYS=7
TENANT_EXPIRED_INBOUND_MODE=accept
```

## 17. Quota và dung lượng

Quota được quản lý theo nhiều lớp:

- Package đặt giới hạn tổng của tenant.
- Tenant có thể có override/extra quota.
- Mailbox có quota riêng do admin cấp.
- Tổng quota đã cấp có thể vượt quota tenant, nhưng dung lượng sử dụng thực tế không được vượt giới hạn vận hành của tenant/package.

Cập nhật dung lượng đã dùng:

- Qua dashboard/quota refresh trong admin.
- Qua agent action đo mailbox storage.
- Qua Dovecot quota/maildir metadata khi hệ thống mail đã chạy ổn định.

Kiểm tra thủ công:

```bash
du -sh /var/vmail/example.com/user
find /var/vmail/example.com/user -name maildirsize -print -exec cat {} \;
```

## 18. Kiểm thử bắt buộc trước production

Kiểm thử ứng dụng:

```bash
cd /opt/mailpanel
php vendor/bin/phpunit --testdox
```

Kiểm thử web:

```bash
curl -I https://portal.example.com/admin/login
curl -I https://portal.example.com/webmail/
```

Kiểm thử DNS:

```bash
dig MX example.com
dig TXT example.com
dig TXT mail._domainkey.example.com
dig TXT _dmarc.example.com
```

Kiểm thử mail inbound/outbound:

- Gửi từ Gmail/Outlook vào mailbox nội bộ.
- Gửi từ mailbox nội bộ ra Gmail/Outlook qua port `587` hoặc `465`.
- Reply từ Gmail về mailbox/alias/group/forward.
- Kiểm tra queue trong admin và bằng `exim -bp`.
- Kiểm tra log Exim khi gặp `Unrouteable address` hoặc `relay not permitted`.

Kiểm thử bảo mật:

- Tenant A không đọc/sửa dữ liệu tenant B.
- Disabled mailbox không auth được.
- Suspended tenant không outbound được.
- SMTP AUTH chỉ hoạt động qua TLS.
- Mailbox chỉ gửi bằng địa chỉ của chính nó hoặc alias được phép.
- Không open relay.
- API token không vượt scope.
- TOTP bắt buộc với admin đã bật 2FA.
- Fail2ban ban brute-force Dovecot/Exim/webmail.

## 19. Backup và cập nhật

Backup source/config/database trước khi update:

```bash
tar -czf /root/mailpanel-source-$(date +%F-%H%M%S).tgz /opt/mailpanel
mysqldump -uroot -p mailpanel > /root/mailpanel-db-$(date +%F-%H%M%S).sql
tar -czf /root/mailpanel-generated-$(date +%F-%H%M%S).tgz /var/lib/mailpanel/generated /etc/mailpanel /var/vmail
```

Cập nhật source:

```bash
cd /opt/mailpanel
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php scripts/migrate.php
bash deploy/install_agent.sh /opt/mailpanel mailpanel-agent www-data
```

Sau update:

```bash
php vendor/bin/phpunit --testdox
nginx -t
systemctl reload php8.3-fpm nginx
```

Sau đó vào **Config Versions** để generate/apply lại cấu hình service nếu có thay đổi renderer.

## 20. Xử lý lỗi thường gặp

### Trang admin trắng hoặc lỗi 500

```bash
tail -n 100 /var/log/nginx/error.log
tail -n 100 /opt/mailpanel/storage/logs/*.log
php -l public/index.php
```

Kiểm tra `.env`, quyền `storage`, PHP-FPM socket và migration DB.

### Agent báo Permission denied

```bash
bash deploy/install_agent.sh /opt/mailpanel mailpanel-agent www-data
tail -n 100 /var/log/mailpanel/agent.log
sudo -u www-data sudo -n /usr/local/bin/mailpanel-web-agent service-status nginx
```

### Gmail trả về `550 relay not permitted`

Kiểm tra:

- Domain có trong bảng `domains` và đang active.
- Mailbox/alias/group/forward recipient tồn tại.
- Config Exim đã generate/apply sau khi tạo domain/routing.
- MX đang trỏ đúng server.
- Exim router có mapping alias/forward/group.

```bash
exim -bt support@example.com
exim -bt alias@example.com
tail -n 100 /var/log/exim4/mainlog
```

### Gmail vào alias báo `Unrouteable address`

Kiểm tra alias/forward/group trong panel, sau đó generate/apply Exim config:

```bash
exim -bt hotro@example.com
exim -bP routers | head
systemctl reload exim4
```

### Webmail lỗi CSP/CSS

Kiểm tra Nginx config sinh ra cho `/webmail`, security headers, asset path và public root:

```bash
curl -I https://portal.example.com/webmail/
find /var/www/webmail -maxdepth 2 -type f | head
tail -n 100 /var/log/nginx/error.log
```

### Quota hiển thị sai

Chạy refresh usage từ admin hoặc kiểm tra maildir:

```bash
du -sh /var/vmail/example.com/user
find /var/vmail/example.com/user -name maildirsize -print -exec cat {} \;
```

## 21. Checklist go-live

- [ ] `.env` production đã có `APP_KEY`, `APP_DEBUG=false`, DB password mạnh.
- [ ] Portal chạy HTTPS.
- [ ] Web app chạy bằng `www-data`, không chạy root.
- [ ] Agent wrapper và sudoers allowlist đã đúng.
- [ ] TOTP bật cho super admin.
- [ ] IP allowlist super admin được cấu hình nếu cần.
- [ ] Port `25`, `80`, `443`, `465`, `587`, `993`, `995`, `4190` đã kiểm tra.
- [ ] Exim không open relay.
- [ ] SMTP AUTH chỉ cho TLS.
- [ ] Dovecot dùng virtual users SQL.
- [ ] DKIM/SPF/DMARC/MX đúng cho domain thật.
- [ ] Let's Encrypt/SNI hoạt động cho portal, mail host và webmail.
- [ ] Rspamd, ClamAV, Fail2ban đang chạy.
- [ ] Queue/logs hiển thị trong admin.
- [ ] Alias, forward và group mail đã test inbound.
- [ ] Webmail đăng nhập/gửi/nhận/2FA hoạt động.
- [ ] Backup database/source/config/mail storage đã có.
- [ ] Test tenant isolation và RBAC pass.
