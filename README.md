# Hệ thống điểm danh sinh viên

Đồ án thực hành môn Triển khai và Quản trị Hệ thống Phần mềm.
Stack hiện tại: PHP, MySQL, phpMyAdmin, Apache và Nginx.

## Yêu cầu

- Docker Desktop đang chạy
- Docker Compose

## Cấu hình môi trường

Tạo file `.env` từ file mẫu:

```powershell
Copy-Item .env.example .env
```

Mở `.env`, thay `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD` và `GRAFANA_ADMIN_PASSWORD` bằng các mật khẩu mạnh, riêng biệt. Không commit hoặc chia sẻ file `.env`.

## Khởi chạy

Từ thư mục chứa `docker-compose.yml`:

```powershell
docker compose up -d --build
```

Kiểm tra container:

```powershell
docker compose ps
```

## Truy cập

- Ứng dụng qua Nginx reverse proxy: http://localhost:8080
- phpMyAdmin: http://localhost:8081
- Grafana: http://localhost:3000 (tài khoản `admin`, mật khẩu `GRAFANA_ADMIN_PASSWORD`)
- Prometheus: http://localhost:9090
- Database: `attendance_db`

Đăng nhập phpMyAdmin bằng username `attendance_app` và mật khẩu đặt trong `.env`.

## Database

Lược đồ ban đầu nằm tại `database/schema.sql`. MySQL tự chạy file này khi khởi tạo volume database lần đầu.

### Nạp dữ liệu demo

Sau khi đã khởi chạy hệ thống và tạo tài khoản giảng viên, có thể nạp hai lớp và sáu sinh viên mẫu:

```powershell
docker compose cp .\database\demo-data.sql mysql:/tmp/attendance-demo-data.sql
docker compose exec mysql sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < /tmp/attendance-demo-data.sql'
docker compose exec mysql sh -c 'rm /tmp/attendance-demo-data.sql'
```

Dữ liệu dùng mã `DEMO-*` và email thuộc miền `.invalid`; script không xóa hoặc sửa các lớp, sinh viên hiện có và có thể chạy lại an toàn.

## Dừng hệ thống

```powershell
docker compose down
```

Dữ liệu MySQL được lưu trong Docker volume `mysql_data`, nên lệnh trên không xóa dữ liệu.

## Giám sát bằng Prometheus và Grafana

Prometheus thu metrics từ cAdvisor (container), Nginx Exporter (web server) và MySQL Exporter (database). Grafana được cấu hình sẵn datasource Prometheus và dashboard `Attendance system overview`.

- Grafana: http://localhost:3000 (`admin` / mật khẩu `GRAFANA_ADMIN_PASSWORD` trong `.env`)
- Prometheus: http://localhost:9090
- Prometheus targets: http://localhost:9090/targets

Các cổng monitoring chỉ bind vào loopback của máy host.

## Trạng thái hiện tại

Ứng dụng có đăng nhập giảng viên, quản lý lớp và sinh viên, tạo buổi học, ghi nhận điểm danh và dữ liệu demo; chạy cùng MySQL, phpMyAdmin, Nginx reverse proxy và cấu hình monitoring Prometheus/Grafana. Loki/Promtail và hardening còn lại sẽ được bổ sung tiếp.