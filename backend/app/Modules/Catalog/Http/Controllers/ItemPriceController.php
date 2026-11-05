<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Approval\Http\Controllers\ApprovalController;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\PriceService;
use App\Modules\Identity\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Harga jual menu (KP-16, AB-23, G-05: Finance mengajukan, Owner menyetujui). */
class ItemPriceController
{
    public function __construct(
        private readonly PriceService $prices,
        private readonly ApprovalService $approvals,
    ) {}

    /** Finance mengajukan harga baru untuk disetujui Owner. */
    public function request(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate(PriceService::rules());
        $user = $request->user();

        // Pemegang peran Owner tidak perlu mengajukan ke dirinya sendiri: ubah langsung.
        if ($user->isOwner()) {
            return response()->json([
                'message' => 'Owner mengubah harga langsung, tanpa pengajuan.',
                'errors' => ['price' => ['Owner mengubah harga langsung, tanpa pengajuan.']],
            ], 422);
        }

        $approval = $this->approvals->submit(ApprovalRequest::TYPE_ITEM_PRICE, $item, $data, $data['reason'] ?? null, $user, Role::FINANCE);

        return response()->json(['data' => app(ApprovalController::class)->present($approval, $user, withEvents: true)], 201);
    }

    /** Owner mengubah harga langsung (tercatat sebagai keputusan langsung Owner). */
    public function setDirect(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate(PriceService::rules());
        $price = $this->prices->setDirect($item, $data, $request->user());

        return response()->json(['data' => $price], 201);
    }
}
