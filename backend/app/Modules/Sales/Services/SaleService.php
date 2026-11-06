<?php

namespace App\Modules\Sales\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\PaymentMethod;
use App\Modules\Catalog\Services\PriceService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Location\Models\Location;
use App\Modules\Recipe\Contracts\CostSource;
use App\Modules\Recipe\Models\Recipe;
use App\Modules\Recipe\Models\RecipeVersion;
use App\Modules\Recipe\Services\RecipeCostService;
use App\Modules\Sales\Models\CashierShift;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SaleLineComponent;
use App\Modules\Sales\Models\SalePayment;
use App\Support\Decimal;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transaksi penjualan (C5, C7, C13; KP-02, KP-05, KP-16; keputusan Owner 6 Nov 2026).
 *
 * - Transaksi dicatat saat dibayar. Item yang dibatalkan sebelum bayar cukup dihapus dari
 *   keranjang oleh kasir (AB-24); tidak ada catatan transaksi yang dibuat untuknya.
 * - Harga jual: harga yang berlaku saat transaksi, sama di semua outlet (KP-16). Tanpa diskon,
 *   pajak, dan service charge di MVP; tempatnya sudah ada di struktur transaksi.
 * - Add-on dijual sebagai menu tersendiri dengan harga dan resepnya sendiri.
 * - Stok outlet dipotong otomatis per resep menu yang berlaku saat transaksi (C7). Stok boleh
 *   minus; kasir mendapat peringatan (KP-02).
 * - HPP dikunci saat transaksi dari harga pokok rata-rata outlet (KP-04, KP-05, AB-09).
 * - Pembayaran boleh campur beberapa metode; hanya bagian tunai yang masuk laci, dan kembalian
 *   hanya dari tunai (C13).
 */
