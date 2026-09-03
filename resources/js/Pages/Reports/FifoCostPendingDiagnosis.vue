<template>
  <AppLayout title="Diagnóstico de custos pendentes">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
      <header class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-300">Análise somente leitura</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950 dark:text-white">Diagnóstico de custos pendentes</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">
              Agrupa as pendências pela causa provável e mostra a próxima ação. Nenhum custo é gravado e nenhum valor ausente é tratado como zero.
            </p>
          </div>
          <Link :href="route('reports.relatorio-ir')" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">← Voltar</Link>
        </div>
      </header>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <div class="grid gap-4 md:grid-cols-5">
          <label class="text-sm font-medium text-slate-700 dark:text-slate-200">Ano
            <select v-model="filters.year" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-950">
              <option v-for="year in availableYears" :key="year" :value="year">{{ year }}</option>
            </select>
          </label>
          <label class="text-sm font-medium text-slate-700 dark:text-slate-200">Ativo
            <input v-model.trim="filters.asset" placeholder="Ex.: BTC" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-950" />
          </label>
          <label class="text-sm font-medium text-slate-700 dark:text-slate-200 md:col-span-2">Categoria
            <select v-model="filters.category" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-950">
              <option value="">Todas as categorias</option>
              <option v-for="category in categories" :key="category" :value="category">{{ categoryLabel(category) }}</option>
            </select>
          </label>
          <button :disabled="loading || !filters.year" @click="load" class="self-end rounded-lg bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
            {{ loading ? 'Analisando…' : 'Executar diagnóstico' }}
          </button>
        </div>
      </section>

      <div v-if="error" class="rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-700 dark:bg-rose-950/40 dark:text-rose-200">{{ error }}</div>

      <template v-if="result">
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-5 dark:border-indigo-700 dark:bg-indigo-950/30">
            <p class="text-sm text-indigo-700 dark:text-indigo-200">Total classificado</p>
            <p class="mt-1 text-3xl font-bold text-indigo-950 dark:text-white">{{ result.total }}</p>
          </div>
          <div v-for="(count, category) in result.counts_by_category" :key="category" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ categoryLabel(category) }}</p>
            <p class="mt-1 text-2xl font-bold text-slate-950 dark:text-white">{{ count }}</p>
          </div>
        </section>

        <section class="space-y-4">
          <article v-for="item in result.diagnoses" :key="item.gap_id" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-lg font-bold text-slate-950 dark:text-white">{{ item.asset }}</span>
                  <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">{{ categoryLabel(item.primary_category) }}</span>
                  <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">Confiança {{ item.confidence }}</span>
                </div>
                <p class="mt-2 text-sm leading-6 text-slate-700 dark:text-slate-200">{{ item.explanation_for_user }}</p>
              </div>
              <div class="text-right text-sm text-slate-500 dark:text-slate-400">
                <p>Gap #{{ item.gap_id }}</p>
                <p>{{ formatDate(item.occurred_at_utc) }}</p>
              </div>
            </div>
            <dl class="mt-4 grid gap-3 text-sm md:grid-cols-3">
              <div><dt class="text-slate-500 dark:text-slate-400">Quantidade com custo pendente</dt><dd class="font-mono font-semibold text-slate-900 dark:text-white">{{ item.pending_quantity }}</dd></div>
              <div><dt class="text-slate-500 dark:text-slate-400">Transação de origem</dt><dd class="font-semibold text-slate-900 dark:text-white">{{ item.source_transaction_id ? `#${item.source_transaction_id}` : 'Não localizada' }}</dd></div>
              <div><dt class="text-slate-500 dark:text-slate-400">Próxima ação</dt><dd class="font-semibold text-slate-900 dark:text-white">{{ item.recommended_action }}</dd></div>
            </dl>
            <details class="mt-4 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-950/60">
              <summary class="cursor-pointer font-semibold text-slate-700 dark:text-slate-200">Evidências utilizadas</summary>
              <pre class="mt-3 overflow-x-auto whitespace-pre-wrap text-xs text-slate-600 dark:text-slate-300">{{ JSON.stringify(item.evidence, null, 2) }}</pre>
            </details>
          </article>
        </section>

        <footer class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-900 dark:border-sky-800 dark:bg-sky-950/30 dark:text-sky-100">
          Cotação histórica estimada não equivale a custo documental. Esta análise não modifica o bloqueio do relatório fiscal e não substitui a avaliação de contador ou tributarista.
        </footer>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  availableYears: { type: Array, default: () => [] },
  classifierVersion: { type: String, required: true },
})

const filters = reactive({ year: props.availableYears[0] || new Date().getFullYear(), asset: '', category: '' })
const result = ref(null)
const loading = ref(false)
const error = ref('')
const categories = computed(() => Object.keys(result.value?.counts_by_category || {}))

const labels = {
  convert_missing_documented_received_value: 'Convert sem valor recebido documental',
  convert_documented_value_not_recognized: 'Valor documental não reconhecido',
  reward_or_distribution_missing_cost: 'Recompensa ou distribuição sem custo',
  external_deposit_missing_cost: 'Depósito externo sem custo',
  acquisition_missing_brl_value: 'Aquisição sem valor em BRL',
  pre_import_history_unknown: 'Histórico anterior à importação',
  possible_internal_transfer_unlinked: 'Possível transferência não conciliada',
  historical_quote_only_estimated: 'Somente cotação histórica estimada',
  unsupported_or_insufficient_evidence: 'Evidência insuficiente',
  unclassified: 'Não classificado',
}

const categoryLabel = category => labels[category] || category
const formatDate = value => value ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'UTC' }).format(new Date(value)) + ' UTC' : '—'

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await axios.get(route('reports.relatorio-ir.cost-pending-diagnosis.data'), { params: filters })
    result.value = data
  } catch (exception) {
    error.value = exception.response?.data?.message || 'Não foi possível executar o diagnóstico.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
