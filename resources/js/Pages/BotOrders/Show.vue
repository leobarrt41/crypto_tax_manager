<template>
  <AppLayout :title="`Ordem #${order?.id}`">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <div class="flex items-center">
              <div 
                class="h-12 w-12 rounded-full flex items-center justify-center mr-4"
                :class="order?.side === 'buy' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'"
              >
                <span class="text-xl font-bold">{{ order?.side === 'buy' ? '↗' : '↘' }}</span>
              </div>
              <div>
                <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
                  Ordem #{{ order?.id }}
                </h2>
                <div class="mt-1 flex items-center space-x-3">
                  <span 
                    :class="getStatusBadgeClass(order?.status)"
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                  >
                    {{ getStatusLabel(order?.status) }}
                  </span>
                  <span class="text-sm text-gray-500">{{ order?.side === 'buy' ? 'Compra' : 'Venda' }}</span>
                  <span class="text-sm text-gray-500">{{ order?.symbol }}</span>
                </div>
              </div>
            </div>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <button
              v-if="order?.status === 'pending'"
              @click="cancelOrder"
              class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
            >
              ❌ Cancelar Ordem
            </button>
            <Link
              href="/bot-orders"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ← Voltar
            </Link>
          </div>
        </div>
      </div>

      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
          
          <!-- Main Content -->
          <div class="lg:col-span-2 space-y-6">
            
            <!-- Order Details -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Detalhes da Ordem</h3>
              </div>
              <div class="px-6 py-4">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                  
                  <div>
                    <dt class="text-sm font-medium text-gray-500">Símbolo</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">{{ order?.symbol }}</dd>
                  </div>

                  <div>
                    <dt class="text-sm font-medium text-gray-500">Tipo de Ordem</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                      {{ order?.side === 'buy' ? 'Compra' : 'Venda' }}
                    </dd>
                  </div>

                  <div>
                    <dt class="text-sm font-medium text-gray-500">Quantidade</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                      {{ order?.quantity }} {{ order?.base_asset }}
                    </dd>
                  </div>

                  <div>
                    <dt class="text-sm font-medium text-gray-500">Preço</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                      {{ formatCurrency(order?.price) }}
                    </dd>
                  </div>

                  <div>
                    <dt class="text-sm font-medium text-gray-500">Valor Total</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                      {{ formatCurrency(order?.amount) }}
                    </dd>
                  </div>

                  <div v-if="order?.fee">
                    <dt class="text-sm font-medium text-gray-500">Taxa</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                      {{ formatCurrency(order?.fee) }}
                    </dd>
                  </div>

                  <div v-if="order?.filled_quantity">
                    <dt class="text-sm font-medium text-gray-500">Quantidade Executada</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                      {{ order?.filled_quantity }} {{ order?.base_asset }}
                    </dd>
                  </div>

                  <div v-if="order?.average_price">
                    <dt class="text-sm font-medium text-gray-500">Preço Médio</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                      {{ formatCurrency(order?.average_price) }}
                    </dd>
                  </div>

                </div>
              </div>
            </div>

            <!-- Execution Timeline -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Timeline de Execução</h3>
              </div>
              <div class="px-6 py-4">
                
                <!-- Loading State -->
                <div v-if="loadingTimeline" class="space-y-3">
                  <div v-for="i in 3" :key="i" class="animate-pulse">
                    <div class="h-12 bg-gray-200 rounded"></div>
                  </div>
                </div>

                <!-- Timeline -->
                <div v-else class="flow-root">
                  <ul class="-mb-8">
                    <li v-for="(event, index) in timeline" :key="event.id">
                      <div class="relative pb-8">
                        <span 
                          v-if="index !== timeline.length - 1" 
                          class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200"
                        ></span>
                        <div class="relative flex space-x-3">
                          <div>
                            <span 
                              :class="getEventIconClass(event.type)"
                              class="h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-white"
                            >
                              <span class="text-xs font-bold text-white">{{ getEventIcon(event.type) }}</span>
                            </span>
                          </div>
                          <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                            <div>
                              <p class="text-sm text-gray-900">{{ event.description }}</p>
                              <p v-if="event.details" class="text-sm text-gray-500">{{ event.details }}</p>
                            </div>
                            <div class="text-right text-sm whitespace-nowrap text-gray-500">
                              <time>{{ formatDate(event.created_at) }}</time>
                            </div>
                          </div>
                        </div>
                      </div>
                    </li>
                  </ul>
                </div>

                <!-- Empty Timeline -->
                <div v-if="!loadingTimeline && !timeline?.length" class="text-center py-8">
                  <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                  <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum evento registrado</h3>
                  <p class="mt-1 text-sm text-gray-500">O timeline aparecerá conforme a ordem for processada.</p>
                </div>

              </div>
            </div>

            <!-- Error Details -->
            <div v-if="order?.error_message" class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Detalhes do Erro</h3>
              </div>
              <div class="px-6 py-4">
                <div class="rounded-md bg-red-50 p-4">
                  <div class="flex">
                    <div class="flex-shrink-0">
                      <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                      </svg>
                    </div>
                    <div class="ml-3">
                      <h3 class="text-sm font-medium text-red-800">Erro na Execução</h3>
                      <div class="mt-2 text-sm text-red-700">
                        <p>{{ order.error_message }}</p>
                      </div>
                      <div v-if="order.error_code" class="mt-2">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                          Código: {{ order.error_code }}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>

          <!-- Sidebar -->
          <div class="space-y-6">
            
            <!-- Strategy Info -->
            <div v-if="order?.strategy" class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Estratégia</h3>
              </div>
              <div class="px-6 py-4 space-y-4">
                
                <div>
                  <dt class="text-sm font-medium text-gray-500">Nome</dt>
                  <dd class="mt-1 text-sm text-gray-900">
                    <Link 
                      :href="`/trading-strategies/${order.strategy.id}`"
                      class="text-primary hover:text-primary-dark"
                    >
                      {{ order.strategy.name }}
                    </Link>
                  </dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Tipo</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ getStrategyTypeLabel(order.strategy.type) }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Status</dt>
                  <dd class="mt-1">
                    <span 
                      :class="getStrategyStatusClass(order.strategy.status)"
                      class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                    >
                      {{ getStrategyStatusLabel(order.strategy.status) }}
                    </span>
                  </dd>
                </div>

              </div>
            </div>

            <!-- Exchange Info -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Exchange</h3>
              </div>
              <div class="px-6 py-4 space-y-4">
                
                <div>
                  <dt class="text-sm font-medium text-gray-500">Nome</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ order?.exchange || 'N/A' }}</dd>
                </div>

                <div v-if="order?.exchange_order_id">
                  <dt class="text-sm font-medium text-gray-500">ID da Exchange</dt>
                  <dd class="mt-1 text-sm text-gray-900 font-mono">{{ order.exchange_order_id }}</dd>
                </div>

                <div v-if="order?.client_order_id">
                  <dt class="text-sm font-medium text-gray-500">Client Order ID</dt>
                  <dd class="mt-1 text-sm text-gray-900 font-mono">{{ order.client_order_id }}</dd>
                </div>

              </div>
            </div>

            <!-- Timestamps -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Timestamps</h3>
              </div>
              <div class="px-6 py-4 space-y-4">
                
                <div>
                  <dt class="text-sm font-medium text-gray-500">Criada em</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ formatDate(order?.created_at) }}</dd>
                </div>

                <div v-if="order?.executed_at">
                  <dt class="text-sm font-medium text-gray-500">Executada em</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ formatDate(order.executed_at) }}</dd>
                </div>

                <div v-if="order?.cancelled_at">
                  <dt class="text-sm font-medium text-gray-500">Cancelada em</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ formatDate(order.cancelled_at) }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Atualizada em</dt>
                  <dd class="mt-1 text-sm text-gray-900">{{ formatDate(order?.updated_at) }}</dd>
                </div>

              </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Ações</h3>
              </div>
              <div class="px-6 py-4 space-y-3">
                
                <Link
                  v-if="order?.strategy"
                  :href="`/trading-strategies/${order.strategy.id}`"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  🎯 Ver Estratégia
                </Link>

                <Link
                  :href="`/bot-orders?symbol=${order?.symbol}`"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  📊 Outras Ordens {{ order?.symbol }}
                </Link>

                <button
                  @click="exportOrder"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  📥 Exportar Dados
                </button>

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
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  order: Object,
  timeline: Array,
})

