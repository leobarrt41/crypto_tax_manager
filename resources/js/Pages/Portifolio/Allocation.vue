<template>
  <AppLayout title="Alocação de Ativos">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Alocação de Ativos
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Gestão e rebalanceamento da alocação do seu portfólio
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <button
              @click="showRebalanceModal = true"
              class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ⚖️ Rebalancear
            </button>
            <Link
              href="/portfolio"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ← Voltar ao Portfólio
            </Link>
          </div>
        </div>
      </div>

      <!-- Allocation Summary -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Valor Total"
            :value="allocation.total_value || 0"
            format="currency"
            icon="currency-dollar"
            color="green"
          />
          <StatCard
            title="Ativos Únicos"
            :value="allocation.unique_assets || 0"
            format="number"
            icon="collection"
            color="blue"
          />
          <StatCard
            title="Diversificação"
            :value="allocation.diversification_score || 0"
            format="score"
            icon="chart-pie"
            color="purple"
          />
          <StatCard
            title="Rebalanceamento"
            :value="allocation.days_since_rebalance || 0"
            format="days"
            icon="refresh"
            color="yellow"
          />
        </div>
      </div>

      <!-- Main Content -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
          
          <!-- Left Column - Current Allocation -->
          <div class="lg:col-span-2 space-y-6">
            
            <!-- Current vs Target Allocation -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                  <h3 class="text-lg font-medium text-gray-900">Alocação Atual vs Meta</h3>
                  <div class="flex items-center space-x-2">
                    <button
                      @click="viewMode = 'percentage'"
                      :class="[
                        'px-3 py-1 text-sm rounded-md',
                        viewMode === 'percentage'
                          ? 'bg-primary text-white'
                          : 'text-gray-500 hover:text-gray-700'
                      ]"
                    >
                      %
                    </button>
                    <button
                      @click="viewMode = 'value'"
                      :class="[
                        'px-3 py-1 text-sm rounded-md',
                        viewMode === 'value'
                          ? 'bg-primary text-white'
                          : 'text-gray-500 hover:text-gray-700'
                      ]"
                    >
                      R$
                    </button>
                  </div>
                </div>
              </div>
              <div class="px-6 py-4">
                
                <!-- Allocation Table -->
                <div class="space-y-4">
                  <div v-for="(asset, index) in allocation.assets" :key="asset.symbol" class="border border-gray-200 rounded-lg p-4">
                    
                    <!-- Asset Header -->
                    <div class="flex items-center justify-between mb-3">
                      <div class="flex items-center">
                        <div 
                          class="h-10 w-10 rounded-full flex items-center justify-center mr-4"
                          :style="{ backgroundColor: getAssetColor(asset.symbol) }"
                        >
                          <span class="text-sm font-bold text-white">
                            {{ asset.symbol.substring(0, 2) }}
                          </span>
                        </div>
                        <div>
                          <h4 class="text-sm font-medium text-gray-900">{{ asset.symbol }}</h4>
                          <p class="text-xs text-gray-500">{{ asset.name }}</p>
                        </div>
                      </div>
                      <div class="text-right">
                        <p class="text-sm font-medium text-gray-900">
                          {{ viewMode === 'percentage' ? asset.current_percentage.toFixed(1) + '%' : formatCurrency(asset.current_value) }}
                        </p>
                        <p class="text-xs text-gray-500">
                          {{ formatQuantity(asset.quantity) }} {{ asset.symbol }}
                        </p>
                      </div>
                    </div>

                    <!-- Progress Bars -->
                    <div class="space-y-2">
                      
                      <!-- Current Allocation -->
                      <div>
                        <div class="flex justify-between text-xs text-gray-600 mb-1">
                          <span>Atual</span>
                          <span>{{ asset.current_percentage.toFixed(1) }}%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                          <div 
                            class="h-2 rounded-full transition-all duration-300"
                            :style="{ 
                              width: `${asset.current_percentage}%`,
                              backgroundColor: getAssetColor(asset.symbol)
                            }"
                          ></div>
                        </div>
                      </div>

                      <!-- Target Allocation -->
                      <div v-if="asset.target_percentage">
                        <div class="flex justify-between text-xs text-gray-600 mb-1">
                          <span>Meta</span>
                          <span>{{ asset.target_percentage.toFixed(1) }}%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                          <div 
                            class="bg-gray-400 h-2 rounded-full transition-all duration-300"
                            :style="{ width: `${asset.target_percentage}%` }"
                          ></div>
                        </div>
                      </div>

                    </div>

                    <!-- Deviation -->
                    <div v-if="asset.target_percentage" class="mt-3 flex items-center justify-between">
                      <span class="text-xs text-gray-500">Desvio:</span>
                      <span 
                        :class="getDeviationClass(asset.deviation)"
                        class="text-xs font-medium"
                      >
                        {{ asset.deviation >= 0 ? '+' : '' }}{{ asset.deviation.toFixed(1) }}%
                      </span>
                    </div>

                    <!-- Actions -->
                    <div class="mt-3 flex items-center justify-end space-x-2">
                      <button
                        @click="editTarget(asset)"
                        class="text-xs text-primary hover:text-primary-dark"
                      >
                        Editar Meta
                      </button>
                      <span class="text-gray-300">|</span>
                      <button
                        @click="viewAssetDetails(asset)"
                        class="text-xs text-gray-500 hover:text-gray-700"
                      >
                        Detalhes
                      </button>
                    </div>

                  </div>
                </div>

              </div>
            </div>

            <!-- Allocation Chart -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Visualização da Alocação</h3>
              </div>
              <div class="px-6 py-4">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                  
                  <!-- Pie Chart Placeholder -->
                  <div class="h-64 bg-gray-50 rounded-lg flex items-center justify-center">
                    <div class="text-center">
                      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
                      </svg>
                      <p class="mt-2 text-sm text-gray-500">Gráfico de Pizza</p>
                    </div>
                  </div>

                  <!-- Treemap Placeholder -->
                  <div class="h-64 bg-gray-50 rounded-lg flex items-center justify-center">
                    <div class="text-center">
                      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"></path>
                      </svg>
                      <p class="mt-2 text-sm text-gray-500">Treemap</p>
                    </div>
                  </div>

                </div>
              </div>
            </div>

          </div>

          <!-- Right Column - Sidebar -->
          <div class="lg:col-span-1 space-y-6">
            
            <!-- Rebalancing Suggestions -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Sugestões de Rebalanceamento</h3>
              </div>
              <div class="px-6 py-4">
                
                <!-- Rebalance Score -->
                <div class="mb-6">
                  <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-700">Necessidade de Rebalanceamento</span>
                    <span class="text-sm text-gray-500">{{ allocation.rebalance_score || 0 }}/10</span>
                  </div>
                  <div class="w-full bg-gray-200 rounded-full h-2">
                    <div 
                      class="h-2 rounded-full transition-all duration-300"
                      :class="getRebalanceScoreColor(allocation.rebalance_score)"
                      :style="{ width: `${(allocation.rebalance_score || 0) * 10}%` }"
                    ></div>
                  </div>
                  <p class="text-xs text-gray-500 mt-1">
                    {{ getRebalanceScoreLabel(allocation.rebalance_score) }}
                  </p>
                </div>

                <!-- Suggestions -->
                <div class="space-y-3">
                  <div v-for="suggestion in allocation.suggestions?.slice(0, 5)" :key="suggestion.asset" class="p-3 border border-gray-200 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                      <span class="text-sm font-medium text-gray-900">{{ suggestion.asset }}</span>
                      <span 
                        :class="suggestion.action === 'buy' ? 'text-green-600' : 'text-red-600'"
                        class="text-xs font-medium"
                      >
                        {{ suggestion.action === 'buy' ? 'COMPRAR' : 'VENDER' }}
                      </span>
                    </div>
                    <p class="text-xs text-gray-600 mb-2">{{ suggestion.reason }}</p>
                    <div class="flex justify-between text-xs">
                      <span class="text-gray-500">Valor:</span>
                      <span class="font-medium">{{ formatCurrency(suggestion.amount) }}</span>
                    </div>
                  </div>
                </div>

                <!-- Rebalance Button -->
                <div class="mt-6">
                  <button
                    @click="showRebalanceModal = true"
                    class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                  >
                    ⚖️ Executar Rebalanceamento
                  </button>
                </div>

              </div>
            </div>

            <!-- Allocation Strategies -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Estratégias de Alocação</h3>
              </div>
              <div class="px-6 py-4">
                <div class="space-y-3">
                  
                  <button
                    v-for="strategy in allocationStrategies"
                    :key="strategy.id"
                    @click="applyStrategy(strategy)"
                    class="w-full p-3 text-left border border-gray-200 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary"
                  >
                    <div class="flex items-center justify-between mb-1">
                      <span class="text-sm font-medium text-gray-900">{{ strategy.name }}</span>
                      <span class="text-xs text-gray-500">{{ strategy.risk_level }}</span>
                    </div>
                    <p class="text-xs text-gray-600">{{ strategy.description }}</p>
                  </button>

                </div>
              </div>
            </div>

            <!-- Allocation History -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Histórico de Rebalanceamentos</h3>
              </div>
              <div class="px-6 py-4">
                
                <!-- Empty State -->
                <div v-if="!allocation.history?.length" class="text-center py-4">
                  <svg class="mx-auto h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                  <p class="mt-2 text-sm text-gray-500">Nenhum rebalanceamento ainda</p>
                </div>

                <!-- History List -->
                <div v-else class="space-y-3">
                  <div v-for="entry in allocation.history?.slice(0, 5)" :key="entry.id" class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                    <div>
                      <p class="text-sm font-medium text-gray-900">{{ entry.strategy_name }}</p>
                      <p class="text-xs text-gray-500">{{ formatDate(entry.date) }}</p>
                    </div>
                    <div class="text-right">
                      <p class="text-sm text-gray-900">{{ entry.assets_count }} ativos</p>
                      <p class="text-xs text-gray-500">{{ formatCurrency(entry.total_value) }}</p>
                    </div>
                  </div>
                </div>

              </div>
            </div>

          </div>

        </div>
      </div>

      <!-- Rebalance Modal -->
      <div v-if="showRebalanceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
          <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Rebalanceamento do Portfólio</h3>
              <button
                @click="showRebalanceModal = false"
                class="text-gray-400 hover:text-gray-600"
              >
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>
            
            <!-- Rebalance Content -->
            <div class="space-y-4">
              <p class="text-sm text-gray-600">
                O rebalanceamento ajustará seu portfólio para atingir as metas de alocação definidas.
              </p>
              
              <!-- Rebalance Preview -->
              <div class="bg-gray-50 rounded-lg p-4">
                <h4 class="text-sm font-medium text-gray-900 mb-3">Prévia das Operações</h4>
                <div class="space-y-2">
                  <div v-for="operation in rebalancePreview" :key="operation.asset" class="flex justify-between text-sm">
                    <span>{{ operation.asset }}</span>
                    <span :class="operation.action === 'buy' ? 'text-green-600' : 'text-red-600'">
                      {{ operation.action === 'buy' ? '+' : '-' }}{{ formatCurrency(operation.amount) }}
                    </span>
                  </div>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex justify-end space-x-3">
                <button
                  @click="showRebalanceModal = false"
                  class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                  Cancelar
                </button>
                <button
                  @click="executeRebalance"
                  class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark"
                >
                  Confirmar Rebalanceamento
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
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  allocation: Object,
})

