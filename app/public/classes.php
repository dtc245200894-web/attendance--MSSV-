<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require_once dirname(__DIR__) . '/src/Database.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: /');
    exit;
}

$pdo = createDatabaseConnection();
$teacherId = (int) $_SESSION['teacher_id'];
$error = '';
$classes = [];

if (!isset($_SESSION['app_csrf'])) {
    $_SESSION['app_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $classCode = $_POST['class_code'] ?? '';
    $className = $_POST['class_name'] ?? '';

    if (!is_string($csrfToken) || !hash_equals($_SESSION['app_csrf'], $csrfToken)) {
        http_response_code(400);
        $error = 'Phiên không hợp lệ. Hãy tải lại trang rồi thử lại.';
    } elseif (!is_string($classCode) || !is_string($className)) {
        http_response_code(400);
        $error = 'Thông tin lớp không hợp lệ.';
    } else {
        $classCode = trim($classCode);
        $className = trim($className);

        if (!preg_match('/^[A-Za-z0-9_-]{1,30}$/', $classCode)) {
            $error = 'Mã lớp chỉ được gồm chữ, số, dấu gạch ngang hoặc gạch dưới; tối đa 30 ký tự.';
        } elseif ($className === '' || preg_match_all('/./us', $className) > 100) {
            $error = 'Tên lớp không được để trống và tối đa 100 ký tự.';
        } else {
            try {
                $statement = $pdo->prepare(
                    'INSERT INTO classes (class_code, class_name, teacher_id)
                     VALUES (:class_code, :class_name, :teacher_id)'
                );
                $statement->execute([
                    'class_code' => $classCode,
                    'class_name' => $className,
                    'teacher_id' => $teacherId,
                ]);

                header('Location: /classes.php');
                exit;
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'Mã lớp này đã tồn tại. Hãy chọn mã khác.';
                } else {
                    error_log('Could not create class: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'Không tạo được lớp do lỗi hệ thống. Hãy kiểm tra log ứng dụng.';
                }
            }
        }
    }
}

$statement = $pdo->prepare(
    'SELECT id, class_code, class_name, created_at
     FROM classes
     WHERE teacher_id = :teacher_id
     ORDER BY class_name'
);
$statement->execute(['teacher_id' => $teacherId]);
$classes = $statement->fetchAll();

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Quản lý lớp</title>
</head>
<body class="app-shell">
    <main>
        <header class="page-topbar">
            <div>
                <span class="eyebrow">QUẢN LÝ ĐÀO TẠO</span>
                <p class="topbar-caption">Không gian làm việc của giảng viên.</p>
            </div>
            <div class="topbar-account">
                <div class="topbar-user">
                    <strong><?= escapeHtml((string) $_SESSION['teacher_name']) ?></strong>
                    <span>Giảng viên</span>
                </div>
                <form method="post" action="/" class="logout-form">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['login_csrf']) ?>">
                    <button type="submit">Đăng xuất</button>
                </form>
            </div>
        </header>
        <h1>Quản lý lớp</h1>
        <nav aria-label="Điều hướng chính">
            <a href="/">Trang chính</a>
            <a href="/classes.php" aria-current="page">Quản lý lớp</a>
            <a href="/students.php">Quản lý sinh viên</a>
            <a href="/attendance.php">Buổi học / Điểm danh</a>
            <a href="/change-password.php">Đổi mật khẩu</a>
            <div class="sidebar-account">
                <span class="sidebar-avatar">GV</span>
                <span class="sidebar-user">
                    <strong><?= escapeHtml((string) $_SESSION['teacher_name']) ?></strong>
                    <small>Giảng viên</small>
                </span>
            </div>
        </nav>

        <?php if ($error !== ''): ?>
            <p role="alert"><?= escapeHtml($error) ?></p>
        <?php endif; ?>

        <h2>Tạo lớp</h2>
        <form method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeHtml($_SESSION['app_csrf']) ?>"
            >

            <label>
                Mã lớp
                <input name="class_code" required maxlength="30">
            </label>

            <label>
                Tên lớp
                <input name="class_name" required maxlength="100">
            </label>

            <button type="submit">Tạo lớp</button>
        </form>

        <h2>Danh sách lớp</h2>
        <?php if ($classes === []): ?>
            <p>Chưa có lớp nào.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($classes as $class): ?>
                    <li>
                        <?= escapeHtml($class['class_code']) ?> —
                        <?= escapeHtml($class['class_name']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
</body>
</html>