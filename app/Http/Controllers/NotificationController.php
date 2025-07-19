<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationController extends Controller
{
    /**
     * Display a listing of notifications.
     */
    public function index(Request $request)
    {
        $query = auth()->user()->notifications();

        // Filtros
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('read_status')) {
            if ($request->read_status === 'read') {
                $query->whereNotNull('read_at');
            } else {
                $query->whereNull('read_at');
            }
        }

        $notifications = $query->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'filters' => $request->only(['type', 'read_status']),
            'unreadCount' => auth()->user()->unreadNotifications()->count()
        ]);
    }

    /**
     * Get unread notifications count.
     */
    public function unreadCount()
    {
        $count = auth()->user()->unreadNotifications()->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Get recent notifications.
     */
    public function recent(Request $request)
    {
        $limit = $request->input('limit', 10);

        $notifications = auth()->user()->notifications()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($notifications);
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead($id)
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        
        $notification->markAsRead();

        return response()->json([
            'message' => 'Notificação marcada como lida',
            'notification' => $notification
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead()
    {
        $count = auth()->user()->unreadNotifications()->count();
        
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Todas as notificações foram marcadas como lidas',
            'marked_count' => $count
        ]);
    }

    /**
     * Delete notification.
     */
    public function destroy($id)
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        
        $notification->delete();

        return response()->json([
            'message' => 'Notificação removida com sucesso'
        ]);
    }

    /**
     * Bulk delete notifications.
     */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'notification_ids' => 'required|array',
            'notification_ids.*' => 'string'
        ]);

        $count = auth()->user()->notifications()
            ->whereIn('id', $validated['notification_ids'])
            ->delete();

        return response()->json([
            'message' => 'Notificações removidas com sucesso',
            'deleted_count' => $count
        ]);
    }

    /**
     * Get notification settings.
     */
    public function settings()
    {
        $settings = auth()->user()->notification_settings ?? $this->getDefaultSettings();

        return Inertia::render('Notifications/Settings', [
            'settings' => $settings
        ]);
    }

    /**
     * Update notification settings.
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'email_notifications' => 'boolean',
            'push_notifications' => 'boolean',
            'trading_alerts' => 'boolean',
            'price_alerts' => 'boolean',
            'tax_reminders' => 'boolean',
            'portfolio_updates' => 'boolean',
            'security_alerts' => 'boolean',
            'system_updates' => 'boolean',
            'marketing_emails' => 'boolean'
        ]);

        auth()->user()->update([
            'notification_settings' => $validated
        ]);

        return response()->json([
            'message' => 'Configurações de notificação atualizadas com sucesso',
            'settings' => $validated
        ]);
    }

    /**
     * Send test notification.
     */
    public function sendTest(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:email,push,database'
        ]);

        try {
            $user = auth()->user();

            switch ($validated['type']) {
                case 'email':
                    // Implementar envio de email de teste
                    break;
                case 'push':
                    // Implementar push notification de teste
                    break;
                case 'database':
                    $user->notify(new \App\Notifications\TestNotification());
                    break;
            }

            return response()->json([
                'message' => 'Notificação de teste enviada com sucesso'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao enviar notificação de teste: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create price alert.
     */
    public function createPriceAlert(Request $request)
    {
        $validated = $request->validate([
            'crypto_asset_id' => 'required|exists:crypto_assets,id',
            'condition' => 'required|in:above,below',
            'target_price' => 'required|numeric|min:0',
            'is_active' => 'boolean'
        ]);

        $alert = auth()->user()->priceAlerts()->create($validated);

        return response()->json([
            'message' => 'Alerta de preço criado com sucesso',
            'alert' => $alert
        ]);
    }

    /**
     * Get price alerts.
     */
    public function priceAlerts()
    {
        $alerts = auth()->user()->priceAlerts()
            ->with('cryptoAsset')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($alerts);
    }

    /**
     * Update price alert.
     */
    public function updatePriceAlert(Request $request, $id)
    {
        $alert = auth()->user()->priceAlerts()->findOrFail($id);

        $validated = $request->validate([
            'condition' => 'required|in:above,below',
            'target_price' => 'required|numeric|min:0',
            'is_active' => 'boolean'
        ]);

        $alert->update($validated);

        return response()->json([
            'message' => 'Alerta de preço atualizado com sucesso',
            'alert' => $alert
        ]);
    }

    /**
     * Delete price alert.
     */
    public function deletePriceAlert($id)
    {
        $alert = auth()->user()->priceAlerts()->findOrFail($id);
        
        $alert->delete();

        return response()->json([
            'message' => 'Alerta de preço removido com sucesso'
        ]);
    }

    /**
     * Get notification statistics.
     */
    public function statistics()
    {
        $user = auth()->user();

        $stats = [
            'total_notifications' => $user->notifications()->count(),
            'unread_notifications' => $user->unreadNotifications()->count(),
            'read_notifications' => $user->readNotifications()->count(),
            'notifications_today' => $user->notifications()
                ->whereDate('created_at', today())
                ->count(),
            'notifications_this_week' => $user->notifications()
                ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->count(),
            'active_price_alerts' => $user->priceAlerts()
                ->where('is_active', true)
                ->count()
        ];

        return response()->json($stats);
    }

    /**
     * Export notifications.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'format' => 'required|in:csv,json',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'type' => 'nullable|string'
        ]);

        $query = auth()->user()->notifications();

        if (isset($validated['start_date'])) {
            $query->where('created_at', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->where('created_at', '<=', $validated['end_date']);
        }

        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $notifications = $query->orderBy('created_at', 'desc')->get();

        $filename = 'notifications_' . now()->format('Y-m-d_H-i-s') . '.' . $validated['format'];

        return response()->json([
            'message' => 'Exportação de notificações iniciada',
            'filename' => $filename,
            'total_records' => $notifications->count()
        ]);
    }

    /**
     * Get default notification settings.
     */
    private function getDefaultSettings()
    {
        return [
            'email_notifications' => true,
            'push_notifications' => true,
            'trading_alerts' => true,
            'price_alerts' => true,
            'tax_reminders' => true,
            'portfolio_updates' => true,
            'security_alerts' => true,
            'system_updates' => false,
            'marketing_emails' => false
        ];
    }
}

