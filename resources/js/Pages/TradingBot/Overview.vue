<template>
  <AppLayout title="Trading Bot">
    <div class="py-8">
      <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-6 text-indigo-950">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl">
              <p class="text-sm font-semibold uppercase tracking-wide text-indigo-700">Visão Geral</p>
              <h1 class="mt-1 text-3xl font-bold">Trading Bot</h1>
              <p class="mt-3 text-sm leading-6 text-indigo-900">
                Estratégias são regras reutilizáveis e versionadas. Elas poderão ser usadas em backtests e operações paper nas próximas fases. Nesta fase, nenhuma operação é iniciada e nenhuma ordem é criada ou enviada.
              </p>
            </div>
            <span class="inline-flex w-fit rounded-full bg-indigo-200 px-3 py-1 text-xs font-semibold text-indigo-900">
              Fase 1 — somente estratégias
            </span>
          </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <article class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p class="text-sm font-medium text-slate-600">Estratégias cadastradas</p>
            <p class="mt-2 text-3xl font-bold text-slate-900">{{ summary.strategies_count }}</p>
            <p class="mt-2 text-sm text-slate-500">Regras reutilizáveis disponíveis para evolução futura.</p>
          </article>

          <article class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p class="text-sm font-medium text-slate-600">Versões registradas</p>
            <p class="mt-2 text-3xl font-bold text-slate-900">{{ summary.versions_count }}</p>
            <p class="mt-2 text-sm text-slate-500">O histórico permanece preservado a cada alteração.</p>
          </article>

          <article class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p class="text-sm font-medium text-slate-600">Última atualização</p>
            <p class="mt-2 text-lg font-bold text-slate-900">{{ formatDate(summary.last_updated_at) }}</p>
            <p class="mt-2 text-sm text-slate-500">Baseado somente no cadastro local de estratégias.</p>
          </article>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 class="text-xl font-semibold text-slate-900">Próximo passo seguro</h2>
              <p class="mt-1 text-sm leading-6 text-slate-600">
                Defina regras de entrada, saída e risco no editor. A estratégia salva não aciona mercado, saldo ou execução automática.
              </p>
            </div>
            <div class="flex flex-col gap-3 sm:flex-row">
              <Link
                :href="route('trading-bot.strategies.index')"
                class="inline-flex items-center justify-center rounded-lg border border-indigo-200 px-4 py-2.5 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-50"
              >
                Ver estratégias
              </Link>
              <Link
                :href="route('trading-bot.strategies.create')"
                class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700"
              >
                Criar estratégia
              </Link>
            </div>
          </div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-xl font-semibold text-slate-900">Estado dos módulos</h2>
          <p class="mt-1 text-sm text-slate-600">A disponibilidade abaixo reflete o estágio real de desenvolvimento do produto.</p>

          <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article v-for="module in modules" :key="module.name" class="rounded-lg border border-slate-200 p-4">
              <div class="flex items-center justify-between gap-3">
                <h3 class="font-semibold text-slate-900">{{ module.name }}</h3>
                <span :class="module.badgeClass" class="rounded-full px-2.5 py-1 text-xs font-semibold">{{ module.status }}</span>
              </div>
              <p class="mt-3 text-sm leading-6 text-slate-600">{{ module.description }}</p>
            </article>
          </div>
        </section>

        <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-950">
          <h2 class="text-base font-semibold">Proteções ativas nesta fase</h2>
          <p class="mt-2 text-sm leading-6">
            Nenhuma chave de API é usada nesta página. Não existe consulta privada à exchange, coleta de candles, monitoramento contínuo, operação paper, job em fila ou execução real. A execução de ordens permanece bloqueada.
          </p>
        </section>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
  summary: {
    type: Object,
    default: () => ({
      strategies_count: 0,
      versions_count: 0,
      last_updated_at: null,
    }),
  },
  executionEnabled: { type: Boolean, default: false },
})

const modules = [
  {
    name: 'Estratégias',
    status: 'Disponível',
    description: 'Cadastro, validação e versionamento de regras reutilizáveis.',
    badgeClass: 'bg-emerald-100 text-emerald-800',
  },
  {
    name: 'Backtesting',
    status: 'Em breve',
    description: 'Avaliação histórica será incorporada em fase posterior.',
    badgeClass: 'bg-slate-100 text-slate-700',
  },
  {
    name: 'Operações paper',
    status: 'Em breve',
    description: 'Simulação de posições e saldos ainda não está ativa.',
    badgeClass: 'bg-slate-100 text-slate-700',
  },
  {
    name: 'Execução real',
    status: 'Bloqueada',
    description: 'Nenhuma ordem é criada, enviada ou monitorada neste estágio.',
    badgeClass: 'bg-rose-100 text-rose-800',
  },
]

const formatDate = (value) => value
  ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : 'Nenhuma estratégia atualizada'
</script>
