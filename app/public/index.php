<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';

$databaseConnected = false;

try {
    $pdo = createDatabaseConnection();
    $pdo->query('SELECT 1')->fetchColumn();
    $databaseConnected = true;
} catch (RuntimeException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hệ thống điểm danh sinh viên</title>
</head>
<body>
    <main>
        <h1>Hệ thống điểm danh sinh viên</h1>
        <p>Đồ án thực hành môn Triển khai và Quản trị Hệ thống Phần mềm.</p>

        <?php if ($databaseConnected): ?>
            <p role="status">Kết nối cơ sở dữ liệu: thành công.</p>
        <?php else: ?>
            <p role="alert">Chưa kết nối được cơ sở dữ liệu. Vui lòng kiểm tra log ứng dụng.</p>
        <?php endif; ?>
    </main>
</body>
</html>