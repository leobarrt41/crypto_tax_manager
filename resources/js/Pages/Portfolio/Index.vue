<template>
  <AppLayout title="Portfólio">
    <div class="py-6">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <header class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl">Meu Portfólio</h2>
            <p class="mt-1 text-sm text-gray-500">Saldos consolidados das suas carteiras, precificados em reais.</p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 gap-3">
            <button
              type="button"
              @click="refreshPortfolio"
              :disabled="refreshing"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
            >
              <svg class="-ml-1 mr-2 h-4 w-4" :class="{ 'animate-spin': refreshing }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              {{ refreshing ? 'Atualizando...' : 'Atualizar saldos' }}
            </button>
            <select v-model="selectedWallet" @change="loadWallet(selectedWallet)" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm text-gray-700 bg-white" aria-label="Filtrar por carteira">
              <option :value="null">Todas as carteiras</option>
              <option v-for="wallet in wallets" :key="wallet.id" :value="wallet.id">{{ wallet.name }}</option>
            </select>
            <select v-model="selectedPeriod" @change="loadPeriod(selectedPeriod)" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm text-gray-700 bg-white">
              <option v-for="period in periods" :key="period.value" :value="period.value">{{ period.label }}</option>
            </select>
          </div>
        </header>

        <div v-if="reconstructionInProgress" class="mt-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900" role="status">
          <strong>Reconstruindo evolução histórica.</strong> {{ reconstructionStatusLabel }} O gráfico será atualizado automaticamente ao concluir.
        </div>
        <div v-else-if="reconstructionSummary" class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" role="status">
          {{ reconstructionSummary }}
        </div>
        <div v-if="flashSuccess" class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">
          {{ flashSuccess }}
        </div>
        <div v-if="flashError" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
          {{ flashError }}
        </div>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mt-6">
          <StatCard
            title="Valor total"
            :value="portfolio.total_value"
            format="currency"
            icon="currency"
            color="green"
            :change="asFiniteNumber(portfolio.total_change_24h_percentage)"
            subtitle="Saldo disponível + bloqueado"
          />
          <StatCard
            title="Lucro/prejuízo não realizado"
            :value="portfolio.total_profit_loss ?? '—'"
            :format="portfolio.total_profit_loss === null ? 'text' : 'currency'"
            icon="chart"
            :color="(portfolio.total_profit_loss ?? 0) >= 0 ? 'green' : 'red'"
            :change="asFiniteNumber(portfolio.total_profit_loss_percentage)"
            :subtitle="portfolio.total_profit_loss === null ? 'Custo histórico ainda indisponível' : 'Com base no custo FIFO disponível'"
          />
          <StatCard title="Ativos" :value="portfolio.assets_count" format="number" icon="users" color="blue" subtitle="Com saldo acima de zero" />
          <StatCard title="Carteiras" :value="portfolio.wallets_count" format="number" icon="currency" color="purple" subtitle="Carteiras cadastradas" />
        </div>

        <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
          Cobertura de preços: <strong>{{ formatPercentage(portfolio.price_coverage_percentage) }}</strong>.
          Cobertura do custo FIFO: <strong>{{ formatPercentage(portfolio.cost_basis_coverage_percentage) }}</strong>.
          Valores sem preço ou custo disponível são sinalizados, nunca calculados como zero.
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 mt-6">
          <div class="lg:col-span-2 space-y-6">
            <section class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap gap-3 items-center justify-between">
                <div>
                  <h3 class="text-lg font-medium text-gray-900">Evolução do Portfólio</h3>
                  <p class="text-xs text-gray-500 mt-1">Série formada por snapshots oficiais, registros locais e saldos reconstruídos a partir das transações.</p>
                </div>
                <div class="flex flex-wrap gap-1">
                  <button
                    v-for="period in chartPeriods"
                    :key="period.value"
                    type="button"
                    @click="loadPeriod(period.value)"
                    :class="selectedPeriod === period.value ? 'bg-primary text-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-3 py-1 text-sm rounded-md"
                  >
                    {{ period.label }}
                  </button>
                </div>
              </div>
              <div class="px-6 py-4">
                <div v-if="chartPoints.length >= 2" class="h-64">
                  <svg viewBox="0 0 760 250" preserveAspectRatio="none" class="w-full h-full" role="img" aria-label="Evolução real do valor do Portfólio">
                    <defs>
                      <linearGradient id="portfolioFill" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.24" />
                        <stop offset="100%" stop-color="#3b82f6" stop-opacity="0.02" />
                      </linearGradient>
                    </defs>
                    <path :d="chartAreaPath" fill="url(#portfolioFill)" />
                    <path :d="chartLinePath" fill="none" stroke="#2563eb" stroke-width="3" vector-effect="non-scaling-stroke" />
                  </svg>
                  <div class="mt-2 flex justify-between text-xs text-gray-500">
                    <span>{{ chartStartLabel }}</span>
                    <span>{{ chartEndLabel }}</span>
                  </div>
                  <p v-if="historyCoverageMessage" class="mt-3 text-xs text-amber-700">{{ historyCoverageMessage }}</p>
                  <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
                    <span v-for="source in historySources" :key="source.key"><strong>{{ source.label }}:</strong> {{ source.count }} ponto(s)</span>
                  </div>
                </div>
                <EmptyState v-else title="Histórico ainda em formação" description="Atualize os saldos para iniciar a reconstrução baseada em snapshots e transações já registradas." />
              </div>
            </section>

            <section class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Alocação de Ativos</h3>
              </div>
              <div class="px-6 py-5 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="flex items-center justify-center min-h-64">
                  <div v-if="portfolio.allocations.length" class="relative h-52 w-52 rounded-full" :style="{ background: allocationGradient }" aria-label="Gráfico de alocação de ativos">
                    <div class="absolute inset-9 bg-white rounded-full flex flex-col items-center justify-center text-center px-2">
                      <span class="text-xs text-gray-500">Valor total</span>
                      <span class="mt-1 text-sm font-semibold text-gray-900">{{ formatCurrency(portfolio.total_value) }}</span>
                    </div>
                  </div>
                  <EmptyState v-else compact title="Sem saldos precificados" description="Importe ou atualize os saldos de suas carteiras." />
                </div>
                <div v-if="portfolio.allocations.length" class="space-y-3">
                  <div v-for="(allocation, index) in portfolio.allocations.slice(0, 8)" :key="allocation.symbol" class="flex items-center justify-between">
                    <div class="flex items-center min-w-0">
                      <span class="h-3 w-3 rounded-full mr-3 flex-none" :style="{ backgroundColor: getAssetColor(index) }"></span>
                      <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ allocation.symbol }}</p>
                        <p class="text-xs text-gray-500 truncate">{{ allocation.name }}</p>
                      </div>
                    </div>
                    <div class="text-right ml-3">
                      <p class="text-sm font-medium text-gray-900">{{ formatPercentage(allocation.percentage, 1) }}</p>
                      <p class="text-xs text-gray-500">{{ formatCurrency(allocation.value_brl) }}</p>
                    </div>
                  </div>
                </div>
              </div>
            </section>
          </div>

          <aside class="space-y-6">
            <section class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200"><h3 class="text-lg font-medium text-gray-900">Maiores ganhos (24h)</h3></div>
              <div class="px-6 py-4">
                <div v-if="portfolio.top_performers.length" class="space-y-3">
                  <div v-for="asset in portfolio.top_performers" :key="asset.symbol" class="flex items-center justify-between">
                    <div><p class="text-sm font-medium text-gray-900">{{ asset.symbol }}</p><p class="text-xs text-gray-500">{{ formatCurrency(asset.value_brl) }}</p></div>
                    <div class="text-right"><p :class="asset.price_change_24h >= 0 ? 'text-green-600' : 'text-red-600'" class="text-sm font-medium">{{ formatSignedPercentage(asset.price_change_24h) }}</p><p class="text-xs text-gray-500">{{ formatSignedCurrency(asset.change_value_24h_brl) }}</p></div>
                  </div>
                </div>
                <EmptyState v-else compact title="Sem variação disponível" description="As variações dependem dos dados de mercado do ativo." />
              </div>
            </section>

            <section class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200"><h3 class="text-lg font-medium text-gray-900">Maiores perdas (24h)</h3></div>
              <div class="px-6 py-4">
                <div v-if="portfolio.top_losers.length" class="space-y-3">
                  <div v-for="asset in portfolio.top_losers" :key="asset.symbol" class="flex items-center justify-between">
                    <div><p class="text-sm font-medium text-gray-900">{{ asset.symbol }}</p><p class="text-xs text-gray-500">{{ formatCurrency(asset.value_brl) }}</p></div>
                    <div class="text-right"><p :class="asset.price_change_24h >= 0 ? 'text-green-600' : 'text-red-600'" class="text-sm font-medium">{{ formatSignedPercentage(asset.price_change_24h) }}</p><p class="text-xs text-gray-500">{{ formatSignedCurrency(asset.change_value_24h_brl) }}</p></div>
                  </div>
                </div>
                <EmptyState v-else compact title="Sem variação disponível" description="As variações dependem dos dados de mercado do ativo." />
              </div>
            </section>

            <section class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200"><h3 class="text-lg font-medium text-gray-900">Métricas</h3></div>
              <dl class="px-6 py-4 space-y-3">
                <MetricRow label="Diversificação" :value="portfolio.diversification_score === null ? '—' : `${formatNumber(portfolio.diversification_score, 1)}/10`" />
                <MetricRow label="Volatilidade anualizada" :value="formatPercentage(portfolio.volatility_30d)" />
                <MetricRow label="Sharpe Ratio" :value="formatNumber(portfolio.sharpe_ratio, 2)" />
                <MetricRow label="Max. drawdown" :value="formatPercentage(portfolio.max_drawdown)" value-class="text-red-600" />
                <MetricRow label="ROI total" :value="formatPercentage(portfolio.total_profit_loss_percentage)" :value-class="(portfolio.total_profit_loss_percentage ?? 0) >= 0 ? 'text-green-600' : 'text-red-600'" />
              </dl>
              <p v-if="!portfolio.metrics_data_available" class="px-6 pb-4 text-xs text-gray-500">São necessários pelo menos três snapshots para calcular risco e retorno.</p>
            </section>
          </aside>
        </div>

        <section class="bg-white shadow rounded-lg mt-6">
          <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-medium text-gray-900">Atividade Recente</h3>
            <Link href="/transactions" class="text-sm text-primary hover:text-primary-dark">Ver todas as transações</Link>
          </div>
          <div v-if="recentActivity.length" class="divide-y divide-gray-200">
            <div v-for="activity in recentActivity" :key="activity.id" class="px-6 py-4 flex items-center justify-between gap-4 hover:bg-gray-50">
              <div class="flex items-center min-w-0">
                <div :class="getActivityTypeClass(activity.type)" class="h-10 w-10 rounded-full flex items-center justify-center flex-none mr-4 text-white text-sm font-bold">{{ activity.asset_symbol?.slice(0, 2) || '—' }}</div>
                <div class="min-w-0"><p class="text-sm font-medium text-gray-900">{{ getActivityTypeLabel(activity.type) }} {{ activity.asset_symbol || 'ativo não informado' }}</p><p class="text-sm text-gray-500 truncate">{{ activity.source_name }} · {{ formatDate(activity.occurred_at) }}</p></div>
              </div>
              <div class="text-right flex-none"><p class="text-sm font-medium text-gray-900">{{ formatQuantity(activity.quantity) }} {{ activity.asset_symbol }}</p><p class="text-sm text-gray-500">{{ formatCurrency(activity.total_brl) }}</p></div>
            </div>
          </div>
          <EmptyState v-else title="Nenhuma atividade recente" description="As transações importadas aparecerão aqui." />
        </section>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import EmptyState from '@/Components/EmptyState.vue'
