# Hệ thống điểm danh sinh viên

Đồ án thực hành môn **Triển khai và Quản trị Hệ thống Phần mềm**.

Ứng dụng cho phép giảng viên quản lý lớp, sinh viên, buổi học và điểm danh; hệ thống sử dụng PHP, MySQL, phpMyAdmin, Apache, Nginx, Prometheus, Grafana, Loki và Promtail. Toàn bộ dịch vụ được quản lý bằng Docker Compose.

## Yêu cầu

- Docker Desktop đang chạy.
- Docker Compose v2 (`docker compose`).
- Git (nếu clone repository).

## Cấu hình và khởi chạy

Mở PowerShell tại thư mục dự án. Tạo file `.env` từ mẫu:

```powershell
Copy-Item .env.example .env
```

Mở `.env` và thay các giá trị sau bằng mật khẩu mạnh, riêng biệt:

- `MYSQL_ROOT_PASSWORD`
- `MYSQL_PASSWORD`
- `GRAFANA_ADMIN_PASSWORD`

Không commit hoặc chia sẻ file `.env`. Giữ `MYSQL_DATABASE` và `MYSQL_USER` theo giá trị trong file mẫu, trừ khi đã cập nhật các cấu hình sử dụng chúng.

Kiểm tra cấu hình Compose và khởi động các dịch vụ:

```powershell
docker compose config --quiet
docker compose up -d --build
docker compose ps
```

Đợi MySQL hiển thị trạng thái `healthy` trước khi sử dụng ứng dụng. Kiểm tra log nếu dịch vụ không khởi động:

```powershell
docker compose logs --tail 100
docker compose logs --tail 100 app
docker compose logs --tail 100 mysql
```

## Các địa chỉ truy cập

| Dịch vụ | Địa chỉ | Đăng nhập / ghi chú |
|---|---|---|
| Ứng dụng điểm danh | <http://localhost:8080> | Đi qua Nginx reverse proxy |
| phpMyAdmin | <http://localhost:8081> | Tên đăng nhập và mật khẩu MySQL trong `.env` |
| Grafana | <http://localhost:3000> | `admin` / giá trị `GRAFANA_ADMIN_PASSWORD` trong `.env` |
| Prometheus | <http://localhost:9090> | Targets tại <http://localhost:9090/targets> |
| Loki | Nội bộ qua Grafana Explore | Chọn datasource `Loki`; Loki không publish cổng host |

Các cổng được publish ra máy host bind vào `127.0.0.1`, vì vậy chỉ truy cập trực tiếp được từ máy đang chạy Docker.

## Tạo tài khoản và sử dụng ứng dụng

1. Mở <http://localhost:8080/setup.php>.
2. Tạo tài khoản giảng viên. Mật khẩu cần ít nhất 12 ký tự.
3. Đăng nhập tại <http://localhost:8080>.
4. Dùng menu để tạo lớp, thêm sinh viên vào lớp, tạo buổi học và ghi nhận sinh viên có mặt/vắng.
5. Chọn **Đổi mật khẩu** trong menu để thay mật khẩu tài khoản; cần nhập mật khẩu hiện tại và xác nhận mật khẩu mới.

Trang thiết lập chỉ cho phép tạo tài khoản khi database chưa có tài khoản giảng viên. Sau khi tạo xong, đăng nhập ứng dụng để tiếp tục.

## Database và dữ liệu demo

Lược đồ nằm tại `database/schema.sql`. MySQL tự chạy lược đồ khi khởi tạo volume database lần đầu. phpMyAdmin sử dụng database `attendance_db` và tài khoản ứng dụng được khai báo trong `.env`.

Sau khi khởi chạy hệ thống và tạo tài khoản giảng viên, có thể nạp hai lớp và sáu sinh viên mẫu:

```powershell
docker compose cp .\database\demo-data.sql mysql:/tmp/attendance-demo-data.sql
docker compose exec mysql sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < /tmp/attendance-demo-data.sql'
docker compose exec mysql sh -c 'rm /tmp/attendance-demo-data.sql'
```

Script dùng mã lớp `DEMO-*` và email thuộc miền `.invalid`; có thể chạy lại mà không xóa hoặc ghi đè dữ liệu hiện có.

`docker compose down` dừng và gỡ các container nhưng giữ dữ liệu trong các Docker volume. **Không dùng `docker compose down -v` nếu muốn giữ database, dashboard và log đã lưu**, vì tùy chọn `-v` xóa volumes.

## Prometheus và Grafana

