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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Role;
use Throwable;

class BulkImportService
{
    public const MODULES = [
        'employees' => ['title' => 'Employees', 'permission' => 'employees.manage', 'route' => 'employees',
            'columns' => ['nip', 'name', 'department_id', 'email', 'phone', 'is_active'], 'required' => ['nip', 'name', 'department_id', 'is_active']],
        'external-participants' => ['title' => 'External Participants', 'permission' => 'external-participants.manage', 'route' => 'external-participants',
            'columns' => ['name', 'email', 'phone', 'organization', 'position', 'is_active'], 'required' => ['name', 'is_active']],
        'users' => ['title' => 'Application Users', 'permission' => 'users.manage', 'route' => 'settings.users',
            'columns' => ['name', 'email', 'is_active', 'role'], 'required' => ['name', 'email', 'is_active', 'role']],
    ];

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
        $raw = $this->reader->read($file, $definition['columns'], $definition['required']);
        $rows = $this->validateRows($module, $raw);

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
            $eligible = array_column(array_filter($snapshot['rows'], fn (array $row): bool => $row['status'] === 'create'), 'row');
            $rows = $this->validateRows($module, $snapshot['raw']);
            $snapshot['state'] = 'processing';
            $snapshot['results'] = [];
            $this->store->write($id, $snapshot);
            foreach (array_chunk($rows, 100) as $batch) {
                foreach ($batch as $row) {
                    $status = match ($row['status']) { 'invalid' => 'failed', 'create' => 'created', default => 'skipped' };
                    if ($row['status'] === 'create' && !in_array($row['row'], $eligible, true)) {
                        $status = 'skipped';
                        $row['errors'][] = 'Baris ini belum disetujui sebagai data baru pada preview. Unggah ulang untuk meninjaunya.';
                    } elseif ($status === 'created') {
                        try {
                            DB::transaction(fn () => $this->persist($module, $row['data']));
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
     * Reference sets replace per-row database validators to keep queries bounded.
     *
     * @param array<int, array{row: int, data: array<string, mixed>, errors: array<int, string>}> $raw
     * @return array<int, array{row: int, data: array<string, mixed>, errors: array<int, string>, warnings: array<int, string>, status: string}>
     */
    private function validateRows(string $module, array $raw): array
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
        $messages = [...$messages, 'is_active.boolean' => 'Status aktif harus 1 atau 0.', 'role.in' => 'Role tidak ditemukan atau tidak boleh diberikan melalui impor.',
            'department_id.in' => 'Departemen tidak tersedia atau sudah tidak aktif.'];
        if ($module === 'employees') {
            $rules['nip'] = array_values(array_filter($rules['nip'], fn (mixed $rule): bool => !$rule instanceof Unique));
            $rules['department_id'] = ['required', 'integer', Rule::in(Department::query()->active()->pluck('id')->all())];
        } elseif ($module === 'users') {
            $rules['email'] = array_values(array_filter($rules['email'], fn (mixed $rule): bool => !$rule instanceof Unique));
            unset($rules['roles'], $rules['roles.*']);
            $rules['role'] = ['required', 'string', Rule::in(Role::query()->where('guard_name', 'web')->where('name', '!=', 'super-admin')->pluck('name')->all())];
        }
        $normalized = array_map(fn (array $row): array => [...$row, 'data' => $this->normalize($row['data'])], $raw);
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
                $row['data']['is_active'] = '0';
            }
            $rows[] = [...$row, 'errors' => array_values(array_unique($errors)), 'warnings' => $warnings, 'status' => $status];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
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
     * @param array<string, mixed> $data
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
     * @param array<int, array{data: array<string, mixed>}> $rows
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

    /** @param array<string, mixed> $data */
    private function persist(string $module, array $data): void
    {
        if ($module === 'users') {
            $role = Role::query()->where('guard_name', 'web')->where('name', $data['role'])->where('name', '!=', 'super-admin')->firstOrFail();
            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->is_active = false;
            $user->password = null;
            $user->save();
            $user->syncRoles([$role]);
        } elseif ($module === 'employees') {
            Employee::create($data);
        } else {
            ExternalParticipant::create($data);
        }
    }

    /**
     * @param array<int, array{status: string}> $rows
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
     * @param array<int, array{status: string}> $rows
     * @return array{created: int, skipped: int, failed: int}
     */
    private function resultSummary(array $rows): array
    {
        $counts = array_count_values(array_column($rows, 'status'));

        return ['created' => $counts['created'] ?? 0, 'skipped' => $counts['skipped'] ?? 0, 'failed' => $counts['failed'] ?? 0];
    }
}
