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
              <tbody class="divide-y divide-slate-100"><tr v-for="trade in backtest.trades" :key="trade.id"><td class="px-4 py-3 font-semibold text-slate-900">{{ trade.event_type === 'entry' ? 'Entrada' : 'Saída' }}</td><td class="px-4 py-3 text-slate-700">{{ formatDateTime(trade.signal_candle_open_time) }}</td><td class="px-4 py-3 text-slate-700">{{ formatDateTime(trade.fill_candle_open_time) }}</td><td class="px-4 py-3 text-right text-slate-700">{{ formatNumber(trade.fill_price) }}</td><td class="px-4 py-3 text-right text-slate-700">{{ formatNumber(trade.quantity) }}</td><td class="px-4 py-3 text-right text-slate-700">{{ formatNumber(trade.fee_amount) }}</td><td class="px-4 py-3 text-right" :class="numberTone(trade.realized_pnl)">{{ formatNumber(trade.realized_pnl) }}</td></tr></tbody>
            </table>
          </div>
          <p v-else class="mt-5 rounded-lg border border-dashed border-slate-300 px-5 py-8 text-center text-sm text-slate-600">Nenhuma operação simulada foi criada para este resultado.</p>
        </section>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ backtest: { type: Object, required: true }, executionEnabled: { type: Boolean, default: false } })
const metrics = props.backtest.metrics || {}
const formatNumber = (value) => value === null || value === undefined ? '—' : new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 6 }).format(Number(value))
const formatPercent = (value) => value === null || value === undefined ? '—' : `${formatNumber(value)}%`
const formatDateTime = (value) => value ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'UTC' }).format(new Date(value)) : '—'
const formatPeriod = (start, end) => `${formatDateTime(start)} a ${formatDateTime(end)}`
const numberTone = (value) => Number(value || 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'
const statusLabel = (status) => ({ completed: 'Concluído', invalid_data: 'Dados insuficientes', failed: 'Falhou' }[status] || status)
const statusClass = (status) => ({ completed: 'bg-emerald-100 text-emerald-800', invalid_data: 'bg-amber-100 text-amber-900', failed: 'bg-rose-100 text-rose-800' }[status] || 'bg-slate-100 text-slate-700')

const MetricCard = { props: ['label', 'value', 'tone'], template: '<div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"><p class="text-sm text-slate-600">{{ label }}</p><p class="mt-2 text-xl font-bold" :class="tone || \'text-slate-900\'">{{ value }}</p></div>' }
const InfoRow = { props: ['label', 'value', 'mono'], template: '<div><dt class="text-slate-500">{{ label }}</dt><dd class="mt-1 break-all font-medium text-slate-900" :class="mono ? \'font-mono text-xs\' : \'\'">{{ value }}</dd></div>' }
const MetricRow = { props: ['label', 'value'], template: '<div class="flex items-center justify-between gap-5 py-3"><dt class="text-slate-600">{{ label }}</dt><dd class="text-right font-semibold text-slate-900">{{ value }}</dd></div>' }
</script>
