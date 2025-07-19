<template>
  <AppLayout title="Relatório Fiscal">
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <div>
                <h2 class="text-2xl font-bold text-gray-900">Relatório Fiscal</h2>
                <p class="text-gray-600 mt-1">Gere relatórios detalhados para declaração de imposto de renda</p>
              </div>
              <div class="flex space-x-3">
                <Link :href="route('reports.index')" 
                      class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                  <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                  </svg>
                  Voltar
                </Link>
                <button
                  @click="generateReport"
                  :disabled="loading || !form.year"
                  class="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white px-4 py-2 rounded-lg font-medium transition-colors"
                >
                  <svg v-if="loading" class="w-5 h-5 inline mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <svg v-else class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                  </svg>
                  {{ loading ? 'Gerando...' : 'Gerar Relatório' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Formulário de Configuração -->
          <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow p-6">
              <h3 class="text-lg font-medium text-gray-900 mb-4">Configurações do Relatório</h3>
              
              <form @submit.prevent="generateReport" class="space-y-4">
                <!-- Ano -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Ano de Referência *</label>
                  <select
                    v-model="form.year"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">Selecione o ano</option>
                    <option v-for="year in availableYears" :key="year" :value="year">{{ year }}</option>
                  </select>
                </div>

                <!-- Tipo de Relatório -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Relatório</label>
                  <div class="space-y-2">
                    <label class="flex items-center">
                      <input
                        v-model="form.report_type"
                        type="radio"
                        value="complete"
                        class="mr-2 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm">Relatório Completo</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.report_type"
                        type="radio"
                        value="summary"
                        class="mr-2 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm">Resumo Executivo</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.report_type"
                        type="radio"
                        value="monthly"
                        class="mr-2 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm">Relatório Mensal</span>
                    </label>
                  </div>
                </div>

                <!-- Método de Cálculo -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Método de Cálculo</label>
                  <select
                    v-model="form.calculation_method"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="fifo">FIFO (Primeiro a Entrar, Primeiro a Sair)</option>
                    <option value="lifo">LIFO (Último a Entrar, Primeiro a Sair)</option>
                    <option value="average">Preço Médio</option>
                  </select>
                </div>

                <!-- Incluir Seções -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Incluir Seções</label>
                  <div class="space-y-2">
                    <label class="flex items-center">
                      <input
                        v-model="form.include_transactions"
                        type="checkbox"
                        class="mr-2 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm">Detalhes das Transações</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.include_gains_losses"
                        type="checkbox"
                        class="mr-2 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm">Ganhos e Perdas</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.include_portfolio"
                        type="checkbox"
                        class="mr-2 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm">Posição do Portfolio</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.include_charts"
                        type="checkbox"
                        class="mr-2 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm">Gráficos e Análises</span>
                    </label>
                  </div>
                </div>

                <!-- Formato de Saída -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Formato de Saída</label>
                  <div class="space-y-2">
                    <label class="flex items-center">
                      <input
                        v-model="form.output_formats"
                        type="checkbox"
                        value="pdf"
                        class="mr-2 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm">PDF</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.output_formats"
                        type="checkbox"
                        value="excel"
                        class="mr-2 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm">Excel</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.output_formats"
                        type="checkbox"
                        value="csv"
                        class="mr-2 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm">CSV</span>
                    </label>
                  </div>
                </div>

                <!-- Observações -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
                  <textarea
                    v-model="form.notes"
                    rows="3"
                    placeholder="Adicione observações ou instruções especiais..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  ></textarea>
                </div>
              </form>
            </div>

            <!-- Informações Importantes -->
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mt-6">
              <div class="flex">
                <svg class="w-5 h-5 text-yellow-400 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <div>
                  <h4 class="text-sm font-medium text-yellow-800">Informações Importantes</h4>
                  <ul class="text-sm text-yellow-700 mt-1 space-y-1">
                    <li>• O relatório será gerado com base nas transações registradas</li>
                    <li>• Verifique se todas as transações do período estão importadas</li>
                    <li>• O cálculo segue as normas da Receita Federal</li>
                    <li>• Mantenha backup dos relatórios gerados</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <!-- Prévia e Resultados -->
          <div class="lg:col-span-2">
            <!-- Estatísticas do Período -->
            <div v-if="form.year" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
              <StatCard
                title="Total de Transações"
                :value="periodStats.total_transactions"
                icon="document-text"
                color="blue"
              />
              <StatCard
                title="Volume Negociado"
                :value="formatCurrency(periodStats.total_volume)"
                icon="currency-dollar"
                color="green"
              />
              <StatCard
                title="Ganhos/Perdas"
                :value="formatCurrency(periodStats.net_gains)"
                :change="periodStats.net_gains >= 0 ? 'positive' : 'negative'"
                icon="trending-up"
                color="purple"
              />
              <StatCard
                title="Impostos Devidos"
                :value="formatCurrency(periodStats.taxes_owed)"
                icon="shield-check"
                color="yellow"
              />
            </div>

            <!-- Prévia do Relatório -->
            <div class="bg-white rounded-lg shadow">
              <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Prévia do Relatório</h3>
              </div>

              <div v-if="!form.year" class="p-8 text-center">
                <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <h4 class="text-lg font-medium text-gray-900 mb-2">Selecione um ano</h4>
                <p class="text-gray-500">Escolha o ano de referência para visualizar a prévia do relatório.</p>
              </div>

              <div v-else-if="loading" class="p-8 text-center">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div>
                <p class="text-gray-500 mt-2">Gerando relatório...</p>
              </div>

              <div v-else class="p-6">
                <!-- Resumo Executivo -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Resumo Executivo - {{ form.year }}</h4>
                  <div class="bg-gray-50 rounded-lg p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                      <div>
                        <span class="font-medium">Período:</span> 01/01/{{ form.year }} a 31/12/{{ form.year }}
                      </div>
                      <div>
                        <span class="font-medium">Método:</span> {{ getMethodLabel(form.calculation_method) }}
                      </div>
                      <div>
                        <span class="font-medium">Total de Operações:</span> {{ periodStats.total_transactions }}
                      </div>
                      <div>
                        <span class="font-medium">Ativos Negociados:</span> {{ periodStats.unique_assets }}
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Resumo Financeiro -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Resumo Financeiro</h4>
                  <div class="space-y-3">
                    <div class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Volume Total Negociado:</span>
                      <span class="font-medium">{{ formatCurrency(periodStats.total_volume) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Ganhos Realizados:</span>
                      <span class="font-medium text-green-600">{{ formatCurrency(periodStats.realized_gains) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Perdas Realizadas:</span>
                      <span class="font-medium text-red-600">{{ formatCurrency(periodStats.realized_losses) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Resultado Líquido:</span>
                      <span :class="periodStats.net_gains >= 0 ? 'text-green-600' : 'text-red-600'" class="font-medium">
                        {{ formatCurrency(periodStats.net_gains) }}
                      </span>
                    </div>
                    <div class="flex justify-between items-center py-2">
                      <span class="text-gray-600">Impostos Devidos:</span>
                      <span class="font-medium text-blue-600">{{ formatCurrency(periodStats.taxes_owed) }}</span>
                    </div>
                  </div>
                </div>

                <!-- Principais Ativos -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Principais Ativos Negociados</h4>
                  <div class="space-y-2">
                    <div v-for="asset in periodStats.top_assets" :key="asset.symbol" 
                         class="flex justify-between items-center py-2 px-3 bg-gray-50 rounded">
                      <span class="font-medium">{{ asset.symbol }}</span>
                      <span class="text-sm text-gray-600">{{ formatCurrency(asset.volume) }}</span>
                    </div>
                  </div>
                </div>

                <!-- Ações -->
                <div class="flex space-x-3 pt-4 border-t border-gray-200">
                  <button
                    @click="previewReport"
                    class="flex-1 bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors"
                  >
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    Visualizar Prévia
                  </button>
                  <button
                    @click="generateReport"
                    :disabled="loading"
                    class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white px-4 py-2 rounded-lg font-medium transition-colors"
                  >
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Gerar e Download
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'

const props = defineProps({
  availableYears: Array,
  periodStats: Object
})

const loading = ref(false)
const form = ref({
  year: '',
  report_type: 'complete',
  calculation_method: 'fifo',
  include_transactions: true,
  include_gains_losses: true,
  include_portfolio: true,
  include_charts: false,
  output_formats: ['pdf'],
  notes: ''
})

const periodStats = ref({
  total_transactions: 0,
  total_volume: 0,
  net_gains: 0,
  taxes_owed: 0,
  unique_assets: 0,
  realized_gains: 0,
  realized_losses: 0,
  top_assets: []
})

const formatCurrency = (value) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(value)
}

const getMethodLabel = (method) => {
  const labels = {
    fifo: 'FIFO (Primeiro a Entrar, Primeiro a Sair)',
    lifo: 'LIFO (Último a Entrar, Primeiro a Sair)',
    average: 'Preço Médio'
  }
  return labels[method] || method
}

const generateReport = async () => {
  if (!form.value.year) {
    alert('Por favor, selecione um ano de referência.')
    return
  }

  loading.value = true
  try {
    const response = await router.post(route('reports.generate-tax'), form.value, {
      preserveState: true,
      onSuccess: (page) => {
        // Redirect to download or show success message
      }
    })
  } catch (error) {
    console.error('Erro ao gerar relatório:', error)
  } finally {
    loading.value = false
  }
}

const previewReport = () => {
  window.open(route('reports.preview-tax', { year: form.value.year }), '_blank')
}

// Watch for year changes to load period stats
watch(() => form.value.year, async (newYear) => {
  if (newYear) {
    try {
      const response = await fetch(route('reports.period-stats', { year: newYear }))
      const data = await response.json()
      periodStats.value = data
    } catch (error) {
      console.error('Erro ao carregar estatísticas:', error)
    }
  }
})
</script>

