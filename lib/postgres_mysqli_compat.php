<?php

class PgCompatResult
{
    public int $num_rows = 0;
    private array $rows;
    private int $cursor = 0;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    public function fetch_assoc()
    {
        if ($this->cursor >= $this->num_rows) {
            return null;
        }

        return $this->rows[$this->cursor++];
    }

    public function fetch_row()
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : array_values($row);
    }

    public function fetch_all($mode = null)
    {
        return $this->rows;
    }

    public function free()
    {
        $this->rows = [];
        $this->cursor = 0;
        $this->num_rows = 0;
    }
}

class PgCompatStatement
{
    public string $error = '';
    public int $affected_rows = 0;
    public int $insert_id = 0;

    private PgCompatConnection $conn;
    private string $sql;
    private array $params = [];
    private string $types = '';
    private ?PDOStatement $stmt = null;
    private ?PgCompatResult $result = null;

    public function __construct(PgCompatConnection $conn, string $sql)
    {
        $this->conn = $conn;
        $this->sql = $sql;
    }

    public function bind_param(string $types, &...$vars)
    {
        $this->types = $types;
        $this->params = [];

        foreach ($vars as &$var) {
            $this->params[] = &$var;
        }

        return true;
    }

    public function execute()
    {
        try {
            $sql = $this->conn->translateSql($this->sql);
            $this->stmt = $this->conn->pdo()->prepare($sql);

            foreach ($this->params as $index => $value) {
                $type = $this->types[$index] ?? 's';
                $pdoType = $type === 'i' ? PDO::PARAM_INT : PDO::PARAM_STR;
                if ($value === null) {
                    $pdoType = PDO::PARAM_NULL;
                }
                $this->stmt->bindValue($index + 1, $value, $pdoType);
            }

            $ok = $this->stmt->execute();
            $this->affected_rows = $this->stmt->rowCount();
            $this->conn->affected_rows = $this->affected_rows;

            if ($this->conn->isSelectLike($sql)) {
                $this->result = new PgCompatResult($this->stmt->fetchAll(PDO::FETCH_ASSOC));
            } else {
                $this->result = null;
                $this->insert_id = $this->conn->captureInsertId($sql);
            }

            return $ok;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->conn->error = $this->error;
            error_log('Postgres statement failed: ' . $this->error . ' SQL: ' . $this->sql);
            return false;
        }
    }

    public function get_result()
    {
        return $this->result ?: new PgCompatResult([]);
    }

    public function close()
    {
        $this->stmt = null;
        $this->result = null;
        return true;
    }
}

class PgCompatConnection
{
    public string $connect_error = '';
    public string $error = '';
    public int $errno = 0;
    public int $insert_id = 0;
    public int $affected_rows = 0;

    private PDO $pdo;

    private array $primaryKeys = [
        'admins' => 'admin_id',
        'bookings' => 'booking_id',
        'customers' => 'customer_id',
        'driver_availability' => 'availability_id',
        'driver_earnings' => 'earning_id',
        'drivers' => 'driver_id',
        'locations' => 'location_id',
        'notifications' => 'id',
        'password_reset_tokens' => 'id',
        'routes' => 'route_id',
        'system_logs' => 'log_id',
        'users' => 'user_id',
        'vehicle_schedules' => 'schedule_id',
        'vehicle_trips' => 'trip_id',
        'vehicles' => 'vehicle_id',
    ];

