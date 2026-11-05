<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Location\Models\Location;
use App\Support\DocumentNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock Request (C8, KP-08, KP-09, AB-12): dibuat kasir untuk outlet yang ditugaskan,
 * dipenuhi gudang lewat satu pengiriman, atau ditolak gudang dengan alasan.
 * Status: SUBMITTED → IN_TRANSIT (dikirim) → COMPLETED (diterima outlet);
 * SUBMITTED → REJECTED (gudang) atau CANCELLED (pembuat).
 */
class StockRequestService
{
    public function __construct(
        private readonly Quantities $quantities,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'needed_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...Quantities::lineRules(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $user): StockRequest
    {
        $outlet = Location::query()->findOrFail($data['location_id']);
        if (! $outlet->isOutlet()) {
            throw ValidationException::withMessages(['location_id' => 'Permintaan stok dibuat untuk outlet.']);
        }
        if (! $user->hasRoleAt(Role::CASHIER, $outlet->id)) {
            throw new AuthorizationException('Permintaan stok dibuat oleh kasir outlet ini.');
        }
        Quantities::ensureUsable($outlet);

        return DB::transaction(function () use ($data, $user, $outlet) {
            $items = $this->quantities->stockItems($data['lines']);
            $request = StockRequest::query()->create([
                'number' => DocumentNumber::next('SR'),
                'location_id' => $outlet->id,
                'status' => StockRequest::SUBMITTED,
                'needed_date' => $data['needed_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'requested_by' => $user->id,
            ]);
            $summary = [];
            foreach ($data['lines'] as $i => $line) {
                $item = $items[$line['item_id']];
                $factor = $this->quantities->factor($item, (int) $line['unit_id'], "lines.{$i}.unit_id");
                $qty = $this->quantities->toBase((string) $line['qty'], $factor);
                $request->lines()->create([
                    'item_id' => $item->id, 'qty' => $qty, 'unit_id' => (int) $line['unit_id'], 'factor' => $factor, 'position' => $i,
                ]);
                $summary[] = ['barang' => $item->code, 'jumlah' => $qty];
            }
            $this->audit->record('STOCK_REQUEST_CREATED', $request, ['outlet' => $outlet->code, 'baris' => $summary],
                Role::CASHIER, $data['notes'] ?? null, locationId: $outlet->id, user: $user, subjectCode: $request->number);

            return $request;
        });
    }

    public function cancel(StockRequest $request, User $user, ?string $note): StockRequest
    {
        return DB::transaction(function () use ($request, $user, $note) {
            $request = $this->lock($request);
            if ($request->requested_by !== $user->id) {
                throw new AuthorizationException('Hanya pembuat permintaan yang dapat membatalkannya.');
            }
            $this->ensureSubmitted($request, 'dibatalkan');
            $request->update(['status' => StockRequest::CANCELLED, 'closed_by' => $user->id, 'closed_at' => now(), 'close_note' => $note]);
            $this->audit->record('STOCK_REQUEST_CANCELLED', $request, null, Role::CASHIER, $note,
                locationId: $request->location_id, user: $user, subjectCode: $request->number);

            return $request;
        });
    }

    public function reject(StockRequest $request, User $user, string $note): StockRequest
    {
        $warehouse = Location::query()->where('type', Location::TYPE_WAREHOUSE)->firstOrFail();
        if (! $user->hasRoleAt(Role::WAREHOUSE, $warehouse->id)) {
            throw new AuthorizationException('Permintaan stok diputuskan oleh staf gudang.');
        }

        return DB::transaction(function () use ($request, $user, $note, $warehouse) {
            $request = $this->lock($request);
            $this->ensureSubmitted($request, 'ditolak');
            $request->update(['status' => StockRequest::REJECTED, 'closed_by' => $user->id, 'closed_at' => now(), 'close_note' => $note]);
            $this->audit->record('STOCK_REQUEST_REJECTED', $request, null, Role::WAREHOUSE, $note,
                locationId: $warehouse->id, user: $user, subjectCode: $request->number,
                related: ['outlet_id' => $request->location_id]);

            return $request;
        });
    }

    /** Dipanggil modul transfer, di dalam transaksinya. */
    public function markStatus(StockRequest $request, string $status): void
    {
        $request->update(['status' => $status]);
    }

    public function lock(StockRequest $request): StockRequest
    {
        return StockRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    private function ensureSubmitted(StockRequest $request, string $verb): void
    {
        if ($request->status !== StockRequest::SUBMITTED) {
            throw ValidationException::withMessages(['status' => "Permintaan ini sudah diproses dan tidak dapat {$verb}."]);
        }
    }
}
