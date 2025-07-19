<template>
  <AppLayout title="Trading Bot">
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        
        <!-- Header com controles do bot -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
          <div class="p-6">
            <div class="flex justify-between items-center">
              <div>
                <h2 class="text-2xl font-bold text-gray-900">Trading Bot Dashboard</h2>
                <p class="text-gray-600">Gerencie suas estratégias de trading automatizado</p>
              </div>
              
              <div class="flex space-x-4">
                <!-- Status do Bot -->
                <div class="flex items-center space-x-2">
                  <div :class="[
                    'w-3 h-3 rounded-full',
                    stats.bot_status === 'running' ? 'bg-green-500' : 'bg-red-500'
                  ]"></div>
                  <span class="text-sm font-medium">
                    {{ stats.bot_status === 'running' ? 'Bot Ativo' : 'Bot Parado' }}
                  </span>
                </div>
                
                <!-- Controles do Bot -->
                <button
                  @click="toggleBot"
                  :disabled="loading"
                  :class="[
                    'px-4 py-2 rounded-md text-sm font-medium',
                    stats.bot_status === 'running' 
                      ? 'bg-red-600 hover:bg-red-700 text-white'
                      : 'bg-green-600 hover:bg-green-700 text-white',
                    loading ? 'opacity-50 cursor-not-allowed' : ''
                  ]"
                >
                  {{ loading ? 'Processando...' : (stats.bot_status === 'running' ? 'Parar Bot' : 'Iniciar Bot') }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
          <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="p-5">
              <div class="flex items-center">
                <div class="flex-shrink-0">
                  <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                      <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                  </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                  <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Estratégias Ativas</dt>
                    <dd class="text-lg font-medium text-gray-900">{{ stats.active_strategies }}</dd>
                  </dl>
                </div>
              </div>
            </div>
          </div>

          <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="p-5">
              <div class="flex items-center">
                <div class="flex-shrink-0">
                  <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                      <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/>
                    </svg>
                  </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                  <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Ordens Hoje</dt>
                    <dd class="text-lg font-medium text-gray-900">{{ stats.orders_today }}</dd>
                  </dl>
                </div>
              </div>
            </div>
          </div>

          <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="p-5">
              <div class="flex items-center">
                <div class="flex-shrink-0">
                  <div :class="[
                    'w-8 h-8 rounded-md flex items-center justify-center',
                    stats.profit_today >= 0 ? 'bg-green-500' : 'bg-red-500'
                  ]">
                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                      <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/>
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/>
                    </svg>
                  </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                  <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Lucro Hoje</dt>
                    <dd :class="[
                      'text-lg font-medium',
                      stats.profit_today >= 0 ? 'text-green-600' : 'text-red-600'
                    ]">
                      {{ stats.profit_today >= 0 ? '+' : '' }}${{ stats.profit_today.toFixed(2) }}
                    </dd>
                  </dl>
                </div>
              </div>
            </div>
          </div>

          <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="p-5">
              <div class="flex items-center">
                <div class="flex-shrink-0">
                  <div class="w-8 h-8 bg-purple-500 rounded-md flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                      <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/>
                      <path fill-rule="evenodd" d="M4 5a2 2 0 012-2v1a1 1 0 102 0V3a2 2 0 012 2v6a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm2.5 7a1.5 1.5 0 100-3 1.5 1.5 0 000 3zm2.45.5a2.5 2.5 0 11-3.9 0 .5.5 0 00-.1.5V14a1 1 0 001 1h3a1 1 0 001-1v-1.5a.5.5 0 00-.1-.5z" clip-rule="evenodd"/>
                    </svg>
                  </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                  <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Total Ordens</dt>
                    <dd class="text-lg font-medium text-gray-900">{{ stats.total_orders || 0 }}</dd>
                  </dl>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Grid Principal -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          
          <!-- Estratégias -->
          <div class="lg:col-span-2">
            <div class="bg-white shadow rounded-lg">
              <div class="px-4 py-5 sm:p-6">
                <div class="flex justify-between items-center mb-4">
                  <h3 class="text-lg leading-6 font-medium text-gray-900">Estratégias de Trading</h3>
                  <Link
                    :href="route('trading-bot.create')"
                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700"
                  >
                    Nova Estratégia
                  </Link>
                </div>

                <div v-if="strategies.length === 0" class="text-center py-8">
                  <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                  </svg>
                  <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhuma estratégia</h3>
                  <p class="mt-1 text-sm text-gray-500">Comece criando sua primeira estratégia de trading.</p>
                </div>

                <div v-else class="space-y-4">
                  <div
                    v-for="strategy in strategies"
                    :key="strategy.id"
                    class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow"
                  >
                    <div class="flex justify-between items-start">
                      <div class="flex-1">
                        <div class="flex items-center space-x-2">
                          <h4 class="text-lg font-medium text-gray-900">{{ strategy.name }}</h4>
                          <span :class="[
                            'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                            strategy.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                          ]">
                            {{ strategy.is_active ? 'Ativa' : 'Inativa' }}
                          </span>
                        </div>
                        <p class="text-sm text-gray-600 mt-1">{{ strategy.type_name }}</p>
                        <div class="flex items-center space-x-4 mt-2 text-sm text-gray-500">
                          <span>Par: {{ strategy.parameters.pair }}</span>
                          <span>Exchange: {{ strategy.parameters.exchange }}</span>
                          <span>Ordens: {{ strategy.bot_orders?.length || 0 }}</span>
                        </div>
                      </div>
                      
                      <div class="flex items-center space-x-2">
                        <button
                          @click="toggleStrategy(strategy)"
                          :class="[
                            'px-3 py-1 rounded text-sm font-medium',
                            strategy.is_active 
                              ? 'bg-red-100 text-red-700 hover:bg-red-200'
                              : 'bg-green-100 text-green-700 hover:bg-green-200'
                          ]"
                        >
                          {{ strategy.is_active ? 'Pausar' : 'Ativar' }}
                        </button>
                        
                        <Link
                          :href="route('trading-bot.show', strategy.id)"
                          class="px-3 py-1 bg-blue-100 text-blue-700 rounded text-sm font-medium hover:bg-blue-200"
                        >
                          Detalhes
                        </Link>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Sidebar com Logs e Ordens Recentes -->
          <div class="space-y-6">
            
            <!-- Ordens Recentes -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-4 py-5 sm:p-6">
                <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Ordens Recentes</h3>
                
                <div v-if="recentOrders.length === 0" class="text-center py-4">
                  <p class="text-sm text-gray-500">Nenhuma ordem executada</p>
                </div>
                
                <div v-else class="space-y-3">
                  <div
                    v-for="order in recentOrders.slice(0, 5)"
                    :key="order.id"
                    class="flex justify-between items-center py-2 border-b border-gray-100 last:border-b-0"
                  >
                    <div>
                      <div class="flex items-center space-x-2">
                        <span :class="[
                          'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                          order.side === 'buy' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                        ]">
                          {{ order.side_name }}
                        </span>
                        <span class="text-sm font-medium">{{ order.pair }}</span>
                      </div>
                      <p class="text-xs text-gray-500 mt-1">{{ order.time_ago }}</p>
                    </div>
                    <div class="text-right">
                      <p class="text-sm font-medium">${{ order.formatted_total }}</p>
                      <p class="text-xs text-gray-500">{{ order.formatted_quantity }}</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Logs Recentes -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-4 py-5 sm:p-6">
                <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Logs do Sistema</h3>
                
                <div v-if="recentLogs.length === 0" class="text-center py-4">
                  <p class="text-sm text-gray-500">Nenhum log disponível</p>
                </div>
                
                <div v-else class="space-y-2 max-h-64 overflow-y-auto">
                  <div
                    v-for="log in recentLogs.slice(0, 10)"
                    :key="log.id"
                    class="text-sm"
                  >
                    <div class="flex items-start space-x-2">
                      <div :class="[
                        'w-2 h-2 rounded-full mt-1.5 flex-shrink-0',
                        log.type_color === 'green' ? 'bg-green-500' :
                        log.type_color === 'red' ? 'bg-red-500' :
                        log.type_color === 'yellow' ? 'bg-yellow-500' :
                        'bg-blue-500'
                      ]"></div>
                      <div class="flex-1 min-w-0">
                        <p class="text-gray-900 break-words">{{ log.clean_message }}</p>
                        <p class="text-xs text-gray-500">{{ log.time_ago }}</p>
                      </div>
                    </div>
                  </div>
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
import { ref, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  stats: Object,
  strategies: Array,
  recentOrders: Array,
  recentLogs: Array
})

