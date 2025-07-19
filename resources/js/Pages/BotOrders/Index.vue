<template>
  <AppLayout title="Ordens do Bot">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Ordens do Bot
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Acompanhe todas as ordens executadas pelo trading bot
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <button
              @click="refreshOrders"
              :disabled="loading"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50"
            >
              🔄 {{ loading ? 'Atualizando...' : 'Atualizar' }}
            </button>
            <button
              @click="exportOrders"
              class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              📥 Exportar
            </button>
          </div>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Total de Ordens"
            :value="stats.total_orders || 0"
            format="number"
            icon="chart-bar"
            color="blue"
            :change="stats.orders_24h"
            change-label="24h"
          />
          <StatCard
            title="Volume Total"
            :value="stats.total_volume || 0"
            format="currency"
            icon="currency-dollar"
            color="green"
            :change="stats.volume_24h"
            change-label="24h"
          />
          <StatCard
            title="Ordens Executadas"
            :value="stats.executed_orders || 0"
            format="number"
            icon="check-circle"
            color="green"
            :change="stats.executed_24h"
            change-label="24h"
          />
          <StatCard
            title="Taxa de Sucesso"
            :value="stats.success_rate || 0"
            format="percentage"
            icon="trending-up"
            :color="(stats.success_rate || 0) >= 80 ? 'green' : (stats.success_rate || 0) >= 60 ? 'yellow' : 'red'"
          />
        </div>
      </div>

      <!-- Filters -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Filtros</h3>
          </div>
          <div class="px-6 py-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
              
              <!-- Search -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Buscar</label>
                <input
                  v-model="filters.search"
                  @input="applyFilters"
                  type="text"
                  placeholder="Símbolo, estratégia..."
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                >
              </div>

              <!-- Status -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Status</label>
                <select
                  v-model="filters.status"
                  @change="applyFilters"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                >
                  <option value="">Todos</option>
                  <option value="pending">Pendente</option>
                  <option value="executed">Executada</option>
                  <option value="cancelled">Cancelada</option>
                  <option value="failed">Falhou</option>
                </select>
              </div>

              <!-- Side -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Tipo</label>
                <select
                  v-model="filters.side"
                  @change="applyFilters"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                >
                  <option value="">Todos</option>
                  <option value="buy">Compra</option>
                  <option value="sell">Venda</option>
                </select>
              </div>

              <!-- Strategy -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Estratégia</label>
                <select
                  v-model="filters.strategy_id"
                  @change="applyFilters"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                >
                  <option value="">Todas</option>
                  <option v-for="strategy in strategies" :key="strategy.id" :value="strategy.id">
                    {{ strategy.name }}
                  </option>
                </select>
              </div>

              <!-- Period -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Período</label>
                <select
                  v-model="filters.period"
                  @change="applyFilters"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                >
                  <option value="">Todos</option>
                  <option value="today">Hoje</option>
                  <option value="week">Esta semana</option>
                  <option value="month">Este mês</option>
                  <option value="quarter">Este trimestre</option>
                </select>
              </div>

            </div>
          </div>
        </div>
      </div>

      <!-- Orders List -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-medium text-gray-900">
                Ordens ({{ orders.total || 0 }})
              </h3>
              <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-500">Ordenar por:</span>
                <select
                  v-model="sortBy"
                  @change="applyFilters"
                  class="text-sm border-gray-300 rounded-md focus:ring-primary focus:border-primary"
                >
                  <option value="created_at">Data</option>
                  <option value="amount">Valor</option>
                  <option value="symbol">Símbolo</option>
                  <option value="status">Status</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Loading State -->
          <div v-if="loading" class="px-6 py-8">
            <div class="space-y-4">
              <div v-for="i in 5" :key="i" class="animate-pulse">
                <div class="h-16 bg-gray-200 rounded"></div>
              </div>
            </div>
          </div>

          <!-- Empty State -->
          <div v-else-if="!orders.data?.length" class="px-6 py-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhuma ordem encontrada</h3>
            <p class="mt-1 text-sm text-gray-500">
              As ordens do bot aparecerão aqui quando forem executadas.
            </p>
          </div>

          <!-- Orders List -->
          <div v-else class="divide-y divide-gray-200">
            <div v-for="order in orders.data" :key="order.id" class="px-6 py-4 hover:bg-gray-50">
              <div class="flex items-center justify-between">
                
                <!-- Order Info -->
                <div class="flex items-center">
                  <div 
                    class="h-10 w-10 rounded-full flex items-center justify-center mr-4"
                    :class="order.side === 'buy' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'"
                  >
                    <span class="text-sm font-bold">{{ order.side === 'buy' ? '↗' : '↘' }}</span>
                  </div>
                  <div>
                    <div class="text-sm font-medium text-gray-900">
                      {{ order.side === 'buy' ? 'Compra' : 'Venda' }} • {{ order.symbol }}
                    </div>
                    <div class="text-sm text-gray-500">
                      {{ order.quantity }} @ {{ formatCurrency(order.price) }}
                    </div>
                  </div>
                </div>

                <!-- Strategy & Status -->
                <div class="text-center">
                  <div class="text-sm text-gray-900">{{ order.strategy?.name || 'Manual' }}</div>
                  <span 
                    :class="getStatusBadgeClass(order.status)"
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                  >
                    {{ getStatusLabel(order.status) }}
                  </span>
                </div>

                <!-- Amount & Date -->
                <div class="text-right">
                  <div class="text-sm font-medium text-gray-900">{{ formatCurrency(order.amount) }}</div>
                  <div class="text-sm text-gray-500">{{ formatRelativeTime(order.created_at) }}</div>
                </div>

                <!-- Actions -->
                <div class="flex items-center space-x-2">
                  <Link
                    :href="`/bot-orders/${order.id}`"
                    class="text-primary hover:text-primary-dark text-sm"
                  >
                    Ver
                  </Link>
                  <button
                    v-if="order.status === 'pending'"
                    @click="cancelOrder(order.id)"
                    class="text-red-600 hover:text-red-900 text-sm"
                  >
                    Cancelar
                  </button>
                </div>

              </div>
            </div>
          </div>

        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  orders: Object,
  stats: Object,
  strategies: Array,
  filters: Object,
})

