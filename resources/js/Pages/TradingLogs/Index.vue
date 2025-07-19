<template>
  <AppLayout title="Logs de Trading">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Logs de Trading
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Monitore a atividade do trading bot em tempo real
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <button
              @click="toggleAutoRefresh"
              :class="[
                autoRefresh ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700',
                'inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2'
              ]"
            >
              {{ autoRefresh ? '⏸️ Pausar' : '▶️ Auto-refresh' }}
            </button>
            <button
              @click="refreshLogs"
              :disabled="loading"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50"
            >
              🔄 {{ loading ? 'Atualizando...' : 'Atualizar' }}
            </button>
            <button
              @click="clearLogs"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              🗑️ Limpar
            </button>
            <button
              @click="exportLogs"
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
            title="Total de Logs"
            :value="stats.total_logs || 0"
            format="number"
            icon="document-text"
            color="blue"
            :change="stats.logs_24h"
            change-label="24h"
          />
          <StatCard
            title="Logs de Erro"
            :value="stats.error_logs || 0"
            format="number"
            icon="exclamation-triangle"
            color="red"
            :change="stats.errors_24h"
            change-label="24h"
          />
          <StatCard
            title="Estratégias Ativas"
            :value="stats.active_strategies || 0"
            format="number"
            icon="play"
            color="green"
          />
          <StatCard
            title="Última Atividade"
            :value="stats.last_activity || 'N/A'"
            format="text"
            icon="clock"
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
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
              
              <!-- Search -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Buscar</label>
                <input
                  v-model="filters.search"
                  @input="applyFilters"
                  type="text"
                  placeholder="Mensagem, estratégia..."
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                >
              </div>

              <!-- Level -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Nível</label>
                <select
                  v-model="filters.level"
                  @change="applyFilters"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                >
                  <option value="">Todos</option>
                  <option value="info">Info</option>
                  <option value="success">Sucesso</option>
                  <option value="warning">Aviso</option>
                  <option value="error">Erro</option>
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
                  <option value="hour">Última hora</option>
                  <option value="today">Hoje</option>
                  <option value="week">Esta semana</option>
                  <option value="month">Este mês</option>
                </select>
              </div>

              <!-- Limit -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Mostrar</label>
                <select
                  v-model="filters.limit"
                  @change="applyFilters"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                >
                  <option value="50">50 logs</option>
                  <option value="100">100 logs</option>
                  <option value="200">200 logs</option>
                  <option value="500">500 logs</option>
                </select>
              </div>

            </div>
          </div>
        </div>
      </div>

      <!-- Logs List -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-medium text-gray-900">
                Logs ({{ logs.total || 0 }})
              </h3>
              <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-500">Auto-refresh:</span>
                <span 
                  :class="autoRefresh ? 'text-green-600' : 'text-gray-400'"
                  class="text-sm font-medium"
                >
                  {{ autoRefresh ? 'Ativo' : 'Inativo' }}
                </span>
              </div>
            </div>
          </div>

          <!-- Loading State -->
          <div v-if="loading" class="px-6 py-8">
            <div class="space-y-3">
              <div v-for="i in 10" :key="i" class="animate-pulse">
                <div class="h-12 bg-gray-200 rounded"></div>
              </div>
            </div>
          </div>

          <!-- Empty State -->
          <div v-else-if="!logs.data?.length" class="px-6 py-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum log encontrado</h3>
            <p class="mt-1 text-sm text-gray-500">
              Os logs de trading aparecerão aqui conforme o bot executa.
            </p>
          </div>

          <!-- Logs List -->
          <div v-else class="divide-y divide-gray-200 max-h-96 overflow-y-auto">
            <div 
              v-for="log in logs.data" 
              :key="log.id" 
              class="px-6 py-3 hover:bg-gray-50"
              :class="getLogRowClass(log.level)"
            >
              <div class="flex items-start space-x-3">
                
                <!-- Level Indicator -->
                <div class="flex-shrink-0 mt-1">
                  <div 
                    class="h-3 w-3 rounded-full"
                    :class="getLevelColor(log.level)"
                  ></div>
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2">
                      <span 
                        :class="getLevelBadgeClass(log.level)"
                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium uppercase"
                      >
                        {{ log.level }}
                      </span>
                      <span v-if="log.strategy" class="text-xs text-gray-500">
                        {{ log.strategy.name }}
                      </span>
                    </div>
                    <div class="text-xs text-gray-500">
                      {{ formatRelativeTime(log.created_at) }}
                    </div>
                  </div>
                  
                  <div class="mt-1">
                    <p class="text-sm text-gray-900">{{ log.message }}</p>
                    <div v-if="log.context" class="mt-1 text-xs text-gray-500 font-mono bg-gray-50 p-2 rounded">
                      {{ formatContext(log.context) }}
                    </div>
                  </div>
                </div>

              </div>
            </div>
          </div>

          <!-- Load More -->
          <div v-if="logs.has_more" class="px-6 py-4 border-t border-gray-200 text-center">
            <button
              @click="loadMore"
              :disabled="loadingMore"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50"
            >
              {{ loadingMore ? 'Carregando...' : 'Carregar Mais' }}
            </button>
          </div>

        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'

