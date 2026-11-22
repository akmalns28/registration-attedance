<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Division;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use RuntimeException;

class OrganizationImportService
{
    private const LEVELS = ['entity' => 'Entitas', 'department' => 'Departemen', 'division' => 'Divisi'];

    /**
     * @param  list<array{row: int, data: array<string, ?string>, errors: list<string>}>  $raw
     * @return array{rows: list<array>, nodes: array<string, array>, fingerprint: string}
     */
    public function plan(array $raw, User $user, bool $lock = false): array
    {
        $rows = [];
        $nodes = [];
        foreach ($raw as $row) {
            $number = $row['row'];
            $rows[$number] = [...$row, 'input' => [], 'display' => [], 'warnings' => [], 'status' => 'existing'];
            $parent = '';
            foreach (self::LEVELS as $level => $label) {
                $code = Entity::normalizeCode($row['data'][$level.'_code'] ?? null);
                $name = Str::trim($row['data'][$level.'_name'] ?? '');
                $statusInput = Str::squish($row['data'][$level.'_status'] ?? '');
                if ($level !== 'entity' && $code === null && $name === '' && $statusInput === '') {
                    $parent = null;

                    continue;
                }
                $status = BulkImportService::booleanStatus($statusInput);
                if ($parent === null || $code === null || ! preg_match('/\A[A-Z0-9_-]{1,20}\z/', $code)
                    || $name === '' || mb_strlen($name) > 150 || $status === null) {
                    $rows[$number]['errors'][] = $parent === null
                        ? "{$label} memerlukan induk yang valid."
                        : "{$label} memerlukan kode yang valid (maksimal 20 karakter), nama, dan status yang dikenali.";
                    $parent = null;

                    continue;
                }

                $key = $parent.'/'.$code;
                $attributes = ['code' => $code, 'name' => $name, 'is_active' => $status];
                $nodes[$key] ??= ['key' => $key, 'parent' => $parent, 'level' => $level, 'attributes' => $attributes, 'rows' => [], 'errors' => []];
                $nodes[$key]['rows'][] = $number;
                if ($nodes[$key]['attributes'] !== $attributes) {
                    $nodes[$key]['errors'][] = "{$label} {$code} memiliki nama atau status yang berbeda antarbaris.";
                }
                $rows[$number]['display'][$level.'_code'] = $code;
                $rows[$number]['display'][$level.'_name'] = $name;
                $rows[$number]['display'][$level.'_status'] = $status ? 'Aktif' : 'Tidak Aktif';
                $parent = $key;
            }
        }

        ksort($nodes, SORT_STRING);
        $scopes = [];
        $names = [];
        $canCreate = $user->can('departments.create');
        $canUpdate = $user->can('departments.update');
        foreach ($nodes as $key => &$node) {
            $attributes = $node['attributes'];
            $parentKey = $node['parent'];
            $label = self::LEVELS[$node['level']];
            $code = $attributes['code'];
            $name = $this->normalizeName($attributes['name']);
            if (isset($names[$parentKey][$name]) && $names[$parentKey][$name] !== $key) {
                $message = "{$label} {$attributes['name']} menggunakan lebih dari satu kode dalam lingkup yang sama.";
                $node['errors'][] = $message;
                $nodes[$names[$parentKey][$name]]['errors'][] = $message;
            }
            $names[$parentKey][$name] ??= $key;

            $scope = $scopes[$parentKey] ??= $this->scope($node['level'], $nodes[$parentKey]['record'] ?? null, $lock);
            $matches = $scope['codes'][$code] ?? [];
            $record = $matches[0] ?? null;
            $changes = ! $record || $record->only(array_keys($attributes)) !== $attributes;
            $node['record'] = $record;
            $node['changes'] = $changes;
            if (count($matches) > 1) {
                $node['errors'][] = "{$label} {$code} cocok dengan lebih dari satu data tersimpan.";
            }
            foreach ($scope['names'][$name] ?? [] as $existingCode) {
                if ($existingCode !== $code) {
                    $node['errors'][] = "Nama {$label} {$attributes['name']} sudah digunakan oleh kode lain dalam lingkup yang sama.";
                    break;
                }
            }
            if ((! $record && ! $canCreate) || ($record && $changes && ! $canUpdate)) {
                $node['errors'][] = "Anda tidak memiliki izin untuk menyimpan perubahan {$label} {$code}.";
            }
            if ($record?->is_active && ! $attributes['is_active'] && $record->employees_exists) {
                $node['errors'][] = "{$label} {$code} masih digunakan karyawan dan tidak dapat dinonaktifkan melalui impor.";
            }
        }
        unset($node);

        foreach ($nodes as $node) {
            foreach ($node['rows'] as $number) {
                $rows[$number]['errors'] = [...$rows[$number]['errors'], ...$node['errors']];
                if ($node['changes']) {
                    $rows[$number]['status'] = 'create';
                }
            }
        }
        foreach ($rows as &$row) {
            if ($row['errors'] !== []) {
                $row['status'] = 'invalid';
                $row['errors'] = array_values(array_unique($row['errors']));
            }
        }
        unset($row);

        $bindings = array_map(fn (array $node): ?array => $node['record']?->only(['id', 'code', 'name', 'is_active']), $nodes);

        return ['rows' => array_values($rows), 'nodes' => $nodes,
            'fingerprint' => hash('sha256', json_encode($bindings, JSON_THROW_ON_ERROR))];
    }

    /** @param array<string, array{level: string, parent: string, record: Entity|Department|Division|null, changes: bool, attributes: array}> $nodes */
    public function persist(array $nodes): void
    {
        $saved = [];
        foreach ($nodes as $key => $node) {
            $record = $node['record'] ?? match ($node['level']) {
                'entity' => new Entity,
                'department' => $saved[$node['parent']]->departments()->make(),
                'division' => $saved[$node['parent']]->divisions()->make(),
            };
            if ($node['changes'] && ! $record->fill($node['attributes'])->save()) {
                throw new RuntimeException('Organization import save was interrupted.');
            }
            $saved[$key] = $record;
        }
    }

    /** @return array{codes: array<string, list<Entity|Department|Division>>, names: array<string, list<?string>>} */
    private function scope(string $level, Entity|Department|null $parent, bool $lock): array
    {
        $query = match ($level) {
            'entity' => Entity::query(),
            'department' => $parent?->departments()->getQuery(),
            'division' => $parent?->divisions()->getQuery(),
        };
        $index = ['codes' => [], 'names' => []];
        foreach ($query?->withExists('employees')->orderBy('id')->when($lock, fn (Builder $query) => $query->lockForUpdate())->get() ?? [] as $record) {
            $code = Entity::normalizeCode($record->code);
            if ($code !== null) {
                $index['codes'][$code][] = $record;
            }
            $index['names'][$this->normalizeName($record->name)][] = $code;
        }

        return $index;
    }

    private function normalizeName(string $name): string
    {
        return Str::lower(Str::squish($name));
    }
}
