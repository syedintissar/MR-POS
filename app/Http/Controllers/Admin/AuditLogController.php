<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('audit_logs.view');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer'],
            'module' => ['nullable', 'string', 'max:50'],
            'event' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name,username')
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->string('module')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->filled('q'), fn ($q) => $q->where('description', 'like', '%'.$request->string('q')->trim().'%'))
            ->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'username']),
            'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module'),
            'events' => AuditLog::query()->distinct()->orderBy('event')->pluck('event'),
        ]);
    }

    public function verify(AuditLogger $audit): RedirectResponse
    {
        $this->authorize('audit_logs.view');

        $problem = $audit->verifyChain();

        return back()->with($problem ? 'error' : 'success', $problem ?? 'Audit log verified: every entry is intact and in order.');
    }
}
