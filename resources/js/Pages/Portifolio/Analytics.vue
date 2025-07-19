<template>
  <AppLayout title="Análises Avançadas">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Análises Avançadas
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Métricas detalhadas e análises quantitativas do seu portfólio
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <Link
              href="/portfolio"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ← Voltar ao Portfólio
            </Link>
            <button
              @click="exportAnalytics"
              class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              📊 Exportar Análises
            </button>
          </div>
        </div>
      </div>

      <!-- Risk Metrics -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="VaR (95%)"
            :value="analytics.var_95 || 0"
            format="currency"
            icon="shield-exclamation"
            color="red"
            description="Value at Risk em 1 dia"
          />
          <StatCard
            title="Beta"
            :value="analytics.beta || 0"
            format="decimal"
            icon="trending-up"
            color="blue"
            description="Correlação com Bitcoin"
          />
          <StatCard
            title="Volatilidade"
            :value="analytics.volatility_30d || 0"
            format="percentage"
            icon="activity"
            color="yellow"
            description="Desvio padrão 30 dias"
          />
          <StatCard
            title="Sharpe Ratio"
            :value="analytics.sharpe_ratio || 0"
            format="decimal"
            icon="calculator"
            color="green"
            description="Retorno ajustado ao risco"
          />
        </div>
      </div>

      <!-- Main Content -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
          
          <!-- Risk Analysis -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Análise de Risco</h3>
            </div>
            <div class="px-6 py-4">
              
              <!-- Risk Score -->
              <div class="mb-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium text-gray-700">Score de Risco</span>
                  <span class="text-sm text-gray-500">{{ analytics.risk_score || 0 }}/10</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                  <div 
                    class="h-2 rounded-full transition-all duration-300"
                    :class="getRiskScoreColor(analytics.risk_score)"
                    :style="{ width: `${(analytics.risk_score || 0) * 10}%` }"
                  ></div>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                  {{ getRiskScoreLabel(analytics.risk_score) }}
                </p>
              </div>

              <!-- Risk Metrics Table -->
              <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <dt class="text-sm font-medium text-gray-500">Max Drawdown</dt>
                    <dd class="mt-1 text-lg font-semibold text-red-600">
                      {{ (analytics.max_drawdown || 0).toFixed(2) }}%
                    </dd>
                  </div>
                  <div>
                    <dt class="text-sm font-medium text-gray-500">Calmar Ratio</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                      {{ (analytics.calmar_ratio || 0).toFixed(2) }}
                    </dd>
                  </div>
                  <div>
                    <dt class="text-sm font-medium text-gray-500">Sortino Ratio</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                      {{ (analytics.sortino_ratio || 0).toFixed(2) }}
                    </dd>
                  </div>
                  <div>
                    <dt class="text-sm font-medium text-gray-500">Downside Deviation</dt>
                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                      {{ (analytics.downside_deviation || 0).toFixed(2) }}%
                    </dd>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Correlation Matrix -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Matriz de Correlação</h3>
            </div>
            <div class="px-6 py-4">
              
              <!-- Correlation Heatmap Placeholder -->
              <div class="h-64 bg-gray-50 rounded-lg flex items-center justify-center mb-4">
                <div class="text-center">
                  <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                  </svg>
                  <p class="mt-2 text-sm text-gray-500">Matriz de Correlação</p>
                </div>
              </div>

              <!-- Top Correlations -->
              <div class="space-y-3">
                <h4 class="text-sm font-medium text-gray-900">Maiores Correlações</h4>
                <div v-for="correlation in analytics.top_correlations?.slice(0, 5)" :key="`${correlation.asset1}-${correlation.asset2}`" class="flex items-center justify-between">
                  <div class="flex items-center">
                    <span class="text-sm text-gray-900">{{ correlation.asset1 }}</span>
                    <span class="mx-2 text-gray-400">↔</span>
                    <span class="text-sm text-gray-900">{{ correlation.asset2 }}</span>
                  </div>
                  <div class="text-right">
                    <span 
                      :class="correlation.value >= 0.7 ? 'text-red-600' : correlation.value >= 0.3 ? 'text-yellow-600' : 'text-green-600'"
                      class="text-sm font-medium"
                    >
                      {{ correlation.value.toFixed(3) }}
                    </span>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Performance Attribution -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Atribuição de Performance</h3>
            </div>
            <div class="px-6 py-4">
              
              <!-- Performance Chart Placeholder -->
              <div class="h-48 bg-gray-50 rounded-lg flex items-center justify-center mb-4">
                <div class="text-center">
                  <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                  </svg>
                  <p class="mt-2 text-sm text-gray-500">Gráfico de Atribuição</p>
                </div>
              </div>

              <!-- Attribution Table -->
              <div class="space-y-3">
                <div v-for="attribution in analytics.performance_attribution?.slice(0, 8)" :key="attribution.asset" class="flex items-center justify-between">
                  <div class="flex items-center">
                    <div class="h-3 w-3 rounded-full mr-3" :style="{ backgroundColor: getAssetColor(attribution.asset) }"></div>
                    <span class="text-sm font-medium text-gray-900">{{ attribution.asset }}</span>
                  </div>
                  <div class="text-right">
                    <span 
                      :class="attribution.contribution >= 0 ? 'text-green-600' : 'text-red-600'"
                      class="text-sm font-medium"
                    >
                      {{ attribution.contribution >= 0 ? '+' : '' }}{{ attribution.contribution.toFixed(2) }}%
                    </span>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Diversification Analysis -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Análise de Diversificação</h3>
            </div>
            <div class="px-6 py-4">
              
              <!-- Diversification Score -->
              <div class="mb-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium text-gray-700">Score de Diversificação</span>
                  <span class="text-sm text-gray-500">{{ analytics.diversification_score || 0 }}/10</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                  <div 
                    class="bg-blue-500 h-2 rounded-full transition-all duration-300"
                    :style="{ width: `${(analytics.diversification_score || 0) * 10}%` }"
                  ></div>
                </div>
              </div>

              <!-- Concentration Metrics -->
              <div class="space-y-4">
                <div>
                  <dt class="text-sm font-medium text-gray-500">Índice Herfindahl</dt>
                  <dd class="mt-1 text-lg font-semibold text-gray-900">
                    {{ (analytics.herfindahl_index || 0).toFixed(4) }}
                  </dd>
                  <p class="text-xs text-gray-500">Menor = mais diversificado</p>
                </div>
                
                <div>
                  <dt class="text-sm font-medium text-gray-500">Top 3 Concentração</dt>
                  <dd class="mt-1 text-lg font-semibold text-gray-900">
                    {{ (analytics.top3_concentration || 0).toFixed(1) }}%
                  </dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Número Efetivo de Ativos</dt>
                  <dd class="mt-1 text-lg font-semibold text-gray-900">
                    {{ (analytics.effective_assets || 0).toFixed(1) }}
                  </dd>
                </div>
              </div>

              <!-- Recommendations -->
              <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                <h4 class="text-sm font-medium text-blue-900 mb-2">Recomendações</h4>
                <ul class="text-sm text-blue-800 space-y-1">
                  <li v-for="recommendation in analytics.diversification_recommendations" :key="recommendation">
                    • {{ recommendation }}
                  </li>
                </ul>
              </div>

            </div>
          </div>

        </div>
      </div>

      <!-- Advanced Metrics -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Métricas Avançadas</h3>
          </div>
          <div class="px-6 py-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              
              <!-- Return Metrics -->
              <div>
                <h4 class="text-sm font-medium text-gray-900 mb-4">Métricas de Retorno</h4>
                <dl class="space-y-3">
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">CAGR (1 ano)</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ (analytics.cagr_1y || 0).toFixed(2) }}%</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">Retorno Anualizado</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ (analytics.annualized_return || 0).toFixed(2) }}%</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">Alpha</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ (analytics.alpha || 0).toFixed(4) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">Tracking Error</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ (analytics.tracking_error || 0).toFixed(2) }}%</dd>
                  </div>
                </dl>
              </div>

              <!-- Risk Metrics -->
              <div>
                <h4 class="text-sm font-medium text-gray-900 mb-4">Métricas de Risco</h4>
                <dl class="space-y-3">
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">VaR (99%)</dt>
                    <dd class="text-sm font-medium text-red-600">{{ formatCurrency(analytics.var_99 || 0) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">CVaR (95%)</dt>
                    <dd class="text-sm font-medium text-red-600">{{ formatCurrency(analytics.cvar_95 || 0) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">Skewness</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ (analytics.skewness || 0).toFixed(3) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">Kurtosis</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ (analytics.kurtosis || 0).toFixed(3) }}</dd>
                  </div>
                </dl>
              </div>

              <!-- Efficiency Metrics -->
              <div>
                <h4 class="text-sm font-medium text-gray-900 mb-4">Métricas de Eficiência</h4>
                <dl class="space-y-3">
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">Information Ratio</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ (analytics.information_ratio || 0).toFixed(3) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">Treynor Ratio</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ (analytics.treynor_ratio || 0).toFixed(3) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">Jensen's Alpha</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ (analytics.jensen_alpha || 0).toFixed(4) }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">M² Measure</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ (analytics.m2_measure || 0).toFixed(3) }}</dd>
                  </div>
                </dl>
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
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  analytics: Object,
})

// Methods
const exportAnalytics = () => {
  // Implementation for exporting analytics data
  console.log('Exporting analytics...')
}

// Helper functions
const getRiskScoreColor = (score) => {
  if (score <= 3) return 'bg-green-500'
  if (score <= 6) return 'bg-yellow-500'
  return 'bg-red-500'
}

const getRiskScoreLabel = (score) => {
  if (score <= 3) return 'Baixo risco'
  if (score <= 6) return 'Risco moderado'
  return 'Alto risco'
}

const getAssetColor = (asset) => {
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
  return colors[asset] || '#6B7280'
}

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(amount || 0)
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

