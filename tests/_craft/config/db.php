<?php

return [
    'dsn' => getenv('CRAFT_DB_DSN') ?: 'mysql:host=db;port=3306;dbname=craft_test',
    'user' => getenv('CRAFT_DB_USER') ?: 'root',
    'password' => getenv('CRAFT_DB_PASSWORD') ?: 'root',
    'schema' => getenv('CRAFT_DB_SCHEMA') ?: '',
    'tablePrefix' => getenv('CRAFT_DB_TABLE_PREFIX') ?: '',
    'charset' => 'utf8mb4',
];
