<template>
  <AppLayout :title="strategy.name">
    <div class="py-8">
      <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <Link :href="route('trading-bot.strategies.index')" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">← Estratégias</Link>
            <h1 class="mt-2 text-3xl font-bold text-slate-900">{{ strategy.name }}</h1>
            <p class="mt-2 text-slate-600">{{ strategy.description || 'Sem descrição.' }}</p>
          </div>
          <div class="flex gap-3">
            <Link :href="route('trading-bot.strategies.edit', strategy.id)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Criar nova versão</Link>
            <button type="button" class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50" @click="archive">Arquivar</button>
          </div>
        </div>

        <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-950">
          <h2 class="font-semibold">Versão reutilizável da estratégia</h2>
          <p class="mt-1 text-sm">Esta versão poderá ser selecionada em diferentes backtests e, futuramente, em operações paper. Nesta fase, não há uso de exchange ou chave de API, monitoramento contínuo, bot ativo ou envio de ordens.</p>
          <p class="mt-2 text-xs">Operações paper estarão disponíveis em uma fase futura.</p>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-lg font-semibold text-slate-900">Definição atual</h2>
              <p class="mt-1 text-sm text-slate-600">Versão {{ strategy.current_version?.version || '—' }} · hash {{ shortHash(strategy.current_version?.definition_hash) }}</p>
            </div>
            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ statusLabel(strategy.current_version?.status) }}</span>
          </div>

          <div v-if="strategy.current_version" class="mt-5 space-y-3">
            <p class="text-sm text-slate-700"><strong>Lógica:</strong> {{ strategy.current_version.definition.logic === 'all' ? 'Todas as condições devem ser atendidas.' : 'Qualquer condição pode ser atendida.' }}</p>
            <ul class="space-y-2">
              <li v-for="(condition, index) in strategy.current_version.definition.entry_conditions" :key="`entry-${index}`" class="rounded-md bg-slate-50 p-3 text-sm text-slate-800">
                <strong>Entrada {{ index + 1 }}:</strong> {{ condition.indicator.toUpperCase() }} {{ condition.operator.replaceAll('_', ' ') }}<span v-if="condition.value !== undefined"> {{ condition.value }}</span>
              </li>
              <li v-for="(condition, index) in strategy.current_version.definition.exit_conditions" :key="`exit-${index}`" class="rounded-md bg-slate-50 p-3 text-sm text-slate-800">
                <strong>Saída {{ index + 1 }}:</strong> {{ condition.indicator.toUpperCase() }} {{ condition.operator.replaceAll('_', ' ') }}<span v-if="condition.value !== undefined"> {{ condition.value }}</span>
              </li>
            </ul>
            <div v-if="Object.keys(strategy.current_version.definition.risk || {}).length" class="rounded-md border border-slate-200 p-3 text-sm text-slate-700">
              <strong>Risco cadastrado:</strong>
              <span v-for="(value, key) in strategy.current_version.definition.risk" :key="key" class="mr-3">{{ key }}: {{ value }}%</span>
            </div>
          </div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-lg font-semibold text-slate-900">Histórico de versões</h2>
          <div class="mt-4 divide-y divide-slate-100">
            <div v-for="version in strategy.versions" :key="version.id" class="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="font-semibold text-slate-900">Versão {{ version.version }} <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ statusLabel(version.status) }}</span></p>
                <p class="mt-1 text-xs text-slate-500">Hash {{ version.definition_hash }} · criada em {{ formatDate(version.created_at) }} por {{ version.creator?.name || 'usuário' }}</p>
              </div>
              <span v-if="version.id === strategy.current_version_id" class="text-xs font-semibold text-indigo-700">Versão atual</span>
            </div>
          </div>
        </section>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ strategy: { type: Object, required: true }, executionEnabled: Boolean })
const shortHash = (hash) => hash ? `${hash.slice(0, 12)}…` : '—'
const formatDate = (value) => value ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'
const statusLabel = (status) => ({ draft: 'Rascunho validado', validated: 'Validada', archived: 'Arquivada' }[status] || '—')
const archive = () => {
  if (!window.confirm('Arquivar esta estratégia? As versões serão preservadas e nenhuma ordem será cancelada ou enviada.')) return
  router.post(route('trading-bot.strategies.archive', props.strategy.id))
}
</script>
