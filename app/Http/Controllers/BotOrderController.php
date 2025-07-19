<?php

namespace App\Http\Controllers;

use App\Models\BotOrder;
use App\Models\TradingStrategy;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BotOrderController extends Controller
{
    /**
     * Display a listing of bot orders.
     */
    public function index(Request $request)
    {
        $query = auth()->user()->botOrders()
            ->with(['tradingStrategy', 'cryptoAsset']);

        // Filtros
        if ($request->filled('strategy_id')) {
            $query->where('trading_strategy_id', $request->strategy_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate(20);

        $strategies = auth()->user()->tradingStrategies()
            ->select('id', 'name')
            ->get();

        return Inertia::render('BotOrders/Index', [
            'orders' => $orders,
            'strategies' => $strategies,
            'filters' => $request->only(['strategy_id', 'status', 'order_type'])
        ]);
    }

    /**
     * Display the specified bot order.
     */
    public function show(BotOrder $botOrder)
    {
        $this->authorize('view', $botOrder);

        $botOrder->load(['tradingStrategy', 'cryptoAsset', 'tradingLogs']);

        return Inertia::render('BotOrders/Show', [
            'order' => $botOrder
        ]);
    }

    /**
     * Cancel the specified bot order.
     */
    public function cancel(BotOrder $botOrder)
    {
        $this->authorize('update', $botOrder);

        if (!in_array($botOrder->status, ['pending', 'partial'])) {
            return response()->json([
                'message' => 'Ordem não pode ser cancelada no status atual.'
            ], 400);
        }

        try {
            // Implementar cancelamento na exchange
            // Usar ExchangeConnector service
            
            $botOrder->update([
                'status' => 'cancelled',
                'cancelled_at' => now()
            ]);

            return response()->json([
                'message' => 'Ordem cancelada com sucesso!',
                'order' => $botOrder
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao cancelar ordem: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retry the specified bot order.
     */
    public function retry(BotOrder $botOrder)
    {
        $this->authorize('update', $botOrder);

        if ($botOrder->status !== 'failed') {
            return response()->json([
                'message' => 'Apenas ordens falhadas podem ser reexecutadas.'
            ], 400);
        }

        try {
            // Implementar reexecução da ordem
            // Usar TradingBotEngine service
            
            $botOrder->update([
                'status' => 'pending',
                'error_message' => null,
                'retry_count' => $botOrder->retry_count + 1
            ]);

            return response()->json([
                'message' => 'Ordem reexecutada com sucesso!',
                'order' => $botOrder
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao reexecutar ordem: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get order statistics.
     */
    public function statistics(Request $request)
    {
        $validated = $request->validate([
            'strategy_id' => 'nullable|exists:trading_strategies,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date'
        ]);

        $query = auth()->user()->botOrders();

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
            'total_orders' => $query->count(),
            'completed_orders' => $query->where('status', 'completed')->count(),
            'pending_orders' => $query->where('status', 'pending')->count(),
            'failed_orders' => $query->where('status', 'failed')->count(),
            'cancelled_orders' => $query->where('status', 'cancelled')->count(),
            'total_volume' => $query->where('status', 'completed')->sum('quantity'),
            'total_value' => $query->where('status', 'completed')->sum('total_value'),
            'avg_order_size' => $query->where('status', 'completed')->avg('quantity'),
            'success_rate' => 0
        ];

        if ($statistics['total_orders'] > 0) {
            $statistics['success_rate'] = round(
                ($statistics['completed_orders'] / $statistics['total_orders']) * 100, 
                2
            );
        }

        return response()->json($statistics);
    }

    /**
     * Get orders by strategy.
     */
    public function byStrategy(TradingStrategy $strategy)
    {
        $this->authorize('view', $strategy);

        $orders = $strategy->botOrders()
            ->with('cryptoAsset')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($orders);
    }

    /**
     * Get recent orders.
     */
    public function recent(Request $request)
    {
        $limit = $request->input('limit', 10);

        $orders = auth()->user()->botOrders()
            ->with(['tradingStrategy', 'cryptoAsset'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($orders);
    }

    /**
     * Export orders to CSV.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'strategy_id' => 'nullable|exists:trading_strategies,id',
            'status' => 'nullable|in:pending,partial,completed,failed,cancelled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date'
        ]);

        $query = auth()->user()->botOrders()
            ->with(['tradingStrategy', 'cryptoAsset']);

        // Aplicar filtros
        if (isset($validated['strategy_id'])) {
            $query->where('trading_strategy_id', $validated['strategy_id']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['start_date'])) {
            $query->where('created_at', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->where('created_at', '<=', $validated['end_date']);
        }

        $orders = $query->orderBy('created_at', 'desc')->get();

        // Implementar exportação CSV
        $filename = 'bot_orders_' . now()->format('Y-m-d_H-i-s') . '.csv';
        
        return response()->json([
            'message' => 'Exportação iniciada',
            'filename' => $filename,
            'total_records' => $orders->count()
        ]);
    }

    /**
     * Bulk cancel orders.
     */
    public function bulkCancel(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => 'required|array',
            'order_ids.*' => 'exists:bot_orders,id'
        ]);

        $orders = auth()->user()->botOrders()
            ->whereIn('id', $validated['order_ids'])
            ->whereIn('status', ['pending', 'partial'])
            ->get();

        $cancelled = 0;
        $errors = [];

        foreach ($orders as $order) {
            try {
                // Implementar cancelamento na exchange
                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now()
                ]);
                $cancelled++;
            } catch (\Exception $e) {
                $errors[] = "Ordem {$order->id}: " . $e->getMessage();
            }
        }

        return response()->json([
            'message' => "{$cancelled} ordens canceladas com sucesso!",
            'cancelled_count' => $cancelled,
            'errors' => $errors
        ]);
    }
}

