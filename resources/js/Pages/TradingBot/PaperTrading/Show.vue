<template>
  <AppLayout title="Sessão de paper trading">
    <div class="py-8">
      <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="rounded-xl border border-violet-200 bg-violet-50 p-5 text-violet-950">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold">{{ session.strategy?.name || 'Sessão simulada' }}</h1>
                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusClass(session.status)">{{ statusLabel(session.status) }}</span>
              </div>
              <p class="mt-1 text-sm leading-6">Paper trading manual · v{{ session.strategy_version?.version || session.strategy_version_number }} · {{ session.exchange?.name || 'Exchange' }} · {{ session.symbol }} · {{ session.timeframe }}.</p>
              <p class="mt-1 text-sm font-semibold">Nenhuma credencial, conta, saldo ou ordem de exchange é usada nesta sessão.</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button v-if="session.status === 'active'" type="button" :disabled="processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60" @click="runCycle">{{ processing ? 'Processando…' : 'Processar ciclo manual' }}</button>
              <button v-if="session.status === 'active'" type="button" :disabled="processing" class="rounded-lg border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50 disabled:opacity-60" @click="pause">Pausar</button>
              <button v-if="session.status === 'paused'" type="button" :disabled="processing" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60" @click="resume">Retomar</button>
              <button v-if="session.status !== 'archived'" type="button" :disabled="processing" class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-60" @click="archive">Arquivar</button>
              <Link :href="route('trading-bot.paper-trading.index')" class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:text-slate-950">Voltar</Link>
            </div>
          </div>
        </section>

        <p v-if="$page.props.flash?.success" class="rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900">{{ $page.props.flash.success }}</p>
        <p v-if="$page.props.errors?.paper_trading" class="rounded-lg bg-rose-50 p-4 text-sm text-rose-800">{{ $page.props.errors.paper_trading }}</p>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Capital inicial fictício</p><p class="mt-2 text-xl font-bold text-slate-900">{{ formatNumber(session.initial_capital) }} USDT</p></div>
          <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Caixa simulado</p><p class="mt-2 text-xl font-bold text-slate-900">{{ formatNumber(session.cash_balance) }} USDT</p></div>
          <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Posição fictícia</p><p class="mt-2 text-xl font-bold text-slate-900">{{ formatNumber(session.position_quantity) }} {{ baseAsset }}</p></div>
          <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">P&L realizado simulado</p><p class="mt-2 text-xl font-bold" :class="numberClass(session.realized_pnl)">{{ formatNumber(session.realized_pnl) }} USDT</p></div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-xl font-semibold text-slate-900">Premissas congeladas</h2>
          <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-slate-500">Alocação por entrada</dt><dd class="mt-1 font-semibold text-slate-900">{{ formatNumber(session.allocation_pct) }}%</dd></div>
            <div><dt class="text-slate-500">Taxa simulada</dt><dd class="mt-1 font-semibold text-slate-900">{{ formatNumber(session.fee_rate) }}%</dd></div>
            <div><dt class="text-slate-500">Slippage simulado</dt><dd class="mt-1 font-semibold text-slate-900">{{ formatNumber(session.slippage_rate) }}%</dd></div>
            <div><dt class="text-slate-500">Taxas acumuladas</dt><dd class="mt-1 font-semibold text-slate-900">{{ formatNumber(session.total_fees) }} USDT</dd></div>
          </dl>
          <p class="mt-5 text-xs text-slate-500">Hash da estratégia: <code class="break-all">{{ session.strategy_definition_hash }}</code></p>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-xl font-semibold text-slate-900">Ciclos manuais</h2>
          <p class="mt-1 text-sm text-slate-600">Cada registro mostra exatamente quais candles fechados foram avaliados e qual foi a decisão simulada.</p>
          <div v-if="session.cycles?.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 px-5 py-8 text-center text-sm text-slate-600">Ainda não há ciclos processados.</div>
          <div v-else class="mt-5 overflow-x-auto rounded-lg border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
              <thead class="bg-slate-50"><tr><th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Ciclo</th><th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Candles</th><th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Decisão</th><th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Estado</th><th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Avisos</th></tr></thead>
              <tbody class="divide-y divide-slate-100"><tr v-for="cycle in session.cycles" :key="cycle.id"><td class="px-4 py-3">#{{ cycle.sequence }}<p class="mt-1 text-xs text-slate-500">{{ formatDateTime(cycle.finished_at) }}</p></td><td class="px-4 py-3">{{ cycle.candles_processed }}</td><td class="px-4 py-3 font-semibold text-slate-800">{{ decisionLabel(cycle.decision) }}</td><td class="px-4 py-3">{{ cycleStatusLabel(cycle.status) }}</td><td class="px-4 py-3 text-xs text-slate-600">{{ cycle.warnings?.join(' ') || '—' }}</td></tr></tbody>
            </table>
          </div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-xl font-semibold text-slate-900">Operações exclusivamente simuladas</h2>
          <p class="mt-1 text-sm text-slate-600">Estes registros não são ordens, não alteram carteiras e não têm efeito fiscal.</p>
          <div v-if="session.trades?.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 px-5 py-8 text-center text-sm text-slate-600">Nenhum fill fictício foi criado.</div>
          <div v-else class="mt-5 overflow-x-auto rounded-lg border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Evento</th><th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Sinal / fill</th><th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Preço</th><th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Quantidade</th><th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">P&L realizado</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="trade in session.trades" :key="trade.id"><td class="px-4 py-3 font-semibold text-slate-800">{{ trade.event_type === 'entry' ? 'Entrada simulada' : 'Saída simulada' }}</td><td class="px-4 py-3 text-xs text-slate-600">{{ formatDateTime(trade.signal_candle_open_time) }}<br>{{ formatDateTime(trade.fill_candle_open_time) }}</td><td class="px-4 py-3 text-right">{{ formatNumber(trade.fill_price) }}</td><td class="px-4 py-3 text-right">{{ formatNumber(trade.quantity) }}</td><td class="px-4 py-3 text-right" :class="numberClass(trade.realized_pnl)">{{ trade.realized_pnl === null ? '—' : formatNumber(trade.realized_pnl) }}</td></tr></tbody></table>
          </div>
        </section>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  session: { type: Object, required: true },
  executionEnabled: { type: Boolean, default: false },
  mode: { type: String, default: 'manual_paper_trading_only' },
})
const processing = ref(false)
const baseAsset = computed(() => props.session.symbol?.replace('USDT', '') || 'Ativo')
const formatNumber = (value) => value === null || value === undefined ? '—' : new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 8 }).format(Number(value))
const formatDateTime = (value) => value ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'UTC' }).format(new Date(value)) + ' UTC' : '—'
const numberClass = (value) => Number(value || 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'
const statusLabel = (status) => ({ active: 'Ativa', paused: 'Pausada', archived: 'Arquivada' }[status] || status)
const statusClass = (status) => ({ active: 'bg-emerald-100 text-emerald-800', paused: 'bg-amber-100 text-amber-900', archived: 'bg-slate-200 text-slate-700' }[status] || 'bg-slate-100 text-slate-700')
const decisionLabel = (decision) => ({ entry: 'Entrada', exit: 'Saída', hold: 'Manter posição' }[decision] || '—')
const cycleStatusLabel = (status) => ({ completed: 'Concluído', insufficient_data: 'Dados insuficientes', invalid_data: 'Dados inválidos', paused: 'Não processado' }[status] || status)
const visit = (name, confirmMessage = null) => {
  if (confirmMessage && !window.confirm(confirmMessage)) return
  processing.value = true
  router.post(route(name, props.session.id), {}, { preserveScroll: true, onFinish: () => { processing.value = false } })
}
const runCycle = () => visit('trading-bot.paper-trading.run')
const pause = () => visit('trading-bot.paper-trading.pause', 'Pausar esta sessão simulada? Nenhuma posição real será alterada.')
const resume = () => visit('trading-bot.paper-trading.resume')
const archive = () => visit('trading-bot.paper-trading.archive', 'Arquivar esta sessão? O histórico simulado será preservado para auditoria e não poderá ser retomado.')
</script>
