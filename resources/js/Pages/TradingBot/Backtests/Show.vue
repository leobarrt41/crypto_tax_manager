<template>
  <AppLayout title="Resultado do backtest">
    <div class="py-8">
      <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="rounded-xl border border-sky-200 bg-sky-50 p-5 text-sky-950">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h1 class="text-2xl font-bold">Resultado histórico do backtest</h1>
              <p class="mt-1 text-sm leading-6">Simulação baseada em candles fechados e versão imutável de estratégia. Nenhuma ordem, saldo ou credencial de exchange foi utilizado.</p>
            </div>
            <span class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass(backtest.status)">{{ statusLabel(backtest.status) }}</span>
          </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <MetricCard label="Capital inicial" :value="formatNumber(metrics.initial_capital)" />
          <MetricCard label="Patrimônio final" :value="formatNumber(metrics.final_equity)" />
          <MetricCard label="Retorno líquido" :value="formatNumber(metrics.net_return)" :tone="numberTone(metrics.net_return)" />
          <MetricCard label="Retorno percentual" :value="formatPercent(metrics.return_percentage)" :tone="numberTone(metrics.return_percentage)" />
        </section>

        <section v-if="chart.points.length" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h2 class="text-lg font-semibold text-slate-900">Evolução do patrimônio</h2>
              <p class="mt-1 text-sm text-slate-600">Patrimônio marcado a mercado no fechamento de cada candle, já considerando taxas e slippage.</p>
            </div>
            <div class="flex flex-wrap gap-4 text-xs font-medium text-slate-600">
              <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 bg-indigo-600"></span>Estratégia</span>
              <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 bg-slate-400"></span>Buy-and-hold</span>
              <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>Entrada</span>
              <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-rose-500"></span>Saída</span>
            </div>
          </div>

          <div class="mt-5 overflow-hidden rounded-lg border border-slate-200 bg-slate-50 p-3">
            <svg viewBox="0 0 1000 360" class="h-72 w-full" role="img" aria-label="Curva de patrimônio da estratégia comparada ao buy-and-hold">
              <line v-for="tick in chart.yTicks" :key="tick.y" x1="70" x2="980" :y1="tick.y" :y2="tick.y" stroke="#e2e8f0" stroke-width="1" />
              <text v-for="tick in chart.yTicks" :key="`label-${tick.y}`" x="62" :y="tick.y + 4" text-anchor="end" class="fill-slate-500 text-[11px]">{{ compactNumber(tick.value) }}</text>
              <polyline :points="chart.buyAndHoldLine" fill="none" stroke="#94a3b8" stroke-width="2" vector-effect="non-scaling-stroke" />
              <polyline :points="chart.strategyLine" fill="none" stroke="#4f46e5" stroke-width="3" vector-effect="non-scaling-stroke" />
              <g v-for="marker in chart.markers" :key="`${marker.index}-${marker.event}`">
                <circle :cx="marker.x" :cy="marker.y" r="5" :fill="marker.event === 'entry' ? '#10b981' : '#f43f5e'" stroke="white" stroke-width="2">
                  <title>{{ marker.event === 'entry' ? 'Entrada' : 'Saída' }} · {{ formatDateTime(marker.timestamp) }} · {{ formatCurrency(marker.value) }}</title>
                </circle>
              </g>
              <text x="70" y="348" class="fill-slate-500 text-[11px]">{{ formatDateTime(chart.firstTimestamp) }}</text>
              <text x="980" y="348" text-anchor="end" class="fill-slate-500 text-[11px]">{{ formatDateTime(chart.lastTimestamp) }}</text>
            </svg>
          </div>
          <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <p class="rounded-lg bg-indigo-50 px-4 py-3 text-indigo-950">Estratégia: <strong :class="numberTone(metrics.net_return)">{{ formatSignedCurrency(metrics.net_return) }}</strong></p>
            <p class="rounded-lg bg-slate-100 px-4 py-3 text-slate-800">Buy-and-hold: <strong :class="numberTone(metrics.buy_and_hold?.net_return)">{{ formatSignedCurrency(metrics.buy_and_hold?.net_return) }}</strong></p>
          </div>
        </section>

        <section v-else class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-8 text-center">
          <h2 class="font-semibold text-slate-900">Curva de patrimônio indisponível</h2>
          <p class="mt-1 text-sm text-slate-600">Este resultado foi criado antes da inclusão do gráfico. Execute um novo backtest para gerar a evolução por candle.</p>
        </section>

        <section class="grid gap-6 lg:grid-cols-3">
          <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 lg:col-span-2">
            <h2 class="text-lg font-semibold text-slate-900">Premissas e integridade</h2>
            <dl class="mt-5 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
              <InfoRow label="Estratégia" :value="`${backtest.strategy?.name || 'Estratégia'} · versão ${backtest.strategy_version?.version || backtest.strategy_version_number}`" />
              <InfoRow label="Mercado" :value="`${backtest.exchange?.name || 'Exchange'} · ${backtest.symbol} · ${backtest.timeframe}`" />
              <InfoRow label="Período solicitado" :value="formatPeriod(backtest.requested_start_at, backtest.requested_end_at)" />
              <InfoRow label="Candles usados" :value="String(backtest.candles_count)" />
              <InfoRow label="Fill" value="Abertura do candle N+1 após o sinal" />
              <InfoRow label="Posição ao final" :value="metrics.open_position_at_end ? 'Aberta e marcada a mercado' : 'Encerrada'" />
              <InfoRow label="Hash da estratégia" :value="backtest.strategy_definition_hash" mono />
              <InfoRow label="Hash do dataset" :value="backtest.dataset_hash" mono />
            </dl>
          </div>

          <aside class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-950">
            <h2 class="text-lg font-semibold">Limites desta simulação</h2>
            <p class="mt-3 text-sm leading-6">Backtest não garante desempenho futuro. O modelo é spot, long-only e mantém no máximo uma posição aberta. Não há paper trading, monitoramento ou envio de ordens.</p>
          </aside>
        </section>

        <section v-if="backtest.warnings?.length" class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-950">
          <h2 class="text-lg font-semibold">Avisos de dados e simulação</h2>
          <ul class="mt-3 list-disc space-y-1 pl-5 text-sm"><li v-for="warning in backtest.warnings" :key="warning">{{ warning }}</li></ul>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
          <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-semibold text-slate-900">Métricas da estratégia</h2>
            <dl class="mt-4 divide-y divide-slate-100 text-sm">
              <MetricRow label="P&L realizado" :value="formatNumber(metrics.realized_pnl)" />
              <MetricRow label="P&L não realizado" :value="formatNumber(metrics.unrealized_pnl)" />
              <MetricRow label="Taxas totais" :value="formatNumber(metrics.total_fees)" />
              <MetricRow label="Efeito estimado do slippage" :value="formatNumber(metrics.estimated_slippage_cost)" />
              <MetricRow label="Entradas / saídas" :value="`${metrics.entries_count ?? 0} / ${metrics.exits_count ?? 0}`" />
              <MetricRow label="Taxa de acerto" :value="formatPercent(metrics.win_rate_percentage)" />
              <MetricRow label="Maior drawdown" :value="formatPercent(metrics.max_drawdown_percentage)" />
              <MetricRow label="Exposição" :value="formatPercent(metrics.exposure_percentage)" />
            </dl>
          </div>

          <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-semibold text-slate-900">Comparativo buy-and-hold</h2>
            <p class="mt-1 text-sm text-slate-600">Mesmo par, período, capital inicial, taxa e slippage declarados.</p>
            <dl class="mt-4 divide-y divide-slate-100 text-sm">
              <MetricRow label="Patrimônio final" :value="formatNumber(metrics.buy_and_hold?.final_equity)" />
              <MetricRow label="Retorno líquido" :value="formatNumber(metrics.buy_and_hold?.net_return)" />
              <MetricRow label="Retorno percentual" :value="formatPercent(metrics.buy_and_hold?.return_percentage)" />
              <MetricRow label="Posição ao final" :value="metrics.buy_and_hold?.open_position_at_end ? 'Aberta e marcada a mercado' : 'Encerrada'" />
            </dl>
          </div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <div class="flex items-center justify-between gap-4">
            <div><h2 class="text-lg font-semibold text-slate-900">Operações simuladas</h2><p class="mt-1 text-sm text-slate-600">Registros históricos deste resultado; não são ordens de exchange nem transações fiscais.</p></div>
            <Link :href="route('trading-bot.backtests.index')" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">Voltar à lista</Link>
          </div>
          <div v-if="backtest.trades?.length" class="mt-5 overflow-x-auto rounded-lg border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
              <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600"><tr><th class="px-4 py-3">Evento</th><th class="px-4 py-3">Sinal</th><th class="px-4 py-3">Fill</th><th class="px-4 py-3 text-right">Preço</th><th class="px-4 py-3 text-right">Quantidade</th><th class="px-4 py-3 text-right">Taxa</th><th class="px-4 py-3 text-right">P&L realizado</th></tr></thead>
              <tbody class="divide-y divide-slate-100"><tr v-for="trade in backtest.trades" :key="trade.id"><td class="px-4 py-3 font-semibold text-slate-900">{{ trade.event_type === 'entry' ? 'Entrada' : 'Saída' }}</td><td class="px-4 py-3 text-slate-700">{{ formatDateTime(trade.signal_candle_open_time) }}</td><td class="px-4 py-3 text-slate-700">{{ formatDateTime(trade.fill_candle_open_time) }}</td><td class="px-4 py-3 text-right text-slate-700">{{ formatNumber(trade.fill_price) }}</td><td class="px-4 py-3 text-right text-slate-700">{{ formatNumber(trade.quantity) }}</td><td class="px-4 py-3 text-right text-slate-700">{{ formatNumber(trade.fee_amount) }}</td><td class="px-4 py-3 text-right"><span class="inline-flex min-w-24 justify-end rounded-md px-2.5 py-1 text-sm font-bold tabular-nums" :class="pnlTone(trade.realized_pnl)">{{ formatNumber(trade.realized_pnl) }}</span></td></tr></tbody>
            </table>
          </div>
          <p v-else class="mt-5 rounded-lg border border-dashed border-slate-300 px-5 py-8 text-center text-sm text-slate-600">Nenhuma operação simulada foi criada para este resultado.</p>
        </section>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ backtest: { type: Object, required: true }, executionEnabled: { type: Boolean, default: false } })