import MetricRow from '@/Components/PortfolioMetricRow.vue'

const props = defineProps({
  portfolio: {
    type: Object,
    default: () => ({
      total_value: 0,
      total_profit_loss: null,
      assets_count: 0,
      wallets_count: 0,
      allocations: [],
      top_performers: [],
      top_losers: [],
      history: { data: [] },
    }),
  },
  recentActivity: { type: Array, default: () => [] },
  wallets: { type: Array, default: () => [] },
  reconstructionSession: { type: Object, default: null },
})

const page = usePage()
const refreshing = ref(false)
const selectedPeriod = ref(props.portfolio.period || '30d')
const selectedWallet = ref(props.portfolio.wallet_id ?? null)
const flashSuccess = computed(() => page.props.flash?.success || '')
const flashError = computed(() => page.props.flash?.error || '')
const periods = [
  { value: '7d', label: '7 dias' },
  { value: '30d', label: '30 dias' },
]
const chartPeriods = periods

const chartPoints = computed(() => Array.isArray(props.portfolio.history?.data) ? props.portfolio.history.data : [])
const reconstructionInProgress = computed(() => ['pending', 'processing', 'pricing'].includes(props.reconstructionSession?.status))
const reconstructionStatusLabel = computed(() => {
  const status = props.reconstructionSession?.status
  if (status === 'pending') return 'Aguardando o worker de fila.'
  if (status === 'pricing') return 'Calculando preços históricos.'
  return 'Revertendo transações por carteira e consolidando os saldos.'
})
const reconstructionSummary = computed(() => {
  const session = props.reconstructionSession
  if (!session || session.status !== 'completed') return ''
  const result = session.settings?.result || {}
  if (!result.snapshots_written) return 'A última reconstrução não gerou novos pontos históricos.'
  const partial = Number(result.partial_snapshots || 0)
  return `${result.snapshots_written} ponto(s) histórico(s) atualizados${partial > 0 ? `; ${partial} com cobertura parcial` : ''}.`
})
const historySources = computed(() => {
  const labels = { official: 'Oficial', local: 'Local', reconstructed: 'Reconstruído' }
  return Object.entries(labels)
    .map(([key, label]) => ({ key, label, count: chartPoints.value.filter((point) => point.source === key).length }))
    .filter((source) => source.count > 0)
})
const historyCoverageMessage = computed(() => {
  const partialPoints = chartPoints.value.filter((point) => point.reconstruction_status === 'partial')
  if (!partialPoints.length) return ''
  const minimum = Math.min(...partialPoints.map((point) => Number(point.coverage_percentage) || 0))
  return `Há ${partialPoints.length} ponto(s) parcialmente precificado(s). A menor cobertura de preços é ${formatPercentage(minimum)}; valores ausentes não foram tratados como zero.`
})
const chartLinePath = computed(() => buildChartPaths(chartPoints.value).line)
const chartAreaPath = computed(() => buildChartPaths(chartPoints.value).area)
const chartStartLabel = computed(() => chartPoints.value[0] ? `${formatDate(chartPoints.value[0].date)} · ${formatCurrency(chartPoints.value[0].value_brl)}` : '')
const chartEndLabel = computed(() => chartPoints.value.at(-1) ? `${formatDate(chartPoints.value.at(-1).date)} · ${formatCurrency(chartPoints.value.at(-1).value_brl)}` : '')
const allocationGradient = computed(() => {
  const allocations = props.portfolio.allocations || []
  if (!allocations.length) return '#e5e7eb'

  let cursor = 0
  const slices = allocations.slice(0, 8).map((allocation, index) => {
    const start = cursor
    cursor += Number(allocation.percentage) || 0
    return `${getAssetColor(index)} ${start}% ${cursor}%`
  })
  if (cursor < 100) slices.push(`#e5e7eb ${cursor}% 100%`)
  return `conic-gradient(${slices.join(', ')})`
})

