<?php

namespace App\Modules\Location\Http\Controllers;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Location\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gudang pusat dan outlet (C1; dokumen 12 §4.2).
 * Membuka dan menutup outlet adalah keputusan Owner (2E-Q4); di irisan 1
 * hanya Owner yang dapat mengubah lokasi, sehingga keputusan itu langsung berlaku.
 */
class LocationController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $locations = Location::query()
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->orderByRaw("CASE WHEN type = 'WAREHOUSE' THEN 0 ELSE 1 END")
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $locations]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('locations', 'code')],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $location = DB::transaction(function () use ($data) {
            $location = Location::query()->create([
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'type' => Location::TYPE_OUTLET,
                'status' => Location::STATUS_ACTIVE,
                'opened_at' => now(),
            ]);
            $this->audit->record('OUTLET_OPENED', $location, ['code' => $location->code, 'name' => $location->name], Role::OWNER);

            return $location;
        });

        return response()->json(['data' => $location], 201);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        // Kode dan jenis tidak dapat diubah (03 §6.5); hanya nama.
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        DB::transaction(function () use ($location, $data) {
            $before = $location->name;
            $location->update(['name' => $data['name']]);
            $this->audit->record('LOCATION_RENAMED', $location, ['name' => ['dari' => $before, 'menjadi' => $location->name]], Role::OWNER);
        });

        return response()->json(['data' => $location]);
    }

    public function deactivate(Location $location): JsonResponse
    {
        $this->guardNotClosed($location);
        if (! $location->isOutlet()) {
            throw ValidationException::withMessages(['location' => 'Gudang pusat tidak dapat dinonaktifkan.']);
        }

        return $this->changeStatus($location, Location::STATUS_INACTIVE, 'OUTLET_DEACTIVATED');
    }

    public function activate(Location $location): JsonResponse
    {
        $this->guardNotClosed($location);

        return $this->changeStatus($location, Location::STATUS_ACTIVE, 'OUTLET_ACTIVATED');
    }

    public function close(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->guardNotClosed($location);
        if (! $location->isOutlet()) {
            throw ValidationException::withMessages(['location' => 'Gudang pusat tidak dapat ditutup.']);
        }

        // Pemeriksaan stok nol, dana tuntas, dan shift tertutup ditambahkan
        // bersama modul Inventory, Sales, dan Cash (03 §6.5).
        return $this->changeStatus($location, Location::STATUS_CLOSED, 'OUTLET_CLOSED', $data['reason']);
    }

    private function guardNotClosed(Location $location): void
    {
        if ($location->status === Location::STATUS_CLOSED) {
            throw ValidationException::withMessages(['location' => 'Outlet ini sudah ditutup permanen.']);
        }
    }

    private function changeStatus(Location $location, string $status, string $action, ?string $reason = null): JsonResponse
    {
        DB::transaction(function () use ($location, $status, $action, $reason) {
            $before = $location->status;
            $location->update([
                'status' => $status,
                'closed_at' => $status === Location::STATUS_CLOSED ? now() : $location->closed_at,
            ]);
            $this->audit->record($action, $location, ['status' => ['dari' => $before, 'menjadi' => $status]], Role::OWNER, $reason);
        });

        return response()->json(['data' => $location]);
    }
}
