<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

use VeyroPHP\Port\Capabilities;

/**
 * Turns an Eloquent model into a VeyroPHP model skeleton: table, fillable,
 * hidden, casts, timestamps, primary key and the four supported relation
 * types. Names Laravel infers (tables, foreign keys, pivot tables) are
 * written out explicitly and flagged. Methods, scopes, accessors and traits
 * are listed as TODO(port) items, not copied.
 */
final class ModelConverter
{
    private const CASTS = [
        'datetime' => 'datetime', 'immutable_datetime' => 'datetime', 'timestamp' => 'datetime',
        'date' => 'date', 'immutable_date' => 'date',
        'boolean' => 'bool', 'bool' => 'bool',
        'integer' => 'int', 'int' => 'int',
        'float' => 'float', 'double' => 'float', 'real' => 'float',
        'string' => 'string',
        'array' => 'json', 'json' => 'json', 'object' => 'json', 'collection' => 'json',
    ];

    private const RELATIONS = ['hasMany', 'hasOne', 'belongsTo', 'belongsToMany'];

    /** @var list<string> */
    private array $todos = [];

    public function convert(string $source, string $code): ?ConvertedFile
    {
        if (preg_match('/\bclass\s+(\w+)\s+extends\s+([\w\\\\]+)/', $code, $class) !== 1) {
            return null;
        }

        $this->todos = [];
        $name = $class[1];
        $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $code, $ns) === 1 ? $ns[1] : 'App\Models';
        $constants = [];

        $table = $this->stringProperty($code, 'table');
        $constants['TABLE'] = $table ?? Words::plural(Words::snake($name));
        if ($table === null) {
            $this->todos[] = "table \"{$constants['TABLE']}\" inferred from the class name as Laravel does";
        }

        if (($key = $this->stringProperty($code, 'primaryKey')) !== null && $key !== 'id') {
            $constants['PRIMARY_KEY'] = $key;
        }

        foreach (['fillable' => 'FILLABLE', 'hidden' => 'HIDDEN'] as $property => $constant) {
            $value = $this->arrayProperty($code, $property);

            if ($value !== null) {
                $constants[$constant] = array_values(array_filter($value, 'is_string'));
            }
        }

        if (preg_match('/\$guarded\s*=/', $code) === 1) {
            $this->todos[] = '$guarded is not supported: list the assignable fields in FILLABLE';
        }

        $casts = $this->arrayProperty($code, 'casts') ?? $this->castsMethod($code);

        if ($casts !== null) {
            $constants['CASTS'] = $this->casts($casts);
        }

        if (preg_match('/\$timestamps\s*=\s*false/', $code) === 1) {
            $constants['TIMESTAMPS'] = false;
        }

        $relations = $this->relations($code, $name);
        $this->otherMembers($code, array_keys($relations));

