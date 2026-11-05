<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Location\Models\Location;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya pintu untuk mengubah stok (dokumen 12 §5, P1, P2).
 *
 * - Setiap perubahan menulis kartu stok (append-only) dan saldo dalam transaksi yang sama;
 *   saldo dikunci per baris sehingga dua pengguna tidak saling menimpa.
 * - Harga pokok rata-rata tertimbang per barang per lokasi (KP-04, AB-06):
 *   barang masuk berharga → rata-rata = (nilai lama + nilai masuk) / (jumlah lama + jumlah masuk);
 *   bila saldo nol atau minus, rata-rata = harga masuk. Barang keluar memakai rata-rata saat itu.
 * - Stok boleh minus (KP-02).
 * - Di gudang, barang masuk ke batch dan barang keluar diambil dari batch yang paling dekat
 *   kedaluwarsa (AB-05). Outlet tanpa batch (KP-03).
 *
 * Harus dipanggil di dalam transaksi database.
 */
class StockLedger
{
    /**
     * @param  array{type: string, id: int, number: string}  $source
     * @param  array{batch?: StockBatch|null, batch_no?: string|null, expiry_date?: string|null, batch_source?: string, note?: string|null}  $options
     */
    public function receive(Item $item, Location $location, string $qty, ?string $unitCost, string $type, array $source, ?User $user, array $options = []): StockMovement
    {
        $this->ensureStockItem($item);
        $balance = $this->lockBalance($item, $location);
        $oldQty = (string) $balance->qty;
        $oldAvg = $balance->avg_cost === null ? null : (string) $balance->avg_cost;

        if ($unitCost !== null) {
            $newAvg = (Decimal::cmp($oldQty, '0') <= 0 || $oldAvg === null)
                ? Decimal::round($unitCost)
                : Decimal::round(bcdiv(bcadd(bcmul($oldQty, $oldAvg, Decimal::WORK), bcmul($qty, $unitCost, Decimal::WORK), Decimal::WORK), bcadd($oldQty, $qty, Decimal::WORK), Decimal::WORK));
            $moveCost = Decimal::round($unitCost);
        } else {
            $newAvg = $oldAvg;
            $moveCost = $oldAvg;
        }

        $batch = null;
        if ($location->type === Location::TYPE_WAREHOUSE) {
            $batch = $options['batch'] ?? $this->batchFor($item, $location, $options['batch_no'] ?? $source['number'], $options['expiry_date'] ?? null, $options['batch_source'] ?? $type);
            $batch->qty_remaining = bcadd((string) $batch->qty_remaining, $qty, 4);
            $batch->save();
        }

        return $this->write($balance, $item, $location, $batch, $type, $qty, $moveCost, $newAvg, $source, $user, $options['note'] ?? null);
    }

    /**
     * Keluarkan barang. Di gudang dibagi per batch (paling dekat kedaluwarsa lebih dulu, atau batch
     * yang dipilih); sisa yang tidak ada batch-nya tetap dicatat sehingga saldo bisa minus (KP-02).
     *
     * @param  array{type: string, id: int, number: string}  $source
     * @return list<StockMovement>
     */
    public function issue(Item $item, Location $location, string $qty, string $type, array $source, ?User $user, ?int $batchId = null, ?string $note = null): array
    {
        $this->ensureStockItem($item);
        $balance = $this->lockBalance($item, $location);
        $avg = $balance->avg_cost === null ? null : (string) $balance->avg_cost;

        $portions = [];
        if ($location->type === Location::TYPE_WAREHOUSE) {
            $left = $qty;
            $batches = StockBatch::query()
                ->where(['item_id' => $item->id, 'location_id' => $location->id])
                ->where('qty_remaining', '>', 0)
                ->when($batchId, fn ($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$batchId]))
                ->orderByRaw('expiry_date IS NULL, expiry_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($batchId && ! $batches->contains('id', $batchId)) {
                throw ValidationException::withMessages(['batch_id' => 'Batch yang dipilih tidak punya sisa stok.']);
            }
            foreach ($batches as $batch) {
                if (Decimal::isZero($left)) {
                    break;
                }
                $take = Decimal::min($left, (string) $batch->qty_remaining);
                $portions[] = [$batch, $take];
                $left = bcsub($left, $take, 4);
            }
            if (Decimal::cmp($left, '0') > 0) {
                $portions[] = [null, $left];
            }
        } else {
            $portions[] = [null, $qty];
        }

