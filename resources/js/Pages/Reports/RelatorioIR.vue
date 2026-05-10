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
                  Apuração mensal pelo método FIFO para fins fiscais (IN 1888 / GCAP)
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
              :disabled="!filters.year || summaries.length === 0"
              class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white rounded-lg font-medium transition-colors"
            >
              ↓ Exportar CSV
            </button>
          </div>

          <!-- Mensagem de feedback -->
          <div v-if="feedback.message" class="mt-4 p-3 rounded-lg text-sm font-medium"
               :class="feedback.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">
            {{ feedback.message }}
          </div>
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
import { ref, reactive } from 'vue'
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

// ── Helpers ──────────────────────────────────────────────────────────────────
const formatBRL = (value) =>
  new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0)

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
    })
    const data = await res.json()

    if (!res.ok || !data.success) throw new Error(data.message ?? 'Erro no recálculo.')

    showFeedback(
      `Recálculo concluído: ${data.stats.transactions_read} transações lidas, ${data.stats.saidas_processed} saídas processadas.`,
      'success'
    )

    // Recarrega a tabela se já havia um ano selecionado
    if (filters.year) await loadSummary()
  } catch (err) {
    showFeedback(err.message, 'error')
  } finally {
    loadingRecalc.value = false
  }
}

function exportarCsv() {
  if (!filters.year) return

  const params = new URLSearchParams({ year: filters.year })
  if (filters.month) params.append('month', filters.month)

  window.location.href = `/reports/relatorio-ir/export-csv?${params}`
}

function getCsrfToken() {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}
</script>
