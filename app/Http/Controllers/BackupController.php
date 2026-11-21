<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    /**
     * Download all tenant-owned database rows as a streamed JSON backup.
     *
     * Only tables that have a tenant_id column are exported. This is
     * deliberate: tables without that boundary cannot safely be attributed
     * to one workspace from a tenant request.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && method_exists($user, 'hasPermission') && $user->hasPermission('backup.export'), 403, 'Backup export permission is required.');

        $tenantId = app()->bound('currentTenantId')
            ? (int) app('currentTenantId')
            : (int) ($user->tenant_id ?? 0);
        abort_unless($tenantId > 0, 403, 'A tenant workspace is required for backup export.');

        $tables = collect(Schema::getTables())
            ->map(function (array $table): array {
                $name = (string) ($table['name'] ?? '');
                return ['name' => $name, 'columns' => $name !== '' ? Schema::getColumnListing($name) : []];
            })
            ->filter(fn (array $table): bool => $table['name'] !== '')
            ->reject(fn (array $table): bool => in_array($table['name'], [
                'cache',
                'cache_locks',
                'failed_jobs',
                'job_batches',
                'jobs',
                'migrations',
                'password_reset_tokens',
                'personal_access_tokens',
                'sessions',
            ], true))
            ->filter(fn (array $table): bool => in_array('tenant_id', $table['columns'], true))
            ->values();

        $filename = sprintf('erp-backup-tenant-%d-%s.json', $tenantId, now()->format('Y-m-d-His'));

        return response()->streamDownload(function () use ($tables, $tenantId): void {
            $firstTable = true;
            $sensitiveColumns = [
                'password',
                'remember_token',
                'api_token',
                'access_token',
                'refresh_token',
                'client_secret',
            ];

            echo '{';
            echo '"format":"erp-backup"';
            echo ',"version":"1.0"';
            echo ',"exported_at":' . json_encode(now()->toISOString(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo ',"tenant_id":' . $tenantId;
            echo ',"tables":{';

            foreach ($tables as $tableDefinition) {
                $table = $tableDefinition['name'];
                if (!$firstTable) echo ',';
                $firstTable = false;
                echo json_encode($table, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ':[';

                $firstRow = true;
                $query = DB::table($table)->where('tenant_id', $tenantId);
                if (in_array('id', $tableDefinition['columns'], true)) {
                    $query->orderBy('id');
                }

                $query->chunk(500, function ($rows) use (&$firstRow, $sensitiveColumns): void {
                        foreach ($rows as $row) {
                            $record = (array) $row;
                            foreach ($sensitiveColumns as $column) {
                                unset($record[$column]);
                            }

                            if (!$firstRow) echo ',';
                            $firstRow = false;
                            echo json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                        }
                    });

                echo ']';
            }

            echo '}}';
        }, $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Backup-Tables' => (string) $tables->count(),
        ]);
    }
}
