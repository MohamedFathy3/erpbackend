<?php

namespace App\Console\Commands;

use App\Models\BaseModel;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Throwable;

class TenantAudit extends Command
{
    protected $signature = 'tenant:audit';

    protected $description = 'Audit tenant isolation across Eloquent models';

    public function handle(): int
    {
        $this->info('==========================================');
        $this->info('       TENANT ISOLATION AUDIT');
        $this->info('==========================================');
        $this->newLine();

        $modelsPath = app_path('Models');

        if (!File::exists($modelsPath)) {
            $this->error('Models directory not found.');
            return self::FAILURE;
        }

        $files = File::allFiles($modelsPath);

        $results = [];

        foreach ($files as $file) {
            $relativePath = $file->getRelativePathname();

            if (!str_ends_with($relativePath, '.php')) {
                continue;
            }

            $class = $this->classFromPath($file->getPathname());

            if (!$class || !class_exists($class)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($class);

                if (
                    $reflection->isAbstract() ||
                    !$reflection->isSubclassOf(Model::class)
                ) {
                    continue;
                }

                if ($class === Tenant::class) {
                    continue;
                }

                $model = app($class);

                $table = $model->getTable();

                $hasTenantId = Schema::hasColumn($table, 'tenant_id');

                $extendsBaseModel = $model instanceof BaseModel;

                $globalScopes = $model->getGlobalScopes();

                $hasTenantScope = array_key_exists('tenant', $globalScopes);

                $results[] = [
                    'model' => class_basename($class),
                    'table' => $table,
                    'base_model' => $extendsBaseModel,
                    'tenant_id' => $hasTenantId,
                    'tenant_scope' => $hasTenantScope,
                    'status' => $this->calculateStatus(
                        $extendsBaseModel,
                        $hasTenantId,
                        $hasTenantScope
                    ),
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'model' => class_basename($class),
                    'table' => '?',
                    'base_model' => false,
                    'tenant_id' => false,
                    'tenant_scope' => false,
                    'status' => 'ERROR: ' . $e->getMessage(),
                ];
            }
        }

        $this->table(
            [
                'Model',
                'Table',
                'BaseModel',
                'tenant_id',
                'Tenant Scope',
                'Status',
            ],
            array_map(function ($row) {
                return [
                    $row['model'],
                    $row['table'],
                    $row['base_model'] ? 'YES' : 'NO',
                    $row['tenant_id'] ? 'YES' : 'NO',
                    $row['tenant_scope'] ? 'YES' : 'NO',
                    $row['status'],
                ];
            }, $results)
        );

        $this->newLine();

        $dangerous = collect($results)
            ->filter(fn ($row) =>
                $row['tenant_id'] === true &&
                (
                    $row['base_model'] === false ||
                    $row['tenant_scope'] === false
                )
            );

        $missingTenantColumn = collect($results)
            ->filter(fn ($row) =>
                $row['base_model'] === true &&
                $row['tenant_id'] === false
            );

        $errors = collect($results)
            ->filter(fn ($row) =>
                str_starts_with((string) $row['status'], 'ERROR:')
            );

        $this->info('==========================================');
        $this->info('AUDIT SUMMARY');
        $this->info('==========================================');

        $this->line('Models scanned: ' . count($results));

        if ($dangerous->isEmpty()) {
            $this->info('Tenant-owned models without proper scope: 0');
        } else {
            $this->error(
                'Tenant-owned models without proper scope: ' .
                $dangerous->count()
            );

            foreach ($dangerous as $row) {
                $this->error(
                    "  ❌ {$row['model']} ({$row['table']})"
                );
            }
        }

        if ($missingTenantColumn->isEmpty()) {
            $this->info('BaseModels missing tenant_id: 0');
        } else {
            $this->warn(
                'BaseModels without tenant_id: ' .
                $missingTenantColumn->count()
            );

            foreach ($missingTenantColumn as $row) {
                $this->warn(
                    "  ⚠ {$row['model']} ({$row['table']})"
                );
            }
        }

        if ($errors->isNotEmpty()) {
            $this->error(
                'Models with audit errors: ' .
                $errors->count()
            );
        }

        $this->newLine();

        if ($dangerous->isEmpty() && $errors->isEmpty()) {
            $this->info('✅ Model-level tenant isolation audit passed.');
        } else {
            $this->error('❌ Tenant isolation requires attention.');
        }

        $this->newLine();

        $this->comment(
            'NOTE: This audit checks Eloquent models only.'
        );

        $this->comment(
            'Raw DB::table(), Query Builder, relationships and'
        );

        $this->comment(
            'controller/service queries require a separate audit.'
        );

        return $dangerous->isEmpty() && $errors->isEmpty()
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function calculateStatus(
        bool $baseModel,
        bool $tenantId,
        bool $tenantScope
    ): string {
        if ($tenantId && $baseModel && $tenantScope) {
            return 'PASS';
        }

        if ($tenantId && (!$baseModel || !$tenantScope)) {
            return 'FAIL';
        }

        if ($baseModel && !$tenantId) {
            return 'REVIEW';
        }

        return 'REVIEW';
    }

    private function classFromPath(string $path): ?string
    {
        $contents = File::get($path);

        if (
            !preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch)
        ) {
            return null;
        }

        if (
            !preg_match('/class\s+([A-Za-z0-9_]+)/', $contents, $classMatch)
        ) {
            return null;
        }

        return trim($namespaceMatch[1]) . '\\' . $classMatch[1];
    }
}