const metrics = props.backtest.metrics || {}
const formatNumber = (value) => value === null || value === undefined ? '—' : new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 6 }).format(Number(value))
const formatCurrency = (value) => value === null || value === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'USD' }).format(Number(value))
const formatSignedCurrency = (value) => value === null || value === undefined ? '—' : `${Number(value) >= 0 ? '+' : ''}${formatCurrency(value)}`
const compactNumber = (value) => new Intl.NumberFormat('pt-BR', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value))
const formatPercent = (value) => value === null || value === undefined ? '—' : `${formatNumber(value)}%`
const formatDateTime = (value) => value ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'UTC' }).format(new Date(value)) : '—'
const formatPeriod = (start, end) => `${formatDateTime(start)} a ${formatDateTime(end)}`
const numberTone = (value) => Number(value || 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'
const pnlTone = (value) => {
  if (value === null || value === undefined) return 'text-slate-500 dark:text-slate-300'
  if (Number(value) > 0) return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-300'
  if (Number(value) < 0) return 'bg-rose-100 text-rose-800 dark:bg-rose-400/15 dark:text-rose-300'
  return 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200'
}
const statusLabel = (status) => ({ completed: 'Concluído', invalid_data: 'Dados insuficientes', failed: 'Falhou' }[status] || status)
const statusClass = (status) => ({ completed: 'bg-emerald-100 text-emerald-800', invalid_data: 'bg-amber-100 text-amber-900', failed: 'bg-rose-100 text-rose-800' }[status] || 'bg-slate-100 text-slate-700')

const chart = computed(() => {
  const points = Array.isArray(metrics.equity_curve) ? metrics.equity_curve : []
  if (points.length === 0) return { points: [], strategyLine: '', buyAndHoldLine: '', markers: [], yTicks: [] }

  const values = points.flatMap((point) => [Number(point.strategy_equity), Number(point.buy_and_hold_equity)]).filter(Number.isFinite)
  let minimum = Math.min(...values)
  let maximum = Math.max(...values)
  const padding = maximum === minimum ? Math.max(Math.abs(maximum) * 0.02, 1) : (maximum - minimum) * 0.08
  minimum -= padding
  maximum += padding
  const width = 910
  const height = 300
  const x = (index) => 70 + (points.length === 1 ? width / 2 : (index / (points.length - 1)) * width)
  const y = (value) => 20 + ((maximum - Number(value)) / (maximum - minimum)) * height
  const line = (key) => points.map((point, index) => `${x(index).toFixed(2)},${y(point[key]).toFixed(2)}`).join(' ')

  return {
    points,
    strategyLine: line('strategy_equity'),
    buyAndHoldLine: line('buy_and_hold_equity'),
    markers: points.map((point, index) => ({ ...point, index, x: x(index), y: y(point.strategy_equity), value: point.strategy_equity })).filter((point) => ['entry', 'exit'].includes(point.event)),
    yTicks: Array.from({ length: 5 }, (_, index) => ({ value: maximum - ((maximum - minimum) * index / 4), y: 20 + (height * index / 4) })),
    firstTimestamp: points[0].timestamp,
    lastTimestamp: points[points.length - 1].timestamp,
  }
})

const MetricCard = { props: ['label', 'value', 'tone'], template: '<div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"><p class="text-sm text-slate-600">{{ label }}</p><p class="mt-2 text-xl font-bold" :class="tone || \'text-slate-900\'">{{ value }}</p></div>' }
const InfoRow = { props: ['label', 'value', 'mono'], template: '<div><dt class="text-slate-500">{{ label }}</dt><dd class="mt-1 break-all font-medium text-slate-900" :class="mono ? \'font-mono text-xs\' : \'\'">{{ value }}</dd></div>' }
const MetricRow = { props: ['label', 'value'], template: '<div class="flex items-center justify-between gap-5 py-3"><dt class="text-slate-600">{{ label }}</dt><dd class="text-right font-semibold text-slate-900">{{ value }}</dd></div>' }
</script>