const refreshPortfolio = () => {
  router.post('/portfolio/refresh', {}, {
    preserveScroll: true,
    onStart: () => { refreshing.value = true },
    onFinish: () => { refreshing.value = false },
  })
}

const loadPortfolio = () => {
  const query = { period: selectedPeriod.value }
  if (selectedWallet.value) query.wallet_id = selectedWallet.value
  router.get('/portfolio', query, {
    preserveScroll: true,
    preserveState: true,
    only: ['portfolio', 'recentActivity', 'wallets', 'reconstructionSession'],
  })
}

const loadPeriod = (period) => {
  selectedPeriod.value = period
  loadPortfolio()
}

const loadWallet = (walletId) => {
  selectedWallet.value = walletId || null
  loadPortfolio()
}

let reconstructionPoll = null
const stopReconstructionPolling = () => {
  if (reconstructionPoll) {
    window.clearTimeout(reconstructionPoll)
    reconstructionPoll = null
  }
}
const pollReconstruction = () => {
  stopReconstructionPolling()
  if (!reconstructionInProgress.value) return
  reconstructionPoll = window.setTimeout(() => {
    router.reload({
      only: ['portfolio', 'reconstructionSession'],
      onFinish: pollReconstruction,
    })
  }, 4000)
}
watch(reconstructionInProgress, pollReconstruction, { immediate: true })
onMounted(pollReconstruction)
onBeforeUnmount(stopReconstructionPolling)

