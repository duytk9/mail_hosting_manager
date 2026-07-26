# Admin UI

## Mục tiêu hiện tại

- Giữ nền PHP MVC hiện có, không đổi framework.
- Ưu tiên UI admin và luồng quản trị trước Phase 2/3/4.
- Dùng giao diện tiếng Việt mặc định, cảm hứng hosting panel nhưng nhận diện riêng của `MailPanel`.

## Những gì đang có

- Layout admin dạng sidebar + topbar tại `src/Views/admin/layout.php`.
- Login admin riêng tại `src/Views/admin/login.php`.
- Các trang admin đã có:
  - `dashboard`
  - `security`
  - `packages`
  - `tenants`
  - `domains`
  - `mailboxes`
  - `routing`
  - `config_versions`
  - `super_admins`
    - quản lý account super admin
    - sync Linux/SSH
    - cấu hình IP allowlist toàn cục cho super admin
- Component view dùng lại:
  - `status_badge`
  - `metric_card`
  - `empty_state`

## Phase 1 đã làm

- Chuẩn hoá layout admin theo nhóm module.
- Thêm breadcrumb và quick actions theo từng trang.
- Thêm search/filter cơ bản cho:
  - packages
  - tenants
  - super admins
  - domains
  - mailboxes
  - routing
  - config versions
- Đồng bộ lại ngôn ngữ UI tiếng Việt rõ ràng hơn.

## Chưa làm xong

- Pagination/sort/bulk action chuẩn cho mọi bảng.
- Toast JS thật thay vì flash server-side.
- Modal confirm dùng lại ở mọi hành động nguy hiểm.
- Route/UI cho:
  - DNS checker
  - queue/logs
  - fail2ban
  - webmail integration settings
  - roles & permissions
  - audit logs

## Nguyên tắc mở rộng tiếp

- Không gọi shell từ view/controller.
- Mọi thao tác hệ thống đi qua service + agent.
- Nếu thêm page mới, ưu tiên reuse component trong `src/Views/admin/components`.