        return new ConvertedFile('model', $source, self::target($source, $name), $this->render($source, $namespace, $name, $constants, $relations, $this->uses($code, $relations)), $this->todos);
    }

    /**
     * The model keeps its path, so it still matches its namespace and two models with one
     * short name do not collide (CORE-3). Only a source outside app/ (or a path that is not
     * a plain class path) goes to app/Models/.
     */
    private static function target(string $source, string $name): string
    {
        return preg_match('#^app/(?:[A-Za-z0-9_]+/)*[A-Za-z0-9_]+\.php$#D', $source) === 1 ? $source : 'app/Models/' . $name . '.php';
    }

    private function stringProperty(string $code, string $property): ?string
    {
        return preg_match('/\$' . $property . '\s*=\s*([\'"][^\'"]*[\'"])\s*;/', $code, $m) === 1 ? Php::string($m[1]) : null;
    }

    /**
     * @return array<mixed>|null
     */
    private function arrayProperty(string $code, string $property): ?array
    {
        if (preg_match('/\$' . $property . '\s*=\s*(\[(?:[^\[\]]|\[[^\[\]]*\])*\])\s*;/s', $code, $m) !== 1) {
            return null;
        }

        [$ok, $value] = Php::literal($m[1]);

        if (!$ok || !is_array($value)) {
            $this->todos[] = "\${$property} is not a plain literal array; copy it by hand";

            return null;
        }

        return $value;
    }

    /**
     * Laravel 11+ casts(): array method.
     *
     * @return array<mixed>|null
     */
    private function castsMethod(string $code): ?array
    {
        if (preg_match('/function\s+casts\s*\(\s*\)[^{]*\{\s*return\s+(\[(?:[^\[\]]|\[[^\[\]]*\])*\])\s*;/s', $code, $m) !== 1) {
            return null;
        }

        [$ok, $value] = Php::literal($m[1]);

        return $ok && is_array($value) ? $value : null;
    }

    /**
     * @param array<mixed> $casts
     * @return array<string, string>
     */
    private function casts(array $casts): array
    {
        $out = [];

        foreach ($casts as $column => $cast) {
            $column = (string) $column;

            if ($cast instanceof ClassName) {
                $this->todos[] = "cast of \"{$column}\" to {$cast->short()} (enum or custom cast) is not supported: convert in code";

                continue;
            }

            $type = is_string($cast) ? strtolower(explode(':', $cast)[0]) : '';

            if ($type === 'decimal') {
                $out[$column] = 'string';
                $this->todos[] = "decimal cast of \"{$column}\" kept as string (no decimal cast)";
            } elseif (isset(self::CASTS[$type])) {
                $out[$column] = self::CASTS[$type];
            } elseif ($type === 'hashed') {
                $this->todos[] = "\"{$column}\" was cast as hashed: hash it with PasswordHasher before saving";
            } elseif (str_starts_with($type, 'encrypted')) {
                $this->todos[] = "\"{$column}\" was cast as {$type}: encrypt it with Encrypter before saving";
            } else {
                $this->todos[] = "cast \"{$type}\" of \"{$column}\" is not supported";
            }
        }

        return $out;
    }

    /**
     * @return array<string, string> relation name => Relation::...() code
     */
    private function relations(string $code, string $model): array
    {
        $relations = [];
        preg_match_all('/function\s+(\w+)\s*\(\s*\)\s*(?::\s*[\w\\\\]+\s*)?\{\s*return\s+\$this->(\w+)\(([^;]*?)\)\s*(->[^;]*)?;\s*\}/s', $code, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            [, $method, $type, $args] = $match;
            $chain = $match[4] ?? '';

            if (!in_array($type, self::RELATIONS, true)) {
                if (in_array($type, Capabilities::relationTypes(), true)) {
                    $this->todos[] = "relation {$method}() uses {$type}(): write Relation::{$type}() by hand (see docs/database/orm.md)";
                } elseif (preg_match('/^(has|belongs|morph)/', $type) === 1) {
                    $this->todos[] = "relation {$method}() uses {$type}(), which VeyroPHP does not support";
                }

                continue;
            }

            $args = Php::splitArgs($args);
            [$ok, $related] = Php::literal($args[0] ?? '');

            if (!$ok || !$related instanceof ClassName) {
                $this->todos[] = "relation {$method}(): related model is not a ::class literal";

                continue;
            }

            $relatedShort = $related->short();
            $explicit = array_map(static fn(string $arg): ?string => Php::string($arg), array_slice($args, 1));

            switch ($type) {
                case 'hasMany':
                case 'hasOne':
                    // Keys come from the source project: always written as string literals (CORE-2).
                    $foreign = $explicit[0] ?? Words::snake($model) . '_id';
                    $relations[$method] = "Relation::{$type}({$relatedShort}::class, " . Php::export($foreign) . ')';
                    break;
                case 'belongsTo':
                    $foreign = $explicit[0] ?? Words::snake($method) . '_id';
                    $owner = $explicit[1] ?? 'id';
                    $relations[$method] = "Relation::belongsTo({$relatedShort}::class, " . Php::export($foreign) . ($owner !== 'id' ? ', ' . Php::export($owner) : '') . ')';
                    break;
                default: // belongsToMany
                    $names = [Words::snake($model), Words::snake($relatedShort)];
                    sort($names);
                    $pivot = $explicit[0] ?? implode('_', $names);
                    $foreign = $explicit[1] ?? Words::snake($model) . '_id';
                    $relatedKey = $explicit[2] ?? Words::snake($relatedShort) . '_id';
                    $relations[$method] = "Relation::belongsToMany({$relatedShort}::class, " . implode(', ', array_map(Php::export(...), [$pivot, $foreign, $relatedKey])) . ')';
            }

            if (in_array(null, array_slice($explicit, 0, $type === 'belongsToMany' ? 3 : 1), true) || count($args) === 1) {
                $this->todos[] = "relation {$method}(): keys inferred as Laravel does; check them";
            }

            if (trim((string) $chain) !== '') {
                $this->todos[] = "relation {$method}(): chained " . trim((string) $chain) . ' was dropped';
            }
        }

        return $relations;
    }

    /**
     * @param list<string> $relationNames
     */
    private function otherMembers(string $code, array $relationNames): void
    {
        if (preg_match('/class\s+\w+[^{]*\{(.*)\}/s', $code, $body) === 1 && preg_match_all('/^\s*use\s+([\w\\\\, ]+);/m', $body[1], $traits) > 0) {
            foreach ($traits[1] as $list) {
                foreach (array_map('trim', explode(',', $list)) as $trait) {
                    $short = substr((string) strrchr('\\' . $trait, '\\'), 1);
                    $this->todos[] = match ($short) {
                        'HasFactory' => 'trait HasFactory dropped: use a Factory class (php veyro make:factory)',
                        'SoftDeletes' => 'trait SoftDeletes dropped: add protected const SOFT_DELETES = \'deleted_at\'',
                        'Notifiable' => 'trait Notifiable dropped: send notifications with Notifier',
                        'HasApiTokens' => 'trait HasApiTokens dropped: use the built-in ApiTokens service',
                        default => "trait {$short} dropped",
                    };
                }
            }
        }

        preg_match_all('/function\s+(\w+)\s*\(/', $code, $methods);
        $skip = [...$relationNames, 'casts'];
        $other = array_values(array_diff(array_unique($methods[1]), $skip));

        if ($other !== []) {
            $this->todos[] = 'not converted (port by hand into a service or the model): ' . implode('(), ', $other) . '()';
        }
    }

    /**
     * Use statements of related models from other namespaces.
     *
     * @param array<string, string> $relations
     * @return list<string>
     */
    private function uses(string $code, array $relations): array
    {
        $uses = ['VeyroPHP\Orm\Model'];

        if ($relations !== []) {
            $uses[] = 'VeyroPHP\Orm\Relation';
        }

        preg_match_all('/^\s*use\s+([\w\\\\]+)\s*;/m', $code, $m);

        foreach ($m[1] as $use) {
            $short = substr((string) strrchr('\\' . $use, '\\'), 1);

            foreach ($relations as $relation) {
                if (str_contains($relation, $short . '::class') && !str_starts_with($use, 'Illuminate\\')) {
                    $uses[] = $use;
                }
            }
        }

        $uses = array_values(array_unique($uses));
        sort($uses);

        return $uses;
    }

    /**
     * @param array<string, mixed> $constants
     * @param array<string, string> $relations
     * @param list<string> $uses
     */
    private function render(string $source, string $namespace, string $name, array $constants, array $relations, array $uses): string
    {
        $lines = ['<?php', '', 'declare(strict_types=1);', '', "namespace {$namespace};", ''];

        foreach ($uses as $use) {
            $lines[] = "use {$use};";
        }

        $lines[] = '';
        $lines[] = '/**';
        $lines[] = ' * ' . Php::comment(ConvertedFile::MARKER . " from {$source}. Review every TODO(port).");
        $lines[] = ' */';
        $lines[] = "final class {$name} extends Model";
        $lines[] = '{';

        foreach ($constants as $constant => $value) {
            $lines[] = "    protected const {$constant} = " . Php::export($value) . ';';
        }

        if ($relations !== []) {
            $lines[] = '';
            $lines[] = '    public static function relations(): array';
            $lines[] = '    {';
            $lines[] = '        return [';

            foreach ($relations as $relation => $code) {
                $lines[] = '            ' . Php::export((string) $relation) . " => {$code},";
            }

            $lines[] = '        ];';
            $lines[] = '    }';
        }

        foreach ($this->todos as $todo) {
            $lines[] = '';
            $lines[] = '    // TODO(port): ' . Php::comment($todo);
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }
}