class SaleService
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly StockLedger $ledger,
        private readonly PriceService $prices,
        private readonly CostSource $costs,
        private readonly RecipeCostService $recipeCosts,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'channel' => ['required', 'in:'.implode(',', Sale::CHANNELS)],
            'customer_note' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'integer', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'payments' => ['required', 'array', 'min:1', 'max:5'],
            'payments.*.payment_method_id' => ['required', 'integer', 'distinct'],
            'payments.*.amount' => ['required', 'integer', 'min:1', 'max:'.PriceService::MAX_PRICE],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sale: Sale, warnings: list<string>}
     */
    public function create(array $data, User $user): array
    {
        $location = Location::query()->findOrFail($data['location_id']);
        $this->access->ensureCashierAt($user, $location);

        return DB::transaction(function () use ($data, $user, $location) {
            $shift = CashierShift::query()->where('location_id', $location->id)
                ->whereIn('status', CashierShift::RUNNING)->lockForUpdate()->first();
            if (! $shift) {
                throw ValidationException::withMessages(['shift' => 'Buka shift dulu sebelum berjualan.']);
            }
            if ($shift->cashier_id !== $user->id) {
                throw ValidationException::withMessages(['shift' => "Shift di {$location->name} sedang dipakai kasir lain. Minta kasir itu menutup shift dulu."]);
            }
            if ($shift->status !== CashierShift::STATUS_OPEN) {
                throw ValidationException::withMessages(['shift' => 'Hasil hitung shift sudah dikirim; tidak bisa berjualan lagi di shift ini.']);
            }

            $now = now();
            $lines = $this->prepareLines($data['lines'], $location, $now);
            $subtotal = array_sum(array_column($lines, 'line_total'));
            $payments = $this->preparePayments($data['payments'], $subtotal);

            $hppComplete = true;
            $hppTotal = '0';
            foreach ($lines as $line) {
                if ($line['hpp_total'] === null) {
                    $hppComplete = false;
                } else {
                    $hppTotal = bcadd($hppTotal, $line['hpp_total'], 4);
                }
            }

            $sale = Sale::query()->create([
                'number' => DocumentNumber::next('TRX'),
                'location_id' => $location->id,
                'shift_id' => $shift->id,
                'cashier_id' => $user->id,
                'channel' => $data['channel'],
                'subtotal' => $subtotal,
                'discount_total' => 0,
                'tax_total' => 0,
                'total' => $subtotal,
                'paid_total' => $subtotal,
                'change_total' => array_sum(array_map(fn ($p) => $p['change'] ?? 0, $payments)),
                'hpp_total' => $hppComplete ? $hppTotal : null,
                'hpp_complete' => $hppComplete,
                'customer_note' => $data['customer_note'] ?? null,
                'business_date' => $now->copy()->setTimezone(config('app.timezone'))->toDateString(),
                'paid_at' => $now,
            ]);
            $source = ['type' => 'sale', 'id' => $sale->id, 'number' => $sale->number];

            $touched = [];
            foreach ($lines as $position => $line) {
                $saleLine = SaleLine::query()->create([
                    'sale_id' => $sale->id,
                    'item_id' => $line['item']->id,
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                    'item_price_id' => $line['item_price_id'],
                    'recipe_version_id' => $line['version']->id,
                    'unit_hpp' => $line['unit_hpp'],
                    'hpp_total' => $line['hpp_total'],
                    'position' => $position,
                ]);
                foreach ($line['components'] as $c) {
                    SaleLineComponent::query()->create([
                        'sale_line_id' => $saleLine->id,
                        'item_id' => $c['item']->id,
                        'qty' => $c['qty'],
                        'unit_cost' => $c['unit_cost'],
                        'cost_source' => $c['cost_source'],
                    ]);
                    $this->ledger->issue($c['item'], $location, $c['qty'], StockMovement::SALE, $source, $user, note: $line['item']->name);
                    $touched[$c['item']->id] = $c['item']->name;
                }
            }
            foreach ($payments as $position => $p) {
                SalePayment::query()->create([...$p, 'sale_id' => $sale->id, 'position' => $position]);
            }

            $warnings = [];
            $negative = StockBalance::query()->where('location_id', $location->id)->whereIn('item_id', array_keys($touched))->where('qty', '<', 0)->pluck('item_id');
            foreach ($negative as $itemId) {
                $warnings[] = "Stok {$touched[$itemId]} di {$location->name} sekarang minus. Periksa stok fisik dan catat lewat stock opname.";
            }

            $this->audit->record('SALE_RECORDED', $sale, [
                'total' => $subtotal,
                'baris' => array_map(fn ($l) => ['menu' => $l['item']->code, 'jumlah' => $l['qty'], 'harga' => $l['unit_price']], $lines),
                'pembayaran' => array_map(fn ($p) => ['metode_id' => $p['payment_method_id'], 'jumlah' => $p['amount']], $payments),
            ], Role::CASHIER, null, $warnings ? ['NEGATIVE_STOCK'] : [], locationId: $location->id, user: $user, subjectCode: $sale->number);

            return ['sale' => $sale, 'warnings' => $warnings];
        });
    }

    /**
     * Menu yang dapat dijual di outlet: aktif, punya harga jual dan resep yang berlaku.
     *
     * @return list<array<string, mixed>>
     */
    public function menu(): array
    {
        $items = Item::query()->where('item_type', Item::TYPE_MENU)->where('is_active', true)->orderBy('name')->get();
        $recipes = Recipe::query()->where('kind', Recipe::KIND_MENU)->whereIn('output_item_id', $items->pluck('id'))->get()->keyBy('output_item_id');

        return $items->map(function (Item $item) use ($recipes) {
            $price = $this->prices->current($item);
            $version = $recipes->get($item->id)?->versionAt();
            $problem = match (true) {
                $price === null => 'Belum punya harga jual',
                $version === null => 'Belum punya resep',
                default => null,
            };

            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'price' => $price?->price,
                'sellable' => $problem === null,
                'problem' => $problem,
            ];
        })->values()->all();
    }

    /**
     * @param  list<array{item_id: int, qty: int}>  $input
     * @return list<array<string, mixed>>
     */
    private function prepareLines(array $input, Location $location, \DateTimeInterface $at): array
    {
        $items = Item::query()->whereIn('id', array_column($input, 'item_id'))->get()->keyBy('id');
        $recipes = Recipe::query()->where('kind', Recipe::KIND_MENU)->whereIn('output_item_id', $items->keys())->get()->keyBy('output_item_id');

        $lines = [];
        foreach ($input as $i => $row) {
            /** @var Item|null $item */
            $item = $items->get($row['item_id']);
            if (! $item || ! $item->isMenu()) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => 'Pilih menu yang dijual.']);
            }
            if (! $item->is_active) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => "{$item->name} sedang nonaktif."]);
            }
            $price = $this->prices->current($item);
            if (! $price) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => "{$item->name} belum punya harga jual."]);
            }
            /** @var RecipeVersion|null $version */
            $version = $recipes->get($item->id)?->versionAt();
            if (! $version) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => "{$item->name} belum punya resep, sehingga stok tidak bisa dipotong. Minta Owner membuat resepnya."]);
            }
            $version->loadMissing('lines.item');
            $qty = (int) $row['qty'];

            $components = [];
            $hpp = '0';
            $complete = true;
            foreach ($version->lines as $rl) {
                $perPortion = bcdiv((string) $rl->qty, (string) $version->output_qty, Decimal::WORK);
                $total = Decimal::round(bcmul($perPortion, (string) $qty, Decimal::WORK));
                [$unitCost, $source] = $this->componentCost($rl->item, $location);
                if ($unitCost === null) {
                    $complete = false;
                } else {
                    $hpp = bcadd($hpp, bcmul($total, $unitCost, Decimal::WORK), Decimal::WORK);
                }
                $components[] = ['item' => $rl->item, 'qty' => $total, 'unit_cost' => $unitCost, 'cost_source' => $source];
            }

            $lines[] = [
                'item' => $item,
                'qty' => $qty,
                'unit_price' => (int) $price->price,
                'line_total' => $qty * (int) $price->price,
                'item_price_id' => $price->id,
                'version' => $version,
                'components' => $components,
                'unit_hpp' => $complete ? Decimal::round(bcdiv($hpp, (string) $qty, Decimal::WORK)) : null,
                'hpp_total' => $complete ? Decimal::round($hpp) : null,
            ];
        }

        return $lines;
    }

    /**
     * Harga pokok bahan saat transaksi: rata-rata outlet (KP-04). Bila outlet belum punya,
     * perkiraan dari rata-rata gudang atau resep produksinya, ditandai ESTIMATE.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function componentCost(Item $item, Location $location): array
    {
        $cost = $this->costs->unitCost($item, $location->id);
        if ($cost !== null) {
            return [$cost, 'OUTLET_AVERAGE'];
        }
        [$estimate] = $this->recipeCosts->unitCost($item);

        return [$estimate, $estimate === null ? null : 'ESTIMATE'];
    }

    /**
     * Bagi pembayaran: non-tunai dipakai sebesar yang diisi; sisanya dibayar tunai, dengan
     * kembalian dari tunai saja.
     *
     * @param  list<array{payment_method_id: int, amount: int, reference?: string|null}>  $input
     * @return list<array<string, mixed>>
     */
    private function preparePayments(array $input, int $total): array
    {
        $methods = PaymentMethod::query()->whereIn('id', array_column($input, 'payment_method_id'))->get()->keyBy('id');
        $nonCash = 0;
        $cash = null;
        $out = [];
        foreach ($input as $i => $p) {
            $method = $methods->get($p['payment_method_id']);
            if (! $method || ! $method->is_active) {
                throw ValidationException::withMessages(["payments.{$i}.payment_method_id" => 'Metode bayar tidak tersedia.']);
            }
            if ($method->is_cash) {
                $cash = ['index' => $i, 'method' => $method, 'received' => (int) $p['amount']];

                continue;
            }
            $nonCash += (int) $p['amount'];
            $out[$i] = [
                'payment_method_id' => $method->id,
                'is_cash' => false,
                'amount' => (int) $p['amount'],
                'received' => null,
                'change' => null,
                'reference' => $p['reference'] ?? null,
            ];
        }
        if ($nonCash > $total) {
            throw ValidationException::withMessages(['payments' => 'Pembayaran non-tunai melebihi total. Kembalian hanya bisa dari tunai.']);
        }
        $remaining = $total - $nonCash;
        if ($cash !== null) {
            if ($remaining === 0) {
                throw ValidationException::withMessages(['payments' => 'Tagihan sudah lunas dengan non-tunai; hapus pembayaran tunai.']);
            }
            if ($cash['received'] < $remaining) {
                throw ValidationException::withMessages(["payments.{$cash['index']}.amount" => 'Uang tunai kurang '.ShiftService::rupiah($remaining - $cash['received']).'.']);
            }
            $out[$cash['index']] = [
                'payment_method_id' => $cash['method']->id,
                'is_cash' => true,
                'amount' => $remaining,
                'received' => $cash['received'],
                'change' => $cash['received'] - $remaining,
                'reference' => null,
            ];
        } elseif ($remaining > 0) {
            throw ValidationException::withMessages(['payments' => 'Pembayaran kurang '.ShiftService::rupiah($remaining).'.']);
        }
        ksort($out);

        return array_values($out);
    }
}