// Reactive data
const loadingTimeline = ref(false)
const timeline = ref(props.timeline || [])

// Methods
const cancelOrder = () => {
  if (confirm('Tem certeza que deseja cancelar esta ordem?')) {
    router.post(`/bot-orders/${props.order.id}/cancel`)
  }
}

const exportOrder = () => {
  window.open(`/bot-orders/${props.order.id}/export`, '_blank')
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

const getEventIconClass = (type) => {
  const classes = {
    'created': 'bg-blue-500',
    'submitted': 'bg-yellow-500',
    'executed': 'bg-green-500',
    'cancelled': 'bg-gray-500',
    'failed': 'bg-red-500'
  }
  return classes[type] || 'bg-gray-500'
}

const getEventIcon = (type) => {
  const icons = {
    'created': '📝',
    'submitted': '📤',
    'executed': '✅',
    'cancelled': '❌',
    'failed': '⚠️'
  }
  return icons[type] || '📋'
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

const getStrategyStatusClass = (status) => {
  const classes = {
    'active': 'bg-green-100 text-green-800',
    'inactive': 'bg-gray-100 text-gray-800',
    'paused': 'bg-yellow-100 text-yellow-800'
  }
  return classes[status] || 'bg-gray-100 text-gray-800'
}

const getStrategyStatusLabel = (status) => {
  const labels = {
    'active': 'Ativa',
    'inactive': 'Inativa',
    'paused': 'Pausada'
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
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  }).format(new Date(date))
}
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

