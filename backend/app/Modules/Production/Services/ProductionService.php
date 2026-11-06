<?php

namespace App\Modules\Production\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemUnit;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Services\Quantities;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Location\Models\Location;
use App\Modules\Production\Models\ProductionFailure;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderLine;
use App\Modules\Recipe\Models\Recipe;
use App\Modules\Recipe\Models\RecipeVersion;
use App\Modules\System\Models\AppSetting;
use App\Support\Decimal;
use App\Support\DocumentNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Produksi di gudang (C2, C17, KP-06, KP-07, AB-10, AB-11; keputusan Owner 6 Nov 2026, 22.15 WIB):
 * - Rencana: gudang memilih barang hasil dan target; bahan standar = resep produksi yang berlaku × target.
 *   Stok belum berubah. Rencana dapat dibuat dari Stock Request yang stok gudangnya kurang, atau tanpa
 *   permintaan. Rencana dapat dibatalkan dengan alasan.
 * - Selesai: gudang mengisi hasil baik, hasil gagal per alasan baku (QC Production), dan pemakaian bahan
 *   sebenarnya. Bahan dipotong dari stok gudang (batch paling dekat kedaluwarsa); hanya hasil baik masuk
 *   stok sebagai batch bernomor Production Order dengan kedaluwarsa. Hasil gagal menjadi waste produksi.
 * - Harga pokok hasil = total biaya bahan terpakai ÷ jumlah hasil baik.
 * - Toleransi varians (pengaturan Owner): pemakaian bahan vs standar dan hasil baik vs target. Di luar
 *   batas wajib alasan dan ditandai untuk pantauan Owner, tanpa persetujuan.
 */
class ProductionService
{
    public const DEFAULT_TOLERANCE = 5;

