<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Location\Models\Location;
use App\Support\Decimal;
use App\Support\DocumentNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengiriman barang (AB-13; dokumen 12 §5 butir 3):
 * - Kirim: stok pengirim berkurang saat dikirim, dengan harga pokok rata-rata pengirim saat itu.
 * - Terima: penerima mengisi jumlah yang benar-benar diterima dalam kondisi baik; hanya jumlah
 *   itu yang menambah stok penerima, dengan harga pokok dari pengirim. Pengirim dan penerima
 *   harus orang berbeda (pemisahan tugas).
 * - Selisih (OD-18, keputusan Owner 5 Nov 2026): pengirim wajib menjelaskan setiap selisih —
 *   kurang kirim (barang kembali ke stok pengirim), hilang/rusak di perjalanan (susut, tampil ke
 *   Owner), atau kelebihan kirim (stok pengirim dikurangi). Jumlah terima tidak diubah.
 * - Retur outlet → gudang memakai alur yang sama (G-03, keputusan Owner 5 Nov 2026).
 */
class StockTransferService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly Quantities $quantities,
        private readonly StockAccess $access,
        private readonly StockRequestService $requests,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'kind' => ['required', 'in:'.StockTransfer::KIND_DISTRIBUTION.','.StockTransfer::KIND_RETURN],
            'to_location_id' => ['required_if:kind,'.StockTransfer::KIND_DISTRIBUTION, 'nullable', 'integer', 'exists:locations,id'],
            'from_location_id' => ['required_if:kind,'.StockTransfer::KIND_RETURN, 'nullable', 'integer', 'exists:locations,id'],
            'stock_request_id' => ['nullable', 'integer', 'exists:stock_requests,id'],
            'reason_code' => ['required_if:kind,'.StockTransfer::KIND_RETURN, 'nullable', 'in:'.implode(',', StockTransfer::RETURN_REASONS)],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...Quantities::lineRules(),
        ];
    }

    public function warehouse(): Location
    {
        return Location::query()->where('type', Location::TYPE_WAREHOUSE)->firstOrFail();
    }

    /** @param  array<string, mixed>  $data */
    public function send(array $data, User $user): StockTransfer
    {
        $warehouse = $this->warehouse();
        if ($data['kind'] === StockTransfer::KIND_DISTRIBUTION) {
            $from = $warehouse;
            $to = Location::query()->findOrFail($data['to_location_id']);
            if (! $to->isOutlet()) {
                throw ValidationException::withMessages(['to_location_id' => 'Pilih outlet tujuan.']);
            }
            Quantities::ensureUsable($to);
        } else {
            $from = Location::query()->findOrFail($data['from_location_id']);
            $to = $warehouse;
            if (! $from->isOutlet()) {
                throw ValidationException::withMessages(['from_location_id' => 'Retur dikirim dari outlet.']);
            }
            Quantities::ensureUsable($from, allowInactive: true);
            if (! empty($data['stock_request_id'])) {
                throw ValidationException::withMessages(['stock_request_id' => 'Retur tidak memakai permintaan stok.']);
            }
        }
        $role = $this->access->ensureOperator($user, $from);

        return DB::transaction(function () use ($data, $user, $role, $from, $to) {
            $request = null;
            if (! empty($data['stock_request_id'])) {
                $request = $this->requests->lock(StockRequest::query()->findOrFail($data['stock_request_id']));
                if ($request->status !== StockRequest::SUBMITTED) {
                    throw ValidationException::withMessages(['stock_request_id' => "Permintaan {$request->number} sudah diproses."]);
                }
                if ($request->location_id !== $to->id) {
                    throw ValidationException::withMessages(['stock_request_id' => "Permintaan {$request->number} untuk outlet lain."]);
                }
            }

            $items = $this->quantities->stockItems($data['lines']);
            $isReturn = $data['kind'] === StockTransfer::KIND_RETURN;
            $transfer = StockTransfer::query()->create([
                'number' => DocumentNumber::next($isReturn ? 'RTR' : 'TRF'),
                'kind' => $data['kind'],
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'stock_request_id' => $request?->id,
                'status' => StockTransfer::IN_TRANSIT,
                'reason_code' => $isReturn ? $data['reason_code'] : null,
                'notes' => $data['notes'] ?? null,
                'sent_by' => $user->id,
                'sent_at' => now(),
            ]);
            $source = $this->source($transfer);

            $summary = [];
            foreach ($data['lines'] as $i => $line) {
                /** @var Item $item */
                $item = $items[$line['item_id']];
                $factor = $this->quantities->factor($item, (int) $line['unit_id'], "lines.{$i}.unit_id");
                $qty = $this->quantities->toBase((string) $line['qty'], $factor);
                $movements = $this->ledger->issue($item, $from, $qty, StockMovement::TRANSFER_OUT, $source, $user);
                $transfer->lines()->create([
                    'item_id' => $item->id, 'unit_id' => (int) $line['unit_id'], 'factor' => $factor,
                    'sent_qty' => $qty, 'unit_cost' => $movements[0]->unit_cost, 'position' => $i,
                ]);
                $summary[] = ['barang' => $item->code, 'jumlah' => $qty];
            }
            if ($request) {
                $this->requests->markStatus($request, StockRequest::IN_TRANSIT);
            }

            $this->audit->record('STOCK_TRANSFER_SENT', $transfer, ['dari' => $from->code, 'ke' => $to->code, 'baris' => $summary, 'permintaan' => $request?->number],
                $role, $data['notes'] ?? null, locationId: $from->id, user: $user);

            return $transfer;
        });
    }

    /** @param  list<array{line_id: int, received_qty: numeric}>  $lines */
    public function receive(StockTransfer $transfer, array $lines, ?string $note, User $user): StockTransfer
    {
        $to = $transfer->toLocation;
        $role = $this->access->ensureOperator($user, $to);

        return DB::transaction(function () use ($transfer, $lines, $note, $user, $role, $to) {
            $transfer = $this->lock($transfer);
            $this->ensureInTransit($transfer);
            if ($transfer->sent_by === $user->id) {
                throw new AuthorizationException('Barang tidak dapat diterima oleh pengirimnya sendiri.');
            }

            $byId = collect($lines)->keyBy('line_id');
            $source = $this->source($transfer);
            $summary = [];
            foreach ($transfer->lines()->with('item')->get() as $line) {
                if (! $byId->has($line->id)) {
                    throw ValidationException::withMessages(['lines' => "Isi jumlah diterima untuk {$line->item->name}."]);
                }
                $received = $this->quantities->toBase((string) $byId[$line->id]['received_qty'], (string) $line->factor);
                $line->update(['received_qty' => $received]);
                if (Decimal::cmp($received, '0') > 0) {
                    $this->ledger->receive($line->item, $to, $received, $line->unit_cost === null ? null : (string) $line->unit_cost,
                        StockMovement::TRANSFER_IN, $source, $user, ['batch_no' => $transfer->number, 'batch_source' => 'TRANSFER']);
                }
                $summary[] = ['barang' => $line->item->code, 'dikirim' => (string) $line->sent_qty, 'diterima' => $received, 'selisih' => $line->difference()];
            }
            if ($byId->keys()->diff($transfer->lines()->pluck('id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Ada baris yang bukan bagian dari pengiriman ini.']);
            }

            $transfer->update(['status' => StockTransfer::RECEIVED, 'received_by' => $user->id, 'received_at' => now(), 'receive_note' => $note]);
            if ($transfer->stock_request_id) {
                $this->requests->markStatus($transfer->stockRequest, StockRequest::COMPLETED);
            }

            $this->audit->record('STOCK_TRANSFER_RECEIVED', $transfer, ['baris' => $summary], $role, $note, locationId: $to->id, user: $user);

            return $transfer;
        });
    }

    public function resolve(StockTransfer $transfer, StockTransferLine $line, string $resolution, string $note, User $user): StockTransfer
    {
        $from = $transfer->fromLocation;
        $role = $this->access->ensureOperator($user, $from);

        return DB::transaction(function () use ($transfer, $line, $resolution, $note, $user, $role, $from) {
            $transfer = $this->lock($transfer);
            $line = StockTransferLine::query()->whereKey($line->id)->where('stock_transfer_id', $transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== StockTransfer::RECEIVED || ! $line->needsResolution()) {
                throw ValidationException::withMessages(['resolution' => 'Baris ini tidak punya selisih yang perlu dijelaskan.']);
            }
            $diff = (string) $line->difference();
            $short = Decimal::cmp($diff, '0') > 0;
            $allowed = $short ? [StockTransferLine::SHORT_SHIPPED, StockTransferLine::LOST_IN_TRANSIT] : [StockTransferLine::OVER_SENT];
            if (! in_array($resolution, $allowed, true)) {
                throw ValidationException::withMessages(['resolution' => $short
                    ? 'Barang diterima kurang: pilih "kurang kirim" atau "hilang/rusak di perjalanan".'
                    : 'Barang diterima lebih: pilih "kelebihan kirim".']);
            }

            $item = $line->item;
            $source = $this->source($transfer);
            if ($resolution === StockTransferLine::SHORT_SHIPPED) {
                $this->restore($transfer, $line, $diff, StockMovement::TRANSFER_SHORT_RETURN, $user);
            } elseif ($resolution === StockTransferLine::OVER_SENT) {
                $this->ledger->issue($item, $from, bcmul($diff, '-1', 4), StockMovement::TRANSFER_OVER_SENT, $source, $user, note: $note);
            }
            // Hilang/rusak di perjalanan: stok pengirim sudah berkurang saat dikirim dan stok
            // penerima hanya bertambah sebesar yang diterima, jadi tidak ada pergerakan baru.
            // Nilainya (selisih × harga pokok kirim) dilaporkan sebagai susut pengirim.

            $line->update(['resolution' => $resolution, 'resolution_note' => $note, 'resolved_by' => $user->id, 'resolved_at' => now()]);

            $this->audit->record('STOCK_TRANSFER_DIFFERENCE_RESOLVED', $transfer, [
                'barang' => $item->code, 'selisih' => $diff, 'penjelasan' => $resolution,
                'nilai' => $line->unit_cost === null ? null : Decimal::mul($diff, (string) $line->unit_cost),
            ], $role, $note, locationId: $from->id, user: $user);

            return $transfer;
        });
    }

    public function cancel(StockTransfer $transfer, string $note, User $user): StockTransfer
    {
        $from = $transfer->fromLocation;
        $role = $this->access->ensureOperator($user, $from);

        return DB::transaction(function () use ($transfer, $note, $user, $role, $from) {
            $transfer = $this->lock($transfer);
            $this->ensureInTransit($transfer);
            foreach ($transfer->lines()->with('item')->get() as $line) {
                $this->restore($transfer, $line, (string) $line->sent_qty, StockMovement::TRANSFER_CANCEL, $user);
            }
            $transfer->update(['status' => StockTransfer::CANCELLED, 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_note' => $note]);
            if ($transfer->stock_request_id) {
                $this->requests->markStatus($this->requests->lock($transfer->stockRequest), StockRequest::SUBMITTED);
            }
            $this->audit->record('STOCK_TRANSFER_CANCELLED', $transfer, null, $role, $note, locationId: $from->id, user: $user);

            return $transfer;
        });
    }

    /** Kembalikan barang ke stok pengirim; di gudang ke batch asalnya. */
    private function restore(StockTransfer $transfer, StockTransferLine $line, string $qty, string $type, User $user): void
    {
        $from = $transfer->fromLocation;
        $cost = $line->unit_cost === null ? null : (string) $line->unit_cost;
        $source = $this->source($transfer);

        if ($from->type !== Location::TYPE_WAREHOUSE) {
            $this->ledger->receive($line->item, $from, $qty, $cost, $type, $source, $user);

            return;
        }

        $left = $qty;
        $outs = StockMovement::query()
            ->where(['source_type' => 'stock_transfer', 'source_id' => $transfer->id, 'item_id' => $line->item_id, 'type' => StockMovement::TRANSFER_OUT])
            ->whereNotNull('batch_id')->orderBy('id')->get();
        foreach ($outs as $out) {
            if (Decimal::isZero($left)) {
                return;
            }
            $put = Decimal::min($left, bcmul((string) $out->qty, '-1', 4));
            $batch = StockBatch::query()->whereKey($out->batch_id)->lockForUpdate()->firstOrFail();
            $this->ledger->receive($line->item, $from, $put, $cost, $type, $source, $user, ['batch' => $batch]);
            $left = bcsub($left, $put, 4);
        }
        if (Decimal::cmp($left, '0') > 0) {
            $this->ledger->receive($line->item, $from, $left, $cost, $type, $source, $user, ['batch_no' => $transfer->number, 'batch_source' => 'TRANSFER']);
        }
    }

    /** @return array{type: string, id: int, number: string} */
    private function source(StockTransfer $transfer): array
    {
        return ['type' => 'stock_transfer', 'id' => $transfer->id, 'number' => $transfer->number];
    }

    private function lock(StockTransfer $transfer): StockTransfer
    {
        return StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
    }

    private function ensureInTransit(StockTransfer $transfer): void
    {
        if ($transfer->status !== StockTransfer::IN_TRANSIT) {
            throw ValidationException::withMessages(['status' => 'Pengiriman ini sudah diterima atau dibatalkan.']);
        }
    }
}
