<template>
  <AppLayout title="Paper trading manual">
    <div class="py-8">
      <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="rounded-xl border border-violet-200 bg-violet-50 p-5 text-violet-950">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h1 class="text-2xl font-bold">Paper trading manual</h1>
              <p class="mt-1 max-w-3xl text-sm leading-6">Acompanhe uma carteira exclusivamente simulada. Cada ciclo só ocorre quando você clicar para processar candles públicos já fechados.</p>
            </div>
            <span class="inline-flex w-fit rounded-full bg-violet-200 px-3 py-1 text-xs font-semibold text-violet-950">Simulação — nenhuma ordem será enviada</span>
          </div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 class="text-xl font-semibold text-slate-900">Minhas sessões simuladas</h2>
              <p class="mt-1 text-sm text-slate-600">As estratégias e configurações permanecem congeladas. Para mudar uma premissa, crie outra sessão.</p>
            </div>
            <Link :href="route('trading-bot.paper-trading.create')" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">Nova sessão simulada</Link>
          </div>

          <div v-if="sessions.length === 0" class="mt-8 rounded-lg border border-dashed border-slate-300 px-6 py-12 text-center">
            <h3 class="text-base font-semibold text-slate-900">Nenhuma sessão de paper trading</h3>
            <p class="mt-2 text-sm text-slate-600">Crie uma sessão para acompanhar os sinais de uma versão de estratégia sem usar saldo, credencial ou ordem de exchange.</p>
          </div>

          <div v-else class="mt-6 overflow-x-auto rounded-lg border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200">
              <thead class="bg-slate-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Estratégia e mercado</th>
                  <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Caixa simulado</th>
                  <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Posição</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Estado</th>
                  <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Ação</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 bg-white">
                <tr v-for="session in sessions" :key="session.id">
                  <td class="px-4 py-4">
                    <p class="font-semibold text-slate-900">{{ session.strategy?.name || 'Estratégia removida' }}</p>
                    <p class="mt-1 text-sm text-slate-600">v{{ session.strategy_version?.version || session.strategy_version_number }} · {{ session.exchange?.name || 'Exchange' }} · {{ session.symbol }} · {{ session.timeframe }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ lastCycleLabel(session) }}</p>
                  </td>
                  <td class="px-4 py-4 text-right text-sm font-semibold text-slate-800">{{ formatNumber(session.cash_balance) }} USDT</td>
                  <td class="px-4 py-4 text-right text-sm text-slate-700">{{ formatNumber(session.position_quantity) }}</td>
                  <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusClass(session.status)">{{ statusLabel(session.status) }}</span></td>
                  <td class="px-4 py-4 text-right"><Link :href="route('trading-bot.paper-trading.show', session.id)" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">Abrir sessão</Link></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
  sessions: { type: Array, default: () => [] },
  executionEnabled: { type: Boolean, default: false },
  mode: { type: String, default: 'manual_paper_trading_only' },
})

const formatNumber = (value) => value === null || value === undefined
  ? '—'
  : new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 8 }).format(Number(value))
const statusLabel = (status) => ({ active: 'Ativa', paused: 'Pausada', archived: 'Arquivada' }[status] || status)
const statusClass = (status) => ({
  active: 'bg-emerald-100 text-emerald-800',
  paused: 'bg-amber-100 text-amber-900',
  archived: 'bg-slate-200 text-slate-700',
}[status] || 'bg-slate-100 text-slate-700')
const lastCycleLabel = (session) => {
  const cycle = session.cycles?.[0]
  if (!cycle) return 'Ainda não processada manualmente'
  return `${cycle.candles_processed} candle(s) no último ciclo · ${cycle.decision || 'sem decisão'}`
}
</script>
