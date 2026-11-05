<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Location\Models\Location;
use App\Support\Decimal;

/** Bentuk data stok yang dikirim ke layar. Harga pokok dan nilai hanya untuk Owner dan Finance (2G-Q2). */
trait Presents
{
    /** @return array<string, mixed>|null */
    protected function itemRef(?Item $item): ?array
    {
        if (! $item) {
            return null;
        }
        $item->loadMissing('baseUnit');

        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'item_type' => $item->item_type,
            'is_active' => $item->is_active,
            'base_unit' => $item->baseUnit?->only(['id', 'code', 'name']),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function locationRef(?Location $location): ?array
    {
        return $location?->only(['id', 'code', 'name', 'type', 'status']);
    }

    /** @return array<string, mixed>|null */
    protected function userRef(?User $user): ?array
    {
        return $user?->only(['id', 'name', 'username']);
    }

    /** @return array<string, mixed>|null */
    protected function unitRef(?Unit $unit): ?array
    {
        return $unit?->only(['id', 'code', 'name']);
    }

    /** Jumlah dalam satuan yang diisi (satuan dasar ÷ faktor). */
    protected function inUnit(string|float|null $qty, string|float $factor): ?string
    {
        return $qty === null ? null : Decimal::round(bcdiv((string) $qty, (string) $factor, Decimal::WORK));
    }

    /** @return array<string, mixed> */
    protected function movementRow(StockMovement $m, bool $seeCost): array
    {
        return [
            'id' => $m->id,
            'type' => $m->type,
            'item' => $this->itemRef($m->item),
            'location' => $this->locationRef($m->location),
            'batch' => $m->batch ? ['id' => $m->batch->id, 'batch_no' => $m->batch->batch_no, 'expiry_date' => $m->batch->expiry_date?->format('Y-m-d')] : null,
            'qty' => $m->qty,
            'balance_after' => $m->balance_after,
            'unit_cost' => $seeCost ? $m->unit_cost : null,
            'value' => $seeCost ? $m->value : null,
            'avg_cost_after' => $seeCost ? $m->avg_cost_after : null,
            'source' => ['type' => $m->source_type, 'id' => $m->source_id, 'number' => $m->source_number],
            'note' => $m->note,
            'user' => $this->userRef($m->user),
            'business_date' => $m->business_date?->format('Y-m-d'),
            'occurred_at' => $m->occurred_at,
        ];
    }
}