// Reactive data
const loading = ref(false)
const sortBy = ref('created_at')

const filters = ref({
  search: props.filters?.search || '',
  status: props.filters?.status || '',
  side: props.filters?.side || '',
  strategy_id: props.filters?.strategy_id || '',
  period: props.filters?.period || '',
})

// Methods
const refreshOrders = () => {
  loading.value = true
  router.reload({
    onFinish: () => {
      loading.value = false
    }
  })
}

const applyFilters = () => {
  const params = {
    ...filters.value,
    sort_by: sortBy.value,
  }
  
  router.get('/bot-orders', params, {
    preserveState: true,
    preserveScroll: true,
  })
}

const cancelOrder = (orderId) => {
  if (confirm('Tem certeza que deseja cancelar esta ordem?')) {
    router.post(`/bot-orders/${orderId}/cancel`)
  }
}

const exportOrders = () => {
  window.open('/bot-orders/export', '_blank')
}

// Helper functions
const getStatusBadgeClass = (status) => {
  const classes = {
    'pending': 'bg-yellow-100 text-yellow-800',
    'executed': 'bg-green-100 text-green-800',
    'cancelled': 'bg-gray-100 text-gray-800',
    'failed': 'bg-red-100 text-red-800'
  }
  return classes[status] || 'bg-gray-100 text-gray-800'
}

const getStatusLabel = (status) => {
  const labels = {
    'pending': 'Pendente',
    'executed': 'Executada',
    'cancelled': 'Cancelada',
    'failed': 'Falhou'
  }
  return labels[status] || status
}

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(amount || 0)
}

const formatRelativeTime = (date) => {
  const diff = Date.now() - new Date(date).getTime()
  const minutes = Math.floor(diff / (1000 * 60))
  const hours = Math.floor(diff / (1000 * 60 * 60))
  
  if (minutes < 60) return `${minutes} min atrás`
  if (hours < 24) return `${hours}h atrás`
  return `${Math.floor(hours / 24)} dias atrás`
}

// Auto-refresh
onMounted(() => {
  const interval = setInterval(() => {
    if (!loading.value) {
      refreshOrders()
    }
  }, 30000)

  return () => clearInterval(interval)
})
</script>

<style scoped>
.bg-primary {
  background-color: var(--primary-color, #3b82f6);
}

.text-primary {
  color: var(--primary-color, #3b82f6);
}

.hover\\:text-primary-dark:hover {
  color: var(--primary-dark, #2563eb);
}

.focus\\:ring-primary:focus {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

.hover\\:bg-primary-dark:hover {
  background-color: var(--primary-dark, #2563eb);
}
</style>

