<template>
  <AppLayout :title="strategy?.name">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <div class="flex items-center">
              <div 
                class="h-12 w-12 rounded-full flex items-center justify-center mr-4"
                :class="getStrategyTypeColor(strategy?.type)"
              >
                <span class="text-xl font-bold text-white">
                  {{ getStrategyIcon(strategy?.type) }}
                </span>
              </div>
              <div>
                <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
                  {{ strategy?.name }}
                </h2>
                <div class="mt-1 flex items-center space-x-3">
                  <span 
                    :class="getStatusBadgeClass(strategy?.status)"
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                  >
                    {{ getStatusLabel(strategy?.status) }}
                  </span>
                  <span class="text-sm text-gray-500">{{ getStrategyTypeLabel(strategy?.type) }}</span>
                  <span class="text-sm text-gray-500">{{ strategy?.exchange }} • {{ strategy?.trading_pair }}</span>
                </div>
              </div>
            </div>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <button
              v-if="strategy?.status === 'inactive'"
              @click="startStrategy"
              class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
            >
              ▶️ Iniciar
            </button>
            <button
              v-else-if="strategy?.status === 'active'"
              @click="stopStrategy"
              class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
            >
              ⏹️ Parar
            </button>
            <button
              v-else-if="strategy?.status === 'paused'"
              @click="resumeStrategy"
              class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
            >
              ▶️ Retomar
            </button>
            <Link
              :href="`/trading-strategies/${strategy?.id}/edit`"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ✏️ Editar
            </Link>
            <Link
              href="/trading-strategies"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ← Voltar
            </Link>
          </div>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Performance Total"
            :value="strategy?.performance || 0"
            format="percentage"
            icon="trending-up"
            :color="(strategy?.performance || 0) >= 0 ? 'green' : 'red'"
            :change="strategy?.performance_24h"
            change-label="24h"
          />
          <StatCard
            title="Volume Negociado"
            :value="strategy?.total_volume || 0"
            format="currency"
            icon="currency-dollar"
            color="blue"
            :change="strategy?.volume_24h"
            change-label="24h"
          />
          <StatCard
            title="Total de Trades"
            :value="strategy?.total_trades || 0"
            format="number"
            icon="chart-bar"
            color="purple"
            :change="strategy?.trades_24h"
            change-label="24h"
          />
          <StatCard
            title="Win Rate"
            :value="strategy?.win_rate || 0"
            format="percentage"
            icon="check-circle"
            :color="(strategy?.win_rate || 0) >= 60 ? 'green' : (strategy?.win_rate || 0) >= 40 ? 'yellow' : 'red'"
          />
        </div>
      </div>

      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
          
          <!-- Main Content -->
          <div class="lg:col-span-2 space-y-6">
            
            <!-- Performance Chart -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                  <h3 class="text-lg font-medium text-gray-900">Performance</h3>
                  <div class="flex items-center space-x-2">
                    <select
                      v-model="selectedPeriod"
                      @change="updateChart"
                      class="text-sm border-gray-300 rounded-md focus:ring-primary focus:border-primary"
                    >
                      <option value="24h">24 horas</option>
                      <option value="7d">7 dias</option>
                      <option value="30d">30 dias</option>
                      <option value="90d">90 dias</option>
                    </select>
                  </div>
                </div>
              </div>
              <div class="px-6 py-4">
                <!-- Chart placeholder -->
                <div class="h-64 bg-gray-50 rounded-lg flex items-center justify-center">
                  <div class="text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">Gráfico de Performance</h3>
                    <p class="mt-1 text-sm text-gray-500">Chart.js será integrado aqui</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Recent Orders -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                  <h3 class="text-lg font-medium text-gray-900">Ordens Recentes</h3>
                  <Link
                    :href="`/bot-orders?strategy_id=${strategy?.id}`"
                    class="text-sm text-primary hover:text-primary-dark"
                  >
                    Ver todas →
                  </Link>
                </div>
              </div>
              <div class="px-6 py-4">
                
                <!-- Loading State -->
                <div v-if="loadingOrders" class="space-y-3">
                  <div v-for="i in 3" :key="i" class="animate-pulse">
                    <div class="h-16 bg-gray-200 rounded"></div>
                  </div>
                </div>

                <!-- Empty State -->
                <div v-else-if="!recentOrders?.length" class="text-center py-8">
                  <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                  </svg>
                  <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhuma ordem executada</h3>
                  <p class="mt-1 text-sm text-gray-500">As ordens aparecerão aqui quando a estratégia for executada.</p>
                </div>

                <!-- Orders List -->
                <div v-else class="space-y-3">
                  <div v-for="order in recentOrders" :key="order.id" class="flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                    <div class="flex items-center">
                      <div 
                        class="h-8 w-8 rounded-full flex items-center justify-center mr-3"
                        :class="order.side === 'buy' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'"
                      >
                        <span class="text-xs font-bold">{{ order.side === 'buy' ? '↗' : '↘' }}</span>
                      </div>
                      <div>
                        <div class="text-sm font-medium text-gray-900">
                          {{ order.side === 'buy' ? 'Compra' : 'Venda' }} • {{ order.symbol }}
                        </div>
                        <div class="text-xs text-gray-500">
                          {{ formatDate(order.created_at) }} • {{ order.status }}
                        </div>
                      </div>
                    </div>
                    <div class="text-right">
                      <div class="text-sm font-medium text-gray-900">{{ formatCurrency(order.amount) }}</div>
                      <div class="text-xs text-gray-500">{{ order.quantity }} {{ order.base_asset }}</div>
                    </div>
                  </div>
                </div>

              </div>
            </div>

            <!-- Trading Logs -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                  <h3 class="text-lg font-medium text-gray-900">Logs de Execução</h3>
                  <Link
                    :href="`/trading-logs?strategy_id=${strategy?.id}`"
                    class="text-sm text-primary hover:text-primary-dark"
                  >
                    Ver todos →
                  </Link>
                </div>
              </div>
              <div class="px-6 py-4">
                
                <!-- Loading State -->
                <div v-if="loadingLogs" class="space-y-2">
                  <div v-for="i in 5" :key="i" class="animate-pulse">
                    <div class="h-8 bg-gray-200 rounded"></div>
                  </div>
                </div>

                <!-- Empty State -->
                <div v-else-if="!recentLogs?.length" class="text-center py-8">
                  <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                  </svg>
                  <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum log disponível</h3>
                  <p class="mt-1 text-sm text-gray-500">Os logs de execução aparecerão aqui.</p>
                </div>

                <!-- Logs List -->
                <div v-else class="space-y-2">
                  <div v-for="log in recentLogs" :key="log.id" class="flex items-center justify-between py-2 border-b border-gray-100 last:border-b-0">
                    <div class="flex items-center">
                      <div 
                        class="h-2 w-2 rounded-full mr-3"
                        :class="getLogLevelColor(log.level)"
                      ></div>
                      <div>
                        <div class="text-sm text-gray-900">{{ log.message }}</div>
                        <div class="text-xs text-gray-500">{{ formatRelativeTime(log.created_at) }}</div>
                      </div>
                    </div>
                    <div class="text-xs text-gray-500">{{ log.level }}</div>
                  </div>
                </div>

              </div>
            </div>

          </div>

          <!-- Sidebar -->
          <div class="space-y-6">
            
            <!-- Strategy Details -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Detalhes da Estratégia</h3>
              </div>
              <div class="px-6 py-4 space-y-4">
                
                <div>
                  <dt class="text-sm font-medium text-gray-500">Descrição</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ strategy?.description || 'Sem descrição' }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Exchange</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ strategy?.exchange }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Par de Trading</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ strategy?.trading_pair }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Valor Base</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ formatCurrency(strategy?.base_amount) }}</dd>
                </div>

                <div v-if="strategy?.max_amount">
                  <dt class="text-sm font-medium text-gray-500">Valor Máximo</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ formatCurrency(strategy?.max_amount) }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Perda Máxima Diária</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ strategy?.max_daily_loss }}%</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Trades Simultâneos</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ strategy?.max_concurrent_trades }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Criada em</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ formatDate(strategy?.created_at) }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Última execução</dt>
                  <dd class="mt-1 text-sm text-gray-900">
                    {{ strategy?.last_execution ? formatRelativeTime(strategy.last_execution) : 'Nunca' }}
                  </dd>
                </div>

              </div>
            </div>

            <!-- Strategy Parameters -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Parâmetros</h3>
              </div>
              <div class="px-6 py-4 space-y-3">
                
                <div v-if="strategy?.parameters" class="space-y-3">
                  <div v-for="(value, key) in strategy.parameters" :key="key">
                    <dt class="text-sm font-medium text-gray-500">{{ formatParameterName(key) }}</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ formatParameterValue(key, value) }}</dd>
                  </div>
                </div>

                <div v-else class="text-sm text-gray-500">
                  Nenhum parâmetro configurado
                </div>

              </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Ações Rápidas</h3>
              </div>
              <div class="px-6 py-4 space-y-3">
                
                <button
                  @click="runBacktest"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  📊 Executar Backtest
                </button>

                <button
                  @click="cloneStrategy"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  📋 Clonar Estratégia
                </button>

                <button
                  @click="exportData"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  📥 Exportar Dados
                </button>

                <Link
                  :href="`/reports?strategy_id=${strategy?.id}`"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  📄 Gerar Relatório
                </Link>

              </div>
            </div>

          </div>

        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  strategy: Object,
  recentOrders: Array,
  recentLogs: Array,
})