Prometheus thu thập metrics từ cAdvisor, Nginx Exporter và MySQL Exporter. Grafana được provision sẵn datasource Prometheus và dashboard **Attendance system overview**.

1. Mở Grafana tại <http://localhost:3000> và đăng nhập bằng `admin` cùng `GRAFANA_ADMIN_PASSWORD`.
2. Mở **Dashboards** → **Attendance system overview** để xem CPU/bộ nhớ container, request rate của Nginx và metrics MySQL.
3. Mở <http://localhost:9090/targets> để xác nhận các scrape targets có trạng thái `UP`.

## Loki và Promtail

Promtail phát hiện container Docker và gửi log ứng dụng/hạ tầng tới Loki. Trong Grafana, mở **Explore**, chọn datasource **Loki**, chuyển sang chế độ **Code** và chạy các truy vấn LogQL:

```logql
{service="nginx"}
{service="app"}
{service="nginx"} |= " 200 "
```

Mở lại <http://localhost:8080> để tạo thêm request và log Nginx. Chọn khoảng thời gian phù hợp trong Grafana Explore nếu chưa thấy bản ghi.

Loki lưu log tối đa 7 ngày trong volume `loki_data`. Promtail lưu vị trí đọc trong volume `promtail_positions` để tiếp tục thu thập sau khi khởi động lại. Promtail không thu thập log nội bộ của Loki và Promtail.

## Các biện pháp bảo mật và giới hạn

- Nginx thêm các header bảo mật cơ bản; cấu hình nằm tại `infra/nginx/default.conf`.
- `.env` chứa mật khẩu cục bộ và được loại khỏi Git bởi `.gitignore`; chỉ file mẫu `.env.example` được đưa vào repository.
- Các cổng dịch vụ được publish chỉ bind vào loopback. MySQL, Loki và exporter không publish cổng trực tiếp ra host.
- Ứng dụng kết nối MySQL bằng tài khoản ứng dụng, không dùng tài khoản `root`; mật khẩu giảng viên được lưu bằng `password_hash` và xác minh bằng `password_verify`.
- Cookie phiên có cờ `HttpOnly` và `SameSite=Strict`; các thao tác gửi biểu mẫu có kiểm tra CSRF token.
- PHP không công khai phiên bản (`expose_php=Off`).

Các dịch vụ Compose hiện cùng tham gia mạng `attendance`; network chưa được tách riêng theo nhóm dịch vụ. cAdvisor đang chạy với `privileged: true` và quyền đọc một số đường dẫn host để thu thập metrics. Promtail cần mount Docker socket ở chế độ chỉ đọc để phát hiện container và log. Đây là các quyền nhạy cảm: không mở các dịch vụ này ra Internet và chỉ chạy stack trên máy/môi trường bạn tin cậy. Không tuyên bố cấu hình hiện tại đã network-isolated hoặc toàn bộ container chạy non-root.

## Xử lý lỗi thường gặp

**Compose báo thiếu biến môi trường**

- Xác nhận đã tạo `.env` trong cùng thư mục với `docker-compose.yml`.
- Kiểm tra các biến bắt buộc đã có giá trị, đặc biệt là ba mật khẩu nêu ở phần cấu hình.
- Chạy lại `docker compose config --quiet`.

**Ứng dụng hoặc phpMyAdmin chưa kết nối được database**

- Chạy `docker compose ps` và đợi MySQL ở trạng thái `healthy`.
- Xem `docker compose logs --tail 100 mysql` và `docker compose logs --tail 100 app`.
- Nếu sửa mật khẩu sau khi volume MySQL đã được tạo, các biến môi trường mới không tự đổi mật khẩu bên trong database hiện có. Không xóa volume nếu cần giữ dữ liệu.

**Grafana/Loki chưa có dữ liệu**

- Mở Prometheus targets và xác nhận scrape targets đang `UP`.
- Trong Grafana Explore, chọn đúng datasource (Prometheus cho metrics, Loki cho log) và kiểm tra khoảng thời gian.
- Mở trang ứng dụng để sinh request/log mới; xem `docker compose logs --tail 100 promtail loki` nếu vẫn không có dữ liệu.

## Dừng hệ thống

```powershell
docker compose down
```

Lệnh trên giữ dữ liệu trong volumes. Chỉ dùng `docker compose down -v` khi chủ ý xóa dữ liệu monitoring, log và database.

## Các mốc commit theo đề

- Commit 1 — Nginx reverse proxy và security headers.
- Commit 2 — Giao diện/quản lý điểm danh và Prometheus + Grafana.
- Commit 3 — Loki + Promtail và tính năng đổi mật khẩu.
