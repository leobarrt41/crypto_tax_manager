<template>
  <AppLayout title="Relatórios">
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <div>
                <h2 class="text-2xl font-bold text-gray-900">Relatórios</h2>
                <p class="text-gray-600 mt-1">Gere relatórios fiscais e de performance</p>
              </div>
              <div class="flex space-x-3">
                <Link :href="route('reports.relatorio-ir')"
                      class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                  <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                  </svg>
                  Novo Relatório
                </Link>
                <Link :href="route('reports.in1888')" 
                      class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                  <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                  </svg>
                  Declarações de Criptoativos
                </Link>
              </div>
            </div>
          </div>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
          <StatCard
            title="Relatórios Gerados"
            :value="stats.total_reports"
            icon="document-text"
            color="blue"
          />
          <StatCard
            title="Último Período"
            :value="stats.last_period"
            icon="calendar"
            color="green"
          />
          <StatCard
            title="Total Impostos"
            :value="formatCurrency(stats.total_taxes)"
            icon="currency-dollar"
            color="yellow"
          />
          <StatCard
            title="Status Compliance"
            :value="stats.compliance_status"
            icon="shield-check"
            color="purple"
          />
        </div>

        <!-- Filtros -->
        <div class="bg-white rounded-lg shadow mb-6 p-6">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Buscar</label>
              <input
                v-model="filters.search"
                type="text"
                placeholder="Buscar relatórios..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Tipo</label>
              <select
                v-model="filters.type"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Todos os tipos</option>
                <option value="tax">Relatório Fiscal</option>
                <option value="in1888">Declarações de Criptoativos</option>
                <option value="portfolio">Portfolio</option>
                <option value="trading">Trading</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Período</label>
              <select
                v-model="filters.period"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Todos os períodos</option>
                <option value="2024">2024</option>
                <option value="2023">2023</option>
                <option value="2022">2022</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
              <select
                v-model="filters.status"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Todos os status</option>
                <option value="generated">Gerado</option>
                <option value="sent">Enviado</option>
                <option value="pending">Pendente</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Lista de Relatórios -->
        <div class="bg-white rounded-lg shadow">
          <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Relatórios Recentes</h3>
          </div>
          
          <div v-if="loading" class="p-8 text-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div>
            <p class="text-gray-500 mt-2">Carregando relatórios...</p>
          </div>

          <div v-else-if="reports.data.length === 0" class="p-8 text-center">
            <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Nenhum relatório encontrado</h3>
            <p class="text-gray-500 mb-4">Comece gerando seu primeiro relatório fiscal.</p>
            <Link :href="route('reports.relatorio-ir')"
                  class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
              Gerar Primeiro Relatório
            </Link>
          </div>

          <div v-else class="divide-y divide-gray-200">
            <div v-for="report in reports.data" :key="report.id" class="p-6 hover:bg-gray-50 transition-colors">
              <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                  <div class="flex-shrink-0">
                    <div :class="getTypeIcon(report.type).class" class="w-10 h-10 rounded-lg flex items-center justify-center">
                      <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getTypeIcon(report.type).path"/>
                      </svg>
                    </div>
                  </div>
                  <div>
                    <h4 class="text-lg font-medium text-gray-900">{{ report.title }}</h4>
                    <div class="flex items-center space-x-4 text-sm text-gray-500 mt-1">
                      <span>{{ getTypeLabel(report.type) }}</span>
                      <span>•</span>
                      <span>{{ report.period }}</span>
                      <span>•</span>
                      <span>{{ formatDate(report.created_at) }}</span>
                    </div>
                  </div>
                </div>
                <div class="flex items-center space-x-3">
                  <span :class="getStatusClass(report.status)" class="px-2 py-1 text-xs font-medium rounded-full">
                    {{ getStatusLabel(report.status) }}
                  </span>
                  <div class="flex space-x-2">
                    <button
                      @click="downloadReport(report)"
                      class="text-blue-600 hover:text-blue-800 p-2 rounded-lg hover:bg-blue-50 transition-colors"
                      title="Download"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                      </svg>
                    </button>
                    <Link
                      :href="route('reports.show', report.id)"
                      class="text-gray-600 hover:text-gray-800 p-2 rounded-lg hover:bg-gray-50 transition-colors"
                      title="Visualizar"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                      </svg>
                    </Link>
                    <button
                      @click="deleteReport(report)"
                      class="text-red-600 hover:text-red-800 p-2 rounded-lg hover:bg-red-50 transition-colors"
                      title="Excluir"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Paginação -->
          <div v-if="reports.data.length > 0" class="px-6 py-4 border-t border-gray-200">
            <div class="flex items-center justify-between">
              <div class="text-sm text-gray-700">
                Mostrando {{ reports.from }} a {{ reports.to }} de {{ reports.total }} resultados
              </div>
              <div class="flex space-x-2">
                <Link
                  v-if="reports.prev_page_url"
                  :href="reports.prev_page_url"
                  class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                >
                  Anterior
                </Link>
                <Link
                  v-if="reports.next_page_url"
                  :href="reports.next_page_url"
                  class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                >
                  Próximo
                </Link>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'

const props = defineProps({
  availableReports: {
    type: Object,
    default: () => ({})
  },
  reports: {
    type: Object,
    default: () => ({
      data: [],
      from: 0,
      to: 0,
      total: 0,
      prev_page_url: null,
      next_page_url: null
    })
  },
  stats: {
    type: Object,
    default: () => ({
      total_reports: 0,
      last_period: '—',
      total_taxes: 0,
      compliance_status: 'Não avaliado'
    })
  },
  filters: {
    type: Object,
    default: () => ({})
  }
})

const loading = ref(false)
const filters = ref({
  search: '',
  type: '',
  period: '',
  status: ''
})

const formatCurrency = (value) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(value)
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('pt-BR')
}

const getTypeIcon = (type) => {
  const icons = {
    tax: {
      class: 'bg-blue-500',
      path: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'
    },
    in1888: {
      class: 'bg-green-500',
      path: 'M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'
    },
    portfolio: {
      class: 'bg-purple-500',
      path: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'
    },
    trading: {
      class: 'bg-yellow-500',
      path: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'
    }
  }
  return icons[type] || icons.tax
}

const getTypeLabel = (type) => {
  const labels = {
    tax: 'Relatório Fiscal',
    in1888: 'IN 1888',
    portfolio: 'Portfolio',
    trading: 'Trading'
  }
  return labels[type] || 'Relatório'
}

const getStatusClass = (status) => {
  const classes = {
    generated: 'bg-blue-100 text-blue-800',
    sent: 'bg-green-100 text-green-800',
    pending: 'bg-yellow-100 text-yellow-800'
  }
  return classes[status] || 'bg-gray-100 text-gray-800'
}

const getStatusLabel = (status) => {
  const labels = {
    generated: 'Gerado',
    sent: 'Enviado',
    pending: 'Pendente'
  }
  return labels[status] || 'Desconhecido'
}

const downloadReport = (report) => {
  window.open(route('reports.download', report.id), '_blank')
}

const deleteReport = (report) => {
  if (confirm('Tem certeza que deseja excluir este relatório?')) {
    router.delete(route('reports.destroy', report.id))
  }
}
</script>
