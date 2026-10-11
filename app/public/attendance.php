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
$message = '';

if (!isset($_SESSION['attendance_csrf'])) {
    $_SESSION['attendance_csrf'] = bin2hex(random_bytes(32));
}

$classStatement = $pdo->prepare(
    'SELECT id, class_code, class_name
     FROM classes
     WHERE teacher_id = :teacher_id
     ORDER BY class_name'
);
$classStatement->execute(['teacher_id' => $teacherId]);
$classes = $classStatement->fetchAll();

$selectedSessionId = filter_var(
    $_GET['session_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedSessionId = filter_var(
        $_POST['session_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($postedSessionId !== false) {
        $selectedSessionId = $postedSessionId;
    }

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['attendance_csrf'], $csrfToken)
    ) {
        http_response_code(400);
        $error = 'Phiên không hợp lệ. Hãy tải lại trang rồi thử lại.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_session') {
            $classId = filter_var(
                $_POST['class_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $dateInput = $_POST['session_at'] ?? '';
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($classId === false || !is_string($dateInput)) {
                $error = 'Thông tin lớp hoặc thời gian không hợp lệ.';
            } else {
                $checkClass = $pdo->prepare(
                    'SELECT id FROM classes
                     WHERE id = :class_id AND teacher_id = :teacher_id'
                );
                $checkClass->execute([
                    'class_id' => $classId,
                    'teacher_id' => $teacherId,
                ]);

                $date = DateTimeImmutable::createFromFormat(
                    '!Y-m-d\TH:i',
                    $dateInput
                );
                $dateErrors = DateTimeImmutable::getLastErrors();
                $validDate = $date !== false
                    && $date->format('Y-m-d\TH:i') === $dateInput
                    && ($dateErrors === false
                        || ($dateErrors['warning_count'] === 0
                            && $dateErrors['error_count'] === 0));
                $notesLength = preg_match_all('/./us', $notes);

                if ($checkClass->fetchColumn() === false) {
                    $error = 'Không tìm thấy lớp bạn quản lý.';
                } elseif (!$validDate) {
                    $error = 'Ngày giờ buổi học không hợp lệ.';
                } elseif ($notesLength === false || $notesLength > 500) {
                    $error = 'Ghi chú tối đa 500 ký tự.';
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO class_sessions (class_id, session_at, notes)
                         VALUES (:class_id, :session_at, :notes)'
                    );
                    $insert->execute([
                        'class_id' => $classId,
                        'session_at' => $date->format('Y-m-d H:i:s'),
                        'notes' => $notes === '' ? null : $notes,
                    ]);

                    header(
                        'Location: /attendance.php?session_id=' .
                        (int) $pdo->lastInsertId()
                    );
                    exit;
                }
            }
        } elseif ($action === 'save_attendance') {
            if ($selectedSessionId === false || $selectedSessionId === null) {
                $error = 'Không xác định được buổi học.';
            } else {
                $sessionCheck = $pdo->prepare(
                    'SELECT cs.id, cs.class_id
                     FROM class_sessions cs
                     INNER JOIN classes c ON c.id = cs.class_id
                     WHERE cs.id = :session_id AND c.teacher_id = :teacher_id'
                );
                $sessionCheck->execute([
                    'session_id' => $selectedSessionId,
                    'teacher_id' => $teacherId,
                ]);
                $session = $sessionCheck->fetch();

                if (!$session) {
                    http_response_code(404);
                    $error = 'Không tìm thấy buổi học.';
                } else {
                    $rosterStatement = $pdo->prepare(
                        'SELECT s.id
                         FROM students s
                         INNER JOIN class_students cs ON cs.student_id = s.id
                         WHERE cs.class_id = :class_id
                         ORDER BY s.full_name'
                    );
                    $rosterStatement->execute([
                        'class_id' => $session['class_id'],
                    ]);
                    $roster = $rosterStatement->fetchAll();

                    $submitted = $_POST['attendance'] ?? null;
                    $validAttendance = is_array($submitted) && $roster !== [];

                    foreach ($roster as $student) {
                        $studentId = (string) $student['id'];
                        if (
                            !$validAttendance ||
                            !isset($submitted[$studentId]) ||
                            !in_array(
                                $submitted[$studentId],
                                ['present', 'absent'],
                                true
                            )
                        ) {
                            $validAttendance = false;
                            break;
                        }
                    }

                    if (!$validAttendance) {
                        $error = $roster === []
                            ? 'Lớp chưa có sinh viên để điểm danh.'
                            : 'Hãy chọn Có mặt hoặc Vắng cho từng sinh viên.';
                    } else {
                        try {
                            $pdo->beginTransaction();
                            $save = $pdo->prepare(
                                'INSERT INTO attendance
                                    (session_id, class_id, student_id, status)
                                 VALUES
                                    (:session_id, :class_id, :student_id, :status)
                                 ON DUPLICATE KEY UPDATE
                                    status = VALUES(status),
                                    marked_at = CURRENT_TIMESTAMP'
                            );

                            foreach ($roster as $student) {
                                $studentId = (string) $student['id'];
                                $save->execute([
                                    'session_id' => $session['id'],
                                    'class_id' => $session['class_id'],
                                    'student_id' => $studentId,
                                    'status' => $submitted[$studentId],
                                ]);
                            }

                            $pdo->commit();
                            $message = 'Đã lưu điểm danh cho buổi học.';
                        } catch (PDOException $exception) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            error_log(
                                'Could not save attendance: ' .
                                $exception->getMessage()
                            );
                            http_response_code(500);
                            $error = 'Không lưu được điểm danh do lỗi hệ thống.';
                        }
                    }
                }
            }
        } else {
            http_response_code(400);
            $error = 'Thao tác không hợp lệ.';
        }
    }
}

