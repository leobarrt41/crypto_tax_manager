<template>
  <AppLayout title="Backtests históricos">
    <div class="py-8">
      <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="rounded-xl border border-sky-200 bg-sky-50 p-5 text-sky-950">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h1 class="text-2xl font-bold">Backtests históricos</h1>
              <p class="mt-1 text-sm leading-6">Compare uma versão imutável de estratégia com candles históricos. O resultado é uma simulação, não uma previsão de desempenho futuro.</p>
            </div>
            <span class="inline-flex w-fit rounded-full bg-sky-200 px-3 py-1 text-xs font-semibold text-sky-900">Nenhuma ordem será enviada</span>
          </div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 class="text-xl font-semibold text-slate-900">Meus resultados</h2>
              <p class="mt-1 text-sm text-slate-600">Cada resultado preserva a versão da estratégia, o conjunto de candles e as premissas utilizadas no cálculo.</p>
            </div>
            <Link :href="route('trading-bot.backtests.create')" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">Novo backtest</Link>
          </div>

          <div v-if="backtests.length === 0" class="mt-8 rounded-lg border border-dashed border-slate-300 px-6 py-12 text-center">
            <h3 class="text-base font-semibold text-slate-900">Nenhum backtest executado</h3>
            <p class="mt-2 text-sm text-slate-600">Selecione uma versão de estratégia e um período histórico para gerar uma simulação auditável.</p>
          </div>

          <div v-else class="mt-6 overflow-x-auto rounded-lg border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200">
              <thead class="bg-slate-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Estratégia e mercado</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Período</th>
                  <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Resultado líquido</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Estado</th>
                  <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Ação</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 bg-white">
                <tr v-for="backtest in backtests" :key="backtest.id">
                  <td class="px-4 py-4">
                    <p class="font-semibold text-slate-900">{{ backtest.strategy?.name || 'Estratégia removida' }}</p>
                    <p class="mt-1 text-sm text-slate-600">v{{ backtest.strategy_version?.version || backtest.strategy_version_number }} · {{ backtest.exchange?.name || 'Exchange' }} · {{ backtest.symbol }} · {{ backtest.timeframe }}</p>
                  </td>
                  <td class="px-4 py-4 text-sm text-slate-700">{{ formatPeriod(backtest.requested_start_at, backtest.requested_end_at) }}</td>
                  <td class="px-4 py-4 text-right text-sm font-semibold" :class="netReturnClass(backtest.metrics?.net_return)">{{ formatNumber(backtest.metrics?.net_return) }}</td>
                  <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusClass(backtest.status)">{{ statusLabel(backtest.status) }}</span></td>
                  <td class="px-4 py-4 text-right">
                    <div class="inline-flex items-center gap-3">
                      <Link :href="route('trading-bot.backtests.show', backtest.id)" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">Ver resultado</Link>
                      <button type="button" class="text-sm font-semibold text-rose-600 hover:text-rose-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="deletingId === backtest.id" @click="destroy(backtest)">
                        {{ deletingId === backtest.id ? 'Excluindo…' : 'Excluir' }}
                      </button>
                    </div>
                  </td>
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
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
  backtests: { type: Array, default: () => [] },
  executionEnabled: { type: Boolean, default: false },
})

const formatNumber = (value) => value === null || value === undefined
  ? '—'
  : new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 6 }).format(Number(value))

const formatDate = (value) => value
  ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(value))
  : '—'

const formatPeriod = (start, end) => `${formatDate(start)} a ${formatDate(end)}`
const netReturnClass = (value) => Number(value || 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'
const statusLabel = (status) => ({ completed: 'Concluído', invalid_data: 'Dados insuficientes', failed: 'Falhou' }[status] || status)
const statusClass = (status) => ({ completed: 'bg-emerald-100 text-emerald-800', invalid_data: 'bg-amber-100 text-amber-900', failed: 'bg-rose-100 text-rose-800' }[status] || 'bg-slate-100 text-slate-700')

const deletingId = ref(null)
const destroy = (backtest) => {
  const strategy = backtest.strategy?.name || 'esta estratégia'
  if (!window.confirm(`Excluir definitivamente este backtest de ${strategy}? As operações simuladas vinculadas também serão removidas.`)) return

  deletingId.value = backtest.id
  router.delete(route('trading-bot.backtests.destroy', backtest.id), {
    preserveScroll: true,
    onFinish: () => { deletingId.value = null },
  })
}
</script>
