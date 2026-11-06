<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Modules\Cash\Models\CashHandover;
use App\Modules\Cash\Models\NoncashReconciliation;
use App\Modules\Cash\Models\PettyCashRefill;
use App\Modules\Cash\Services\CashAccess;
use App\Modules\Cash\Services\HandoverService;
use App\Modules\Cash\Services\PettyCashService;
use App\Modules\Cash\Services\ReconciliationService;
use App\Modules\Catalog\Models\PaymentMethod;
use App\Modules\Identity\Models\User;
use App\Modules\Location\Models\Location;
use App\Modules\Sales\Models\CashierShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Ringkasan tiga dana, pencocokan non-tunai, dan daftar selisih. */
class CashOverviewController
{
    public function __construct(
        private readonly CashAccess $access,
        private readonly HandoverService $handovers,
        private readonly PettyCashService $petty,
        private readonly ReconciliationService $recon,
    ) {}

    /** Tiga dana per outlet (C14) dan kas yang sedang di gudang atau menuju Finance (G-02). */
    public function overview(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ids = $this->access->locationIds($user);
        $locations = Location::query()->where('status', '<>', Location::STATUS_CLOSED)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('type', 'desc')->orderBy('code')->get();

        $outlets = [];
        $warehouse = null;
        foreach ($locations as $loc) {
            if ($loc->type === Location::TYPE_WAREHOUSE) {
                $atWarehouse = $this->handovers->pendingAtWarehouse();
                $warehouse = [
                    'location' => $loc->only(['id', 'code', 'name']),
                    'incoming' => (int) CashHandover::query()->where(['stage' => CashHandover::STAGE_OUTLET, 'status' => CashHandover::STATUS_SENT])->sum('reported_amount'),
                    'at_warehouse' => (int) $atWarehouse->sum('received_amount'),
                    'to_finance' => (int) CashHandover::query()->where(['stage' => CashHandover::STAGE_WAREHOUSE, 'status' => CashHandover::STATUS_SENT])->sum('reported_amount'),
                ];

                continue;
            }
            $running = CashierShift::query()->where('location_id', $loc->id)->whereIn('status', CashierShift::RUNNING)->first();
            $last = CashierShift::query()->where('location_id', $loc->id)->where('status', CashierShift::STATUS_CLOSED)->orderByDesc('closed_at')->first();
            $pendingShifts = $this->handovers->pendingShifts($loc);
            $outlets[] = [
                'location' => $loc->only(['id', 'code', 'name']),
                'cash_float' => $running?->float_counted ?? $last?->float_kept,
                'cash_float_target' => (int) config('pos.cash_float'),
                'shift_running' => $running !== null,
                'sales_cash_at_outlet' => (int) $pendingShifts->sum('sales_cash'),
                'shifts_to_hand_over' => $pendingShifts->count(),
                'sales_cash_in_transit' => (int) CashHandover::query()->where(['stage' => CashHandover::STAGE_OUTLET, 'status' => CashHandover::STATUS_SENT, 'from_location_id' => $loc->id])->sum('reported_amount'),
                'petty_cash' => $this->petty->balance($loc->id),
                'petty_cash_target' => $loc->petty_cash_amount,
                'expenses_to_check' => $this->petty->pendingExpenses($loc->id)->count(),
                'refill_requested' => PettyCashRefill::query()->where(['location_id' => $loc->id, 'status' => PettyCashRefill::STATUS_REQUESTED])->exists(),
            ];
        }

        return response()->json(['data' => [
            'outlets' => $outlets,
            'warehouse' => $warehouse,
            'sees_all' => $this->access->seesAll($user),
            'open_discrepancies' => $this->access->seesAll($user) ? count(array_filter($this->recon->discrepancies(), fn ($d) => $d['resolution'] === null)) : null,
        ]]);
    }

    /** Pencocokan non-tunai per tanggal dan metode untuk satu rentang (bawaan: 14 hari terakhir). */
    public function reconciliations(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:62']]);
        $this->ensureSeesAll($request->user());
        $tz = config('app.timezone');
        $methods = PaymentMethod::query()->where('is_cash', false)->orderBy('name')->get();
        $done = NoncashReconciliation::query()->with('reconciler:id,name')
            ->where('business_date', '>=', now()->setTimezone($tz)->subDays(($data['days'] ?? 14) - 1)->toDateString())->get()
            ->keyBy(fn ($r) => $r->business_date->format('Y-m-d').'|'.$r->payment_method_id);

        $rows = [];
        for ($i = 0; $i < ($data['days'] ?? 14); $i++) {
            $date = now()->setTimezone($tz)->subDays($i)->toDateString();
            foreach ($methods as $m) {
                $rec = $done->get("{$date}|{$m->id}");
                $system = $rec?->system_amount ?? $this->recon->systemAmount($date, $m->id);
                if (! $rec && $system === 0 && ! $m->is_active) {
                    continue;
                }
                $rows[] = [
                    'business_date' => $date,
                    'method' => $m->only(['id', 'code', 'name']),
                    'system_amount' => $system,
                    'reconciliation' => $rec ? [
                        'id' => $rec->id, 'bank_amount' => $rec->bank_amount, 'difference' => $rec->difference, 'note' => $rec->note,
                        'by' => $rec->reconciler?->name, 'at' => $rec->created_at,
                    ] : null,
                ];
            }
        }

        return response()->json(['data' => $rows]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['required', 'date_format:Y-m-d'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'bank_amount' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['bank_amount.required' => 'Isi total mutasi di rekening untuk tanggal ini.']);
        $rec = $this->recon->reconcile($data, $request->user());

        return response()->json(['data' => ['id' => $rec->id, 'difference' => $rec->difference]], 201);
    }

    /** Semua selisih kas dan keputusan Finance atasnya (KP-12): Finance memutuskan, Owner melihat. */
    public function discrepancyIndex(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['OPEN', 'ALL'])]]);
        $this->ensureSeesAll($request->user());
        $rows = $this->recon->discrepancies();
        if ($request->query('status', 'OPEN') === 'OPEN') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['resolution'] === null));
        }

        return response()->json(['data' => $rows, 'can_resolve' => $request->user()->hasActiveRole('FINANCE')]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(ReconciliationService::SUBJECTS))],
            'subject_id' => ['required', 'integer'],
            'category' => ['required', Rule::in(ReconciliationService::CATEGORIES)],
            'decision' => ['required', 'string', 'max:1000'],
        ], ['decision.required' => 'Tuliskan keputusan penyelesaian selisih.', 'category.required' => 'Pilih jenis penyelesaian selisih.']);
        $this->recon->resolve($data['subject_type'], (int) $data['subject_id'], $data['decision'], $request->user(), $data['category']);

        return response()->json(['data' => ['ok' => true]], 201);
    }

    private function ensureSeesAll(User $user): void
    {
        if (! $this->access->seesAll($user)) {
            abort(response()->json(['message' => 'Halaman ini untuk Owner dan Finance.'], 403));
        }
    }
}