// Reactive data
const viewMode = ref('percentage')
const showRebalanceModal = ref(false)

// Mock data for rebalance preview
const rebalancePreview = ref([
  { asset: 'BTC', action: 'sell', amount: 5000 },
  { asset: 'ETH', action: 'buy', amount: 3000 },
  { asset: 'ADA', action: 'buy', amount: 2000 }
])

// Allocation strategies
const allocationStrategies = [
  {
    id: 1,
    name: 'Conservador',
    risk_level: 'Baixo',
    description: '70% BTC, 20% ETH, 10% Stablecoins'
  },
  {
    id: 2,
    name: 'Moderado',
    risk_level: 'Médio',
    description: '50% BTC, 30% ETH, 20% Altcoins'
  },
  {
    id: 3,
    name: 'Agressivo',
    risk_level: 'Alto',
    description: '40% BTC, 25% ETH, 35% Altcoins'
  },
  {
    id: 4,
    name: 'DeFi Focus',
    risk_level: 'Alto',
    description: '30% ETH, 40% DeFi Tokens, 30% Layer 1s'
  }
]

// Methods
const editTarget = (asset) => {
  // Implementation for editing target allocation
  console.log('Editing target for', asset.symbol)
}

const viewAssetDetails = (asset) => {
  // Implementation for viewing asset details
  console.log('Viewing details for', asset.symbol)
}

