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

Mở `.env`, thay `MYSQL_ROOT_PASSWORD` và `MYSQL_PASSWORD` bằng hai mật khẩu mạnh, riêng biệt. Không commit hoặc chia sẻ file `.env`.

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
- Database: `attendance_db`

Đăng nhập phpMyAdmin bằng username `attendance_app` và mật khẩu đặt trong `.env`.

## Database

Lược đồ ban đầu nằm tại `database/schema.sql`. MySQL tự chạy file này khi khởi tạo volume database lần đầu.

## Dừng hệ thống

```powershell
docker compose down
```

Lệnh này chỉ là hướng dẫn ghi trong README; **không chạy nó lúc này**. Dữ liệu MySQL được lưu trong Docker volume `mysql_data`, nên lệnh trên không xóa dữ liệu.

## Trạng thái hiện tại

Đã dựng nền tảng PHP + MySQL, phpMyAdmin và Nginx reverse proxy; trang ứng dụng kiểm tra kết nối database. Các chức năng điểm danh và các phần Prometheus/Grafana, Loki/Promtail sẽ được bổ sung trong các bước tiếp theo.