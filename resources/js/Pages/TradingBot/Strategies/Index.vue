<template>
  <AppLayout title="Estratégias de Trading">
    <div class="py-8">
      <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-950">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h1 class="text-2xl font-bold">Estratégias para backtesting</h1>
              <p class="mt-1 text-sm leading-6">
                Nesta fase, as estratégias apenas descrevem regras reutilizáveis. Nenhuma ordem é criada, nenhum worker é iniciado e nenhuma chamada privada é feita à exchange.
              </p>
            </div>
            <span class="inline-flex w-fit rounded-full bg-amber-200 px-3 py-1 text-xs font-semibold text-amber-900">
              Execução real bloqueada
            </span>
          </div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 class="text-xl font-semibold text-slate-900">Minhas estratégias</h2>
              <p class="mt-1 text-sm text-slate-600">Cada edição cria uma versão imutável para auditoria e backtesting futuro.</p>
            </div>
            <Link
              :href="route('trading-bot.strategies.create')"
              class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700"
            >
              Nova estratégia
            </Link>
          </div>

          <div v-if="strategies.length === 0" class="mt-8 rounded-lg border border-dashed border-slate-300 px-6 py-12 text-center">
            <h3 class="text-base font-semibold text-slate-900">Nenhuma estratégia cadastrada</h3>
            <p class="mt-2 text-sm text-slate-600">Crie uma regra com indicadores para utilizá-la no backtesting da próxima fase.</p>
          </div>

          <div v-else class="mt-6 overflow-hidden rounded-lg border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200">
              <thead class="bg-slate-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Estratégia</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Versão</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Estado</th>
                  <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Ação</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 bg-white">
                <tr v-for="strategy in strategies" :key="strategy.id">
                  <td class="px-4 py-4">
                    <p class="font-semibold text-slate-900">{{ strategy.name }}</p>
                    <p class="mt-1 max-w-xl text-sm text-slate-600">{{ strategy.description || 'Sem descrição.' }}</p>
                  </td>
                  <td class="px-4 py-4 text-sm text-slate-700">
                    v{{ strategy.current_version?.version || '—' }}
                    <span class="block text-xs text-slate-500">{{ strategy.versions_count }} versão(ões)</span>
                  </td>
                  <td class="px-4 py-4">
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                      {{ statusLabel(strategy.current_version?.status) }}
                    </span>
                    <span class="mt-1 block text-xs text-slate-500">Somente para backtesting</span>
                  </td>
                  <td class="px-4 py-4 text-right">
                    <Link :href="route('trading-bot.strategies.show', strategy.id)" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">
                      Ver detalhes
                    </Link>
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
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
  strategies: { type: Array, default: () => [] },
  executionEnabled: { type: Boolean, default: false },
})

const statusLabel = (status) => ({
  draft: 'Rascunho validado',
  validated: 'Validada',
  archived: 'Arquivada',
}[status] || 'Sem versão')
</script>
