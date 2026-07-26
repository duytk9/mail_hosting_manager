# ACME TLS per-domain

## Mục tiêu
- Cấp chứng chỉ riêng theo từng domain tenant.
- Dùng cùng một inventory cert cho `nginx`, `exim`, `dovecot`.
- Validate/apply qua `config_versions`, có reload và rollback như các service khác.

## Kiến trúc
- `certbot` dùng `HTTP-01` qua webroot `/.well-known/acme-challenge/`.
- Script `deploy/manage_acme_tls.sh` quản lý bootstrap, readiness, provision, sync.
- Sau khi cấp cert, script copy `fullchain.pem` và `privkey.pem` vào `TLS_SNI_ROOT`, mặc định `/etc/mailpanel/tls/sni/<hostname>/`.
- `NginxConfigRenderer` render:
  - `80/tcp` challenge location
  - default `443` server
  - thêm `443` server block riêng cho từng hostname đã có cert trong inventory
- `EximConfigRenderer` render `tls_certificates.map` và `tls_privatekeys.map`, dùng `tls_in_sni` để chọn cert theo hostname.
- `DovecotConfigRenderer` render `local_name <hostname>` để chọn cert theo hostname IMAP/POP3/ManageSieve.

## Hostname mặc định mỗi domain
- apex domain nếu `ACME_INCLUDE_APEX=1`
- `mail.<domain>`
- `webmail.<domain>`
- `autodiscover.<domain>`
- `autoconfig.<domain>`

## Lệnh chính
- Bootstrap:
  - `bash deploy/manage_acme_tls.sh bootstrap /opt/mailpanel`
- Kiểm tra readiness DNS:
  - `bash deploy/manage_acme_tls.sh readiness /opt/mailpanel example.com`
- Cấp cert cho domain:
  - `ACME_EMAIL=admin@example.com bash deploy/manage_acme_tls.sh provision-domain /opt/mailpanel example.com`
- Đồng bộ lại inventory + apply config:
  - `bash deploy/manage_acme_tls.sh sync-domain /opt/mailpanel example.com`
  - `bash deploy/manage_acme_tls.sh sync-all /opt/mailpanel`

## DNS bắt buộc
- Tất cả hostname cần cấp cert phải trỏ về server đang chạy Nginx port `80`.
- Với model mặc định, cần ít nhất:
  - `mail.<domain>`
  - `webmail.<domain>`
  - `autodiscover.<domain>`
  - `autoconfig.<domain>`
- Nếu bật apex cert, root domain cũng phải trỏ đúng server.

## Ghi chú hiện trạng server
- Flow SSL per-domain có thể làm được trọn vẹn về mặt kiến trúc và automation.
- Nếu DNS chưa trỏ các hostname về server, `certbot` sẽ chưa issue thành công; khi đó dùng `readiness` để nhìn rõ hostname nào còn thiếu.
