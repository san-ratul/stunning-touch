<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(): View
    {
        return view('admin.audit-logs.index', [
            'logs' => AuditLog::query()
                ->with('user')
                ->when(request()->filled('action'), fn ($q) => $q->where('action', 'like', '%'.request('action').'%'))
                ->latest('created_at')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }
}