// Props
const props = defineProps({
  logs: Object,
  stats: Object,
  strategies: Array,
  filters: Object,
})

// Reactive data
const loading = ref(false)
const loadingMore = ref(false)
const autoRefresh = ref(true)
const refreshInterval = ref(null)

const filters = ref({
  search: props.filters?.search || '',
  level: props.filters?.level || '',
  strategy_id: props.filters?.strategy_id || '',
  period: props.filters?.period || '',
  limit: props.filters?.limit || '100',
})

// Methods
const refreshLogs = () => {
  loading.value = true
  router.reload({
    onFinish: () => {
      loading.value = false
    }
  })
}

const applyFilters = () => {
  const params = { ...filters.value }
  
  router.get('/trading-logs', params, {
    preserveState: true,
    preserveScroll: true,
  })
}

const toggleAutoRefresh = () => {
  autoRefresh.value = !autoRefresh.value
  
  if (autoRefresh.value) {
    startAutoRefresh()
  } else {
    stopAutoRefresh()
  }
}

const startAutoRefresh = () => {
  refreshInterval.value = setInterval(() => {
    if (!loading.value && !loadingMore.value) {
      refreshLogs()
    }
  }, 5000) // Refresh every 5 seconds
}

const stopAutoRefresh = () => {
  if (refreshInterval.value) {
    clearInterval(refreshInterval.value)
    refreshInterval.value = null
  }
}

const loadMore = () => {
  loadingMore.value = true
  
  const params = {
    ...filters.value,
    offset: props.logs.data.length
  }
  
  router.get('/trading-logs', params, {
    preserveState: true,
    preserveScroll: true,
    onFinish: () => {
      loadingMore.value = false
    }
  })
}

const clearLogs = () => {
  if (confirm('Tem certeza que deseja limpar todos os logs? Esta ação não pode ser desfeita.')) {
    router.delete('/trading-logs', {
      onSuccess: () => {
        refreshLogs()
      }
    })
  }
}

const exportLogs = () => {
  const params = new URLSearchParams(filters.value)
  window.open(`/trading-logs/export?${params.toString()}`, '_blank')
}

// Helper functions
const getLevelColor = (level) => {
  const colors = {
    'info': 'bg-blue-400',
    'success': 'bg-green-400',
    'warning': 'bg-yellow-400',
    'error': 'bg-red-400'
  }
  return colors[level] || 'bg-gray-400'
}

const getLevelBadgeClass = (level) => {
  const classes = {
    'info': 'bg-blue-100 text-blue-800',
    'success': 'bg-green-100 text-green-800',
    'warning': 'bg-yellow-100 text-yellow-800',
    'error': 'bg-red-100 text-red-800'
  }
  return classes[level] || 'bg-gray-100 text-gray-800'
}

const getLogRowClass = (level) => {
  if (level === 'error') {
    return 'bg-red-50 border-l-4 border-red-400'
  }
  if (level === 'warning') {
    return 'bg-yellow-50 border-l-4 border-yellow-400'
  }
  return ''
}

const formatRelativeTime = (date) => {
  const diff = Date.now() - new Date(date).getTime()
  const seconds = Math.floor(diff / 1000)
  const minutes = Math.floor(diff / (1000 * 60))
  const hours = Math.floor(diff / (1000 * 60 * 60))
  
  if (seconds < 60) return `${seconds}s atrás`
  if (minutes < 60) return `${minutes}m atrás`
  if (hours < 24) return `${hours}h atrás`
  return `${Math.floor(hours / 24)} dias atrás`
}

const formatContext = (context) => {
  if (typeof context === 'string') {
    return context
  }
  return JSON.stringify(context, null, 2)
}

// Lifecycle
onMounted(() => {
  if (autoRefresh.value) {
    startAutoRefresh()
  }
})

onUnmounted(() => {
  stopAutoRefresh()
})
</script>

<style scoped>
.bg-primary {
  background-color: var(--primary-color, #3b82f6);
}

.text-primary {
  color: var(--primary-color, #3b82f6);
}

.hover\\:bg-primary-dark:hover {
  background-color: var(--primary-dark, #2563eb);
}

.focus\\:ring-primary:focus {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

/* Custom scrollbar for logs */
.overflow-y-auto::-webkit-scrollbar {
  width: 6px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: #f1f1f1;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background: #c1c1c1;
  border-radius: 3px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background: #a8a8a8;
}
</style>

