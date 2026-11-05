<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Supplier;
use App\Modules\Identity\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Data supplier, dikelola Finance (AB-17); Owner melihat. */
class SupplierController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%")))
            ->when($request->query('active') !== null, fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name');

        return response()->json($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/', Rule::unique('suppliers', 'code')],
            ...$this->rules(),
        ]);

        $supplier = DB::transaction(function () use ($data) {
            $supplier = Supplier::query()->create($data);
            $this->audit->record('SUPPLIER_CREATED', $supplier, $supplier->only(['code', ...Supplier::FIELDS]), Role::FINANCE);

            return $supplier;
        });

        return response()->json(['data' => $supplier->fresh()], 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        // Kode supplier tetap; data lain dapat diperbarui.
        $data = $request->validate($this->rules());

        DB::transaction(function () use ($supplier, $data) {
            $before = $supplier->only(Supplier::FIELDS);
            $supplier->update($data);
            $changes = [];
            foreach (Supplier::FIELDS as $field) {
                if (($before[$field] ?? null) != ($supplier->{$field} ?? null)) {
                    $changes[$field] = ['dari' => $before[$field], 'menjadi' => $supplier->{$field}];
                }
            }
            if ($changes) {
                $this->audit->record('SUPPLIER_UPDATED', $supplier, $changes, Role::FINANCE);
            }
        });

        return response()->json(['data' => $supplier->fresh()]);
    }

    public function deactivate(Supplier $supplier): JsonResponse
    {
        return $this->setActive($supplier, false);
    }

    public function activate(Supplier $supplier): JsonResponse
    {
        return $this->setActive($supplier, true);
    }

    private function setActive(Supplier $supplier, bool $active): JsonResponse
    {
        DB::transaction(function () use ($supplier, $active) {
            $supplier->update(['is_active' => $active]);
            $this->audit->record($active ? 'SUPPLIER_ACTIVATED' : 'SUPPLIER_DEACTIVATED', $supplier, actingRole: Role::FINANCE);
        });

        return response()->json(['data' => $supplier->fresh()]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'address' => ['nullable', 'string', 'max:1000'],
            'payment_term_days' => ['required', 'integer', 'min:0', 'max:365'],
            'bank_account' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
