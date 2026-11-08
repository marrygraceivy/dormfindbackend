<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Buffered MySQLi result used by LavaLust Query Builder, ORM and migrations. */
class Database_result
{
    public const FETCH_ASSOC = 1, FETCH_NUM = 2, FETCH_OBJ = 3, FETCH_COLUMN = 4,
        FETCH_KEY_PAIR = 5, FETCH_GROUP = 6, FETCH_CLASS = 7;

    public function __construct(private ?mysqli_result $result, private int $affected = 0) {}

    public function rowCount(): int { return $this->affected; }
    public function columnCount(): int { return $this->result?->field_count ?? 0; }

    public function fetch($mode = self::FETCH_ASSOC, ...$args)
    {
        if (!$this->result) return false;
        $row = $this->result->fetch_assoc();
        if ($row === null) return false;
        return $this->convert($row, $mode, $args);
    }

    private function convert(array $row, int $mode, array $args)
    {
        if ($mode === self::FETCH_NUM) return array_values($row);
        if ($mode === self::FETCH_OBJ) return (object)$row;
        if ($mode === self::FETCH_COLUMN) return array_values($row)[$args[0] ?? 0] ?? false;
        if ($mode === self::FETCH_CLASS) {
            $object = (new ReflectionClass($args[0]))->newInstanceWithoutConstructor();
            foreach ($row as $key => $value) $object->$key = $value;
            if (method_exists($object, '__construct')) $object->__construct(...array_slice($args, 1));
            return $object;
        }
        return $row;
    }

    public function fetchAll($mode = self::FETCH_ASSOC, ...$args): array
    {
        $rows = [];
        // Fetch associative rows first so a legitimate false/NULL column does not end iteration.
        while (($row = $this->fetch()) !== false) {
            if ($mode === self::FETCH_KEY_PAIR) {
                $values = array_values($row); $rows[$values[0]] = $values[1] ?? null;
            } elseif ($mode === self::FETCH_GROUP) {
                $key = array_shift($row); $rows[$key][] = $row;
            } else $rows[] = $this->convert($row, $mode, $args);
        }
        return $rows;
    }

    public function fetchColumn($column = 0) { return $this->fetch(self::FETCH_COLUMN, $column); }
    public function closeCursor(): bool { if ($this->result) $this->result->free(); $this->result = null; return true; }
    public function __destruct() { $this->closeCursor(); }
}
