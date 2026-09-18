<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Schema transpiler - VERIFICATION HARNESS, NOT PRODUCTION CODE
|--------------------------------------------------------------------------
|
| WHY THIS EXISTS
| Composer cannot reach Packagist in the environment where these migrations
| were authored, so `php artisan migrate` could not be executed. Rather than
| hand-writing SQL that might drift from the migration files, this harness
| executes the ACTUAL migration files against a minimal Blueprint emulator
| and emits PostgreSQL DDL, which is then applied to a real PostgreSQL server
| so constraints, indexes and relationships can be verified for real.
|
| CAVEAT - READ BEFORE TRUSTING IT
| This approximates Laravel's PostgreSQL grammar. It is not Laravel. It
| verifies that the migration files describe the schema intended, and that
| that schema behaves correctly in PostgreSQL. It does NOT prove Laravel will
| emit byte-identical DDL.
|
| DELETE THIS FILE once `php artisan migrate` has been run successfully on a
| machine with Composer access, and replace it with a real migration test.
|
*/

final class ColumnDefinition
{
    public bool $nullable = false;
    public bool $unique = false;
    public bool $autoIncrement = false;
    public mixed $default = null;
    public bool $hasDefault = false;
    public bool $useCurrent = false;
    public ?string $foreignTable = null;
    public string $onDelete = '';

    public function __construct(
        public string $name,
        public string $type,
        public Blueprint $blueprint,
    ) {}

    public function nullable(bool $v = true): self { $this->nullable = $v; return $this; }
    public function unique(?string $name = null): self { $this->unique = true; return $this; }
    public function default(mixed $v): self { $this->default = $v; $this->hasDefault = true; return $this; }
    public function useCurrent(): self { $this->useCurrent = true; return $this; }
    public function index(?string $name = null): self { $this->blueprint->index([$this->name], $name); return $this; }

    public function constrained(?string $table = null, string $column = 'id'): self
    {
        $this->foreignTable = $table ?? throw new RuntimeException("constrained() needs an explicit table in this harness");
        return $this;
    }

    public function cascadeOnDelete(): self { $this->onDelete = 'cascade'; return $this; }
    public function restrictOnDelete(): self { $this->onDelete = 'restrict'; return $this; }
    public function nullOnDelete(): self { $this->onDelete = 'set null'; return $this; }
}

final class Blueprint
{
    /** @var ColumnDefinition[] */
    public array $columns = [];
    public array $indexes = [];
    public array $uniques = [];
    public ?array $primary = null;

    public function __construct(public string $table) {}

    private function add(string $name, string $type): ColumnDefinition
    {
        return $this->columns[] = new ColumnDefinition($name, $type, $this);
    }

    public function id(string $name = 'id'): ColumnDefinition
    {
        $c = $this->add($name, 'bigserial');
        $c->autoIncrement = true;
        return $c;
    }

    public function foreignId(string $name): ColumnDefinition { return $this->add($name, 'bigint'); }
    public function string(string $n, int $len = 255): ColumnDefinition { return $this->add($n, "varchar($len)"); }
    public function char(string $n, int $len = 255): ColumnDefinition { return $this->add($n, "char($len)"); }
    public function text(string $n): ColumnDefinition { return $this->add($n, 'text'); }
    public function jsonb(string $n): ColumnDefinition { return $this->add($n, 'jsonb'); }
    public function boolean(string $n): ColumnDefinition { return $this->add($n, 'boolean'); }
    public function decimal(string $n, int $p = 8, int $s = 2): ColumnDefinition { return $this->add($n, "decimal($p, $s)"); }

    // PostgreSQL has no unsigned integer types; Laravel maps these to signed.
    public function unsignedInteger(string $n): ColumnDefinition { return $this->add($n, 'integer'); }
    public function unsignedSmallInteger(string $n): ColumnDefinition { return $this->add($n, 'smallint'); }

    public function timestampTz(string $n, int $precision = 0): ColumnDefinition
    {
        return $this->add($n, "timestamp($precision) with time zone");
    }

    public function timestampsTz(int $precision = 0): void
    {
        $this->timestampTz('created_at', $precision)->nullable();
        $this->timestampTz('updated_at', $precision)->nullable();
    }

