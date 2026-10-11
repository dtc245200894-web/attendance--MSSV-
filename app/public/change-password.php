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
$message = $_SESSION['password_change_message'] ?? '';
unset($_SESSION['password_change_message']);

if (!isset($_SESSION['password_change_csrf'])) {
    $_SESSION['password_change_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['password_change_csrf'], $csrfToken)
    ) {
        http_response_code(400);
        $error = 'Phiên không hợp lệ. Hãy tải lại trang rồi thử lại.';
    } elseif (
        !is_string($currentPassword) ||
        !is_string($newPassword) ||
        !is_string($confirmPassword)
    ) {
        http_response_code(400);
        $error = 'Thông tin mật khẩu không hợp lệ.';
    } else {
        $statement = $pdo->prepare(
            'SELECT password_hash FROM teachers WHERE id = :teacher_id LIMIT 1'
        );
        $statement->execute(['teacher_id' => $teacherId]);
        $passwordHash = $statement->fetchColumn();

        if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
            $error = 'Mật khẩu hiện tại không đúng.';
        } elseif (strlen($newPassword) < 12) {
            $error = 'Mật khẩu mới cần có ít nhất 12 ký tự.';
        } elseif (!hash_equals($newPassword, $confirmPassword)) {
            $error = 'Mật khẩu mới và phần xác nhận không khớp.';
        } elseif (password_verify($newPassword, $passwordHash)) {
            $error = 'Mật khẩu mới phải khác mật khẩu hiện tại.';
        } else {
            $update = $pdo->prepare(
                'UPDATE teachers SET password_hash = :password_hash WHERE id = :teacher_id'
            );
            $update->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'teacher_id' => $teacherId,
            ]);

            session_regenerate_id(true);
            $_SESSION['password_change_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['password_change_message'] = 'Đổi mật khẩu thành công.';
            header('Location: /change-password.php');
            exit;
        }
    }
}

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
    <title>Đổi mật khẩu</title>
</head>
<body class="app-shell">
    <main>
        <header class="page-topbar">
            <div>
                <span class="eyebrow">TÀI KHOẢN</span>
                <p class="topbar-caption">Cập nhật thông tin bảo mật của bạn.</p>
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
        <h1>Đổi mật khẩu</h1>
        <nav aria-label="Điều hướng chính">
            <a href="/">Trang chính</a>
            <a href="/classes.php">Quản lý lớp</a>
            <a href="/students.php">Quản lý sinh viên</a>
            <a href="/attendance.php">Buổi học / Điểm danh</a>
            <a href="/change-password.php" aria-current="page">Đổi mật khẩu</a>
            <div class="sidebar-account">
                <span class="sidebar-avatar">GV</span>
                <span class="sidebar-user">
                    <strong><?= escapeHtml((string) $_SESSION['teacher_name']) ?></strong>
                    <small>Giảng viên</small>
                </span>
            </div>
        </nav>

        <?php if ($message !== ''): ?>
            <p role="status"><?= escapeHtml((string) $message) ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p role="alert"><?= escapeHtml($error) ?></p>
        <?php endif; ?>

        <section class="dashboard-panel">
            <h2>Thay đổi mật khẩu tài khoản</h2>
            <p class="muted">Mật khẩu mới cần có ít nhất 12 ký tự.</p>
            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapeHtml($_SESSION['password_change_csrf']) ?>"
                >
                <label>
                    Mật khẩu hiện tại
                    <input
                        type="password"
                        name="current_password"
                        required
                        autocomplete="current-password"
                    >
                </label>
                <label>
                    Mật khẩu mới
                    <input
                        type="password"
                        name="new_password"
                        required
                        minlength="12"
                        autocomplete="new-password"
                    >
                </label>
                <label>
                    Xác nhận mật khẩu mới
                    <input
                        type="password"
                        name="confirm_password"
                        required
                        minlength="12"
                        autocomplete="new-password"
                    >
                </label>
                <button type="submit">Lưu mật khẩu mới</button>
            </form>
        </section>
    </main>
</body>
</html>
