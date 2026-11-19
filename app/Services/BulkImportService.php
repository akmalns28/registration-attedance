<?php

namespace App\Services;

use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\ExternalParticipant\StoreExternalParticipantRequest;
use App\Http\Requests\Settings\StoreUserRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExternalParticipant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Role;
use Throwable;

class BulkImportService
{
    public const MODULES = [
        'employees' => ['title' => 'Employees', 'permission' => 'employees.manage', 'route' => 'employees',
            'columns' => ['nip', 'name', 'department', 'email', 'phone', 'status'], 'required' => ['nip', 'name', 'department', 'status']],
        'external-participants' => ['title' => 'External Participants', 'permission' => 'external-participants.manage', 'route' => 'external-participants',
            'columns' => ['name', 'email', 'phone', 'organization', 'position', 'status'], 'required' => ['name', 'status']],
        'users' => ['title' => 'Application Users', 'permission' => 'users.manage', 'route' => 'settings.users',
            'columns' => ['name', 'email', 'status', 'role'], 'required' => ['name', 'email', 'status', 'role']],
    ];

    public const LEGACY_HEADERS = ['department_id' => 'department', 'is_active' => 'status'];

    public function __construct(private BulkImportStore $store, private ImportSpreadsheetReader $reader) {}

    /** @return array{title: string, permission: string, route: string, columns: array<int, string>, required: array<int, string>} */
    public static function module(string $module): array
    {
        abort_unless(isset(self::MODULES[$module]), 404);

        return self::MODULES[$module];
    }

    public function authorize(User $user, string $module): void
    {
        $user->refresh()->unsetRelation('roles')->unsetRelation('permissions');
        abort_unless($user->is_active && $user->can(self::module($module)['permission']), 403);
    }

    public function preview(UploadedFile $file, User $user, string $module): string
    {
        $this->authorize($user, $module);
        $definition = self::module($module);
        $raw = $this->reader->read($file, $definition['columns'], $definition['required'], self::LEGACY_HEADERS);
        $rows = $this->validateRows($module, $raw, $this->references($module));

        return $this->store->create($user, $module, ['raw' => $raw, 'rows' => $rows, 'summary' => $this->summary($rows)]);
    }

    /** @return array<string, mixed> */
    public function snapshot(string $id, User $user, string $module): array
    {
        $this->authorize($user, $module);

        return $this->store->read($id, $user, $module);
    }

    /** @return array<string, mixed> */
    public function confirm(string $id, User $user, string $module): array
    {
        $this->authorize($user, $module);

        return $this->store->locked(function () use ($id, $user, $module): array {
            $snapshot = $this->store->read($id, $user, $module);
            if ($snapshot['state'] === 'completed') {
                return $snapshot;
            }
            abort_if($snapshot['state'] !== 'preview', 409, 'Impor sedang diproses atau terhenti. Periksa hasil sebelum mengunggah ulang.');
            abort_unless($snapshot['summary']['create'] > 0, 422, 'Tidak ada baris valid untuk diimpor.');
            $eligible = array_column(array_filter($snapshot['rows'], fn (array $row): bool => $row['status'] === 'create'), 'data', 'row');
            $references = $this->references($module);
            $rows = $this->validateRows($module, $snapshot['raw'], $references);
            $snapshot['state'] = 'processing';
            $snapshot['results'] = [];
            $this->store->write($id, $snapshot);
            foreach (array_chunk($rows, 100) as $batch) {
                foreach ($batch as $row) {
                    $status = match ($row['status']) {
                        'invalid' => 'failed', 'create' => 'created', default => 'skipped'
                    };
                    $approved = $eligible[$row['row']] ?? null;
                    if ($status === 'created' && $approved === null) {
                        $status = 'skipped';
                        $row['errors'][] = 'Baris ini belum disetujui sebagai data baru pada preview. Unggah ulang untuk meninjaunya.';
                    } elseif ($status === 'created' && ! $this->referenceStillApproved($module, $approved, $row['data'], $references)) {
                        $status = 'failed';
                        $row['errors'][] = 'Referensi berubah sejak preview. Unggah ulang untuk meninjau referensi terbaru.';
                    } elseif ($status === 'created') {
                        try {
                            DB::transaction(fn () => $this->persist($module, $row['data'], $references));
                        } catch (Throwable $exception) {
                            report($exception);
                            $status = 'failed';
                            $row['errors'][] = 'Data tidak tersimpan. Kemungkinan ada konflik data terbaru; periksa daftar dan unggah ulang baris ini.';
                        }
                    }
                    $snapshot['results'][] = [...$row, 'status' => $status];
                }
                $snapshot['result_summary'] = $this->resultSummary($snapshot['results']);
                $this->store->write($id, $snapshot);
            }
            $snapshot['state'] = 'completed';
            unset($snapshot['raw']);
            $this->store->write($id, $snapshot);

            return $snapshot;
        });
    }

