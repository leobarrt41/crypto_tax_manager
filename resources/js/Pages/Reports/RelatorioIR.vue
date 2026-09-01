<template>
  <AppLayout title="Relatórios IR">
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        <!-- ── Cabeçalho ──────────────────────────────────────────────────── -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <div>
                <h2 class="text-2xl font-bold text-gray-900">Relatórios IR — Ganhos de Capital</h2>
                <p class="text-gray-600 mt-1">
                  Apuração pelo método FIFO para fins de ganhos de capital e IR. A obrigação declaratória é consultada separadamente.
                </p>
              </div>
              <Link
                :href="route('reports.index')"
                class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors"
              >
                ← Voltar
              </Link>
            </div>
          </div>
        </div>

        <!-- ── Filtros + Ações ────────────────────────────────────────────── -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
          <div class="flex flex-wrap items-end gap-4">

            <!-- Ano -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ano *</label>
              <select
                v-model="filters.year"
                class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Selecione o ano</option>
                <option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option>
              </select>
            </div>

            <!-- Mês (opcional) -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mês (opcional)</label>
              <select
                v-model="filters.month"
                class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Todos os meses</option>
                <option v-for="(nome, idx) in meses" :key="idx + 1" :value="idx + 1">{{ nome }}</option>
              </select>
            </div>

            <!-- Botão Buscar -->
            <button
              @click="loadSummary"
              :disabled="!filters.year || loadingData"
              class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white rounded-lg font-medium transition-colors"
            >
              <span v-if="loadingData">Carregando...</span>
              <span v-else>Buscar</span>
            </button>

            <!-- Botão Recalcular FIFO -->
            <button
              @click="recalcularFifo"
              :disabled="loadingRecalc"
              class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 disabled:bg-gray-400 text-white rounded-lg font-medium transition-colors"
            >
              <span v-if="loadingRecalc">
                <svg class="w-4 h-4 inline mr-1 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                Recalculando...
              </span>
              <span v-else>⟳ Recalcular FIFO</span>
            </button>

            <!-- Botão Exportar CSV -->
            <button
              @click="exportarCsv"
              :disabled="!filters.year || summaries.length === 0 || acquisitionHistory.status === 'incomplete'"
              class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white rounded-lg font-medium transition-colors"
            >
              {{ acquisitionHistory.status === 'incomplete' ? 'Exportação bloqueada: FIFO incompleto' : '↓ Exportar CSV' }}
            </button>
          </div>

          <!-- Mensagem de feedback -->
          <div v-if="feedback.message" class="mt-4 p-3 rounded-lg text-sm font-medium"
               :class="feedback.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">
            {{ feedback.message }}
          </div>
        </div>

        <!-- ── Histórico de aquisição ─────────────────────────────────────── -->
        <section v-if="filters.year" class="mb-6 rounded-lg border p-6 shadow"
                 :class="acquisitionHistory.status === 'complete' ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50'">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h3 class="text-lg font-semibold text-gray-900">Histórico de aquisição</h3>
              <p v-if="loadingAcquisitionHistory" class="mt-1 text-sm text-gray-600">Verificando as compras e os lotes anteriores...</p>
              <p v-else-if="acquisitionHistory.status === 'complete'" class="mt-1 text-sm text-green-800">
                Histórico completo para as saídas registradas em {{ filters.year }}. O relatório fiscal pode ser exportado.
              </p>
              <p v-else class="mt-1 text-sm text-amber-900">
                <strong>FIFO incompleto.</strong> Encontramos uma venda sem compras anteriores suficientes para identificar o custo de compra. Importe seus arquivos CSV anteriores para completar o histórico.
              </p>
            </div>
            <span class="inline-flex w-fit items-center rounded-full px-3 py-1 text-sm font-medium"
                  :class="acquisitionHistory.status === 'complete' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900'">
              {{ acquisitionHistory.status === 'complete' ? 'Histórico completo' : `${acquisitionHistory.open_gaps_count} pendência(s)` }}
            </span>
          </div>

          <template v-if="!loadingAcquisitionHistory && acquisitionHistory.status !== 'complete'">
            <div class="mt-4 overflow-x-auto rounded-lg border border-amber-200 bg-white">
              <table class="min-w-full divide-y divide-amber-100">
                <thead class="bg-amber-50">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-amber-900">Ativo</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-amber-900">Data</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-amber-900">Quantidade faltante</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-amber-900">Transação relacionada</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-amber-900">Situação</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-amber-100">
                  <tr v-for="gap in acquisitionHistory.gaps" :key="gap.id">
                    <td class="px-4 py-3 text-sm font-semibold text-gray-900">{{ gap.asset }}</td>
                    <td class="px-4 py-3 text-sm text-gray-700">{{ formatDate(gap.occurred_at) }}</td>
                    <td class="px-4 py-3 text-right text-sm text-gray-700">{{ formatQuantity(gap.missing_quantity) }}</td>
                    <td class="px-4 py-3 text-sm text-gray-700">#{{ gap.transaction?.id }} · {{ gap.transaction?.type || 'Saída' }}</td>
                    <td class="px-4 py-3 text-sm font-medium text-amber-800">Operações anteriores ausentes</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
              <Link :href="route('transactions.import')" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Importar CSVs anteriores</Link>
              <button type="button" disabled class="cursor-not-allowed rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-500" title="A busca complementar com prévia será disponibilizada em etapa posterior.">Buscar movimentações na Binance</button>
              <a href="#correcao-manual" class="rounded-lg border border-amber-400 bg-white px-4 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100">Correção manual</a>
            </div>
          </template>
        </section>

        <!-- ── Correção manual de histórico ────────────────────────────────── -->
        <div id="correcao-manual" class="bg-white rounded-lg shadow p-6 mb-6">
          <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between mb-5">
            <div>
              <h3 class="text-lg font-semibold text-gray-800">Correção manual de histórico não reconstruído</h3>
              <p class="text-sm text-gray-600 mt-1">
                Use esta correção somente quando a aquisição não estiver nas transações importadas. O saldo e o custo histórico em <strong>31/12 do ano anterior</strong> serão usados como primeiros lotes no recálculo do ano selecionado.
              </p>
            </div>
            <span v-if="filters.year" class="inline-flex w-fit items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700">
              Ano fiscal {{ filters.year }} · referência 31/12/{{ Number(filters.year) - 1 }}
            </span>
          </div>

          <div v-if="!filters.year" class="rounded-lg border border-dashed border-gray-300 p-5 text-sm text-gray-500">
            Selecione um ano acima para cadastrar ou consultar o estoque inicial.
          </div>

          <template v-else>
            <form class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4" @submit.prevent="salvarSaldoInicial">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Ativo *</label>
                <input v-model.trim="openingBalanceForm.asset" maxlength="20" placeholder="Ex.: BTC" required
                       class="w-full rounded-md border-gray-300 uppercase focus:border-blue-500 focus:ring-blue-500" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quantidade em 31/12 *</label>
                <input v-model="openingBalanceForm.quantity" type="number" min="0" step="0.000000000001" required
                       class="w-full rounded-md border-gray-300 focus:border-blue-500 focus:ring-blue-500" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Custo histórico total (R$) *</label>
                <input v-model="openingBalanceForm.total_cost_brl" type="number" min="0" step="0.0000000001" required
                       class="w-full rounded-md border-gray-300 focus:border-blue-500 focus:ring-blue-500" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Origem documental</label>
                <input v-model.trim="openingBalanceForm.source" maxlength="100" placeholder="Ex.: CSV Binance 2023"
                       class="w-full rounded-md border-gray-300 focus:border-blue-500 focus:ring-blue-500" />
              </div>
              <div class="md:col-span-2 lg:col-span-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
                <input v-model.trim="openingBalanceForm.notes" maxlength="2000" placeholder="Ex.: Custo consolidado de aquisições anteriores"
                       class="w-full rounded-md border-gray-300 focus:border-blue-500 focus:ring-blue-500" />
              </div>
              <label class="md:col-span-2 lg:col-span-3 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                <input v-model="confirmManualCorrection" type="checkbox" class="mt-1 rounded border-amber-400 text-amber-600 focus:ring-amber-500" />
                <span>Entendo que esta é uma correção excepcional. Só confirmarei se a aquisição não estiver nas transações importadas, pois um lote manual adicional pode duplicar o estoque FIFO.</span>
              </label>
              <div class="flex items-end">
                <button type="submit" :disabled="savingOpeningBalance"
                        class="w-full rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white transition-colors hover:bg-indigo-700 disabled:bg-gray-400">
                  <span v-if="savingOpeningBalance">Salvando...</span>
                  <span v-else>Salvar correção manual</span>
                </button>
              </div>
            </form>

            <div class="mt-6 overflow-x-auto rounded-lg border border-gray-200">
              <div v-if="loadingOpeningBalances" class="p-6 text-center text-sm text-gray-500">Carregando saldos iniciais...</div>
              <div v-else-if="openingBalances.length === 0" class="p-6 text-center text-sm text-gray-500">
                Nenhum saldo inicial cadastrado para {{ filters.year }}. Sem um lote de abertura, o FIFO usará apenas as transações importadas.
              </div>
              <table v-else class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Ativo</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Quantidade</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Custo total</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Custo médio</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Origem</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Ação</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                  <tr v-for="balance in openingBalances" :key="balance.id">
                    <td class="px-4 py-3 text-sm font-semibold text-gray-900">{{ balance.asset }}</td>
                    <td class="px-4 py-3 text-right text-sm text-gray-700">{{ formatQuantity(balance.quantity) }}</td>
                    <td class="px-4 py-3 text-right text-sm text-gray-700">{{ formatBRL(balance.total_cost_brl) }}</td>
                    <td class="px-4 py-3 text-right text-sm text-gray-700">{{ formatBRL(balance.unit_cost_brl) }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500">
                      <div>{{ balance.source || 'Não informada' }}</div>
                      <div v-if="balance.notes" class="mt-1 max-w-xs text-xs text-gray-400">{{ balance.notes }}</div>
                    </td>
                    <td class="px-4 py-3 text-right">
                      <button @click="removerSaldoInicial(balance)" class="text-sm font-medium text-red-600 hover:text-red-800">Remover</button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>

        <!-- ── Tabela de Resumo Mensal ─────────────────────────────────────── -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
          <div class="p-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800">
              Resumo Mensal
              <span v-if="filters.year" class="text-gray-500 font-normal text-base ml-2">
                — {{ filters.year }}{{ filters.month ? ' / ' + meses[filters.month - 1] : '' }}
              </span>
            </h3>
          </div>

          <!-- Estado vazio -->
          <div v-if="!filters.year" class="p-10 text-center text-gray-500">
            Selecione um ano e clique em <strong>Buscar</strong> para visualizar o resumo.
          </div>

          <div v-else-if="loadingData" class="p-10 text-center text-gray-500">
            Carregando dados...
          </div>

          <div v-else-if="summaries.length === 0" class="p-10 text-center text-gray-500">
            Nenhum dado encontrado para o período selecionado.
            <br/>
            <span class="text-sm">Execute o <strong>Recalcular FIFO</strong> para gerar os resumos.</span>
          </div>

          <div v-else>
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mês</th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Alienações (R$)</th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Lucro (R$)</th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Prejuízo (R$)</th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Resultado Líquido (R$)</th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Operações</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="row in summaries" :key="row.mes" class="hover:bg-gray-50">
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {{ row.nome_mes }} / {{ row.ano }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-700">
                    {{ formatBRL(row.total_alienacoes_brl) }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium"
                      :class="row.lucro_realizado_brl > 0 ? 'text-green-600' : 'text-gray-500'">
                    {{ formatBRL(row.lucro_realizado_brl) }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium"
                      :class="row.prejuizo_realizado_brl > 0 ? 'text-red-600' : 'text-gray-500'">
                    {{ formatBRL(row.prejuizo_realizado_brl) }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold"
                      :class="row.resultado_liquido_brl >= 0 ? 'text-green-700' : 'text-red-700'">
                    {{ formatBRL(row.resultado_liquido_brl) }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500">
                    {{ row.qtd_operacoes }}
                  </td>
                </tr>
              </tbody>

              <!-- Linha de totais -->
              <tfoot class="bg-gray-100 border-t-2 border-gray-300">
                <tr>
                  <td class="px-6 py-4 text-sm font-bold text-gray-900">TOTAL</td>
                  <td class="px-6 py-4 text-sm text-right font-bold text-gray-900">{{ formatBRL(totals.total_alienacoes_brl) }}</td>
                  <td class="px-6 py-4 text-sm text-right font-bold text-green-700">{{ formatBRL(totals.lucro_realizado_brl) }}</td>
                  <td class="px-6 py-4 text-sm text-right font-bold text-red-700">{{ formatBRL(totals.prejuizo_realizado_brl) }}</td>
                  <td class="px-6 py-4 text-sm text-right font-bold"
                      :class="totals.resultado_liquido_brl >= 0 ? 'text-green-700' : 'text-red-700'">
                    {{ formatBRL(totals.resultado_liquido_brl) }}
                  </td>
                  <td class="px-6 py-4 text-sm text-right font-bold text-gray-900">{{ totals.qtd_operacoes }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

// ── Props ────────────────────────────────────────────────────────────────────
const props = defineProps({
  availableYears: {
    type: Array,
    default: () => [],
  },
})

// ── Estado ───────────────────────────────────────────────────────────────────
const meses = [
  'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
]

const filters = reactive({
  year: props.availableYears[0] ?? '',
  month: '',
})

const summaries    = ref([])
const totals       = ref({ total_alienacoes_brl: 0, lucro_realizado_brl: 0, prejuizo_realizado_brl: 0, resultado_liquido_brl: 0, qtd_operacoes: 0 })
const loadingData  = ref(false)
const loadingRecalc = ref(false)
const feedback     = reactive({ message: '', type: 'success' })

const openingBalances = ref([])
const loadingOpeningBalances = ref(false)
const savingOpeningBalance = ref(false)
const loadingAcquisitionHistory = ref(false)
const acquisitionHistory = ref({
  status: 'complete',
  is_official_export_available: true,
  open_gaps_count: 0,
  gaps: [],
  coverage: [],
})
const confirmManualCorrection = ref(false)
const openingBalanceForm = reactive({
  asset: '',
  quantity: '',
  total_cost_brl: '',
  source: '',
  notes: '',
})

// ── Helpers ──────────────────────────────────────────────────────────────────
const formatBRL = (value) => {
  const normalized = Number(value)
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number.isFinite(normalized) ? normalized : 0)
}

const formatQuantity = (value) => {
  const normalized = Number(value)
  return Number.isFinite(normalized)
    ? new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 12 }).format(normalized)
    : '0'
}

const formatDate = (value) => {
  if (!value) return 'Data não informada'
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? 'Data não informada'
    : new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'America/Sao_Paulo' }).format(date)
}

const showFeedback = (message, type = 'success') => {
  feedback.message = message
  feedback.type    = type
  setTimeout(() => { feedback.message = '' }, 6000)
}

// ── Métodos ──────────────────────────────────────────────────────────────────

async function loadSummary() {
  if (!filters.year) return

  loadingData.value = true
  summaries.value   = []

  try {
    const params = new URLSearchParams({ year: filters.year })
    if (filters.month) params.append('month', filters.month)

    const res  = await fetch(`/reports/relatorio-ir/summary?${params}`, {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
    const data = await res.json()

    if (!res.ok) throw new Error(data.message ?? 'Erro ao carregar dados.')

    summaries.value = data.summaries ?? []
    totals.value    = data.totals    ?? {}
  } catch (err) {
    showFeedback(err.message, 'error')
  } finally {
    loadingData.value = false
  }
}

async function loadAcquisitionHistory() {
  if (!filters.year) {
    acquisitionHistory.value = { status: 'complete', is_official_export_available: true, open_gaps_count: 0, gaps: [], coverage: [] }
    return
  }

  loadingAcquisitionHistory.value = true
  try {
    const res = await fetch(`/reports/relatorio-ir/acquisition-history?year=${filters.year}`, {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message ?? 'Erro ao verificar o histórico de aquisição.')
    acquisitionHistory.value = data
  } catch (err) {
    acquisitionHistory.value = { status: 'incomplete', is_official_export_available: false, open_gaps_count: 0, gaps: [], coverage: [] }
    showFeedback(err.message, 'error')
  } finally {
    loadingAcquisitionHistory.value = false
  }
}

async function recalcularFifo() {
  loadingRecalc.value = true

  try {
    const res  = await fetch('/reports/relatorio-ir/recalculate', {
      method:  'POST',
      headers: {
        'Accept':           'application/json',
        'Content-Type':     'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN':     getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify(filters.year ? { fiscal_year: Number(filters.year) } : {}),
    })
    const data = await res.json()

    if (!res.ok || !data.success) throw new Error(data.message ?? 'Erro no recálculo.')

    showFeedback(
      `Recálculo concluído: ${data.stats.transactions_read} transações lidas, ${data.stats.saidas_processed} saídas processadas, ${data.stats.opening_lots_loaded ?? 0} lotes iniciais aplicados.`,
      'success'
    )

    // Recarrega a tabela se já havia um ano selecionado
    if (filters.year) {
      await Promise.all([loadSummary(), loadAcquisitionHistory()])
    }
  } catch (err) {
    showFeedback(err.message, 'error')
  } finally {
    loadingRecalc.value = false
  }
}

async function loadOpeningBalances() {
  if (!filters.year) {
    openingBalances.value = []
    return
  }

  loadingOpeningBalances.value = true
  try {
    const params = new URLSearchParams({ fiscal_year: filters.year })
    const res = await fetch(`/reports/relatorio-ir/opening-balances?${params}`, {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
    const data = await res.json()

    if (!res.ok) throw new Error(data.message ?? 'Erro ao carregar saldos iniciais.')
    openingBalances.value = data.balances ?? []
  } catch (err) {
    openingBalances.value = []
    showFeedback(err.message, 'error')
  } finally {
    loadingOpeningBalances.value = false
  }
}

async function salvarSaldoInicial() {
  if (!filters.year) return

  savingOpeningBalance.value = true
  try {
    const res = await fetch('/reports/relatorio-ir/opening-balances', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        fiscal_year: Number(filters.year),
        asset: openingBalanceForm.asset,
        quantity: openingBalanceForm.quantity,
        total_cost_brl: openingBalanceForm.total_cost_brl,
        source: openingBalanceForm.source || null,
        notes: openingBalanceForm.notes || null,
        confirm_manual_correction: confirmManualCorrection.value,
      }),
    })
    const data = await res.json()

    if (!res.ok || !data.success) throw new Error(data.message ?? 'Erro ao salvar saldo inicial.')

    Object.assign(openingBalanceForm, { asset: '', quantity: '', total_cost_brl: '', source: '', notes: '' })
    confirmManualCorrection.value = false
    await loadOpeningBalances()
    showFeedback(data.message, 'success')
  } catch (err) {
    showFeedback(err.message, 'error')
  } finally {
    savingOpeningBalance.value = false
  }
}

async function removerSaldoInicial(balance) {
  if (!window.confirm(`Remover o saldo inicial de ${balance.asset}?`)) return

  try {
    const res = await fetch(`/reports/relatorio-ir/opening-balances/${balance.id}`, {
      method: 'DELETE',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
    })
    const data = await res.json()

    if (!res.ok || !data.success) throw new Error(data.message ?? 'Erro ao remover saldo inicial.')

    await loadOpeningBalances()
    showFeedback(data.message, 'success')
  } catch (err) {
    showFeedback(err.message, 'error')
  }
}

watch(() => filters.year, async () => {
  await Promise.all([loadOpeningBalances(), loadAcquisitionHistory()])
})

if (filters.year) {
  loadOpeningBalances()
  loadAcquisitionHistory()
}

async function exportarCsv() {
  if (!filters.year || acquisitionHistory.value.status === 'incomplete') return

  const params = new URLSearchParams({ year: filters.year })
  if (filters.month) params.append('month', filters.month)

  try {
    const res = await fetch(`/reports/relatorio-ir/export-csv?${params}`, {
      headers: { 'Accept': 'text/csv, application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
    if (!res.ok) {
      const data = await res.json()
      throw new Error(data.message ?? 'A exportação fiscal não está disponível.')
    }

    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `relatorio_ir_${filters.year}${filters.month ? `_${filters.month}` : ''}.csv`
    link.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    showFeedback(err.message, 'error')
  }
}

function getCsrfToken() {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}
</script>