// Reactive data
const selectedPeriod = ref('7d')
const loadingOrders = ref(false)
const loadingLogs = ref(false)

// Methods
const startStrategy = () => {
  router.post(`/trading-strategies/${props.strategy.id}/start`)
}

const stopStrategy = () => {
  router.post(`/trading-strategies/${props.strategy.id}/stop`)
}

const resumeStrategy = () => {
  router.post(`/trading-strategies/${props.strategy.id}/resume`)
}

const runBacktest = () => {
  router.get(`/backtesting?strategy_id=${props.strategy.id}`)
}

const cloneStrategy = () => {
  router.post(`/trading-strategies/${props.strategy.id}/clone`)
}

const exportData = () => {
  window.open(`/trading-strategies/${props.strategy.id}/export`, '_blank')
}

const updateChart = () => {
  // Update chart based on selected period
  console.log('Updating chart for period:', selectedPeriod.value)
}

// Helper functions
const getStrategyTypeColor = (type) => {
  const colors = {
    'scalping': 'bg-red-500',
    'swing': 'bg-blue-500',
    'arbitrage': 'bg-green-500',
    'grid': 'bg-purple-500',
    'dca': 'bg-yellow-500',
    'momentum': 'bg-indigo-500'
  }
  return colors[type] || 'bg-gray-500'
}