    public function __construct(string $databaseUrl)
    {
        $parts = parse_url($databaseUrl);
        if (!$parts || empty($parts['host'])) {
            $this->connect_error = 'Invalid PostgreSQL DATABASE_URL';
            throw new RuntimeException($this->connect_error);
        }

        $host = $parts['host'];
        $port = intval($parts['port'] ?? 5432);
        $dbname = ltrim($parts['path'] ?? '/postgres', '/') ?: 'postgres';
        $user = isset($parts['user']) ? urldecode($parts['user']) : '';
        $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function set_charset(string $charset)
    {
        return true;
    }

    public function close()
    {
        return true;
    }

    public function begin_transaction()
    {
        return $this->pdo->beginTransaction();
    }

    public function commit()
    {
        return $this->pdo->commit();
    }

    public function rollback()
    {
        return $this->pdo->rollBack();
    }

    public function real_escape_string(string $value)
    {
        $quoted = $this->pdo->quote($value);
        return substr($quoted, 1, -1);
    }

    public function prepare(string $sql)
    {
        return new PgCompatStatement($this, $sql);
    }

    public function query(string $sql)
    {
        try {
            $translated = $this->translateSql($sql);
            $stmt = $this->pdo->query($translated);
            $this->affected_rows = $stmt->rowCount();

            if ($this->isSelectLike($translated)) {
                return new PgCompatResult($stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            $this->insert_id = $this->captureInsertId($translated);
            return true;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            error_log('Postgres query failed: ' . $this->error . ' SQL: ' . $sql);
            return false;
        }
    }

    public function isSelectLike(string $sql): bool
    {
        return (bool) preg_match('/^\s*(SELECT|WITH|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql);
    }

    public function captureInsertId(string $sql): int
    {
        if (!preg_match('/^\s*INSERT\s+INTO\s+"?([a-zA-Z_][a-zA-Z0-9_]*)"?/i', $sql, $match)) {
            return 0;
        }

        $table = strtolower($match[1]);
        $pk = $this->primaryKeys[$table] ?? null;
        if (!$pk) {
            return 0;
        }

        try {
            $stmt = $this->pdo->query("select currval(pg_get_serial_sequence('{$table}', '{$pk}'))");
            $id = intval($stmt->fetchColumn());
            $this->insert_id = $id;
            return $id;
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function translateSql(string $sql): string
    {
        $sql = str_replace('`', '', $sql);
        $sql = preg_replace('/\bIFNULL\s*\(/i', 'COALESCE(', $sql);
        $sql = preg_replace('/\bCURDATE\s*\(\s*\)/i', 'CURRENT_DATE', $sql);
        $sql = preg_replace('/\bNOW\s*\(\s*\)/i', 'CURRENT_TIMESTAMP', $sql);
        $sql = preg_replace('/\bPOINT\s*\(/i', 'point(', $sql);
        $sql = preg_replace('/ST_X\s*\(\s*([a-zA-Z_][a-zA-Z0-9_]*\.)?current_location\s*\)/i', '$1current_location[0]', $sql);
        $sql = preg_replace('/ST_Y\s*\(\s*([a-zA-Z_][a-zA-Z0-9_]*\.)?current_location\s*\)/i', '$1current_location[1]', $sql);
        $sql = preg_replace('/TIMESTAMPDIFF\s*\(\s*SECOND\s*,\s*([^,]+?)\s*,\s*CURRENT_TIMESTAMP\s*\)/i', 'EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - $1))::integer', $sql);
        $sql = preg_replace('/DATE_SUB\s*\(\s*CURRENT_TIMESTAMP\s*,\s*INTERVAL\s+\?\s+HOUR\s*\)/i', "(CURRENT_TIMESTAMP - (? * interval '1 hour'))", $sql);
        $sql = preg_replace('/DATE_SUB\s*\(\s*CURRENT_TIMESTAMP\s*,\s*INTERVAL\s+([0-9]+)\s+(MINUTE|HOUR|DAY|WEEK|MONTH)\s*\)/i', "(CURRENT_TIMESTAMP - interval '$1 $2')", $sql);
        $sql = preg_replace('/DATE_ADD\s*\(\s*CURRENT_TIMESTAMP\s*,\s*INTERVAL\s+([0-9]+)\s+(MINUTE|HOUR|DAY|WEEK|MONTH)\s*\)/i', "(CURRENT_TIMESTAMP + interval '$1 $2')", $sql);
        $sql = preg_replace("/DATE_SUB\s*\(\s*'([^']+)'\s*,\s*INTERVAL\s+([0-9]+)\s+(DAY|WEEK|MONTH)\s*\)/i", "(DATE '$1' - interval '$2 $3')", $sql);
        $sql = preg_replace('/YEARWEEK\s*\(\s*([^)]+?)\s*,\s*1\s*\)/i', "to_char(($1)::date, 'IYYYIW')::integer", $sql);
        $sql = preg_replace('/DAYNAME\s*\(\s*([^)]+?)\s*\)/i', "trim(to_char(($1)::date, 'Day'))", $sql);
        $sql = preg_replace('/\bDATE\s*\(([^()]+)\)/i', 'CAST($1 AS DATE)', $sql);

        $sql = preg_replace('/\b(is_online|is_active|is_read)\s*=\s*1\b/i', '$1 = true', $sql);
        $sql = preg_replace('/\b(is_online|is_active|is_read)\s*=\s*0\b/i', '$1 = false', $sql);

        $sql = $this->translateUpdateJoin($sql);
        $sql = $this->translateOnDuplicateKey($sql);
        $sql = $this->translateInformationSchema($sql);

        return $sql;
    }

    private function translateUpdateJoin(string $sql): string
    {
        if (preg_match('/UPDATE\s+drivers\s+d\s+JOIN\s+vehicles\s+v\s+ON\s+v\.driver_id\s*=\s*d\.user_id\s+SET\s+d\.current_location\s*=\s*point\(([^)]+)\)\s+WHERE\s+v\.vehicle_id\s*=\s*\?/is', $sql, $match)) {
            return 'UPDATE drivers d SET current_location = point(' . $match[1] . ') FROM vehicles v WHERE v.driver_id = d.user_id AND v.vehicle_id = ?';
        }

        return $sql;
    }

    private function translateOnDuplicateKey(string $sql): string
    {
        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') === false) {
            return $sql;
        }

        if (preg_match('/INSERT\s+INTO\s+vehicle_trips\s*\((.*?)\)\s*VALUES\s*\((.*?)\)\s*ON DUPLICATE KEY UPDATE.*$/is', $sql, $match)) {
            return 'INSERT INTO vehicle_trips (' . $match[1] . ') VALUES (' . $match[2] . ')
                ON CONFLICT (schedule_id, direction, scheduled_departure_at) DO UPDATE SET
                    vehicle_id = EXCLUDED.vehicle_id,
                    route_id = EXCLUDED.route_id,
                    seat_capacity_snapshot = EXCLUDED.seat_capacity_snapshot';
        }

        if (preg_match('/INSERT\s+INTO\s+password_reset_tokens\s*\((.*?)\)\s*VALUES\s*\((.*?)\)\s*ON DUPLICATE KEY UPDATE\s+token\s*=\s*\?\s*,\s*expiry_date\s*=\s*\?/is', $sql, $match)) {
            return 'INSERT INTO password_reset_tokens (' . $match[1] . ') VALUES (' . $match[2] . ')
                ON CONFLICT (token) DO UPDATE SET token = ?, expiry_date = ?';
        }

        return preg_replace('/\s+ON DUPLICATE KEY UPDATE.*$/is', '', $sql);
    }

    private function translateInformationSchema(string $sql): string
    {
        $sql = preg_replace("/SHOW\s+COLUMNS\s+FROM\s+([a-zA-Z_][a-zA-Z0-9_]*)\s+LIKE\s+'([^']+)'/i", "SELECT column_name AS Field FROM information_schema.columns WHERE table_name = '$1' AND column_name = '$2'", $sql);
        $sql = preg_replace("/SHOW\s+INDEX\s+FROM\s+([a-zA-Z_][a-zA-Z0-9_]*)\s+WHERE\s+Key_name\s*=\s*'([^']+)'/i", "SELECT indexname AS Key_name FROM pg_indexes WHERE tablename = '$1' AND indexname = '$2'", $sql);
        return $sql;
    }
}
