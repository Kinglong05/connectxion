<?php

class PostgresResultBridge {
    private $pdoStatement;
    public $num_rows;

    public function __construct($pdoStatement) {
        $this->pdoStatement = $pdoStatement;
        $this->num_rows = $this->pdoStatement->rowCount();
    }

    public function fetch_assoc() {
        return $this->pdoStatement->fetch(PDO::FETCH_ASSOC);
    }

    public function fetch_all($mode = MYSQLI_ASSOC) {
        // Only map MYSQLI_ASSOC for now as we know codebase uses it
        return $this->pdoStatement->fetchAll(PDO::FETCH_ASSOC);
    }
}

class PostgresStmtBridge {
    private $pdo;
    private $pdoStatement;
    private $sql;
    private $params = [];
    public $error = null;
    public $insert_id = 0;
    public $affected_rows = 0;

    public function __construct($pdo, $sql) {
        $this->pdo = $pdo;
        $this->sql = $this->convertSql($sql);
        try {
            $this->pdoStatement = $this->pdo->prepare($this->sql);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
        }
    }
    
    private function convertSql($sql) {
        return PostgresBridge::staticConvertSql($sql);
    }

    public function bind_param($types, ...$params) {
        // Extract array of params if passed by reference natively, but PHP 8 handles splat operater okay
        $this->params = [];
        // Flatten params just in case since variables might be passed by ref
        foreach ($params as $p) {
            $this->params[] = $p;
        }
        return true;
    }

