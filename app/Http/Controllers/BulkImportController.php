<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkImport\UploadImportRequest;
use App\Models\Department;
use App\Services\BulkImportService;
use App\Services\ImportSpreadsheetTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Excel;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkImportController extends Controller
{
    public function index(Request $request, BulkImportService $imports, string $module): Response
    {
        $imports->authorize($request->user(), $module);

        return response()->view('imports.index', ['module' => $module, 'definition' => $imports::module($module),
            'departments' => $module === 'employees' ? Department::query()->active()->orderBy('name')->get(['code', 'name']) : collect(),
            'roles' => $module === 'users' ? Role::query()->where('guard_name', 'web')->whereRaw('lower(trim(name)) != ?', ['super-admin'])->orderBy('name')->pluck('name') : collect(),
        ])->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    public function template(Request $request, BulkImportService $imports, string $module): BinaryFileResponse|StreamedResponse
    {
        $imports->authorize($request->user(), $module);

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($module): void {
                $output = fopen('php://output', 'wb');
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, BulkImportService::module($module)['columns'], ',', '"', '', "\r\n");
                fclose($output);
            }, 'template-'.$module.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }

        return app(Excel::class)->download(new ImportSpreadsheetTemplate($module), 'template-'.$module.'.xlsx', Excel::XLSX,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }

    public function preview(UploadImportRequest $request, BulkImportService $imports, string $module): RedirectResponse
    {
        $id = $imports->preview($request->file('file'), $request->user(), $module);

        return redirect()->route($imports::module($module)['route'].'.import.show', ['import' => $id]);
    }

    public function show(Request $request, BulkImportService $imports, string $import, string $module): Response|RedirectResponse
    {
        $snapshot = $imports->snapshot($import, $request->user(), $module);
        if ($snapshot['state'] !== 'preview') {
            return redirect()->route($imports::module($module)['route'].'.import.result', ['import' => $import]);
        }

        return $this->report($request, 'imports.preview', $snapshot, $import, $module, $snapshot['rows']);
    }

    public function confirm(Request $request, BulkImportService $imports, string $import, string $module): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'accepted'], 'rows' => ['prohibited'], 'summary' => ['prohibited'], 'file' => ['prohibited'], 'module' => ['prohibited']]);
        $snapshot = $imports->confirm($import, $request->user(), $module);
        $summary = $snapshot['result_summary'];

        return redirect()->route($imports::module($module)['route'].'.import.result', ['import' => $import])
            ->with($summary['failed'] ? 'warning' : 'success', "Impor selesai. Created: {$summary['created']}, Skipped: {$summary['skipped']}, Failed: {$summary['failed']}.");
    }

    public function result(Request $request, BulkImportService $imports, string $import, string $module): Response
    {
        $snapshot = $imports->snapshot($import, $request->user(), $module);
        abort_if($snapshot['state'] === 'preview', 409, 'Preview belum dikonfirmasi.');

        return $this->report($request, 'imports.result', $snapshot, $import, $module, $snapshot['results'] ?? []);
    }

    public function errors(Request $request, BulkImportService $imports, string $import, string $module): StreamedResponse
    {
        $snapshot = $imports->snapshot($import, $request->user(), $module);
        $rows = $snapshot['state'] === 'preview' ? $snapshot['rows'] : ($snapshot['results'] ?? []);

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['row', 'status', 'details'], ',', '"', '', "\r\n");
            foreach ($rows as $row) {
                if ($row['errors'] !== [] || $row['warnings'] !== []) {
                    fputcsv($output, [$row['row'], $row['status'], implode(' ', [...$row['errors'], ...$row['warnings']])], ',', '"', '', "\r\n");
                }
            }
            fclose($output);
        }, 'import-'.$module.'-'.$import.'-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function report(Request $request, string $view, array $snapshot, string $id, string $module, array $rows): Response
    {
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'status' => ['nullable', 'in:create,invalid,existing,duplicate,created,skipped,failed']]);
        if (! empty($filters['status'])) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['status'] === $filters['status']));
        }
        $page = (int) ($filters['page'] ?? 1);

        return response()->view($view, ['snapshot' => $snapshot, 'module' => $module, 'definition' => BulkImportService::module($module), 'importId' => $id,
            'rows' => (new LengthAwarePaginator(array_slice($rows, ($page - 1) * 100, 100), count($rows), 100, $page,
                ['path' => $request->url(), 'query' => $request->query()]))])->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
