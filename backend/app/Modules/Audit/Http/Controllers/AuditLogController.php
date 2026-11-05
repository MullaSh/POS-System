<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penelusuran jejak audit lintas orang, lokasi, dan periode: hanya Owner (3F-Q1).
 */
class AuditLogController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:60'],
            'subject_type' => ['nullable', 'string', 'max:60'],
            'subject_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $logs = AuditLog::query()
            ->with(['user:id,name,username', 'role:id,code,name', 'location:id,code,name'])
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('action'), fn ($q, $v) => $q->where('action', $v))
            ->when($request->query('subject_type'), fn ($q, $v) => $q->where('subject_type', $v))
            ->when($request->query('subject_id'), fn ($q, $v) => $q->where('subject_id', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->where('recorded_at', '>=', $v.' 00:00:00'))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('recorded_at', '<=', $v.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json($logs);
    }
}