    public function index(array|string $cols, ?string $name = null): void
    {
        $cols = (array) $cols;
        $this->indexes[] = ['cols' => $cols, 'name' => $name ?? $this->autoName($cols, 'index')];
    }

    public function unique(array|string $cols, ?string $name = null): void
    {
        $cols = (array) $cols;
        $this->uniques[] = ['cols' => $cols, 'name' => $name ?? $this->autoName($cols, 'unique')];
    }

    public function primary(array|string $cols): void { $this->primary = (array) $cols; }

    private function autoName(array $cols, string $suffix): string
    {
        return $this->table . '_' . implode('_', $cols) . '_' . $suffix;
    }

    public function toSql(): array
    {
        $lines = [];
        $post = [];

        foreach ($this->columns as $c) {
            $line = "  \"{$c->name}\" {$c->type}";

            if ($c->autoIncrement) {
                $line .= ' primary key';
            }

            $line .= $c->nullable ? ' null' : ' not null';

            if ($c->useCurrent) {
                $line .= ' default CURRENT_TIMESTAMP';
            } elseif ($c->hasDefault) {
                $d = $c->default;
                $line .= ' default ' . (is_bool($d) ? ($d ? 'true' : 'false') : (is_string($d) ? "'$d'" : (string) $d));
            }

            $lines[] = $line;

            if ($c->unique) {
                $post[] = "CREATE UNIQUE INDEX \"{$this->table}_{$c->name}_unique\" ON \"{$this->table}\" (\"{$c->name}\")";
            }

            if ($c->foreignTable !== null) {
                $od = $c->onDelete !== '' ? " ON DELETE {$c->onDelete}" : '';
                $post[] = "ALTER TABLE \"{$this->table}\" ADD CONSTRAINT \"{$this->table}_{$c->name}_foreign\""
                    . " FOREIGN KEY (\"{$c->name}\") REFERENCES \"{$c->foreignTable}\" (\"id\"){$od}";
            }
        }

        if ($this->primary !== null) {
            $cols = '"' . implode('", "', $this->primary) . '"';
            $lines[] = "  primary key ($cols)";
        }

        foreach ($this->uniques as $u) {
            $cols = '"' . implode('", "', $u['cols']) . '"';
            $post[] = "CREATE UNIQUE INDEX \"{$u['name']}\" ON \"{$this->table}\" ($cols)";
        }

        foreach ($this->indexes as $i) {
            $cols = '"' . implode('", "', $i['cols']) . '"';
            $post[] = "CREATE INDEX \"{$i['name']}\" ON \"{$this->table}\" ($cols)";
        }

        $create = "CREATE TABLE \"{$this->table}\" (\n" . implode(",\n", $lines) . "\n)";

        return array_merge([$create], $post);
    }
}

final class SchemaCollector
{
    public static array $sql = [];

    public static function create(string $table, callable $cb): void
    {
        $bp = new Blueprint($table);
        $cb($bp);
        foreach ($bp->toSql() as $stmt) {
            self::$sql[] = $stmt;
        }
    }

    public static function dropIfExists(string $table): void {}
}

final class DBCollector
{
    public static function statement(string $sql): void
    {
        SchemaCollector::$sql[] = trim(preg_replace('/\s+/', ' ', $sql));
    }
}

// Alias the emulator into the namespaces the migration files import.
class_alias(Blueprint::class, 'Illuminate\\Database\\Schema\\Blueprint');
class_alias(SchemaCollector::class, 'Illuminate\\Support\\Facades\\Schema');
class_alias(DBCollector::class, 'Illuminate\\Support\\Facades\\DB');

abstract class MigrationBase {}
class_alias(MigrationBase::class, 'Illuminate\\Database\\Migrations\\Migration');

$dir = $argv[1] ?? __DIR__ . '/../../database/migrations';
$files = glob($dir . '/*.php');
sort($files);

foreach ($files as $file) {
    $migration = require $file;
    $migration->up();
}

echo "-- Generated from " . count($files) . " migration files\n";
echo "-- Harness: tests/Support/schema-transpiler.php (see caveat in header)\n\n";
foreach (SchemaCollector::$sql as $stmt) {
    echo rtrim($stmt, ';') . ";\n";
}