    /**
     * Existing FormRequest rules remain the source of field validation.
     * Reference lookups replace per-row database validators to keep queries bounded.
     *
     * @param  array<int, array{row: int, data: array<string, mixed>, errors: array<int, string>}>  $raw
     * @param  Collection<int, Department|Role>  $references
     * @return array<int, array{row: int, input: array<string, mixed>, data: array<string, mixed>, display: array<string, string>, errors: array<int, string>, warnings: array<int, string>, status: string}>
     */
    private function validateRows(string $module, array $raw, Collection $references): array
    {
        $rules = match ($module) {
            'employees' => (new StoreEmployeeRequest)->rules(),
            'external-participants' => (new StoreExternalParticipantRequest)->rules(),
            'users' => (new StoreUserRequest)->rules(),
        };
        $messages = match ($module) {
            'employees' => (new StoreEmployeeRequest)->messages(),
            'external-participants' => (new StoreExternalParticipantRequest)->messages(),
            default => [],
        };
        $messages['is_active.required'] ??= 'Status wajib diisi.';
        $messages['is_active.boolean'] = 'Status tidak dikenal. Gunakan Aktif, Tidak Aktif, Active, Inactive, Yes, No, True, False, 1, atau 0.';
        if ($module === 'employees') {
            $rules['nip'] = array_values(array_filter($rules['nip'], fn (mixed $rule): bool => ! $rule instanceof Unique));
            unset($rules['department_id']);
        } elseif ($module === 'users') {
            $rules['email'] = array_values(array_filter($rules['email'], fn (mixed $rule): bool => ! $rule instanceof Unique));
            unset($rules['roles'], $rules['roles.*']);
            $messages['name.required'] = 'Nama pengguna wajib diisi.';
            $messages['email.required'] = 'Email pengguna wajib diisi.';
            $messages['email.email'] = 'Format email tidak valid.';
        }
        $referenceField = match ($module) {
            'employees' => 'department',
            'users' => 'role',
            default => null,
        };
        $aliases = $this->referenceAliases($references);
        $normalized = array_map(fn (array $row): array => $this->prepareRow($row, $referenceField, $references, $aliases), $raw);
        $counts = [];
        foreach ($normalized as $row) {
            foreach ($this->keys($module, $row['data']) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        $existing = $this->existing($module, $normalized);
        $rows = [];
        foreach ($normalized as $row) {
            $errors = [...$row['errors'], ...Validator::make($row['data'], $rules, $messages)->errors()->all()];
            if ($module === 'external-participants' && blank($row['data']['email']) && blank($row['data']['phone'])) {
                $errors[] = 'Isi email atau telepon untuk pemeriksaan duplikasi. Peserta tanpa keduanya perlu ditambahkan melalui form manual.';
            }
            $keys = $this->keys($module, $row['data']);
            $duplicate = array_any($keys, fn (string $key): bool => ($counts[$key] ?? 0) > 1);
            $exists = array_any($keys, fn (string $key): bool => isset($existing[$key]));
            $status = $errors !== [] ? 'invalid' : ($duplicate ? 'duplicate' : ($exists ? 'existing' : 'create'));
            if ($status === 'duplicate') {
                $errors[] = 'Identitas berulang di dalam berkas. Semua baris dengan identitas yang sama dilewati; perbaiki berkas sebelum mengimpor.';
            } elseif ($status === 'existing') {
                $errors[] = $module === 'external-participants' ? 'Ada peserta dengan kontak atau nama/organisasi yang sama. Ditandai untuk pemeriksaan dan dilewati tanpa mengubah data.' : 'Identitas sudah ada. Data existing dilewati dan tidak diubah.';
            }
            $warnings = [];
            if ($module === 'users') {
                $warnings[] = 'Akun lokal akan tetap nonaktif. Identitas Keycloak belum diverifikasi; aktivasi dilakukan administrator setelah verifikasi.';
                $row['data']['is_active'] = false;
            }
            $rows[] = [...$row, 'errors' => array_values(array_unique($errors)), 'warnings' => $warnings, 'status' => $status];
        }

        return $rows;
    }

    /**
     * @param  array{row: int, data: array<string, mixed>, errors: array<int, string>}  $row
     * @param  Collection<int, Department|Role>  $references
     * @param  array<string, array<int, Department|Role>>  $aliases
     * @return array{row: int, input: array<string, mixed>, data: array<string, mixed>, display: array<string, string>, errors: array<int, string>}
     */
    private function prepareRow(array $row, ?string $referenceField, Collection $references, array $aliases): array
    {
        $input = $row['data'];
        foreach (self::LEGACY_HEADERS as $legacy => $field) {
            if (array_key_exists($legacy, $input)) {
                $input[$field] = $input[$legacy];
                unset($input[$legacy]);
            }
        }

        $data = $this->normalize($input);
        $display = [];
        $errors = $row['errors'];
        $status = $this->booleanStatus($data['status']);
        $data['is_active'] = $status ?? $data['status'];
        unset($data['status']);
        if ($status !== null) {
            $display['status'] = $status ? 'Aktif' : 'Tidak Aktif';
        }

        if ($referenceField !== null) {
            $label = $referenceField === 'department' ? 'Departemen' : 'Role';
            $reference = $this->resolveReference($input[$referenceField], $label, $references, $aliases);
            unset($data[$referenceField]);
            if (is_string($reference)) {
                $errors[] = $reference;
            } else {
                $data[$referenceField.'_id'] = $reference->getKey();
                $display[$referenceField] = $reference instanceof Department ? $reference->code.' — '.$reference->name : $reference->name;
            }
        }

        $originalValues = array_filter($input, fn (?string $value, string $field): bool => $value !== ($data[$field] ?? null), ARRAY_FILTER_USE_BOTH);

        return ['row' => $row['row'], 'input' => $originalValues, 'data' => $data, 'display' => $display, 'errors' => $errors];
    }

    private function booleanStatus(?string $value): ?bool
    {
        return match (Str::lower($value ?? '')) {
            'active', 'aktif', 'yes', 'true', '1' => true,
            'inactive', 'tidak aktif', 'no', 'false', '0' => false,
            default => null,
        };
    }

    /** @return Collection<int, Department|Role> */
    private function references(string $module): Collection
    {
        return match ($module) {
            'employees' => Department::query()->get(['id', 'code', 'name', 'is_active'])->keyBy('id'),
            'users' => Role::query()->where('guard_name', 'web')->get()->keyBy('id'),
            default => collect(),
        };
    }

    /**
     * @param  Collection<int, Department|Role>  $references
     * @return array<string, array<int, Department|Role>>
     */
    private function referenceAliases(Collection $references): array
    {
        $aliases = [];
        foreach ($references as $reference) {
            $values = $reference instanceof Department ? [$reference->code, $reference->name] : [$reference->name];
            foreach ($values as $value) {
                $aliases[Str::lower(Str::trim($value))][$reference->getKey()] = $reference;
            }
        }

        return $aliases;
    }

    /**
     * @param  Collection<int, Department|Role>  $references
     * @param  array<string, array<int, Department|Role>>  $aliases
     */
    private function resolveReference(?string $input, string $label, Collection $references, array $aliases): Department|Role|string
    {
        $key = Str::lower(Str::trim($input ?? ''));
        if ($key === '') {
            return "{$label} wajib diisi.";
        }

        $matches = $aliases[$key] ?? [];
        $byId = ctype_digit($key) ? $references->get(ltrim($key, '0') ?: '0') : null;
        if ($byId !== null) {
            $matches[$byId->getKey()] = $byId;
        }

        if (count($matches) > 1) {
            return "{$label} memiliki lebih dari satu kecocokan. Gunakan referensi yang unik.";
        }

        $reference = reset($matches);

        return match (true) {
            $reference === false => "{$label} tidak tersedia. Gunakan referensi dari daftar pada halaman impor.",
            $reference instanceof Department && ! $reference->is_active => 'Departemen sudah tidak aktif.',
            $reference instanceof Role && Str::lower(Str::trim($reference->name)) === 'super-admin' => 'Role super-admin tidak boleh diberikan melalui impor.',
            default => $reference,
        };
    }

    /**
     * @param  array<string, mixed>  $approved
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Department|Role>  $references
     */
    private function referenceStillApproved(string $module, array $approved, array $data, Collection $references): bool
    {
        return match ($module) {
            'employees' => (string) $approved['department_id'] === (string) $data['department_id'],
            'users' => isset($approved['role_id'])
                ? $approved['role_id'] === $data['role_id']
                : $approved['role'] === $references[$data['role_id']]->name,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach ($data as $field => $value) {
            $data[$field] = $value === null ? null : Str::squish((string) $value);
        }
        if (array_key_exists('nip', $data)) {
            $data['nip'] = Str::upper($data['nip'] ?? '');
        }
        $data['email'] = filled($data['email'] ?? null) ? Str::lower($data['email']) : null;
        if (array_key_exists('phone', $data)) {
            $data['phone'] = filled($data['phone']) ? preg_replace('/[\s().-]+/u', '', $data['phone']) : null;
        }
        foreach (['organization', 'position'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function keys(string $module, array $data): array
    {
        if ($module === 'employees') {
            return filled($data['nip']) ? ['nip:'.Str::upper($data['nip'])] : [];
        }
        if ($module === 'users') {
            return filled($data['email']) ? ['email:'.Str::lower($data['email'])] : [];
        }
        $keys = [];
        foreach (['email', 'phone'] as $field) {
            if (filled($data[$field] ?? null)) {
                $keys[] = $field.':'.Str::lower($data[$field]);
            }
        }
        if (filled($data['name'] ?? null)) {
            $keys[] = 'person:'.Str::lower(str_replace(' ', '', $data['name'])).'|'.Str::lower(str_replace(' ', '', $data['organization'] ?? ''));
        }

        return $keys;
    }

    /**
     * @param  array<int, array{data: array<string, mixed>}>  $rows
     * @return array<string, true>
     */
    private function existing(string $module, array $rows): array
    {
        $found = [];
        foreach (array_chunk($rows, 200) as $batch) {
            if ($module === 'employees') {
                $records = Employee::query()->whereIn(DB::raw('upper(nip)'), array_column(array_column($batch, 'data'), 'nip'))->get(['nip']);
            } elseif ($module === 'users') {
                $records = User::query()->whereIn(DB::raw('lower(email)'), array_column(array_column($batch, 'data'), 'email'))->get(['email']);
            } else {
                $data = array_column($batch, 'data');
                $phones = array_values(array_filter(array_column($data, 'phone'), fn (mixed $phone): bool => filled($phone)));
                $names = array_map(fn (array $row): string => Str::lower(str_replace(' ', '', $row['name'] ?? '')), $data);
                $phoneExpression = "replace(replace(replace(replace(replace(phone, ' ', ''), '-', ''), '.', ''), '(', ''), ')', '')";
                $records = ExternalParticipant::query()->where(function ($query) use ($data, $phones, $names, $phoneExpression): void {
                    $query->whereIn(DB::raw('lower(email)'), array_column($data, 'email'))
                        ->orWhereIn(DB::raw($phoneExpression), $phones)
                        ->orWhereIn(DB::raw("lower(replace(name, ' ', ''))"), $names);
                })->lazy(200);
            }
            foreach ($records as $record) {
                foreach ($this->keys($module, $this->normalize($record->getAttributes())) as $key) {
                    $found[$key] = true;
                }
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Department|Role>  $references
     */
    private function persist(string $module, array $data, Collection $references): void
    {
        if ($module === 'users') {
            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->is_active = false;
            $user->password = null;
            $user->save();
            $user->syncRoles([$references[$data['role_id']]]);
        } elseif ($module === 'employees') {
            Employee::create($data);
        } else {
            ExternalParticipant::create($data);
        }
    }

    /**
     * @param  array<int, array{status: string}>  $rows
     * @return array{total: int, valid: int, invalid: int, existing: int, duplicate: int, create: int, skip: int}
     */
    private function summary(array $rows): array
    {
        $counts = array_count_values(array_column($rows, 'status'));

        return ['total' => count($rows), 'valid' => ($counts['create'] ?? 0) + ($counts['existing'] ?? 0),
            'invalid' => $counts['invalid'] ?? 0, 'existing' => $counts['existing'] ?? 0, 'duplicate' => $counts['duplicate'] ?? 0,
            'create' => $counts['create'] ?? 0, 'skip' => count($rows) - ($counts['create'] ?? 0)];
    }

    /**
     * @param  array<int, array{status: string}>  $rows
     * @return array{created: int, skipped: int, failed: int}
     */
    private function resultSummary(array $rows): array
    {
        $counts = array_count_values(array_column($rows, 'status'));

        return ['created' => $counts['created'] ?? 0, 'skipped' => $counts['skipped'] ?? 0, 'failed' => $counts['failed'] ?? 0];
    }
}
