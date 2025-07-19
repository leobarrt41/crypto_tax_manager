<?php

namespace App\Http\Controllers;

use App\Models\TradingLog;
use App\Models\TradingStrategy;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TradingLogController extends Controller
{
    /**
     * Display a listing of trading logs.
     */
    public function index(Request $request)
    {
        $query = auth()->user()->tradingLogs()
            ->with(['tradingStrategy', 'botOrder']);

        // Filtros
        if ($request->filled('strategy_id')) {
            $query->where('trading_strategy_id', $request->strategy_id);
        }

        if ($request->filled('log_level')) {
            $query->where('log_level', $request->log_level);
        }

        if ($request->filled('start_date')) {
            $query->where('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('created_at', '<=', $request->end_date);
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate(50);

        $strategies = auth()->user()->tradingStrategies()
            ->select('id', 'name')
            ->get();

        return Inertia::render('TradingLogs/Index', [
            'logs' => $logs,
            'strategies' => $strategies,
            'filters' => $request->only(['strategy_id', 'log_level', 'start_date', 'end_date'])
        ]);
    }

    /**
     * Display the specified trading log.
     */
    public function show(TradingLog $tradingLog)
    {
        $this->authorize('view', $tradingLog);

        $tradingLog->load(['tradingStrategy', 'botOrder']);

        return Inertia::render('TradingLogs/Show', [
            'log' => $tradingLog
        ]);
    }

    /**
     * Get logs for specific strategy.
     */
    public function byStrategy(TradingStrategy $strategy, Request $request)
    {
        $this->authorize('view', $strategy);

        $query = $strategy->tradingLogs();

        if ($request->filled('log_level')) {
            $query->where('log_level', $request->log_level);
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($logs);
    }

    /**
     * Get real-time logs.
     */
    public function realtime(Request $request)
    {
        $validated = $request->validate([
            'strategy_id' => 'nullable|exists:trading_strategies,id',
            'since' => 'nullable|date'
        ]);

        $query = auth()->user()->tradingLogs();

        if (isset($validated['strategy_id'])) {
            $query->where('trading_strategy_id', $validated['strategy_id']);
        }

        if (isset($validated['since'])) {
            $query->where('created_at', '>', $validated['since']);
        } else {
            $query->where('created_at', '>', now()->subMinutes(5));
        }

        $logs = $query->with(['tradingStrategy', 'botOrder'])
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json($logs);
    }

    /**
     * Get log statistics.
     */
    public function statistics(Request $request)
    {
        $validated = $request->validate([
            'strategy_id' => 'nullable|exists:trading_strategies,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date'
        ]);

        $query = auth()->user()->tradingLogs();

        if (isset($validated['strategy_id'])) {
            $query->where('trading_strategy_id', $validated['strategy_id']);
        }

        if (isset($validated['start_date'])) {
            $query->where('created_at', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->where('created_at', '<=', $validated['end_date']);
        }

        $statistics = [
            'total_logs' => $query->count(),
            'info_logs' => $query->where('log_level', 'info')->count(),
            'warning_logs' => $query->where('log_level', 'warning')->count(),
            'error_logs' => $query->where('log_level', 'error')->count(),
            'debug_logs' => $query->where('log_level', 'debug')->count(),
            'recent_errors' => $query->where('log_level', 'error')
                ->where('created_at', '>', now()->subHours(24))
                ->count()
        ];

        return response()->json($statistics);
    }

    /**
     * Clear old logs.
     */
    public function clearOld(Request $request)
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:365'
        ]);

        $cutoffDate = now()->subDays($validated['days']);

        $deletedCount = auth()->user()->tradingLogs()
            ->where('created_at', '<', $cutoffDate)
            ->delete();

        return response()->json([
            'message' => "Logs antigos removidos com sucesso!",
            'deleted_count' => $deletedCount
        ]);
    }

    /**
     * Export logs to file.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'strategy_id' => 'nullable|exists:trading_strategies,id',
            'log_level' => 'nullable|in:debug,info,warning,error',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'format' => 'required|in:csv,json,txt'
        ]);

        $query = auth()->user()->tradingLogs()
            ->with(['tradingStrategy', 'botOrder']);

        // Aplicar filtros
        if (isset($validated['strategy_id'])) {
            $query->where('trading_strategy_id', $validated['strategy_id']);
        }

        if (isset($validated['log_level'])) {
            $query->where('log_level', $validated['log_level']);
        }

        if (isset($validated['start_date'])) {
            $query->where('created_at', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->where('created_at', '<=', $validated['end_date']);
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        // Implementar exportação
        $filename = 'trading_logs_' . now()->format('Y-m-d_H-i-s') . '.' . $validated['format'];
        
        return response()->json([
            'message' => 'Exportação iniciada',
            'filename' => $filename,
            'total_records' => $logs->count()
        ]);
    }

    /**
     * Search logs.
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|min:3',
            'strategy_id' => 'nullable|exists:trading_strategies,id',
            'log_level' => 'nullable|in:debug,info,warning,error'
        ]);

        $query = auth()->user()->tradingLogs()
            ->with(['tradingStrategy', 'botOrder'])
            ->where(function($q) use ($validated) {
                $q->where('message', 'ILIKE', '%' . $validated['query'] . '%')
                  ->orWhere('context', 'ILIKE', '%' . $validated['query'] . '%');
            });

        if (isset($validated['strategy_id'])) {
            $query->where('trading_strategy_id', $validated['strategy_id']);
        }

        if (isset($validated['log_level'])) {
            $query->where('log_level', $validated['log_level']);
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($logs);
    }

    /**
     * Get error summary.
     */
    public function errorSummary(Request $request)
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:30'
        ]);

        $days = $validated['days'] ?? 7;
        $startDate = now()->subDays($days);

        $errors = auth()->user()->tradingLogs()
            ->where('log_level', 'error')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('message, COUNT(*) as count')
            ->groupBy('message')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'period_days' => $days,
            'total_errors' => $errors->sum('count'),
            'unique_errors' => $errors->count(),
            'top_errors' => $errors
        ]);
    }

    /**
     * Mark logs as read.
     */
    public function markAsRead(Request $request)
    {
        $validated = $request->validate([
            'log_ids' => 'required|array',
            'log_ids.*' => 'exists:trading_logs,id'
        ]);

        $updatedCount = auth()->user()->tradingLogs()
            ->whereIn('id', $validated['log_ids'])
            ->update(['is_read' => true]);

        return response()->json([
            'message' => 'Logs marcados como lidos',
            'updated_count' => $updatedCount
        ]);
    }
}

