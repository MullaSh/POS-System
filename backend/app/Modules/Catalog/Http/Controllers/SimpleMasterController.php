<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Data master sederhana berisi kode tetap + nama + status aktif.
 * Dikelola Owner (dokumen 12 §9 Q4; G-07). Tidak dihapus, hanya dinonaktifkan (P9).
 */
abstract class SimpleMasterController
{
    /** @var class-string<Model> */
    protected string $model;

    /** Awalan tindakan audit, mis. PAYMENT_METHOD. */
    protected string $auditPrefix;

    public function __construct(protected readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->model::query()->orderByDesc('is_active')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
        $table = (new $this->model)->getTable();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique($table, 'code')],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $record = DB::transaction(function () use ($data) {
            $record = $this->model::query()->create($data);
            $this->audit->record("{$this->auditPrefix}_CREATED", $record, $data, Role::OWNER);

            return $record;
        });

        return response()->json(['data' => $record->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $record = $this->model::query()->findOrFail($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        DB::transaction(function () use ($record, $data) {
            $before = $record->getAttribute('name');
            $record->update($data);
            if ($before !== $record->getAttribute('name')) {
                $this->audit->record("{$this->auditPrefix}_UPDATED", $record, ['name' => ['dari' => $before, 'menjadi' => $data['name']]], Role::OWNER);
            }
        });

        return response()->json(['data' => $record->fresh()]);
    }

    public function deactivate(int $id): JsonResponse
    {
        return $this->setActive($id, false);
    }

    public function activate(int $id): JsonResponse
    {
        return $this->setActive($id, true);
    }

    /** Tolak penonaktifan tertentu; kembalikan pesan bila ditolak. */
    protected function blockDeactivation(Model $record): ?string
    {
        return null;
    }

    private function setActive(int $id, bool $active): JsonResponse
    {
        $record = $this->model::query()->findOrFail($id);
        if (! $active && ($message = $this->blockDeactivation($record))) {
            return response()->json(['message' => $message, 'errors' => ['id' => [$message]]], 422);
        }

        DB::transaction(function () use ($record, $active) {
            $record->update(['is_active' => $active]);
            $this->audit->record($this->auditPrefix.($active ? '_ACTIVATED' : '_DEACTIVATED'), $record, actingRole: Role::OWNER);
        });

        return response()->json(['data' => $record->fresh()]);
    }
}