        $movements = [];
        foreach ($portions as [$batch, $take]) {
            if ($batch) {
                $batch->qty_remaining = bcsub((string) $batch->qty_remaining, $take, 4);
                $batch->save();
            }
            $movements[] = $this->write($balance, $item, $location, $batch, $type, bcmul($take, '-1', 4), $avg, $avg, $source, $user, $note);
        }

        return $movements;
    }

    /** Saldo saat ini tanpa mengunci (untuk tampilan dan snapshot opname). */
    public function currentQty(int $itemId, int $locationId): string
    {
        return (string) (StockBalance::query()->where(['item_id' => $itemId, 'location_id' => $locationId])->value('qty') ?? '0');
    }

    public function hasMovements(int $itemId, int $locationId): bool
    {
        return StockMovement::query()->where(['item_id' => $itemId, 'location_id' => $locationId])->exists();
    }

    public function batchFor(Item $item, Location $location, string $batchNo, ?string $expiry, string $source): StockBatch
    {
        $batch = StockBatch::query()->where(['item_id' => $item->id, 'location_id' => $location->id, 'batch_no' => $batchNo])->lockForUpdate()->first();
        if ($batch) {
            if ($batch->expiry_date?->format('Y-m-d') !== $expiry) {
                throw ValidationException::withMessages(['batch_no' => "Batch {$batchNo} untuk {$item->name} sudah ada dengan tanggal kedaluwarsa lain."]);
            }

            return $batch;
        }

        return StockBatch::query()->create([
            'item_id' => $item->id, 'location_id' => $location->id, 'batch_no' => $batchNo,
            'expiry_date' => $expiry, 'qty_remaining' => 0, 'source' => $source,
        ]);
    }

    private function lockBalance(Item $item, Location $location): StockBalance
    {
        DB::statement(
            'INSERT INTO stock_balances (item_id, location_id, qty, created_at, updated_at) VALUES (?, ?, 0, now(), now()) ON CONFLICT (item_id, location_id) DO NOTHING',
            [$item->id, $location->id],
        );

        return StockBalance::query()->where(['item_id' => $item->id, 'location_id' => $location->id])->lockForUpdate()->firstOrFail();
    }

    private function ensureStockItem(Item $item): void
    {
        if (! $item->isStockItem()) {
            throw ValidationException::withMessages(['item_id' => "{$item->name} adalah menu, bukan barang stok."]);
        }
    }

    /** @param  array{type: string, id: int, number: string}  $source */
    private function write(StockBalance $balance, Item $item, Location $location, ?StockBatch $batch, string $type, string $qty, ?string $unitCost, ?string $avgAfter, array $source, ?User $user, ?string $note): StockMovement
    {
        $after = bcadd((string) $balance->qty, $qty, 4);
        $now = now();
        $balance->qty = $after;
        $balance->avg_cost = $avgAfter;
        $balance->last_movement_at = $now;
        $balance->save();

        return StockMovement::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'batch_id' => $batch?->id,
            'type' => $type,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'value' => $unitCost === null ? null : Decimal::mul($qty, $unitCost),
            'balance_after' => $after,
            'avg_cost_after' => $avgAfter,
            'source_type' => $source['type'],
            'source_id' => $source['id'],
            'source_number' => $source['number'],
            'note' => $note,
            'user_id' => $user?->id,
            'business_date' => $now->copy()->setTimezone(config('app.timezone'))->toDateString(),
            'occurred_at' => $now,
        ]);
    }
}