const getStrategyIcon = (type) => {
  const icons = {
    'scalping': '⚡',
    'swing': '📈',
    'arbitrage': '⚖️',
    'grid': '🔲',
    'dca': '📊',
    'momentum': '🚀'
  }
  return icons[type] || '🤖'
}

const getStatusBadgeClass = (status) => {
  const classes = {
    'active': 'bg-green-100 text-green-800',
    'inactive': 'bg-gray-100 text-gray-800',
    'paused': 'bg-yellow-100 text-yellow-800',
    'testing': 'bg-blue-100 text-blue-800'
  }
  return classes[status] || 'bg-gray-100 text-gray-800'
}

const getStatusLabel = (status) => {
  const labels = {
    'active': 'Ativa',
    'inactive': 'Inativa',
    'paused': 'Pausada',
    'testing': 'Testando'
  }
  return labels[status] || status
}

const getStrategyTypeLabel = (type) => {
  const labels = {
    'scalping': 'Scalping',
    'swing': 'Swing Trading',
    'arbitrage': 'Arbitragem',
    'grid': 'Grid Trading',
    'dca': 'DCA',
    'momentum': 'Momentum'
  }
  return labels[type] || type
}

const getLogLevelColor = (level) => {
  const colors = {
    'info': 'bg-blue-400',
    'success': 'bg-green-400',
    'warning': 'bg-yellow-400',
    'error': 'bg-red-400'
  }
  return colors[level] || 'bg-gray-400'
}

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(amount || 0)
}

const formatDate = (date) => {
  return new Intl.DateTimeFormat('pt-BR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(new Date(date))
}

const formatRelativeTime = (date) => {
  const rtf = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' })
  const diff = Date.now() - new Date(date).getTime()
  const minutes = Math.floor(diff / (1000 * 60))
  const hours = Math.floor(diff / (1000 * 60 * 60))
  const days = Math.floor(diff / (1000 * 60 * 60 * 24))
  
  if (minutes < 60) return `${minutes} min atrás`
  if (hours < 24) return `${hours}h atrás`
  return `${days} dias atrás`
}

const formatParameterName = (key) => {
  const names = {
    'profit_target': 'Profit Target',
    'stop_loss': 'Stop Loss',
    'timeframe': 'Timeframe',
    'grid_count': 'Número de Grids',
    'grid_spacing': 'Espaçamento',
    'base_price': 'Preço Base',
    'interval_hours': 'Intervalo',
    'buy_amount': 'Valor por Compra',
    'total_limit': 'Limite Total',
    'take_profit': 'Take Profit'
  }
  return names[key] || key.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const formatParameterValue = (key, value) => {
  if (key.includes('target') || key.includes('loss') || key.includes('profit') || key.includes('spacing')) {
    return `${value}%`
  }
  if (key.includes('amount') || key.includes('price') || key.includes('limit')) {
    return formatCurrency(value)
  }
  if (key === 'interval_hours') {
    return `${value} horas`
  }
  return value
}

// Lifecycle
onMounted(() => {
  updateChart()
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
</style>

