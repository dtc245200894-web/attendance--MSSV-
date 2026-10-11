<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require_once dirname(__DIR__) . '/src/Database.php';

$pdo = createDatabaseConnection();
$teacherCount = (int) $pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
$errors = [];
$created = false;

if (!isset($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($teacherCount > 0) {
        http_response_code(403);
        $errors[] = 'Tài khoản giảng viên đã được tạo; không thể chạy thiết lập lần nữa.';
    } elseif (
        !isset($_POST['csrf_token']) ||
        !is_string($_POST['csrf_token']) ||
        !hash_equals($_SESSION['setup_csrf'], $_POST['csrf_token'])
    ) {
        http_response_code(400);
        $errors[] = 'Phiên thiết lập không hợp lệ. Hãy tải lại trang và thử lại.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $errors[] = 'Tên đăng nhập cần dài 3–50 ký tự, chỉ gồm chữ, số, dấu chấm, gạch ngang hoặc gạch dưới.';
        }

    $fullNameLength = preg_match_all('/./us', $fullName);

    if (
        $fullName === '' ||
        $fullNameLength === false ||
        $fullNameLength > 100
    ) {
        $errors[] = 'Họ tên không được để trống và tối đa 100 ký tự.';
    }

        if (!is_string($password) || strlen($password) < 12) {
            $errors[] = 'Mật khẩu cần có ít nhất 12 ký tự.';
        }

        if ($errors === []) {
            $statement = $pdo->prepare(
                'INSERT INTO teachers (username, password_hash, full_name)
                 VALUES (:username, :password_hash, :full_name)'
            );
            $statement->execute([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name' => $fullName,
            ]);

            $created = true;
            $teacherCount = 1;
            $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
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
    <title>Tạo tài khoản giảng viên</title>
</head>
<body class="auth-page">
    <main>
        <h1>Tạo tài khoản giảng viên</h1>

        <?php if ($created): ?>
            <p role="status">Đã tạo tài khoản. Bước tiếp theo sẽ là làm trang đăng nhập.</p>
        <?php elseif ($teacherCount > 0): ?>
            <p>Tài khoản giảng viên đã được tạo. Trang thiết lập đã khóa.</p>
        <?php else: ?>
            <?php foreach ($errors as $error): ?>
                <p role="alert"><?= escapeHtml($error) ?></p>
            <?php endforeach; ?>

            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapeHtml($_SESSION['setup_csrf']) ?>"
                >

                <label>
                    Tên đăng nhập
                    <input name="username" required minlength="3" maxlength="50">
                </label>

                <label>
                    Họ tên
                    <input name="full_name" required maxlength="100">
                </label>

                <label>
                    Mật khẩu (ít nhất 12 ký tự)
                    <input name="password" type="password" required minlength="12">
                </label>

                <button type="submit">Tạo tài khoản</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>