    public function execute() {
        if (!$this->pdoStatement) return false;
        try {
            // Because PDO bind params by types requires explicit matching
            // and mysqli bind_param uses type string (e.g. 'iss'), we can instead
            // execute the statement with an array of values natively since PDO deals w/ it well.
            $result = $this->pdoStatement->execute($this->params);
            $this->affected_rows = $this->pdoStatement->rowCount();
            
            // Try to get last insert ID
            try {
                $this->insert_id = $this->pdo->lastInsertId();
            } catch (Exception $e) {
                $this->insert_id = 0; // if not sequence
            }
            return $result;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function get_result() {
        return new PostgresResultBridge($this->pdoStatement);
    }

    public function close() {
        $this->pdoStatement = null;
        return true;
    }
}

class PostgresBridge {
    private $pdo;
    public $connect_error = null;
    public $connect_errno = 0;
    public $error = null;
    public $affected_rows = 0;
    public $insert_id = 0;
    
    public function __construct() {}

    public function options($opt, $val) {
        // Stub for options
    }

    public function ssl_set($key, $cert, $ca, $capath, $cipher) {
        // Stub for SSL config. Supabase uses standard PG sslmode
    }
    
    public function set_charset($charset) {
        // Stub, PDO handles it in DSN
    }

    public function real_connect($host, $username, $password, $database, $port = 5432, $socket = null, $flags = 0) {
        try {
            // Supabase requires sslmode=require
            $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode=require";
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->connect_error = null;
            $this->connect_errno = 0;
            return true;
        } catch (PDOException $e) {
            $this->connect_error = $e->getMessage();
            $this->connect_errno = intval($e->getCode());
            return false;
        }
    }

    public function ping() {
        try {
            if (!$this->pdo) return false;
            $this->pdo->query('SELECT 1');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function query($sql) {
        try {
            $sql = $this->convertSql($sql);
            
            // If it's a DDL or variable set we might not get a result set
            if (stripos(trim($sql), 'SET ') === 0) {
                // PDO Postgres doesn't support MySQL "SET NAMES" or some timezone sets exactly
                if (stripos($sql, 'SET NAMES') !== false) return true;
                if (stripos($sql, 'SET time_zone') !== false) {
                     $this->pdo->exec("SET TIME ZONE '+08:00'");
                     return true;
                }
            }

            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                $err = $this->pdo->errorInfo();
                $this->error = $err[2];
                return false;
            }
            $this->affected_rows = $stmt->rowCount();
            if (stripos(trim($sql), 'INSERT') === 0) {
                try {
                    $this->insert_id = $this->pdo->lastInsertId();
                } catch (Exception $e) {}
            }
            return new PostgresResultBridge($stmt);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function prepare($sql) {
        $stmt = new PostgresStmtBridge($this->pdo, $sql);
        if ($stmt->error) {
            $this->error = $stmt->error;
            return false;
        }
        return $stmt;
    }

    public function real_escape_string($string) {
        if (!$this->pdo) return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
        // PDO quote adds surrounding single quotes, which mysqli's real_escape_string doesn't.
        // We have to strip the surrounding quotes.
        $quoted = $this->pdo->quote($string);
        // Remove the outer single quotes if present
        if (strlen($quoted) >= 2 && $quoted[0] === "'" && substr($quoted, -1) === "'") {
            return substr($quoted, 1, -1);
        }
        return $quoted;
    }

    public static function staticConvertSql($sql) {
        $sql = str_replace('`', '"', $sql);
        
        // Convert MySQL INSERT IGNORE to Postgres ON CONFLICT DO NOTHING
        if (stripos($sql, 'INSERT IGNORE INTO') !== false) {
            $sql = str_ireplace('INSERT IGNORE INTO', 'INSERT INTO', $sql);
            if (!str_ends_with(trim($sql), ';')) {
                $sql .= " ON CONFLICT DO NOTHING";
            }
        }

        // Robust UNIX_TIMESTAMP translation using regex to capture any column name
        $sql = preg_replace('/UNIX_TIMESTAMP\((.*?)\)/i', 'EXTRACT(EPOCH FROM $1)', $sql);
        $sql = str_ireplace('UNIX_TIMESTAMP()', 'EXTRACT(EPOCH FROM NOW())', $sql);
        
        $sql = str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $sql);
        $sql = str_ireplace('RAND()', 'RANDOM()', $sql);
        
        // Convert MySQL GROUP_CONCAT(...) to Postgres STRING_AGG(..., ',')
        // Uses a sub-pattern for balanced parentheses to handle nested functions like CONCAT
        $sql = preg_replace_callback('/GROUP_CONCAT\s*(\((?:[^()]++|(?1))*\))/is', function($matches) {
            $inner_with_parens = $matches[1];
            $inner = substr($inner_with_parens, 1, -1); // Remove outer ( and )
            
            $separator = "','"; // Default
            if (preg_match('/SEPARATOR\s+[\'"](.*?)[\'"]/i', $inner, $sepMatches)) {
                $separator = "'" . $sepMatches[1] . "'";
                $inner = preg_replace('/SEPARATOR\s+[\'"](.*?)[\'"]/i', '', $inner);
            }
            // Postgres STRING_AGG requires the first arg to be text
            return "STRING_AGG(" . trim($inner, " ,") . "::text, $separator)";
        }, $sql);

        // Convert MySQL LIMIT offset, count to Postgres LIMIT count OFFSET offset
        $sql = preg_replace_callback('/LIMIT\s+(\d+)\s*,\s*(\d+)/i', function($matches) {
            return "LIMIT " . $matches[2] . " OFFSET " . $matches[1];
        }, $sql);

        // Convert MySQL ON DUPLICATE KEY UPDATE to Postgres ON CONFLICT (...) DO UPDATE SET...
        // This requires heuristic detection of the key. We'll target the common session/typing patterns.
        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            // Heuristic for sessions table
            if (stripos($sql, 'INTO sessions') !== false) {
                $sql = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT (id) DO UPDATE SET', $sql);
            } 
            // Heuristic for typing_status table
            elseif (stripos($sql, 'INTO typing_status') !== false) {
                $sql = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT (user_id, receiver_id) DO UPDATE SET', $sql);
            }
        }

        return $sql;
    }

    private function convertSql($sql) {
        return self::staticConvertSql($sql);
    }
}
?>