const loading = ref(false)
const stats = ref(props.stats)
let statsInterval = null

// Atualizar estatísticas em tempo real
const updateStats = async () => {
  try {
    const response = await fetch('/api/trading-bot/stats')
    const data = await response.json()
    stats.value = { ...stats.value, ...data }
  } catch (error) {
    console.error('Erro ao atualizar estatísticas:', error)
  }
}

// Controlar bot (iniciar/parar)
const toggleBot = async () => {
  loading.value = true
  
  try {
    const action = stats.value.bot_status === 'running' ? 'stop' : 'start'
    
    await router.post('/trading-bot/toggle', { action }, {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        // Atualizar status imediatamente
        stats.value.bot_status = action === 'start' ? 'running' : 'stopped'
      }
    })
  } catch (error) {
    console.error('Erro ao controlar bot:', error)
  } finally {
    loading.value = false
  }
}

// Ativar/Desativar estratégia
const toggleStrategy = async (strategy) => {
  try {
    await router.patch(`/trading-bot/strategies/${strategy.id}/toggle`, {}, {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        strategy.is_active = !strategy.is_active
      }
    })
  } catch (error) {
    console.error('Erro ao alterar estratégia:', error)
  }
}

onMounted(() => {
  // Atualizar estatísticas a cada 30 segundos
  statsInterval = setInterval(updateStats, 30000)
})

onUnmounted(() => {
  if (statsInterval) {
    clearInterval(statsInterval)
  }
})
</script>

