<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Location\Models\Location;
use App\Support\Decimal;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock opname (C18, KP-02; OD-21 keputusan Owner 5 Nov 2026):
 * petugas lokasi mencatat hasil hitung → selisih terhadap saldo sistem saat dihitung →
 * penyesuaian berlaku setelah Owner menyetujui. Satu opname terbuka per lokasi.
 * Jumlah penyesuaian = hasil hitung − saldo sistem saat dihitung, sehingga penjualan atau
 * pengiriman yang terjadi sambil menunggu persetujuan tidak ikut terhapus.
 */
class StockCountService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly Quantities $quantities,
        private readonly StockAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [...Quantities::lineRules('counted_qty', allowZero: true), 'reason' => ['nullable', 'string', 'max:1000']];
    }

    /** @param  array<string, mixed>  $data */
    public function submit(Location $location, array $data, User $user): StockCount
    {
        $role = $this->access->ensureOperator($user, $location);
        Quantities::ensureUsable($location, allowInactive: true);

        return DB::transaction(function () use ($location, $data, $user, $role) {
            Location::query()->whereKey($location->id)->lockForUpdate()->first();
            $open = StockCount::query()->where('location_id', $location->id)
                ->whereHas('approval', fn ($q) => $q->whereIn('status', ApprovalRequest::OPEN_STATUSES))
                ->first();
            if ($open) {
                throw ValidationException::withMessages(['location_id' => "Masih ada opname {$open->number} di {$location->name} yang belum selesai."]);
            }

            $count = StockCount::query()->create([
                'number' => DocumentNumber::next('OPN'),
                'location_id' => $location->id,
                'created_by' => $user->id,
                'role_id' => Role::idFor($role),
            ]);
            $approval = app(ApprovalService::class)->submit(ApprovalRequest::TYPE_STOCK_COUNT, $count, $data, $data['reason'] ?? null, $user, $role);
            $count->update(['approval_request_id' => $approval->id]);

            return $count;
        });
    }

    /**
     * Isi pengajuan: hasil hitung dan saldo sistem saat dihitung (tanpa harga pokok, karena
     * pengaju tidak melihat harga pokok — 2G-Q2).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function buildPayload(StockCount $count, array $input): array
    {
        $items = $this->quantities->stockItems($input['lines']);
        $lines = [];
        foreach ($input['lines'] as $i => $line) {
            /** @var Item $item */
            $item = $items[$line['item_id']];
            $factor = $this->quantities->factor($item, (int) $line['unit_id'], "lines.{$i}.unit_id");
            $counted = $this->quantities->toBase((string) $line['counted_qty'], $factor);
            $system = $this->ledger->currentQty($item->id, $count->location_id);
            $lines[] = [
                'item_id' => $item->id,
                'unit_id' => (int) $line['unit_id'],
                'factor' => $factor,
                'counted_entered' => (string) $line['counted_qty'],
                'counted_qty' => $counted,
                'system_qty' => $system,
                'difference' => bcsub($counted, $system, 4),
            ];
        }

        return ['location_id' => $count->location_id, 'counted_at' => now()->toIso8601String(), 'lines' => $lines];
    }

    public function apply(ApprovalRequest $request, User $approver): void
    {
        /** @var StockCount $count */
        $count = StockCount::query()->with('location')->lockForUpdate()->findOrFail($request->subject_id);
        if ($count->applied_at) {
            return;
        }
        $source = ['type' => 'stock_count', 'id' => $count->id, 'number' => $count->number];
        $creator = $count->creator;
        $applied = [];
        foreach ($request->payload['lines'] as $line) {
            $diff = (string) $line['difference'];
            if (Decimal::isZero($diff)) {
                continue;
            }
            $item = Item::query()->findOrFail($line['item_id']);
            if (Decimal::cmp($diff, '0') > 0) {
                $this->ledger->receive($item, $count->location, $diff, null, StockMovement::ADJUSTMENT, $source, $creator, [
                    'batch_no' => $count->number, 'batch_source' => 'ADJUSTMENT', 'note' => 'Hasil opname',
                ]);
            } else {
                $this->ledger->issue($item, $count->location, bcmul($diff, '-1', 4), StockMovement::ADJUSTMENT, $source, $creator, note: 'Hasil opname');
            }
            $applied[] = ['barang' => $item->code, 'selisih' => $diff];
        }
        $count->update(['applied_at' => now()]);

        $this->audit->record('STOCK_ADJUSTMENT_APPLIED', $count, ['baris' => $applied, 'pengajuan' => $request->number],
            Role::OWNER, $request->decision_note, locationId: $count->location_id, user: $approver);
    }
}