const applyStrategy = (strategy) => {
  // Implementation for applying allocation strategy
  console.log('Applying strategy', strategy.name)
}

const executeRebalance = () => {
  // Implementation for executing rebalance
  console.log('Executing rebalance...')
  showRebalanceModal.value = false
}

// Helper functions
const getAssetColor = (symbol) => {
  const colors = {
    'BTC': '#F7931A',
    'ETH': '#627EEA',
    'BNB': '#F3BA2F',
    'ADA': '#0033AD',
    'SOL': '#9945FF',
    'DOT': '#E6007A',
    'MATIC': '#8247E5',
    'AVAX': '#E84142'
  }
  return colors[symbol] || '#6B7280'
}

const getDeviationClass = (deviation) => {
  const abs = Math.abs(deviation)
  if (abs <= 2) return 'text-green-600'
  if (abs <= 5) return 'text-yellow-600'
  return 'text-red-600'
}

const getRebalanceScoreColor = (score) => {
  if (score <= 3) return 'bg-green-500'
  if (score <= 6) return 'bg-yellow-500'
  return 'bg-red-500'
}

const getRebalanceScoreLabel = (score) => {
  if (score <= 3) return 'Bem balanceado'
  if (score <= 6) return 'Rebalanceamento recomendado'
  return 'Rebalanceamento urgente'
}

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(amount || 0)
}

const formatQuantity = (quantity) => {
  return new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 8
  }).format(quantity || 0)
}

const formatDate = (date) => {
  return new Intl.DateTimeFormat('pt-BR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
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

