<template>
  <AppLayout title="Geração IN 1888">
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <div>
                <h2 class="text-2xl font-bold text-gray-900">Geração IN 1888</h2>
                <p class="text-gray-600 mt-1">Gere arquivos de movimentação mensal para a Receita Federal</p>
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
                  @click="generateIN1888"
                  :disabled="loading || !form.year || !form.month"
                  class="bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white px-4 py-2 rounded-lg font-medium transition-colors"
                >
                  <svg v-if="loading" class="w-5 h-5 inline mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <svg v-else class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                  </svg>
                  {{ loading ? 'Gerando...' : 'Gerar IN 1888' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Formulário de Configuração -->
          <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow p-6">
              <h3 class="text-lg font-medium text-gray-900 mb-4">Configurações da IN 1888</h3>
              
              <form @submit.prevent="generateIN1888" class="space-y-4">
                <!-- Período -->
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Mês *</label>
                    <select
                      v-model="form.month"
                      required
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                    >
                      <option value="">Mês</option>
                      <option v-for="(month, index) in months" :key="index" :value="index + 1">{{ month }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Ano *</label>
                    <select
                      v-model="form.year"
                      required
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                    >
                      <option value="">Ano</option>
                      <option v-for="year in availableYears" :key="year" :value="year">{{ year }}</option>
                    </select>
                  </div>
                </div>

                <!-- Dados do Declarante -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">CPF do Declarante *</label>
                  <input
                    v-model="form.declarant_cpf"
                    type="text"
                    required
                    placeholder="000.000.000-00"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                  />
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Nome Completo *</label>
                  <input
                    v-model="form.declarant_name"
                    type="text"
                    required
                    placeholder="Nome completo do declarante"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                  />
                </div>

                <!-- Tipo de Arquivo -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Arquivo</label>
                  <div class="space-y-2">
                    <label class="flex items-center">
                      <input
                        v-model="form.file_type"
                        type="radio"
                        value="original"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Original</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.file_type"
                        type="radio"
                        value="retificadora"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Retificadora</span>
                    </label>
                  </div>
                </div>

                <!-- Incluir Operações -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Incluir Operações</label>
                  <div class="space-y-2">
                    <label class="flex items-center">
                      <input
                        v-model="form.include_purchases"
                        type="checkbox"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Compras</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.include_sales"
                        type="checkbox"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Vendas</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.include_transfers"
                        type="checkbox"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Transferências</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="form.include_mining"
                        type="checkbox"
                        class="mr-2 text-green-600 focus:ring-green-500"
                      />
                      <span class="text-sm">Mineração</span>
                    </label>
                  </div>
                </div>

                <!-- Observações -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
                  <textarea
                    v-model="form.notes"
                    rows="3"
                    placeholder="Observações adicionais..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                  ></textarea>
                </div>
              </form>
            </div>

            <!-- Informações da IN 1888 -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mt-6">
              <div class="flex">
                <svg class="w-5 h-5 text-blue-400 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
                <div>
                  <h4 class="text-sm font-medium text-blue-800">Sobre a IN 1888</h4>
                  <ul class="text-sm text-blue-700 mt-1 space-y-1">
                    <li>• Obrigatória para operações acima de R$ 30.000/mês</li>
                    <li>• Prazo: até o 15º dia útil do mês seguinte</li>
                    <li>• Formato: arquivo TXT específico da RFB</li>
                    <li>• Multa por atraso: R$ 500 a R$ 1.500</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <!-- Prévia e Resultados -->
          <div class="lg:col-span-2">
            <!-- Estatísticas do Período -->
            <div v-if="form.year && form.month" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
              <StatCard
                title="Operações no Período"
                :value="monthStats.total_operations"
                icon="document-text"
                color="blue"
              />
              <StatCard
                title="Volume Total"
                :value="formatCurrency(monthStats.total_volume)"
                icon="currency-dollar"
                color="green"
              />
              <StatCard
                title="Obrigatoriedade"
                :value="monthStats.is_required ? 'Obrigatória' : 'Não Obrigatória'"
                :change="monthStats.is_required ? 'required' : 'optional'"
                icon="shield-check"
                color="purple"
              />
              <StatCard
                title="Status"
                :value="monthStats.status"
                icon="check-circle"
                color="yellow"
              />
            </div>

            <!-- Prévia do Arquivo -->
            <div class="bg-white rounded-lg shadow">
              <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Prévia do Arquivo IN 1888</h3>
              </div>

              <div v-if="!form.year || !form.month" class="p-8 text-center">
                <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <h4 class="text-lg font-medium text-gray-900 mb-2">Selecione o período</h4>
                <p class="text-gray-500">Escolha o mês e ano para visualizar a prévia do arquivo IN 1888.</p>
              </div>

              <div v-else-if="loading" class="p-8 text-center">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-green-600 mx-auto"></div>
                <p class="text-gray-500 mt-2">Gerando arquivo IN 1888...</p>
              </div>

              <div v-else class="p-6">
                <!-- Informações do Arquivo -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Informações do Arquivo</h4>
                  <div class="bg-gray-50 rounded-lg p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                      <div>
                        <span class="font-medium">Período:</span> {{ getMonthName(form.month) }}/{{ form.year }}
                      </div>
                      <div>
                        <span class="font-medium">Tipo:</span> {{ form.file_type === 'original' ? 'Original' : 'Retificadora' }}
                      </div>
                      <div>
                        <span class="font-medium">Declarante:</span> {{ form.declarant_name }}
                      </div>
                      <div>
                        <span class="font-medium">CPF:</span> {{ form.declarant_cpf }}
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Resumo das Operações -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Resumo das Operações</h4>
                  <div class="space-y-3">
                    <div v-if="form.include_purchases" class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Compras:</span>
                      <span class="font-medium">{{ monthStats.purchases_count }} operações - {{ formatCurrency(monthStats.purchases_volume) }}</span>
                    </div>
                    <div v-if="form.include_sales" class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Vendas:</span>
                      <span class="font-medium">{{ monthStats.sales_count }} operações - {{ formatCurrency(monthStats.sales_volume) }}</span>
                    </div>
                    <div v-if="form.include_transfers" class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Transferências:</span>
                      <span class="font-medium">{{ monthStats.transfers_count }} operações</span>
                    </div>
                    <div v-if="form.include_mining" class="flex justify-between items-center py-2 border-b border-gray-200">
                      <span class="text-gray-600">Mineração:</span>
                      <span class="font-medium">{{ monthStats.mining_count }} operações - {{ formatCurrency(monthStats.mining_volume) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 font-medium">
                      <span class="text-gray-900">Total:</span>
                      <span class="text-blue-600">{{ monthStats.total_operations }} operações - {{ formatCurrency(monthStats.total_volume) }}</span>
                    </div>
                  </div>
                </div>

                <!-- Principais Criptoativos -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Principais Criptoativos</h4>
                  <div class="space-y-2">
                    <div v-for="asset in monthStats.top_assets" :key="asset.symbol" 
                         class="flex justify-between items-center py-2 px-3 bg-gray-50 rounded">
                      <span class="font-medium">{{ asset.symbol }}</span>
                      <div class="text-right">
                        <div class="text-sm font-medium">{{ asset.operations }} operações</div>
                        <div class="text-xs text-gray-600">{{ formatCurrency(asset.volume) }}</div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Validações -->
                <div class="mb-6">
                  <h4 class="text-md font-medium text-gray-900 mb-3">Validações</h4>
                  <div class="space-y-2">
                    <div class="flex items-center">
                      <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                      </svg>
                      <span class="text-sm">Dados do declarante válidos</span>
                    </div>
                    <div class="flex items-center">
                      <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                      </svg>
                      <span class="text-sm">Período válido</span>
                    </div>
                    <div class="flex items-center">
                      <svg :class="monthStats.is_required ? 'text-yellow-500' : 'text-green-500'" class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                      </svg>
                      <span class="text-sm">
                        {{ monthStats.is_required ? 'Declaração obrigatória (volume > R$ 30.000)' : 'Declaração opcional (volume < R$ 30.000)' }}
                      </span>
                    </div>
                  </div>
                </div>

                <!-- Ações -->
                <div class="flex space-x-3 pt-4 border-t border-gray-200">
                  <button
                    @click="previewFile"
                    class="flex-1 bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors"
                  >
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    Visualizar Arquivo
                  </button>
                  <button
                    @click="generateIN1888"
                    :disabled="loading"
                    class="flex-1 bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white px-4 py-2 rounded-lg font-medium transition-colors"
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
  declarantInfo: Object
})

const loading = ref(false)
const months = [
  'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'
]

const form = ref({
  month: '',
  year: '',
  declarant_cpf: props.declarantInfo?.cpf || '',
  declarant_name: props.declarantInfo?.name || '',
  file_type: 'original',
  include_purchases: true,
  include_sales: true,
  include_transfers: true,
  include_mining: false,
  notes: ''
})

const monthStats = ref({
  total_operations: 0,
  total_volume: 0,
  is_required: false,
  status: 'Não Gerado',
  purchases_count: 0,
  purchases_volume: 0,
  sales_count: 0,
  sales_volume: 0,
  transfers_count: 0,
  mining_count: 0,
  mining_volume: 0,
  top_assets: []
})

const formatCurrency = (value) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(value)
}

const getMonthName = (monthNumber) => {
  return months[monthNumber - 1] || ''
}

const generateIN1888 = async () => {
  if (!form.value.year || !form.value.month) {
    alert('Por favor, selecione o período (mês e ano).')
    return
  }

  if (!form.value.declarant_cpf || !form.value.declarant_name) {
    alert('Por favor, preencha os dados do declarante.')
    return
  }

  loading.value = true
  try {
    const response = await router.post(route('reports.generate-in1888'), form.value, {
      preserveState: true,
      onSuccess: (page) => {
        // Redirect to download or show success message
      }
    })
  } catch (error) {
    console.error('Erro ao gerar IN 1888:', error)
  } finally {
    loading.value = false
  }
}

const previewFile = () => {
  window.open(route('reports.preview-in1888', { 
    year: form.value.year, 
    month: form.value.month 
  }), '_blank')
}

// Watch for period changes to load month stats
watch([() => form.value.year, () => form.value.month], async ([newYear, newMonth]) => {
  if (newYear && newMonth) {
    try {
      const response = await fetch(route('reports.month-stats', { 
        year: newYear, 
        month: newMonth 
      }))
      const data = await response.json()
      monthStats.value = data
    } catch (error) {
      console.error('Erro ao carregar estatísticas:', error)
    }
  }
})
</script>

