<template>
  <AppLayout title="Performance">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Performance do Portfólio
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Análise histórica de performance e comparações com benchmarks
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <select
              v-model="selectedPeriod"
              @change="updatePeriod"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              <option value="7d">7 dias</option>
              <option value="30d">30 dias</option>
              <option value="90d">90 dias</option>
              <option value="1y">1 ano</option>
              <option value="all">Tudo</option>
            </select>
            <Link
              href="/portfolio"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ← Voltar ao Portfólio
            </Link>
          </div>
        </div>
      </div>

      <!-- Performance Summary -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Retorno Total"
            :value="performance.total_return || 0"
            format="percentage"
            icon="trending-up"
            :color="(performance.total_return || 0) >= 0 ? 'green' : 'red'"
            :change="performance.return_change"
          />
          <StatCard
            title="Retorno Anualizado"
            :value="performance.annualized_return || 0"
            format="percentage"
            icon="calendar"
            color="blue"
          />
          <StatCard
            title="Volatilidade"
            :value="performance.volatility || 0"
            format="percentage"
            icon="activity"
            color="yellow"
          />
          <StatCard
            title="Sharpe Ratio"
            :value="performance.sharpe_ratio || 0"
            format="decimal"
            icon="calculator"
            color="purple"
          />
        </div>
      </div>

      <!-- Main Content -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
          
          <!-- Left Column - Charts -->
          <div class="lg:col-span-2 space-y-6">
            
            <!-- Performance Chart -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                  <h3 class="text-lg font-medium text-gray-900">Performance vs Benchmarks</h3>
                  <div class="flex items-center space-x-2">
                    <label class="inline-flex items-center">
                      <input
                        type="checkbox"
                        v-model="showBenchmarks.bitcoin"
                        class="form-checkbox h-4 w-4 text-primary"
                      >
                      <span class="ml-2 text-sm text-gray-700">Bitcoin</span>
                    </label>
                    <label class="inline-flex items-center">
                      <input
                        type="checkbox"
                        v-model="showBenchmarks.ethereum"
                        class="form-checkbox h-4 w-4 text-primary"
                      >
                      <span class="ml-2 text-sm text-gray-700">Ethereum</span>
                    </label>
                    <label class="inline-flex items-center">
                      <input
                        type="checkbox"
                        v-model="showBenchmarks.market"
                        class="form-checkbox h-4 w-4 text-primary"
                      >
                      <span class="ml-2 text-sm text-gray-700">Mercado</span>
                    </label>
                  </div>
                </div>
              </div>
              <div class="px-6 py-4">
                <!-- Performance Chart Placeholder -->
                <div class="h-80 bg-gray-50 rounded-lg flex items-center justify-center">
                  <div class="text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">Gráfico de Performance</h3>
                    <p class="mt-1 text-sm text-gray-500">
                      Comparação com benchmarks selecionados
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Drawdown Chart -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Análise de Drawdown</h3>
              </div>
              <div class="px-6 py-4">
                <!-- Drawdown Chart Placeholder -->
                <div class="h-64 bg-gray-50 rounded-lg flex items-center justify-center">
                  <div class="text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">Gráfico de Drawdown</h3>
                    <p class="mt-1 text-sm text-gray-500">
                      Períodos de perda máxima
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Monthly Returns Heatmap -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Retornos Mensais</h3>
              </div>
              <div class="px-6 py-4">
                
                <!-- Heatmap Placeholder -->
                <div class="h-64 bg-gray-50 rounded-lg flex items-center justify-center mb-4">
                  <div class="text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"></path>
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">Heatmap de Retornos</h3>
                    <p class="mt-1 text-sm text-gray-500">
                      Performance mensal por ano
                    </p>
                  </div>
                </div>

                <!-- Monthly Stats -->
                <div class="grid grid-cols-3 gap-4 text-center">
                  <div>
                    <p class="text-sm text-gray-500">Meses Positivos</p>
                    <p class="text-lg font-semibold text-green-600">{{ performance.positive_months || 0 }}</p>
                  </div>
                  <div>
                    <p class="text-sm text-gray-500">Meses Negativos</p>
                    <p class="text-lg font-semibold text-red-600">{{ performance.negative_months || 0 }}</p>
                  </div>
                  <div>
                    <p class="text-sm text-gray-500">Win Rate</p>
                    <p class="text-lg font-semibold text-gray-900">{{ (performance.win_rate || 0).toFixed(1) }}%</p>
                  </div>
                </div>

              </div>
            </div>

          </div>

          <!-- Right Column - Sidebar -->
          <div class="lg:col-span-1 space-y-6">
            
            <!-- Performance Metrics -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Métricas de Performance</h3>
              </div>
              <div class="px-6 py-4">
                <dl class="space-y-3">
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">CAGR</dt>
                    <dd class="text-sm font-semibold text-gray-900">{{ (performance.cagr || 0).toFixed(2) }}%</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">Max Drawdown</dt>
                    <dd class="text-sm font-semibold text-red-600">{{ (performance.max_drawdown || 0).toFixed(2) }}%</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">Calmar Ratio</dt>
                    <dd class="text-sm font-semibold text-gray-900">{{ (performance.calmar_ratio || 0).toFixed(2) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">Sortino Ratio</dt>
                    <dd class="text-sm font-semibold text-gray-900">{{ (performance.sortino_ratio || 0).toFixed(2) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">Alpha vs BTC</dt>
                    <dd class="text-sm font-semibold text-gray-900">{{ (performance.alpha_btc || 0).toFixed(4) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">Beta vs BTC</dt>
                    <dd class="text-sm font-semibold text-gray-900">{{ (performance.beta_btc || 0).toFixed(2) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500">Correlação BTC</dt>
                    <dd class="text-sm font-semibold text-gray-900">{{ (performance.correlation_btc || 0).toFixed(3) }}</dd>
                  </div>
                </dl>
              </div>
            </div>

            <!-- Benchmark Comparison -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Comparação com Benchmarks</h3>
              </div>
              <div class="px-6 py-4">
                <div class="space-y-4">
                  
                  <!-- Bitcoin Comparison -->
                  <div class="p-4 border border-gray-200 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                      <div class="flex items-center">
                        <div class="h-8 w-8 rounded-full bg-orange-500 flex items-center justify-center mr-3">
                          <span class="text-xs font-bold text-white">₿</span>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Bitcoin</span>
                      </div>
                      <span class="text-sm text-gray-500">{{ selectedPeriod }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                      <div>
                        <p class="text-gray-500">Seu Portfólio</p>
                        <p :class="(performance.portfolio_return || 0) >= 0 ? 'text-green-600' : 'text-red-600'" class="font-semibold">
                          {{ (performance.portfolio_return || 0).toFixed(2) }}%
                        </p>
                      </div>
                      <div>
                        <p class="text-gray-500">Bitcoin</p>
                        <p :class="(performance.btc_return || 0) >= 0 ? 'text-green-600' : 'text-red-600'" class="font-semibold">
                          {{ (performance.btc_return || 0).toFixed(2) }}%
                        </p>
                      </div>
                    </div>
                    <div class="mt-2 pt-2 border-t border-gray-100">
                      <p class="text-xs text-gray-500">Diferença:</p>
                      <p :class="(performance.vs_btc || 0) >= 0 ? 'text-green-600' : 'text-red-600'" class="text-sm font-semibold">
                        {{ (performance.vs_btc || 0) >= 0 ? '+' : '' }}{{ (performance.vs_btc || 0).toFixed(2) }}%
                      </p>
                    </div>
                  </div>

                  <!-- Ethereum Comparison -->
                  <div class="p-4 border border-gray-200 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                      <div class="flex items-center">
                        <div class="h-8 w-8 rounded-full bg-blue-600 flex items-center justify-center mr-3">
                          <span class="text-xs font-bold text-white">Ξ</span>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Ethereum</span>
                      </div>
                      <span class="text-sm text-gray-500">{{ selectedPeriod }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                      <div>
                        <p class="text-gray-500">Seu Portfólio</p>
                        <p :class="(performance.portfolio_return || 0) >= 0 ? 'text-green-600' : 'text-red-600'" class="font-semibold">
                          {{ (performance.portfolio_return || 0).toFixed(2) }}%
                        </p>
                      </div>
                      <div>
                        <p class="text-gray-500">Ethereum</p>
                        <p :class="(performance.eth_return || 0) >= 0 ? 'text-green-600' : 'text-red-600'" class="font-semibold">
                          {{ (performance.eth_return || 0).toFixed(2) }}%
                        </p>
                      </div>
                    </div>
                    <div class="mt-2 pt-2 border-t border-gray-100">
                      <p class="text-xs text-gray-500">Diferença:</p>
                      <p :class="(performance.vs_eth || 0) >= 0 ? 'text-green-600' : 'text-red-600'" class="text-sm font-semibold">
                        {{ (performance.vs_eth || 0) >= 0 ? '+' : '' }}{{ (performance.vs_eth || 0).toFixed(2) }}%
                      </p>
                    </div>
                  </div>

                  <!-- Market Index Comparison -->
                  <div class="p-4 border border-gray-200 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                      <div class="flex items-center">
                        <div class="h-8 w-8 rounded-full bg-purple-600 flex items-center justify-center mr-3">
                          <span class="text-xs font-bold text-white">📊</span>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Índice Crypto</span>
                      </div>
                      <span class="text-sm text-gray-500">{{ selectedPeriod }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                      <div>
                        <p class="text-gray-500">Seu Portfólio</p>
                        <p :class="(performance.portfolio_return || 0) >= 0 ? 'text-green-600' : 'text-red-600'" class="font-semibold">
                          {{ (performance.portfolio_return || 0).toFixed(2) }}%
                        </p>
                      </div>
                      <div>
                        <p class="text-gray-500">Índice</p>
                        <p :class="(performance.market_return || 0) >= 0 ? 'text-green-600' : 'text-red-600'" class="font-semibold">
                          {{ (performance.market_return || 0).toFixed(2) }}%
                        </p>
                      </div>
                    </div>
                    <div class="mt-2 pt-2 border-t border-gray-100">
                      <p class="text-xs text-gray-500">Diferença:</p>
                      <p :class="(performance.vs_market || 0) >= 0 ? 'text-green-600' : 'text-red-600'" class="text-sm font-semibold">
                        {{ (performance.vs_market || 0) >= 0 ? '+' : '' }}{{ (performance.vs_market || 0).toFixed(2) }}%
                      </p>
                    </div>
                  </div>

                </div>
              </div>
            </div>

            <!-- Best/Worst Periods -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Melhores/Piores Períodos</h3>
              </div>
              <div class="px-6 py-4">
                
                <!-- Best Periods -->
                <div class="mb-6">
                  <h4 class="text-sm font-medium text-green-900 mb-3">🚀 Melhores Períodos</h4>
                  <div class="space-y-2">
                    <div v-for="period in performance.best_periods?.slice(0, 3)" :key="period.date" class="flex justify-between text-sm">
                      <span class="text-gray-600">{{ formatPeriod(period.date) }}</span>
                      <span class="font-medium text-green-600">+{{ period.return.toFixed(2) }}%</span>
                    </div>
                  </div>
                </div>

                <!-- Worst Periods -->
                <div>
                  <h4 class="text-sm font-medium text-red-900 mb-3">📉 Piores Períodos</h4>
                  <div class="space-y-2">
                    <div v-for="period in performance.worst_periods?.slice(0, 3)" :key="period.date" class="flex justify-between text-sm">
                      <span class="text-gray-600">{{ formatPeriod(period.date) }}</span>
                      <span class="font-medium text-red-600">{{ period.return.toFixed(2) }}%</span>
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
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  performance: Object,
})

// Reactive data
const selectedPeriod = ref('30d')
const showBenchmarks = ref({
  bitcoin: true,
  ethereum: true,
  market: false
})

// Methods
const updatePeriod = () => {
  router.get('/portfolio/performance', { period: selectedPeriod.value }, {
    preserveState: true,
    only: ['performance']
  })
}

// Helper functions
const formatPeriod = (date) => {
  return new Intl.DateTimeFormat('pt-BR', {
    year: 'numeric',
    month: 'short'
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

