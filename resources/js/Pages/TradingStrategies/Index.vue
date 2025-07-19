<template>
  <AppLayout title="Estratégias de Trading">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Estratégias de Trading
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Gerencie suas estratégias automatizadas de trading
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <Link
              href="/trading-strategies/create"
              class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ➕ Nova Estratégia
            </Link>
            <button
              @click="runBacktest"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              📊 Backtest
            </button>
          </div>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Total de Estratégias"
            :value="stats.total_strategies || 0"
            format="number"
            icon="collection"
            color="blue"
          />
          <StatCard
            title="Estratégias Ativas"
            :value="stats.active_strategies || 0"
            format="number"
            icon="play"
            color="green"
          />
          <StatCard
            title="Performance Média"
            :value="stats.avg_performance || 0"
            format="percentage"
            icon="trending-up"
            :color="(stats.avg_performance || 0) >= 0 ? 'green' : 'red'"
          />
          <StatCard
            title="Volume Total"
            :value="stats.total_volume || 0"
            format="currency"
            icon="currency-dollar"
            color="purple"
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
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              
              <!-- Search -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
                <input
                  v-model="filters.search"
                  type="text"
                  placeholder="Nome da estratégia..."
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                >
              </div>

              <!-- Status -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select
                  v-model="filters.status"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                >
                  <option value="">Todos</option>
                  <option value="active">Ativa</option>
                  <option value="inactive">Inativa</option>
                  <option value="paused">Pausada</option>
                  <option value="testing">Em Teste</option>
                </select>
              </div>

              <!-- Type -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
                <select
                  v-model="filters.type"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                >
                  <option value="">Todos</option>
                  <option value="scalping">Scalping</option>
                  <option value="swing">Swing Trading</option>
                  <option value="arbitrage">Arbitragem</option>
                  <option value="grid">Grid Trading</option>
                  <option value="dca">DCA</option>
                  <option value="momentum">Momentum</option>
                </select>
              </div>

              <!-- Performance -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Performance</label>
                <select
                  v-model="filters.performance"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                >
                  <option value="">Todas</option>
                  <option value="positive">Positiva (> 0%)</option>
                  <option value="negative">Negativa (< 0%)</option>
                  <option value="high">Alta (> 10%)</option>
                  <option value="low">Baixa (< 5%)</option>
                </select>
              </div>

            </div>
            
            <!-- Filter Actions -->
            <div class="mt-4 flex justify-between">
              <button
                @click="clearFilters"
                class="text-sm text-gray-500 hover:text-gray-700"
              >
                Limpar Filtros
              </button>
              <button
                @click="applyFilters"
                class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
              >
                Aplicar
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Strategies List -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        
        <!-- Loading State -->
        <div v-if="loading" class="bg-white shadow rounded-lg p-6">
          <div class="animate-pulse space-y-4">
            <div v-for="i in 3" :key="i" class="h-20 bg-gray-200 rounded"></div>
          </div>
        </div>

        <!-- Empty State -->
        <div v-else-if="!strategies?.length" class="bg-white shadow rounded-lg">
          <div class="px-6 py-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhuma estratégia encontrada</h3>
            <p class="mt-1 text-sm text-gray-500">
              Comece criando sua primeira estratégia de trading automatizada.
            </p>
            <div class="mt-6">
              <Link
                href="/trading-strategies/create"
                class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
              >
                ➕ Criar Primeira Estratégia
              </Link>
            </div>
          </div>
        </div>

        <!-- Strategies Grid -->
        <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-2 xl:grid-cols-3">
          
          <div v-for="strategy in strategies" :key="strategy.id" class="bg-white shadow rounded-lg overflow-hidden hover:shadow-lg transition-shadow duration-200">
            
            <!-- Strategy Header -->
            <div class="px-6 py-4 border-b border-gray-200">
              <div class="flex items-center justify-between">
                <div class="flex items-center">
                  <div 
                    class="h-10 w-10 rounded-full flex items-center justify-center mr-3"
                    :class="getStrategyTypeColor(strategy.type)"
                  >
                    <span class="text-sm font-bold text-white">
                      {{ getStrategyIcon(strategy.type) }}
                    </span>
                  </div>
                  <div>
                    <h3 class="text-lg font-medium text-gray-900">{{ strategy.name }}</h3>
                    <p class="text-sm text-gray-500">{{ strategy.type_label }}</p>
                  </div>
                </div>
                <div class="flex items-center space-x-2">
                  <span 
                    :class="getStatusBadgeClass(strategy.status)"
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                  >
                    {{ getStatusLabel(strategy.status) }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Strategy Stats -->
            <div class="px-6 py-4">
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <p class="text-sm text-gray-500">Performance</p>
                  <p :class="strategy.performance >= 0 ? 'text-green-600' : 'text-red-600'" class="text-lg font-semibold">
                    {{ strategy.performance >= 0 ? '+' : '' }}{{ strategy.performance.toFixed(2) }}%
                  </p>
                </div>
                <div>
                  <p class="text-sm text-gray-500">Volume</p>
                  <p class="text-lg font-semibold text-gray-900">{{ formatCurrency(strategy.volume) }}</p>
                </div>
                <div>
                  <p class="text-sm text-gray-500">Trades</p>
                  <p class="text-lg font-semibold text-gray-900">{{ strategy.total_trades || 0 }}</p>
                </div>
                <div>
                  <p class="text-sm text-gray-500">Win Rate</p>
                  <p class="text-lg font-semibold text-gray-900">{{ (strategy.win_rate || 0).toFixed(1) }}%</p>
                </div>
              </div>
            </div>

            <!-- Strategy Details -->
            <div class="px-6 py-4 bg-gray-50">
              <div class="text-sm text-gray-600 space-y-1">
                <div class="flex justify-between">
                  <span>Par:</span>
                  <span class="font-medium">{{ strategy.trading_pair }}</span>
                </div>
                <div class="flex justify-between">
                  <span>Exchange:</span>
                  <span class="font-medium">{{ strategy.exchange }}</span>
                </div>
                <div class="flex justify-between">
                  <span>Criada:</span>
                  <span class="font-medium">{{ formatDate(strategy.created_at) }}</span>
                </div>
                <div class="flex justify-between">
                  <span>Última execução:</span>
                  <span class="font-medium">{{ strategy.last_execution ? formatRelativeTime(strategy.last_execution) : 'Nunca' }}</span>
                </div>
              </div>
            </div>

            <!-- Strategy Actions -->
            <div class="px-6 py-4 border-t border-gray-200">
              <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2">
                  <button
                    v-if="strategy.status === 'inactive'"
                    @click="startStrategy(strategy.id)"
                    class="text-sm text-green-600 hover:text-green-800 font-medium"
                  >
                    ▶️ Iniciar
                  </button>
                  <button
                    v-else-if="strategy.status === 'active'"
                    @click="stopStrategy(strategy.id)"
                    class="text-sm text-red-600 hover:text-red-800 font-medium"
                  >
                    ⏹️ Parar
                  </button>
                  <button
                    v-else-if="strategy.status === 'paused'"
                    @click="resumeStrategy(strategy.id)"
                    class="text-sm text-blue-600 hover:text-blue-800 font-medium"
                  >
                    ▶️ Retomar
                  </button>
                  <span class="text-gray-300">|</span>
                  <button
                    @click="runBacktestForStrategy(strategy.id)"
                    class="text-sm text-purple-600 hover:text-purple-800 font-medium"
                  >
                    📊 Backtest
                  </button>
                </div>
                <div class="flex items-center space-x-2">
                  <Link
                    :href="`/trading-strategies/${strategy.id}`"
                    class="text-sm text-gray-600 hover:text-gray-800"
                  >
                    👁️ Ver
                  </Link>
                  <span class="text-gray-300">|</span>
                  <Link
                    :href="`/trading-strategies/${strategy.id}/edit`"
                    class="text-sm text-primary hover:text-primary-dark"
                  >
                    ✏️ Editar
                  </Link>
                  <span class="text-gray-300">|</span>
                  <button
                    @click="deleteStrategy(strategy.id)"
                    class="text-sm text-red-600 hover:text-red-800"
                  >
                    🗑️ Excluir
                  </button>
                </div>
              </div>
            </div>

          </div>

        </div>

        <!-- Pagination -->
        <div v-if="strategies?.length" class="mt-6 flex items-center justify-between">
          <div class="flex-1 flex justify-between sm:hidden">
            <button
              :disabled="!pagination.prev_page_url"
              class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
            >
              Anterior
            </button>
            <button
              :disabled="!pagination.next_page_url"
              class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
            >
              Próximo
            </button>
          </div>
          <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
              <p class="text-sm text-gray-700">
                Mostrando
                <span class="font-medium">{{ pagination.from || 0 }}</span>
                a
                <span class="font-medium">{{ pagination.to || 0 }}</span>
                de
                <span class="font-medium">{{ pagination.total || 0 }}</span>
                estratégias
              </p>
            </div>
            <div>
              <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                <!-- Pagination buttons would go here -->
              </nav>
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
  strategies: Array,
  stats: Object,
  pagination: Object,
})