const buildChartPaths = (points) => {
  if (points.length < 2) return { line: '', area: '' }
  const width = 760
  const height = 250
  const padding = 12
  const values = points.map((point) => Number(point.value_brl)).filter(Number.isFinite)
  const min = Math.min(...values)
  const max = Math.max(...values)
  const range = max - min || 1
  const usableWidth = width - padding * 2
  const usableHeight = height - padding * 2
  const coordinates = points.map((point, index) => {
    const value = Number(point.value_brl)
    const x = padding + (index / (points.length - 1)) * usableWidth
    const y = padding + (1 - ((value - min) / range)) * usableHeight
    return [x.toFixed(2), y.toFixed(2)]
  })
  const line = coordinates.map(([x, y], index) => `${index === 0 ? 'M' : 'L'} ${x} ${y}`).join(' ')
  const area = `${line} L ${coordinates.at(-1)[0]} ${height - padding} L ${coordinates[0][0]} ${height - padding} Z`
  return { line, area }
}

const formatCurrency = (value) => {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(numeric) : '—'
}
const formatSignedCurrency = (value) => {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? `${numeric > 0 ? '+' : ''}${formatCurrency(numeric)}` : '—'
}
const formatNumber = (value, digits = 2) => {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? new Intl.NumberFormat('pt-BR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(numeric) : '—'
}
const formatPercentage = (value, digits = 2) => {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? `${formatNumber(numeric, digits)}%` : '—'
}
const formatSignedPercentage = (value) => {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? `${numeric > 0 ? '+' : ''}${formatPercentage(numeric)}` : '—'
}
const formatQuantity = (value) => {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 8 }).format(numeric) : '—'
}
const formatDate = (value) => {
  if (!value) return 'Data não informada'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Data não informada' : date.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' })
}
const asFiniteNumber = (value) => Number.isFinite(Number(value)) ? Number(value) : null
const getAssetColor = (index) => ['#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6', '#06B6D4', '#F97316', '#84CC16'][index % 8]
const getActivityTypeLabel = (type) => ({ buy: 'Compra de', sell: 'Venda de', deposit: 'Depósito de', withdrawal: 'Saque de', trade: 'Trade de', convert: 'Conversão de', swap: 'Swap de', reward: 'Recompensa em', airdrop: 'Airdrop de' }[type] || 'Movimentação de')
const getActivityTypeClass = (type) => ({
  buy: 'bg-green-600',
  deposit: 'bg-green-600',
  reward: 'bg-green-600',
  airdrop: 'bg-green-600',
  sell: 'bg-red-600',
  withdrawal: 'bg-red-600',
  trade: 'bg-blue-600',
  convert: 'bg-purple-600',
  swap: 'bg-purple-600',
}[String(type || '').toLowerCase()] || 'bg-gray-500')
</script>

<style scoped>
.bg-primary { background-color: var(--primary-color, #3b82f6); }
.text-primary { color: var(--primary-color, #3b82f6); }
.hover\:text-primary-dark:hover { color: var(--primary-dark, #2563eb); }
</style>
