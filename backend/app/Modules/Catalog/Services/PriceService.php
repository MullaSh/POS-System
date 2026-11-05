<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Harga jual menu: satu harga untuk semua outlet (KP-16), setiap perubahan disetujui Owner
 * (2E-Q1b, AB-23). Finance mengajukan; Owner menyetujui, atau mengubah langsung dengan
 * penanda OWNER_DIRECT (2F-Q1). Harga bisa langsung berlaku atau dijadwalkan per tanggal
 * (keputusan Owner, 5 Nov 2026).
 */
class PriceService
{
    public const MAX_PRICE = 100_000_000;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'price' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PRICE],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Periksa usulan harga dan bentuk isi yang disimpan.
     *
     * @param  array{price: int, effective_date?: string|null}  $input
     * @return array{price: int, effective_date: string|null, previous_price: int|null}
     */
    public function buildProposal(Item $item, array $input): array
    {
        if (! $item->isMenu()) {
            throw ValidationException::withMessages(['price' => 'Harga jual hanya untuk barang berjenis menu.']);
        }

        $date = $input['effective_date'] ?? null;
        if ($date !== null && $date < $this->today()) {
            throw ValidationException::withMessages(['effective_date' => 'Tanggal berlaku tidak boleh sebelum hari ini.']);
        }

        $current = $this->current($item)?->price;
        if ($date === null && $current === (int) $input['price']) {
            throw ValidationException::withMessages(['price' => 'Harga sama dengan harga yang berlaku sekarang.']);
        }

        return ['price' => (int) $input['price'], 'effective_date' => $date, 'previous_price' => $current];
    }

    /**
     * Owner mengubah harga langsung (keputusan langsung Owner, tercatat).
     *
     * @param  array{price: int, effective_date?: string|null, reason?: string|null}  $input
     */
    public function setDirect(Item $item, array $input, User $owner): ItemPrice
    {
        return DB::transaction(function () use ($item, $input, $owner) {
            $proposal = $this->buildProposal($this->lockItem($item), $input);
            $open = $this->openRequest($item);
            if ($open) {
                throw ValidationException::withMessages([
                    'price' => "Masih ada pengajuan harga {$open->number} dari {$open->requester->name}. Putuskan pengajuan itu dulu di Persetujuan.",
                ]);
            }

            return $this->write($item, $proposal, $owner, $input['reason'] ?? null, null, [AuditLogger::FLAG_OWNER_DIRECT]);
        });
    }

    /** Dipanggil modul Approval setelah Owner menyetujui. */
    public function applyApproved(ApprovalRequest $request, User $approver): ItemPrice
    {
        $item = $this->lockItem(Item::query()->findOrFail($request->subject_id));

        return $this->write($item, $request->payload, $approver, $request->reason, $request);
    }

    public function openRequest(Item $item): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where(['type' => ApprovalRequest::TYPE_ITEM_PRICE, 'subject_type' => 'item', 'subject_id' => $item->id])
            ->whereIn('status', ApprovalRequest::OPEN_STATUSES)
            ->with('requester:id,name')
            ->first();
    }

    public function current(Item $item, ?Carbon $at = null): ?ItemPrice
    {
        return $item->prices()->where('effective_from', '<=', $at ?? now())->orderByDesc('id')->first();
    }

    /**
     * Harga terjadwal yang masih akan berlaku (belum digantikan keputusan yang lebih baru).
     *
     * @return Collection<int, ItemPrice>
     */
    public function upcoming(Item $item): Collection
    {
        $now = now();
        $currentId = $this->current($item)?->id ?? 0;
        $future = $item->prices()->where('effective_from', '>', $now)->where('id', '>', $currentId)->orderBy('id')->get();

        // Baris terjadwal digantikan bila sesudahnya ada keputusan yang berlaku pada atau sebelum tanggalnya.
        return $future
            ->filter(fn (ItemPrice $p) => ! $future->contains(fn (ItemPrice $later) => $later->id > $p->id && $later->effective_from <= $p->effective_from))
            ->sortBy('effective_from')
            ->values();
    }

    /** @param  array{price: int, effective_date: string|null, previous_price: int|null}  $proposal */
    private function write(Item $item, array $proposal, User $by, ?string $reason, ?ApprovalRequest $request, array $flags = []): ItemPrice
    {
        $effectiveFrom = $this->effectiveFrom($proposal['effective_date']);

        $price = ItemPrice::query()->create([
            'item_id' => $item->id,
            'price' => $proposal['price'],
            'effective_from' => $effectiveFrom,
            'approval_request_id' => $request?->id,
            'set_by' => $by->id,
            'reason' => $reason,
        ]);

        $this->audit->record('ITEM_PRICE_SET', $item, [
            'harga' => ['dari' => $proposal['previous_price'], 'menjadi' => $proposal['price']],
            'berlaku_mulai' => $effectiveFrom->toIso8601String(),
        ], Role::OWNER, $reason, $flags, related: $request ? ['approval_request' => $request->number] : null, user: $by);

        return $price;
    }

    /** Tanggal di masa depan berlaku mulai pukul 00.00 WIB; selain itu langsung berlaku. */
    private function effectiveFrom(?string $date): Carbon
    {
        $now = now();
        if ($date === null) {
            return $now;
        }
        $start = Carbon::createFromFormat('Y-m-d', $date, config('app.timezone'))->startOfDay();

        return $start->greaterThan($now) ? $start : $now;
    }

    private function today(): string
    {
        return now()->setTimezone(config('app.timezone'))->format('Y-m-d');
    }

    private function lockItem(Item $item): Item
    {
        return Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
    }
}