// Reactive data
const loading = ref(false)
const filters = ref({
  search: '',
  status: '',
  type: '',
  performance: ''
})

// Methods
const clearFilters = () => {
  filters.value = {
    search: '',
    status: '',
    type: '',
    performance: ''
  }
  applyFilters()
}

const applyFilters = () => {
  router.get('/trading-strategies', filters.value, {
    preserveState: true,
    only: ['strategies', 'pagination']
  })
}

const startStrategy = (id) => {
  router.post(`/trading-strategies/${id}/start`, {}, {
    preserveState: true,
    only: ['strategies']
  })
}

const stopStrategy = (id) => {
  router.post(`/trading-strategies/${id}/stop`, {}, {
    preserveState: true,
    only: ['strategies']
  })
}

const resumeStrategy = (id) => {
  router.post(`/trading-strategies/${id}/resume`, {}, {
    preserveState: true,
    only: ['strategies']
  })
}

const deleteStrategy = (id) => {
  if (confirm('Tem certeza que deseja excluir esta estratégia?')) {
    router.delete(`/trading-strategies/${id}`, {
      preserveState: true,
      only: ['strategies', 'stats']
    })
  }
}

const runBacktest = () => {
  router.get('/backtesting')
}

const runBacktestForStrategy = (id) => {
  router.get(`/backtesting?strategy_id=${id}`)
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
    day: 'numeric'
  }).format(new Date(date))
}

const formatRelativeTime = (date) => {
  const rtf = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' })
  const diff = Date.now() - new Date(date).getTime()
  const days = Math.floor(diff / (1000 * 60 * 60 * 24))
  
  if (days === 0) return 'Hoje'
  if (days === 1) return 'Ontem'
  if (days < 7) return `${days} dias atrás`
  if (days < 30) return `${Math.floor(days / 7)} semanas atrás`
  return `${Math.floor(days / 30)} meses atrás`
}
</script>

<style scoped>
.bg-primary {
  background-color: var(--primary-color, #3b82f6);
}

.text-primary {
  color: var(--primary-color, #3b82f6);
}

.border-primary {
  border-color: var(--primary-color, #3b82f6);
}

.ring-primary {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

.hover\\:bg-primary-dark:hover {
  background-color: var(--primary-dark, #2563eb);
}

.hover\\:text-primary-dark:hover {
  color: var(--primary-dark, #2563eb);
}

.focus\\:ring-primary:focus {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

.focus\\:border-primary:focus {
  border-color: var(--primary-color, #3b82f6);
}
</style>

