START TRANSACTION;

INSERT INTO classes (class_code, class_name, teacher_id)
SELECT 'DEMO-WEB', 'Lập trình Web - Demo', teacher.id
FROM teachers AS teacher
WHERE teacher.id = (SELECT MIN(id) FROM teachers)
  AND NOT EXISTS (
      SELECT 1 FROM classes WHERE class_code = 'DEMO-WEB'
  );

INSERT INTO classes (class_code, class_name, teacher_id)
SELECT 'DEMO-DEVOPS', 'Triển khai hệ thống - Demo', teacher.id
FROM teachers AS teacher
WHERE teacher.id = (SELECT MIN(id) FROM teachers)
  AND NOT EXISTS (
      SELECT 1 FROM classes WHERE class_code = 'DEMO-DEVOPS'
  );

INSERT INTO students (student_code, full_name, email)
SELECT 'DEMO23001', 'Nguyễn Minh Khang', 'demo23001@example.invalid'
WHERE NOT EXISTS (
    SELECT 1 FROM students WHERE student_code = 'DEMO23001'
);

INSERT INTO students (student_code, full_name, email)
SELECT 'DEMO23002', 'Trần Gia Hân', 'demo23002@example.invalid'
WHERE NOT EXISTS (
    SELECT 1 FROM students WHERE student_code = 'DEMO23002'
);

INSERT INTO students (student_code, full_name, email)
SELECT 'DEMO23003', 'Lê Hoàng Nam', 'demo23003@example.invalid'
WHERE NOT EXISTS (
    SELECT 1 FROM students WHERE student_code = 'DEMO23003'
);

INSERT INTO students (student_code, full_name, email)
SELECT 'DEMO23004', 'Phạm Khánh Linh', 'demo23004@example.invalid'
WHERE NOT EXISTS (
    SELECT 1 FROM students WHERE student_code = 'DEMO23004'
);

INSERT INTO students (student_code, full_name, email)
SELECT 'DEMO23005', 'Võ Nhật Minh', 'demo23005@example.invalid'
WHERE NOT EXISTS (
    SELECT 1 FROM students WHERE student_code = 'DEMO23005'
);

INSERT INTO students (student_code, full_name, email)
SELECT 'DEMO23006', 'Đặng Bảo Ngọc', 'demo23006@example.invalid'
WHERE NOT EXISTS (
    SELECT 1 FROM students WHERE student_code = 'DEMO23006'
);

INSERT INTO class_students (class_id, student_id)
SELECT classes.id, students.id
FROM classes
INNER JOIN students
    ON students.student_code IN ('DEMO23001', 'DEMO23002', 'DEMO23003')
WHERE classes.class_code = 'DEMO-WEB'
  AND NOT EXISTS (
      SELECT 1
      FROM class_students AS enrollment
      WHERE enrollment.class_id = classes.id
        AND enrollment.student_id = students.id
  );

INSERT INTO class_students (class_id, student_id)
SELECT classes.id, students.id
FROM classes
INNER JOIN students
    ON students.student_code IN ('DEMO23004', 'DEMO23005', 'DEMO23006')
WHERE classes.class_code = 'DEMO-DEVOPS'
  AND NOT EXISTS (
      SELECT 1
      FROM class_students AS enrollment
      WHERE enrollment.class_id = classes.id
        AND enrollment.student_id = students.id
  );

COMMIT;
