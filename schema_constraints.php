<?php
declare(strict_types=1);

if (!function_exists('ensure_core_database_constraints')) {
    function ensure_core_database_constraints(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;
        $databaseName = database_constraints_current_schema($conn);
        if ($databaseName === null) {
            return;
        }
        $checkConstraints = [
            ['bookings', 'chk_bookings_times', 'CHECK (start_time < end_time)'],
            ['bookings', 'chk_bookings_start_not_null', 'CHECK (start_time IS NOT NULL)'],
            ['bookings', 'chk_bookings_end_not_null', 'CHECK (end_time IS NOT NULL)'],
            ['booking_waitlist', 'chk_waitlist_times', 'CHECK (desired_start < desired_end)'],
        ];
        foreach ($checkConstraints as [$table, $name, $definition]) {
            ensure_check_constraint($conn, $databaseName, $table, $name, $definition);
        }
        $foreignKeys = [
            ['bookings', 'fk_bookings_equipment', 'FOREIGN KEY (equipment_id) REFERENCES equipment (equipment_id) ON DELETE CASCADE'],
            ['bookings', 'fk_bookings_requester', 'FOREIGN KEY (requester_id) REFERENCES users (user_id) ON DELETE CASCADE'],
            ['booking_waitlist', 'fk_waitlist_equipment', 'FOREIGN KEY (equipment_id) REFERENCES equipment (equipment_id) ON DELETE CASCADE'],
            ['booking_waitlist', 'fk_waitlist_user', 'FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE'],
            ['maintenance_tasks', 'fk_maintenance_equipment', 'FOREIGN KEY (equipment_id) REFERENCES equipment (equipment_id) ON DELETE CASCADE'],
        ];
        foreach ($foreignKeys as [$table, $name, $definition]) {
            ensure_foreign_key_constraint($conn, $databaseName, $table, $name, $definition);
        }
        ensure_unique_index($conn, $databaseName, 'equipment', 'uq_equipment_name', ['name']);
    }

    function database_constraints_current_schema(mysqli $conn): ?string
    {
        $result = mysqli_query($conn, 'SELECT DATABASE() AS db_name');
        if ($result === false) {
            return null;
        }
        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        $name = trim((string) ($row['db_name'] ?? ''));
        return $name === '' ? null : $name;
    }

    function ensure_check_constraint(mysqli $conn, string $schema, string $table, string $constraintName, string $definition): void
    {
        if (!is_valid_identifier($table) || !is_valid_identifier($constraintName)) {
            return;
        }
        if (constraint_exists($conn, $schema, $table, $constraintName)) {
            return;
        }
        $sql = sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` %s', $table, $constraintName, $definition);
        @mysqli_query($conn, $sql);
    }

    function ensure_foreign_key_constraint(mysqli $conn, string $schema, string $table, string $constraintName, string $definition): void
    {
        if (!is_valid_identifier($table) || !is_valid_identifier($constraintName)) {
            return;
        }
        if (constraint_exists($conn, $schema, $table, $constraintName)) {
            return;
        }
        $sql = sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` %s', $table, $constraintName, $definition);
        @mysqli_query($conn, $sql);
    }

    function ensure_unique_index(mysqli $conn, string $schema, string $table, string $indexName, array $columns): void
    {
        if (!is_valid_identifier($table) || !is_valid_identifier($indexName) || empty($columns)) {
            return;
        }
        foreach ($columns as $column) {
            if (!is_valid_identifier($column)) {
                return;
            }
        }
        if (unique_index_exists($conn, $schema, $table, $indexName)) {
            return;
        }
        $columnList = '`' . implode('`,`', $columns) . '`';
        $sql = sprintf('ALTER TABLE `%s` ADD UNIQUE `%s` (%s)', $table, $indexName, $columnList);
        @mysqli_query($conn, $sql);
    }

    function constraint_exists(mysqli $conn, string $schema, string $table, string $constraintName): bool
    {
        $sql = 'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1';
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'sss', $schema, $table, $constraintName);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        return $exists;
    }

    function unique_index_exists(mysqli $conn, string $schema, string $table, string $indexName): bool
    {
        $sql = 'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1';
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'sss', $schema, $table, $indexName);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        return $exists;
    }

    function is_valid_identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }
}
