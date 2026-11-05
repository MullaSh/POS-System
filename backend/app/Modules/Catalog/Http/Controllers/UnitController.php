<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UnitController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Unit::query()->orderBy('code')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[a-z0-9_-]+$/i', Rule::unique('units', 'code')],
            'name' => ['required', 'string', 'max:50'],
        ]);

        $unit = DB::transaction(function () use ($data) {
            $unit = Unit::query()->create(['code' => strtolower($data['code']), 'name' => $data['name']]);
            $this->audit->record('UNIT_CREATED', $unit, ['code' => $unit->code, 'name' => $unit->name], Role::OWNER);

            return $unit;
        });

        return response()->json(['data' => $unit], 201);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        // Kode satuan tetap; hanya nama tampilan yang dapat diubah.
        $data = $request->validate(['name' => ['required', 'string', 'max:50']]);

        DB::transaction(function () use ($unit, $data) {
            $before = $unit->name;
            $unit->update(['name' => $data['name']]);
            $this->audit->record('UNIT_RENAMED', $unit, ['name' => ['dari' => $before, 'menjadi' => $unit->name]], Role::OWNER);
        });

        return response()->json(['data' => $unit]);
    }
}