$selectedSession = false;
$roster = [];
$savedAttendance = [];

if ($selectedSessionId !== false && $selectedSessionId !== null) {
    $sessionStatement = $pdo->prepare(
        'SELECT cs.id, cs.class_id, cs.session_at, cs.notes,
                c.class_code, c.class_name
         FROM class_sessions cs
         INNER JOIN classes c ON c.id = cs.class_id
         WHERE cs.id = :session_id AND c.teacher_id = :teacher_id'
    );
    $sessionStatement->execute([
        'session_id' => $selectedSessionId,
        'teacher_id' => $teacherId,
    ]);
    $selectedSession = $sessionStatement->fetch();

    if ($selectedSession) {
        $rosterStatement = $pdo->prepare(
            'SELECT s.id, s.student_code, s.full_name
             FROM students s
             INNER JOIN class_students cs ON cs.student_id = s.id
             WHERE cs.class_id = :class_id
             ORDER BY s.full_name'
        );
        $rosterStatement->execute([
            'class_id' => $selectedSession['class_id'],
        ]);
        $roster = $rosterStatement->fetchAll();

        $savedStatement = $pdo->prepare(
            'SELECT student_id, status
             FROM attendance
             WHERE session_id = :session_id'
        );
        $savedStatement->execute(['session_id' => $selectedSessionId]);

        foreach ($savedStatement->fetchAll() as $record) {
            $savedAttendance[(string) $record['student_id']] = $record['status'];
        }
    } elseif ($error === '') {
        http_response_code(404);
        $error = 'Không tìm thấy buổi học.';
    }
}

$historyStatement = $pdo->prepare(
    'SELECT cs.id, cs.session_at, c.class_code, c.class_name,
            COUNT(a.student_id) AS marked_count
     FROM class_sessions cs
     INNER JOIN classes c ON c.id = cs.class_id
     LEFT JOIN attendance a ON a.session_id = cs.id
     WHERE c.teacher_id = :teacher_id
     GROUP BY cs.id, cs.session_at, c.class_code, c.class_name
     ORDER BY cs.session_at DESC'
);
$historyStatement->execute(['teacher_id' => $teacherId]);
$sessions = $historyStatement->fetchAll();

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
    <title>Điểm danh</title>
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
        <h1>Buổi học và điểm danh</h1>
        <nav aria-label="Điều hướng chính">
            <a href="/">Trang chính</a>
            <a href="/classes.php">Quản lý lớp</a>
            <a href="/students.php">Quản lý sinh viên</a>
            <a href="/attendance.php" aria-current="page">Buổi học / Điểm danh</a>
            <a href="/change-password.php">Đổi mật khẩu</a>
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

        <h2>Tạo buổi học</h2>
        <?php if ($classes === []): ?>
            <p>Chưa có lớp. Hãy tạo lớp trước.</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="create_session">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['attendance_csrf']) ?>">

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

                <label>
                    Ngày giờ
                    <input type="datetime-local" name="session_at" required>
                </label>

                <label>
                    Ghi chú
                    <input name="notes" maxlength="500">
                </label>

                <button type="submit">Tạo buổi học</button>
            </form>
        <?php endif; ?>

        <h2>Danh sách buổi học</h2>
        <?php if ($sessions === []): ?>
            <p>Chưa có buổi học nào.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($sessions as $session): ?>
                    <li>
                        <?= escapeHtml(
                            $session['class_code'] . ' — ' .
                            $session['class_name'] . ' — ' .
                            $session['session_at']
                        ) ?>
                        (đã điểm danh <?= (int) $session['marked_count'] ?> sinh viên)
                        <a href="/attendance.php?session_id=<?= (int) $session['id'] ?>">
                            Mở điểm danh
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($selectedSession): ?>
            <h2>
                Điểm danh:
                <?= escapeHtml(
                    $selectedSession['class_code'] . ' — ' .
                    $selectedSession['class_name'] . ' — ' .
                    $selectedSession['session_at']
                ) ?>
            </h2>

            <?php if ($roster === []): ?>
                <p>Lớp này chưa có sinh viên.</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="save_attendance">
                    <input type="hidden" name="session_id" value="<?= (int) $selectedSession['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['attendance_csrf']) ?>">

                    <table>
                        <thead>
                            <tr>
                                <th>Mã sinh viên</th>
                                <th>Họ tên</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roster as $student): ?>
                                <?php $studentId = (string) $student['id']; ?>
                                <tr>
                                    <td><?= escapeHtml($student['student_code']) ?></td>
                                    <td><?= escapeHtml($student['full_name']) ?></td>
                                    <td>
                                        <select name="attendance[<?= (int) $student['id'] ?>]" required>
                                            <option value="present" <?= ($savedAttendance[$studentId] ?? '') === 'present' ? 'selected' : '' ?>>
                                                Có mặt
                                            </option>
                                            <option value="absent" <?= ($savedAttendance[$studentId] ?? '') === 'absent' ? 'selected' : '' ?>>
                                                Vắng
                                            </option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <button type="submit">Lưu điểm danh</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>