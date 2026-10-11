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
$message = '';
$error = '';
$selectedClassId = null;

if (isset($_GET['class_id']) && $_GET['class_id'] !== '') {
    $selectedClassId = filter_var(
        $_GET['class_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($selectedClassId === false) {
        http_response_code(400);
        $error = 'Bộ lọc lớp không hợp lệ.';
        $selectedClassId = null;
    }
}

if (!isset($_SESSION['students_csrf'])) {
    $_SESSION['students_csrf'] = bin2hex(random_bytes(32));
}

$classStatement = $pdo->prepare(
    'SELECT id, class_code, class_name
     FROM classes
     WHERE teacher_id = :teacher_id
     ORDER BY class_name'
);
$classStatement->execute(['teacher_id' => $teacherId]);
$classes = $classStatement->fetchAll();

if ($selectedClassId !== null) {
    $selectedClassExists = false;
    foreach ($classes as $class) {
        if ((int) $class['id'] === $selectedClassId) {
            $selectedClassExists = true;
            break;
        }
    }

    if (!$selectedClassExists) {
        http_response_code(404);
        $error = 'Không tìm thấy lớp bạn quản lý.';
        $selectedClassId = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['students_csrf'], $csrfToken)
    ) {
        http_response_code(400);
        $error = 'Phiên không hợp lệ. Hãy tải lại trang rồi thử lại.';
    } else {
        $action = $_POST['action'] ?? '';
        $classId = filter_var(
            $_POST['class_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $classExists = false;
        if ($classId !== false) {
            $checkClass = $pdo->prepare(
                'SELECT id FROM classes
                 WHERE id = :class_id AND teacher_id = :teacher_id'
            );
            $checkClass->execute([
                'class_id' => $classId,
                'teacher_id' => $teacherId,
            ]);
            $classExists = $checkClass->fetchColumn() !== false;
        }

        if (!$classExists) {
            $error = 'Hãy chọn một lớp hợp lệ.';
        } elseif ($action === 'create_student') {
            $studentCode = trim((string) ($_POST['student_code'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $nameLength = preg_match_all('/./us', $fullName);

            if (!preg_match('/^[A-Za-z0-9_-]{1,30}$/', $studentCode)) {
                $error = 'Mã sinh viên chỉ được gồm chữ, số, dấu gạch ngang hoặc gạch dưới; tối đa 30 ký tự.';
            } elseif (
                $fullName === '' ||
                $nameLength === false ||
                $nameLength > 100
            ) {
                $error = 'Họ tên không được để trống và tối đa 100 ký tự.';
            } elseif (
                $email !== '' &&
                filter_var($email, FILTER_VALIDATE_EMAIL) === false
            ) {
                $error = 'Địa chỉ email không hợp lệ.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $insertStudent = $pdo->prepare(
                        'INSERT INTO students (student_code, full_name, email)
                         VALUES (:student_code, :full_name, :email)'
                    );
                    $insertStudent->execute([
                        'student_code' => $studentCode,
                        'full_name' => $fullName,
                        'email' => $email === '' ? null : $email,
                    ]);

                    $studentId = (int) $pdo->lastInsertId();

                    $enroll = $pdo->prepare(
                        'INSERT INTO class_students (class_id, student_id)
                         VALUES (:class_id, :student_id)'
                    );
                    $enroll->execute([
                        'class_id' => $classId,
                        'student_id' => $studentId,
                    ]);

                    $pdo->commit();
                    $message = 'Đã thêm sinh viên vào lớp.';
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if ($exception->getCode() === '23000') {
                        $error = 'Mã sinh viên hoặc email đã tồn tại.';
                    } else {
                        error_log('Could not create student: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'Không thêm được sinh viên do lỗi hệ thống.';
                    }
                }
            }
        } elseif ($action === 'enroll_student') {
            $studentId = filter_var(
                $_POST['student_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($studentId === false) {
                $error = 'Hãy chọn một sinh viên hợp lệ.';
            } else {
                try {
                    $enroll = $pdo->prepare(
                        'INSERT INTO class_students (class_id, student_id)
                         VALUES (:class_id, :student_id)'
                    );
                    $enroll->execute([
                        'class_id' => $classId,
                        'student_id' => $studentId,
                    ]);
                    $message = 'Đã thêm sinh viên vào lớp.';
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23000') {
                        $error = 'Sinh viên đã thuộc lớp này.';
                    } else {
                        error_log('Could not enroll student: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'Không thêm được sinh viên vào lớp do lỗi hệ thống.';
                    }
                }
            }
        } else {
            http_response_code(400);
            $error = 'Thao tác không hợp lệ.';
        }
    }
}

$studentStatement = $pdo->prepare(
    'SELECT DISTINCT s.id, s.student_code, s.full_name
     FROM students s
     INNER JOIN class_students cs ON cs.student_id = s.id
     INNER JOIN classes c ON c.id = cs.class_id
     WHERE c.teacher_id = :teacher_id
     ORDER BY s.full_name'
);
$studentStatement->execute(['teacher_id' => $teacherId]);
$existingStudents = $studentStatement->fetchAll();

$rosterSql =
    'SELECT s.student_code, s.full_name, s.email, c.class_code, c.class_name
     FROM students s
     INNER JOIN class_students cs ON cs.student_id = s.id
     INNER JOIN classes c ON c.id = cs.class_id
     WHERE c.teacher_id = :teacher_id';
$rosterParams = ['teacher_id' => $teacherId];

if ($selectedClassId !== null) {
    $rosterSql .= ' AND c.id = :class_id';
    $rosterParams['class_id'] = $selectedClassId;
}

$rosterSql .= ' ORDER BY c.class_name, s.full_name';
$rosterStatement = $pdo->prepare($rosterSql);
$rosterStatement->execute($rosterParams);
$roster = $rosterStatement->fetchAll();

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
    <title>Quản lý sinh viên</title>
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
        <h1>Quản lý sinh viên</h1>
        <nav>
            <a href="/">Trang chính</a>
            <a href="/classes.php">Quản lý lớp</a>
            <a href="/students.php" aria-current="page">Quản lý sinh viên</a>
            <a href="/attendance.php">Buổi học / Điểm danh</a>
            <div class="sidebar-account">
                <span class="sidebar-avatar">GV</span>
                <span class="sidebar-user">
                    <strong><?= escapeHtml((string) $_SESSION['teacher_name']) ?></strong>
                    <small>Giảng viên</small>
                </span>
            </div>
        </nav>

        <?php if ($message !== ''): ?>
            <p role="status"><?= escapeHtml($message) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p role="alert"><?= escapeHtml($error) ?></p>
        <?php endif; ?>

        <?php if ($classes === []): ?>
            <p>Chưa có lớp. Hãy tạo lớp trước khi thêm sinh viên.</p>
        <?php else: ?>
            <h2>Thêm sinh viên mới</h2>
            <form method="post">
                <input type="hidden" name="action" value="create_student">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['students_csrf']) ?>">

                <label>
                    Mã sinh viên
                    <input name="student_code" required maxlength="30">
                </label>

                <label>
                    Họ tên
                    <input name="full_name" required maxlength="100">
                </label>

                <label>
                    Email (không bắt buộc)
                    <input name="email" type="email" maxlength="255">
                </label>

                <label>
                    Lớp
                    <select name="class_id" required>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int) $class['id'] ?>">
                                <?= escapeHtml($class['class_code'] . ' — ' . $class['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="submit">Thêm sinh viên</button>
            </form>

            <?php if ($existingStudents !== []): ?>
                <h2>Thêm sinh viên đã có vào lớp khác</h2>
                <form method="post">
                    <input type="hidden" name="action" value="enroll_student">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['students_csrf']) ?>">

                    <label>
                        Sinh viên
                        <select name="student_id" required>
                            <?php foreach ($existingStudents as $student): ?>
                                <option value="<?= (int) $student['id'] ?>">
                                    <?= escapeHtml($student['student_code'] . ' — ' . $student['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Lớp cần thêm
                        <select name="class_id" required>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>">
                                    <?= escapeHtml($class['class_code'] . ' — ' . $class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <button type="submit">Thêm vào lớp</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <h2>Danh sách sinh viên theo lớp</h2>
        <form method="get" class="roster-filter">
            <label for="roster-class-filter">Lọc theo lớp</label>
            <select id="roster-class-filter" name="class_id">
                <option value="">Tất cả lớp</option>
                <?php foreach ($classes as $class): ?>
                    <option
                        value="<?= (int) $class['id'] ?>"
                        <?= $selectedClassId === (int) $class['id'] ? 'selected' : '' ?>
                    >
                        <?= escapeHtml($class['class_code'] . ' — ' . $class['class_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Lọc danh sách</button>
            <?php if ($selectedClassId !== null): ?>
                <a class="clear-filter" href="/students.php">Xóa bộ lọc</a>
            <?php endif; ?>
        </form>

        <p class="roster-summary">
            <?= count($roster) ?> sinh viên
            <?= $selectedClassId !== null ? 'trong lớp đã chọn' : 'trong tất cả lớp' ?>.
        </p>

        <?php if ($roster === []): ?>
            <p>Chưa có sinh viên nào.</p>
        <?php else: ?>
            <div class="roster-scroll" role="region" aria-label="Danh sách sinh viên có thể cuộn">
                <table>
                    <thead>
                        <tr>
                            <th>Mã sinh viên</th>
                            <th>Họ tên</th>
                            <th>Email</th>
                            <th>Lớp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roster as $student): ?>
                            <tr>
                                <td><?= escapeHtml($student['student_code']) ?></td>
                                <td><?= escapeHtml($student['full_name']) ?></td>
                                <td><?= escapeHtml($student['email'] ?? '') ?></td>
                                <td><?= escapeHtml($student['class_code'] . ' — ' . $student['class_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>