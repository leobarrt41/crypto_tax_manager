<template>
  <AppLayout title="Portfólio">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Meu Portfólio
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Visão geral dos seus investimentos em criptomoedas
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <button
              @click="refreshPortfolio"
              :disabled="refreshing"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50"
            >
              <svg class="-ml-1 mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
              </svg>
              {{ refreshing ? 'Atualizando...' : 'Atualizar' }}
            </button>
            <select
              v-model="selectedPeriod"
              @change="updatePeriod"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              <option value="24h">24 horas</option>
              <option value="7d">7 dias</option>
              <option value="30d">30 dias</option>
              <option value="90d">90 dias</option>
              <option value="1y">1 ano</option>
              <option value="all">Tudo</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Portfolio Summary Cards -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Valor Total"
            :value="portfolio.total_value || 0"
            format="currency"
            icon="currency-dollar"
            color="green"
            :change="portfolio.total_change_24h"
          />
          <StatCard
            title="Lucro/Prejuízo"
            :value="portfolio.total_pnl || 0"
            format="currency"
            icon="trending-up"
            :color="(portfolio.total_pnl || 0) >= 0 ? 'green' : 'red'"
            :change="portfolio.pnl_change_24h"
          />
          <StatCard
            title="Ativos"
            :value="portfolio.total_assets || 0"
            format="number"
            icon="collection"
            color="blue"
          />
          <StatCard
            title="Carteiras"
            :value="portfolio.total_wallets || 0"
            format="number"
            icon="wallet"
            color="purple"
          />
        </div>
      </div>

      <!-- Main Content -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
          
          <!-- Left Column - Charts -->
          <div class="lg:col-span-2 space-y-6">
            
            <!-- Portfolio Value Chart -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                  <h3 class="text-lg font-medium text-gray-900">Evolução do Portfólio</h3>
                  <div class="flex items-center space-x-2">
                    <button
                      v-for="period in chartPeriods"
                      :key="period.value"
                      @click="selectedChartPeriod = period.value"
                      :class="[
                        'px-3 py-1 text-sm rounded-md',
                        selectedChartPeriod === period.value
                          ? 'bg-primary text-white'
                          : 'text-gray-500 hover:text-gray-700'
                      ]"
                    >
                      {{ period.label }}
                    </button>
                  </div>
                </div>
              </div>
              <div class="px-6 py-4">
                <!-- Chart placeholder - you would integrate with Chart.js or similar -->
                <div class="h-64 bg-gray-50 rounded-lg flex items-center justify-center">
                  <div class="text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">Gráfico de Evolução</h3>
                    <p class="mt-1 text-sm text-gray-500">
                      Período: {{ getChartPeriodLabel(selectedChartPeriod) }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Asset Allocation Chart -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Alocação de Ativos</h3>
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

                  <!-- Allocation List -->
                  <div class="space-y-3">
                    <div v-for="(allocation, index) in portfolio.allocations?.slice(0, 8)" :key="allocation.symbol" class="flex items-center justify-between">
                      <div class="flex items-center">
                        <div 
                          class="h-3 w-3 rounded-full mr-3"
                          :style="{ backgroundColor: getAssetColor(index) }"
                        ></div>
                        <div>
                          <p class="text-sm font-medium text-gray-900">{{ allocation.symbol }}</p>
                          <p class="text-xs text-gray-500">{{ allocation.name }}</p>
                        </div>
                      </div>
                      <div class="text-right">
                        <p class="text-sm font-medium text-gray-900">{{ allocation.percentage.toFixed(1) }}%</p>
                        <p class="text-xs text-gray-500">{{ formatCurrency(allocation.value) }}</p>
                      </div>
                    </div>
                    <div v-if="portfolio.allocations?.length > 8" class="text-center pt-2 border-t border-gray-200">
                      <Link
                        href="/portfolio/allocation"
                        class="text-sm text-primary hover:text-primary-dark"
                      >
                        Ver todos os {{ portfolio.allocations.length }} ativos
                      </Link>
                    </div>
                  </div>

                </div>
              </div>
            </div>

          </div>

          <!-- Right Column - Sidebar -->
          <div class="lg:col-span-1 space-y-6">
            
            <!-- Quick Actions -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Ações Rápidas</h3>
              </div>
              <div class="px-6 py-4 space-y-3">
                <Link
                  href="/transactions/create"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                  </svg>
                  Nova Transação
                </Link>
                <Link
                  href="/portfolio/analytics"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                  </svg>
                  Análises Avançadas
                </Link>
                <Link
                  href="/portfolio/performance"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                  </svg>
                  Performance
                </Link>
                <Link
                  href="/reports"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                >
                  <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                  </svg>
                  Relatórios Fiscais
                </Link>
              </div>
            </div>

            <!-- Top Performers -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Maiores Ganhos (24h)</h3>
              </div>
              <div class="px-6 py-4">
                <div class="space-y-3">
                  <div v-for="performer in portfolio.top_performers?.slice(0, 5)" :key="performer.symbol" class="flex items-center justify-between">
                    <div class="flex items-center">
                      <div class="h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center mr-3">
                        <span class="text-xs font-medium text-gray-600">
                          {{ performer.symbol.substring(0, 2) }}
                        </span>
                      </div>
                      <div>
                        <p class="text-sm font-medium text-gray-900">{{ performer.symbol }}</p>
                        <p class="text-xs text-gray-500">{{ formatCurrency(performer.value) }}</p>
                      </div>
                    </div>
                    <div class="text-right">
                      <p class="text-sm font-medium text-green-600">
                        +{{ performer.change_24h.toFixed(2) }}%
                      </p>
                      <p class="text-xs text-gray-500">
                        +{{ formatCurrency(performer.change_value) }}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Top Losers -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Maiores Perdas (24h)</h3>
              </div>
              <div class="px-6 py-4">
                <div class="space-y-3">
                  <div v-for="loser in portfolio.top_losers?.slice(0, 5)" :key="loser.symbol" class="flex items-center justify-between">
                    <div class="flex items-center">
                      <div class="h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center mr-3">
                        <span class="text-xs font-medium text-gray-600">
                          {{ loser.symbol.substring(0, 2) }}
                        </span>
                      </div>
                      <div>
                        <p class="text-sm font-medium text-gray-900">{{ loser.symbol }}</p>
                        <p class="text-xs text-gray-500">{{ formatCurrency(loser.value) }}</p>
                      </div>
                    </div>
                    <div class="text-right">
                      <p class="text-sm font-medium text-red-600">
                        {{ loser.change_24h.toFixed(2) }}%
                      </p>
                      <p class="text-xs text-gray-500">
                        {{ formatCurrency(loser.change_value) }}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Portfolio Metrics -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Métricas</h3>
              </div>
              <div class="px-6 py-4">
                <dl class="space-y-3">
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">Diversificação</dt>
                    <dd class="text-sm text-gray-900">{{ portfolio.diversification_score || 0 }}/10</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">Volatilidade (30d)</dt>
                    <dd class="text-sm text-gray-900">{{ (portfolio.volatility_30d || 0).toFixed(2) }}%</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">Sharpe Ratio</dt>
                    <dd class="text-sm text-gray-900">{{ (portfolio.sharpe_ratio || 0).toFixed(2) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">Max Drawdown</dt>
                    <dd class="text-sm text-red-600">{{ (portfolio.max_drawdown || 0).toFixed(2) }}%</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">ROI Total</dt>
                    <dd :class="(portfolio.total_roi || 0) >= 0 ? 'text-green-600' : 'text-red-600'" class="text-sm font-medium">
                      {{ (portfolio.total_roi || 0).toFixed(2) }}%
                    </dd>
                  </div>
                </dl>
              </div>
            </div>

          </div>

        </div>
      </div>

      <!-- Recent Activity -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-medium text-gray-900">Atividade Recente</h3>
              <Link
                href="/transactions"
                class="text-sm text-primary hover:text-primary-dark"
              >
                Ver todas as transações
              </Link>
            </div>
          </div>
          <div class="px-6 py-4">
            
            <!-- Loading State -->
            <div v-if="loadingActivity" class="text-center py-8">
              <svg class="animate-spin h-8 w-8 text-primary mx-auto" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <p class="mt-2 text-sm text-gray-500">Carregando atividades...</p>
            </div>

            <!-- Empty State -->
            <div v-else-if="recentActivity.length === 0" class="text-center py-8">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
              </svg>
              <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhuma atividade recente</h3>
              <p class="mt-1 text-sm text-gray-500">
                Suas transações aparecerão aqui quando você começar a usar o sistema.
              </p>
            </div>

            <!-- Activity List -->
            <div v-else class="space-y-4">
              <div v-for="activity in recentActivity" :key="activity.id" class="flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                
                <!-- Activity Info -->
                <div class="flex items-center">
                  <div :class="getActivityTypeClass(activity.type)" class="h-10 w-10 rounded-full flex items-center justify-center mr-4">
                    <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path v-if="activity.type === 'buy'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                      <path v-else-if="activity.type === 'sell'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                      <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                    </svg>
                  </div>
                  <div>
                    <p class="text-sm font-medium text-gray-900">
                      {{ getActivityTypeLabel(activity.type) }} {{ activity.crypto_asset.symbol }}
                    </p>
                    <p class="text-sm text-gray-500">
                      {{ activity.wallet.name }} • {{ formatRelativeTime(activity.date) }}
                    </p>
                  </div>
                </div>

                <!-- Activity Values -->
                <div class="text-right">
                  <p class="text-sm font-medium text-gray-900">
                    {{ formatQuantity(activity.quantity) }} {{ activity.crypto_asset.symbol }}
                  </p>
                  <p class="text-sm text-gray-500">
                    {{ formatCurrency(activity.total_amount) }}
                  </p>
                </div>

                <!-- View Details -->
                <div class="ml-4">
                  <Link
                    :href="`/transactions/${activity.id}`"
                    class="text-primary hover:text-primary-dark"
                  >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                  </Link>
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
  portfolio: Object,
  recentActivity: Array,
})

// Reactive data
const refreshing = ref(false)
const loadingActivity = ref(false)
const selectedPeriod = ref('30d')
const selectedChartPeriod = ref('30d')

// Chart periods
const chartPeriods = [
  { value: '24h', label: '24h' },
  { value: '7d', label: '7d' },
  { value: '30d', label: '30d' },
  { value: '90d', label: '90d' },
  { value: '1y', label: '1a' },
]

// Methods
const refreshPortfolio = async () => {
  refreshing.value = true
  try {
    await router.reload({
      only: ['portfolio', 'recentActivity']
    })
  } finally {
    refreshing.value = false
  }
}

const updatePeriod = () => {
  router.get('/portfolio', { period: selectedPeriod.value }, {
    preserveState: true,
    only: ['portfolio']
  })
}

// Helper functions
const getChartPeriodLabel = (period) => {
  const labels = {
    '24h': '24 horas',
    '7d': '7 dias',
    '30d': '30 dias',
    '90d': '90 dias',
    '1y': '1 ano'
  }
  return labels[period] || period
}

const getAssetColor = (index) => {
  const colors = [
    '#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6',
    '#06B6D4', '#F97316', '#84CC16', '#EC4899', '#6366F1'
  ]
  return colors[index % colors.length]
}

const getActivityTypeLabel = (type) => {
  const labels = {
    buy: 'Compra',
    sell: 'Venda',
    transfer_in: 'Recebimento',
    transfer_out: 'Envio',
    trade: 'Trade',
    mining: 'Mineração',
    staking: 'Staking',
    airdrop: 'Airdrop'
  }
  return labels[type] || type
}

const getActivityTypeClass = (type) => {
  const classes = {
    buy: 'bg-green-500',
    sell: 'bg-red-500',
    transfer_in: 'bg-blue-500',
    transfer_out: 'bg-orange-500',
    trade: 'bg-purple-500',
    mining: 'bg-yellow-500',
    staking: 'bg-indigo-500',
    airdrop: 'bg-pink-500'
  }
  return classes[type] || 'bg-gray-500'
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

const formatRelativeTime = (date) => {
  const now = new Date()
  const past = new Date(date)
  const diffInSeconds = Math.floor((now - past) / 1000)
  
  if (diffInSeconds < 60) return 'agora'
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m atrás`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h atrás`
  return `${Math.floor(diffInSeconds / 86400)}d atrás`
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

