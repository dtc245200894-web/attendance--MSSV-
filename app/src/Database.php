<?php
declare(strict_types=1);

function createDatabaseConnection(): PDO
{
    $host = getenv('DB_HOST');
    $port = getenv('DB_PORT');
    $database = getenv('DB_DATABASE');
    $username = getenv('DB_USERNAME');
    $password = getenv('DB_PASSWORD');

    if (
        $host === false || $host === '' ||
        $database === false || $database === '' ||
        $username === false || $password === false
    ) {
        throw new RuntimeException('Database environment variables are missing.');
    }

    if ($port === false || $port === '') {
        $port = '3306';
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}