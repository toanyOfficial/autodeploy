<?php

use App\Config\Database;
use App\Config\Env;

require __DIR__ . '/../app/Core/Autoloader.php';

Env::load(__DIR__ . '/../.env');

$pdo = Database::connection();
$database = $pdo->query('SELECT DATABASE() AS database_name')->fetch()['database_name'] ?? null;
$tables = ['deploy_project', 'deploy_version', 'deploy_history'];

foreach ($tables as $table) {
    $statement = $pdo->prepare('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = :database AND table_name = :table');
    $statement->execute(['database' => $database, 'table' => $table]);
    $exists = (int) ($statement->fetch()['total'] ?? 0) === 1;
    echo sprintf("%s: %s\n", $table, $exists ? 'OK' : 'MISSING');
}

echo sprintf("DB connection OK: %s\n", $database);
