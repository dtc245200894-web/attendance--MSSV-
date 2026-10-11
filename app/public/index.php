<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require_once dirname(__DIR__) . '/src/Database.php';

$pdo = createDatabaseConnection();
$error = '';
$username = '';

if (!isset($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['login_csrf'], $csrfToken)
    ) {
        http_response_code(400);
        $error = 'Phiên không hợp lệ. Tải lại trang rồi thử lại.';
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: /');
        exit;
    } else {
        $submittedUsername = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $username = is_string($submittedUsername)
            ? trim($submittedUsername)
            : '';

        if (!is_string($password)) {
            $password = '';
        }

        $statement = $pdo->prepare(
            'SELECT id, username, full_name, password_hash
             FROM teachers
             WHERE username = :username
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $teacher = $statement->fetch();

        if ($teacher && password_verify($password, $teacher['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['teacher_id'] = (int) $teacher['id'];
            $_SESSION['teacher_name'] = $teacher['full_name'];
            $_SESSION['teacher_username'] = $teacher['username'];
            $_SESSION['login_csrf'] = bin2hex(random_bytes(32));

            header('Location: /');
            exit;
        }

        $error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
    }
}

$isLoggedIn = isset($_SESSION['teacher_id']);
$dashboardStats = null;
$recentSessions = [];

if ($isLoggedIn) {
    $teacherId = (int) $_SESSION['teacher_id'];
    $statsStatement = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM classes WHERE teacher_id = :classes_teacher_id) AS class_count,
            (SELECT COUNT(DISTINCT cs.student_id)
             FROM class_students cs
             INNER JOIN classes c ON c.id = cs.class_id
             WHERE c.teacher_id = :students_teacher_id) AS student_count,
            (SELECT COUNT(*)
             FROM class_sessions cs
             INNER JOIN classes c ON c.id = cs.class_id
             WHERE c.teacher_id = :sessions_teacher_id) AS session_count'
    );
    $statsStatement->execute([
        'classes_teacher_id' => $teacherId,
        'students_teacher_id' => $teacherId,
        'sessions_teacher_id' => $teacherId,
    ]);
    $dashboardStats = $statsStatement->fetch();

    $recentStatement = $pdo->prepare(
        'SELECT cs.id, cs.session_at, c.class_code, c.class_name,
                (SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.id) AS marked_count
         FROM class_sessions cs
         INNER JOIN classes c ON c.id = cs.class_id
         WHERE c.teacher_id = :teacher_id
         ORDER BY cs.session_at DESC
         LIMIT 5'
    );
    $recentStatement->execute(['teacher_id' => $teacherId]);
    $recentSessions = $recentStatement->fetchAll();
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Đăng nhập - Hệ thống điểm danh</title>
</head>
<body class="<?= $isLoggedIn ? 'app-shell' : 'auth-page' ?>">
    <main>
        <?php if ($isLoggedIn): ?>
            <header class="page-topbar">
                <div>
                    <span class="eyebrow">KHÔNG GIAN GIẢNG VIÊN</span>
                    <p class="topbar-caption">Quản lý điểm danh trong một nơi.</p>
                </div>
                <div class="topbar-account">
                    <div class="topbar-user">
                        <strong><?= htmlspecialchars((string) $_SESSION['teacher_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <span>Giảng viên</span>
                    </div>
                    <form method="post" action="/" class="logout-form">
                        <input type="hidden" name="action" value="logout">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        >
                        <button type="submit">Đăng xuất</button>
                    </form>
                </div>
            </header>
        <?php endif; ?>
        <h1>Hệ thống điểm danh sinh viên</h1>

        <?php if ($isLoggedIn): ?>
            <nav aria-label="Điều hướng chính">
                <a href="/" aria-current="page">Trang chính</a>
                <a href="/classes.php">Quản lý lớp</a>
                <a href="/students.php">Quản lý sinh viên</a>
                <a href="/attendance.php">Buổi học / Điểm danh</a>
                <a href="/change-password.php">Đổi mật khẩu</a>
                <div class="sidebar-account">
                    <span class="sidebar-avatar">GV</span>
                    <span class="sidebar-user">
                        <strong><?= htmlspecialchars((string) $_SESSION['teacher_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <small>Giảng viên</small>
                    </span>
                </div>
            </nav>

            <section class="dashboard-intro">
                <div>
                    <p class="eyebrow">TỔNG QUAN</p>
                    <h2>Chào mừng trở lại!</h2>
                    <p class="muted">Theo dõi lớp học, sinh viên và các buổi điểm danh của bạn.</p>
                </div>
                <a class="primary-link" href="/attendance.php">+ Tạo buổi điểm danh</a>
            </section>

            <section class="stats-grid" aria-label="Thống kê nhanh">
                <article class="stat-card">
                    <span class="stat-icon icon-navy">L</span>
                    <div><p>Tổng số lớp</p><strong><?= (int) $dashboardStats['class_count'] ?></strong></div>
                    <a href="/classes.php" aria-label="Mở quản lý lớp">›</a>
                </article>
                <article class="stat-card">
                    <span class="stat-icon icon-teal">SV</span>
                    <div><p>Sinh viên</p><strong><?= (int) $dashboardStats['student_count'] ?></strong></div>
                    <a href="/students.php" aria-label="Mở quản lý sinh viên">›</a>
                </article>
                <article class="stat-card">
                    <span class="stat-icon icon-blue">B</span>
                    <div><p>Buổi học</p><strong><?= (int) $dashboardStats['session_count'] ?></strong></div>
                    <a href="/attendance.php" aria-label="Mở buổi học">›</a>
                </article>
            </section>

            <section class="dashboard-lower">
                <div class="dashboard-panel recent-panel">
                    <div class="section-heading">
                        <div><p class="eyebrow">HOẠT ĐỘNG</p><h2>Buổi học gần đây</h2></div>
                        <a href="/attendance.php">Xem tất cả</a>
                    </div>
                    <?php if ($recentSessions === []): ?>
                        <div class="empty-state">
                            <span class="empty-icon">✦</span>
                            <strong>Chưa có buổi học nào</strong>
                            <p>Tạo buổi học đầu tiên để bắt đầu điểm danh.</p>
                            <a href="/attendance.php">Tạo buổi học</a>
                        </div>
                    <?php else: ?>
                        <div class="recent-list">
                            <?php foreach ($recentSessions as $session): ?>
                                <a class="recent-item" href="/attendance.php?session_id=<?= (int) $session['id'] ?>">
                                    <span class="recent-date"><?= htmlspecialchars(date('d/m', strtotime($session['session_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="recent-details">
                                        <strong><?= htmlspecialchars($session['class_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                        <small><?= htmlspecialchars($session['class_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars(date('H:i', strtotime($session['session_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                                    </span>
                                    <span class="recent-count"><?= (int) $session['marked_count'] ?> đã điểm danh</span>
                                    <span class="recent-arrow">›</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <aside class="dashboard-panel quick-panel">
                    <p class="eyebrow">TRUY CẬP NHANH</p>
                    <h2>Bắt đầu công việc</h2>
                    <a class="quick-action" href="/classes.php"><span class="quick-action-icon">＋</span><span><strong>Thêm lớp học</strong><small>Tổ chức danh sách lớp</small></span><b>›</b></a>
                    <a class="quick-action" href="/students.php"><span class="quick-action-icon">＋</span><span><strong>Thêm sinh viên</strong><small>Cập nhật danh sách lớp</small></span><b>›</b></a>
                    <a class="quick-action" href="/attendance.php"><span class="quick-action-icon">✓</span><span><strong>Điểm danh</strong><small>Tạo buổi học và ghi nhận</small></span><b>›</b></a>
                </aside>
            </section>
        <?php else: ?>
            <?php if ($error !== ''): ?>
                <p role="alert">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) ?>
                </p>
            <?php endif; ?>

            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION['login_csrf'],
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) ?>"
                >

                <label>
                    Tên đăng nhập
                    <input
                        name="username"
                        required
                        autocomplete="username"
                        value="<?= htmlspecialchars(
                            $username,
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8'
                        ) ?>"
                    >
                </label>

                <label>
                    Mật khẩu
                    <input
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                    >
                </label>

                <button type="submit">Đăng nhập</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>