    public function __construct(
        private readonly Quantities $quantities,
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public static function tolerance(): int
    {
        return AppSetting::int(AppSetting::PRODUCTION_VARIANCE_TOLERANCE, self::DEFAULT_TOLERANCE);
    }

    public function setTolerance(int $pct, User $owner): void
    {
        $before = self::tolerance();
        AppSetting::query()->updateOrCreate(['key' => AppSetting::PRODUCTION_VARIANCE_TOLERANCE], ['value' => ['v' => $pct], 'updated_by' => $owner->id]);
        $this->audit->record('SETTING_CHANGED', null, ['toleransi_varians_produksi_persen' => ['dari' => $before, 'menjadi' => $pct]], Role::OWNER,
            user: $owner, subjectCode: AppSetting::PRODUCTION_VARIANCE_TOLERANCE);
    }

    public static function warehouse(): Location
    {
        return Location::query()->where('type', Location::TYPE_WAREHOUSE)->firstOrFail();
    }

    /** @return array<string, mixed> */
    public static function planRules(): array
    {
        return [
            'output_item_id' => ['required', 'integer', 'exists:items,id'],
            'unit_id' => ['required', 'integer'],
            'qty' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'planned_date' => ['nullable', 'date_format:Y-m-d'],
            'stock_request_id' => ['nullable', 'integer', 'exists:stock_requests,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function completeRules(): array
    {
        return [
            'unit_id' => ['nullable', 'integer'],
            'good_qty' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'failures' => ['nullable', 'array', 'max:4'],
            'failures.*.reason' => ['required', 'distinct', 'in:'.implode(',', ProductionFailure::REASONS)],
            'failures.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'failures.*.note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.line_id' => ['required', 'integer', 'distinct'],
            'lines.*.actual_qty' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'variance_note' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** Versi resep produksi yang berlaku untuk barang hasil. */
    public function currentVersion(Item $item, string $field = 'output_item_id'): RecipeVersion
    {
        $recipe = Recipe::query()->where(['output_item_id' => $item->id, 'kind' => Recipe::KIND_PRODUCTION])->first();
        $version = $recipe?->versionAt();
        if (! $version) {
            throw ValidationException::withMessages([$field => "{$item->name} belum punya resep produksi yang berlaku. Owner membuatnya di halaman Resep."]);
        }

        return $version;
    }

    /**
     * Bahan standar untuk target (satuan dasar hasil): takaran resep × (target ÷ hasil satu resep).
     * Satuan tampilan mengikuti satuan di resep.
     *
     * @return list<array{item: Item, unit_id: int, factor: string, standard_qty: string}>
     */
    public function standardLines(RecipeVersion $version, string $plannedQty): array
    {
        $scale = bcdiv($plannedQty, (string) $version->output_qty, Decimal::WORK);
        $out = [];
        foreach ($version->lines()->with('item')->get() as $l) {
            $factor = ItemUnit::query()->where(['item_id' => $l->item_id, 'unit_id' => $l->unit_id])->value('factor_to_base');
            $unitId = $factor === null ? $l->item->base_unit_id : $l->unit_id;
            $qty = Decimal::round(bcmul((string) $l->qty, $scale, Decimal::WORK));
            if (Decimal::cmp($qty, '0') <= 0) {
                $qty = '0.0001';
            }
            $out[] = ['item' => $l->item, 'unit_id' => $unitId, 'factor' => (string) ($factor ?? '1'), 'standard_qty' => $qty];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $data */
    public function plan(array $data, User $user): ProductionOrder
    {
        $warehouse = self::warehouse();
        $this->ensureOperator($user, $warehouse);
        Quantities::ensureUsable($warehouse);
        $item = Item::query()->findOrFail($data['output_item_id']);
        $version = $this->currentVersion($item);
        $factor = $this->quantities->factor($item, (int) $data['unit_id'], 'unit_id');
        $planned = $this->quantities->toBase(Decimal::round((string) $data['qty']), $factor);

        return DB::transaction(function () use ($data, $user, $warehouse, $item, $version, $factor, $planned) {
            $request = null;
            if (! empty($data['stock_request_id'])) {
                $request = StockRequest::query()->lockForUpdate()->findOrFail($data['stock_request_id']);
                if ($request->status !== StockRequest::SUBMITTED) {
                    throw ValidationException::withMessages(['stock_request_id' => "Permintaan {$request->number} sudah diproses."]);
                }
                if (! $request->lines()->where('item_id', $item->id)->exists()) {
                    throw ValidationException::withMessages(['stock_request_id' => "{$item->name} tidak ada di permintaan {$request->number}."]);
                }
            }
            $order = ProductionOrder::query()->create([
                'number' => DocumentNumber::next('PRD'),
                'location_id' => $warehouse->id,
                'output_item_id' => $item->id,
                'recipe_version_id' => $version->id,
                'stock_request_id' => $request?->id,
                'status' => ProductionOrder::STATUS_PLANNED,
                'unit_id' => (int) $data['unit_id'],
                'factor' => $factor,
                'planned_qty' => $planned,
                'planned_date' => $data['planned_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            $summary = [];
            foreach ($this->standardLines($version, $planned) as $pos => $l) {
                ProductionOrderLine::query()->create([
                    'production_order_id' => $order->id, 'item_id' => $l['item']->id, 'unit_id' => $l['unit_id'], 'factor' => $l['factor'],
                    'standard_qty' => $l['standard_qty'], 'position' => $pos,
                ]);
                $summary[] = ['bahan' => $l['item']->code, 'standar' => $l['standard_qty']];
            }
            $this->audit->record('PRODUCTION_PLANNED', $order, [
                'hasil' => $item->code, 'target' => $planned, 'resep_versi' => $version->version_no, 'permintaan' => $request?->number, 'bahan' => $summary,
            ], Role::WAREHOUSE, $data['notes'] ?? null, locationId: $warehouse->id, user: $user, subjectCode: $order->number);

            return $order;
        });
    }

    public function cancel(ProductionOrder $order, string $reason, User $user): ProductionOrder
    {
        $this->ensureOperator($user, $order->location);

        return DB::transaction(function () use ($order, $reason, $user) {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->ensurePlanned($order, 'dibatalkan');
            $order->update(['status' => ProductionOrder::STATUS_CANCELLED, 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $this->audit->record('PRODUCTION_CANCELLED', $order, null, Role::WAREHOUSE, $reason, locationId: $order->location_id, user: $user, subjectCode: $order->number);

            return $order;
        });
    }

    /** Persentase (a − b) ÷ b, dua desimal. */
    public static function variancePct(string $actual, string $standard): string
    {
        return Decimal::round(bcmul(bcdiv(bcsub($actual, $standard, Decimal::WORK), $standard, Decimal::WORK), '100', Decimal::WORK), 2);
    }

    public static function beyond(string $pct, int $tolerance): bool
    {
        return Decimal::cmp(ltrim($pct, '-'), (string) $tolerance) > 0;
    }

    /** @param  array<string, mixed>  $data */
    public function complete(ProductionOrder $order, array $data, User $user): ProductionOrder
    {
        $this->ensureOperator($user, $order->location);

        return DB::transaction(function () use ($order, $data, $user) {
            $order = ProductionOrder::query()->with(['outputItem', 'location'])->lockForUpdate()->findOrFail($order->id);
            $this->ensurePlanned($order, 'diselesaikan');
            $item = $order->outputItem;
            $location = $order->location;
            $tolerance = self::tolerance();

            $outFactor = empty($data['unit_id']) ? (string) $order->factor : $this->quantities->factor($item, (int) $data['unit_id'], 'unit_id');
            $good = $this->quantities->toBase(Decimal::round((string) $data['good_qty']), $outFactor);
            $failures = [];
            $failed = '0';
            foreach ($data['failures'] ?? [] as $i => $f) {
                if ($f['reason'] === 'OTHER' && empty($f['note'])) {
                    throw ValidationException::withMessages(["failures.{$i}.note" => 'Jelaskan alasan "lainnya".']);
                }
                $qty = $this->quantities->toBase(Decimal::round((string) $f['qty']), $outFactor);
                $failed = bcadd($failed, $qty, 4);
                $failures[] = ['reason' => $f['reason'], 'qty' => $qty, 'note' => $f['note'] ?? null];
            }
            if (Decimal::isZero(bcadd($good, $failed, 4))) {
                throw ValidationException::withMessages(['good_qty' => 'Isi jumlah hasil baik atau hasil gagal.']);
            }

            $lines = $order->lines()->with('item')->get()->keyBy('id');
            $actuals = [];
            foreach ($data['lines'] as $i => $l) {
                $line = $lines->get($l['line_id']);
                if (! $line) {
                    throw ValidationException::withMessages(["lines.{$i}.line_id" => 'Bahan ini tidak ada di rencana produksi.']);
                }
                $actuals[$line->id] = $this->quantities->toBase(Decimal::round((string) $l['actual_qty']), (string) $line->factor);
            }
            if (count($actuals) !== $lines->count()) {
                throw ValidationException::withMessages(['lines' => 'Isi pemakaian sebenarnya untuk semua bahan (isi 0 bila tidak dipakai).']);
            }

            // Varians dan toleransi (AB-11).
            $outputPct = self::variancePct($good, (string) $order->planned_qty);
            $over = self::beyond($outputPct, $tolerance);
            $linePct = [];
            foreach ($lines as $line) {
                $linePct[$line->id] = self::variancePct($actuals[$line->id], (string) $line->standard_qty);
                $over = $over || self::beyond($linePct[$line->id], $tolerance);
            }
            $varianceNote = trim((string) ($data['variance_note'] ?? '')) ?: null;
            if ($over && $varianceNote === null) {
                throw ValidationException::withMessages(['variance_note' => "Pemakaian bahan atau hasil baik berbeda lebih dari {$tolerance}% dari rencana. Tuliskan alasannya."]);
            }

            // Bahan keluar dari stok gudang; biaya = harga pokok rata-rata saat dipakai (KP-04).
            $source = ['type' => 'production_order', 'id' => $order->id, 'number' => $order->number];
            $total = '0';
            $complete = true;
            foreach ($lines as $line) {
                $cost = '0';
                if (! Decimal::isZero($actuals[$line->id])) {
                    $moves = $this->ledger->issue($line->item, $location, $actuals[$line->id], StockMovement::PRODUCTION_CONSUME, $source, $user, note: $order->number);
                    foreach ($moves as $m) {
                        if ($m->value === null) {
                            $complete = false;
                        } else {
                            $cost = bcsub($cost, (string) $m->value, 4);
                        }
                    }
                }
                $total = bcadd($total, $cost, 4);
                $line->update(['actual_qty' => $actuals[$line->id], 'cost' => $cost, 'variance_pct' => $linePct[$line->id]]);
            }

            // Hasil baik masuk stok sebagai batch bernomor Production Order (KP-03).
            $expiry = $data['expiry_date'] ?? ($item->shelf_life_days ? now()->setTimezone(config('app.timezone'))->addDays($item->shelf_life_days)->toDateString() : null);
            $unitCost = null;
            if (! Decimal::isZero($good)) {
                // Bila tidak satu pun bahan punya harga pokok, harga pokok hasil tidak diketahui dan rata-rata tidak diubah.
                $unitCost = (! $complete && Decimal::isZero($total)) ? null : Decimal::round(bcdiv($total, $good, Decimal::WORK));
                $this->ledger->receive($item, $location, $good, $unitCost, StockMovement::PRODUCTION_OUTPUT, $source, $user, [
                    'batch_no' => $order->number, 'expiry_date' => $expiry, 'batch_source' => StockMovement::PRODUCTION_OUTPUT, 'note' => $order->number,
                ]);
            }
            $failedValue = Decimal::round(bcdiv(bcmul($total, $failed, Decimal::WORK), bcadd($good, $failed, Decimal::WORK), Decimal::WORK));
            foreach ($failures as $f) {
                ProductionFailure::query()->create(['production_order_id' => $order->id, ...$f]);
            }

            $order->update([
                'status' => ProductionOrder::STATUS_COMPLETED,
                'good_qty' => $good,
                'failed_qty' => $failed,
                'total_cost' => $total,
                'unit_cost' => $unitCost,
                'cost_complete' => $complete,
                'failed_value' => $failedValue,
                'batch_no' => Decimal::isZero($good) ? null : $order->number,
                'expiry_date' => Decimal::isZero($good) ? null : $expiry,
                'tolerance_pct' => $tolerance,
                'output_variance_pct' => $outputPct,
                'over_tolerance' => $over,
                'variance_note' => $varianceNote,
                'completion_note' => $data['note'] ?? null,
                'completed_by' => $user->id,
                'completed_at' => now(),
            ]);
            $flags = [];
            if ($over) {
                $flags[] = 'VARIANCE_OVER_TOLERANCE';
            }
            if (! Decimal::isZero($failed)) {
                $flags[] = 'QC_REJECTED';
            }
            $this->audit->record('PRODUCTION_COMPLETED', $order, [
                'hasil_baik' => $good, 'hasil_gagal' => $failed, 'target' => (string) $order->planned_qty, 'varians_hasil_persen' => $outputPct,
                'bahan' => $lines->map(fn ($l) => ['bahan' => $l->item->code, 'standar' => (string) $l->standard_qty, 'sebenarnya' => $actuals[$l->id], 'varians_persen' => $linePct[$l->id]])->values()->all(),
                'kedaluwarsa' => $expiry,
            ], Role::WAREHOUSE, $varianceNote ?? ($data['note'] ?? null), $flags, locationId: $order->location_id, user: $user, subjectCode: $order->number);

            return $order;
        });
    }

    private function ensureOperator(User $user, Location $location): void
    {
        if (! $user->hasRoleAt(Role::WAREHOUSE, $location->id)) {
            throw new AuthorizationException('Produksi dikerjakan oleh staf gudang.');
        }
    }

    private function ensurePlanned(ProductionOrder $order, string $verb): void
    {
        if ($order->status !== ProductionOrder::STATUS_PLANNED) {
            throw ValidationException::withMessages(['status' => "Production Order ini sudah tidak dapat {$verb}."]);
        }
    }
}
