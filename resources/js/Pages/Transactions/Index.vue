<template>
  <AppLayout title="Transações">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Transações
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Gerencie todas as suas transações de criptomoedas
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4">
            <Link
              href="/transactions/import"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
              </svg>
              Importar
            </Link>
            <Link
              href="/transactions/create"
              class="ml-3 inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
              </svg>
              Nova Transação
            </Link>
          </div>
        </div>
      </div>

      <!-- Seletor de moeda -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-2 flex justify-end">
          <div class="flex items-center space-x-2">
            <label for="currency" class="text-sm text-gray-700">Exibir valores em:</label>
            <select 
              id="currency"
              v-model="displayCurrency" 
              class="border border-gray-300 rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-primary focus:border-primary"
            >
              <option value="brl">BRL</option>
              <option value="usdt">USDT</option>
            </select>
          </div>
        </div>




      <!-- Stats Cards -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Total de Transações"
            :value="stats.total_transactions"
            format="number"
            icon="chart-bar"
            color="blue"
          />
                    <StatCard
          title="Volume Total"
          :value="displayCurrency === 'brl' ? stats.total_brl : stats.total_usdt"
          :format="displayCurrency === 'brl' ? 'currency' : 'decimal'"
          icon="currency-dollar"
          color="green"
        />

          <StatCard
            title="Este Mês"
            :value="stats.this_month"
            format="number"
            icon="calendar"
            color="purple"
          />
          <StatCard
            title="Lucro/Prejuízo"
            :value="stats.profit_loss"
            format="currency"
            icon="trending-up"
            :color="stats.profit_loss >= 0 ? 'green' : 'red'"
          />
        </div>
      </div>

      <!-- Filters -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg p-6">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            
            <!-- Search -->
            <div>
              <label for="search" class="block text-sm font-medium text-gray-700">Buscar</label>
              <input
                id="search"
                v-model="filters.search"
                type="text"
                placeholder="Buscar por ativo, hash..."
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @input="debouncedSearch"
              />
            </div>

            <!-- Type Filter -->
            <div>
              <label for="type" class="block text-sm font-medium text-gray-700">Tipo</label>
              <select
                id="type"
                v-model="filters.type"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @change="applyFilters"
              >
                <option value="">Todos os tipos</option>
                <option value="buy">Compra</option>
                <option value="sell">Venda</option>
                <option value="transfer">Transferência</option>
                <option value="mining">Mineração</option>
                <option value="staking">Staking</option>
                <option value="airdrop">Airdrop</option>
              </select>
            </div>

            <!-- Asset Filter -->
            <div>
              <label for="asset" class="block text-sm font-medium text-gray-700">Ativo</label>
              <select
                id="asset"
                v-model="filters.crypto_asset_id"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @change="applyFilters"
              >
                <option value="">Todos os ativos</option>
                <option v-for="asset in cryptoAssets" :key="asset.id" :value="asset.id">
                  {{ asset.symbol }} - {{ asset.name }}
                </option>
              </select>
            </div>

            <!-- Date Range -->
            <div>
              <label for="date_range" class="block text-sm font-medium text-gray-700">Período</label>
              <select
                id="date_range"
                v-model="filters.date_range"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @change="applyFilters"
              >
                <option value="">Todos os períodos</option>
                <option value="today">Hoje</option>
                <option value="week">Esta semana</option>
                <option value="month">Este mês</option>
                <option value="quarter">Este trimestre</option>
                <option value="year">Este ano</option>
                <option value="custom">Personalizado</option>
              </select>
            </div>

          </div>

          <!-- Custom Date Range -->
          <div v-if="filters.date_range === 'custom'" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label for="start_date" class="block text-sm font-medium text-gray-700">Data inicial</label>
              <input
                id="start_date"
                v-model="filters.start_date"
                type="date"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @change="applyFilters"
              />
            </div>
            <div>
              <label for="end_date" class="block text-sm font-medium text-gray-700">Data final</label>
              <input
                id="end_date"
                v-model="filters.end_date"
                type="date"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                @change="applyFilters"
              />
            </div>
          </div>

          <!-- Filter Actions -->
          <div class="mt-4 flex justify-between">
            <button
              @click="clearFilters"
              class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              Limpar filtros
            </button>
            <button
              @click="exportTransactions"
              class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              <svg class="-ml-0.5 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
              </svg>
              Exportar
            </button>
          </div>
        </div>
      </div>

      <!-- Transactions Table -->
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow overflow-hidden sm:rounded-md">
          
          <!-- Loading State -->
          <div v-if="loading" class="p-6 text-center">
            <svg class="animate-spin h-8 w-8 text-primary mx-auto" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="mt-2 text-sm text-gray-500">Carregando transações...</p>
          </div>

          <!-- Empty State -->
          <div v-else-if="transactions.data.length === 0" class="p-6 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhuma transação encontrada</h3>
            <p class="mt-1 text-sm text-gray-500">
              {{ hasFilters ? 'Tente ajustar os filtros ou' : 'Comece' }} criando sua primeira transação.
            </p>
            <div class="mt-6">
              <Link
                href="/transactions/create"
                class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
              >
                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Nova Transação
              </Link>
            </div>
          </div>

          <!-- Transactions List -->
   <!-- Lista de transações com segurança -->
<ul v-else class="divide-y divide-gray-200">
  <li v-for="transaction in transactions?.data || []" :key="transaction?.id">
    {{ console.log('Transaction ID:', transaction?.id) }}
    {{ console.log('executed_at:', transaction?.executed_at) }}
    <div class="px-4 py-4 flex items-center justify-between hover:bg-gray-50">

      <!-- Informações da transação -->
      <div class="flex items-center">
        <!-- Tipo -->
        <div class="flex-shrink-0">
          <div :class="getTypeIconClass(transaction?.type)" class="h-10 w-10 rounded-full flex items-center justify-center">
            <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-if="transaction?.type === 'buy'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
              <path v-else-if="transaction?.type === 'sell'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
              <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
            </svg>
          </div>
        </div>

        <!-- Detalhes -->
        <div class="ml-4">
          <div class="flex items-center">
            <p class="text-sm font-medium text-gray-900">
              {{ getTypeLabel(transaction?.type) }} {{ transaction?.from_crypto_asset?.symbol || transaction?.from_asset }}
            </p>
            <span :class="getTypeClass(transaction?.type)" class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
              {{ getTypeLabel(transaction?.type) }}
            </span>
          </div>
          <div class="mt-1 flex items-center text-sm text-gray-500">
            <p>{{ formatQuantity(transaction?.quantity) }} {{ transaction?.from_crypto_asset?.symbol || transaction?.from_asset }}</p>
            <span class="mx-2">&bull;</span>
            <p>{{ formatCurrency(transaction?.unit_price) }} por unidade</p>
            <span class="mx-2">&bull;</span>
           <p>{{ formatDate(transaction.executed_at) }}</p>
          </div>
        </div>
      </div>

      <!-- Ações -->
      <div class="flex items-center">
        <div class="text-right mr-4">
          <p class="text-sm font-medium text-gray-900">
            {{ formatCurrency(transaction?.total_amount) }}
          </p>
          <p v-if="transaction?.fees > 0" class="text-xs text-gray-500">
            Taxa: {{ formatCurrency(transaction?.fees) }}
          </p>
        </div>
        <div class="flex items-center space-x-2">
          <Link
           
            v-if="transaction?.id && typeof route === 'function'"
            :href="route('transactions.show', transaction.id)"
            class="text-primary hover:text-primary-dark"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
          </Link>
          <Link
            v-if="transaction?.id"
            :href="`/transactions/${transaction.id}/edit`"
            class="text-gray-400 hover:text-gray-600"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
          </Link>
          <button
            v-if="transaction?.id"
            @click="deleteTransaction(transaction.id)"
            class="text-red-400 hover:text-red-600"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </div>

    </div>
  </li>
</ul>


          <!-- Pagination -->
          <div v-if="transactions.data.length > 0" class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
            <div class="flex items-center justify-between">
              <div class="flex-1 flex justify-between sm:hidden">
                <button
                  v-if="transactions.prev_page_url"
                  @click="goToPage(transactions.current_page - 1)"
                  class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                >
                  Anterior
                </button>
                <button
                  v-if="transactions.next_page_url"
                  @click="goToPage(transactions.current_page + 1)"
                  class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                >
                  Próximo
                </button>
              </div>
              <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                <div>
                  <p class="text-sm text-gray-700">
                    Mostrando
                    <span class="font-medium">{{ transactions.from }}</span>
                    a
                    <span class="font-medium">{{ transactions.to }}</span>
                    de
                    <span class="font-medium">{{ transactions.total }}</span>
                    resultados
                  </p>
                </div>
                <div>
                  <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                    <!-- Pagination buttons would go here -->
                  </nav>
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
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'


// 1. Definição ausente de `loading`
const loading = ref(false)
const displayCurrency = ref('brl')

// Props
const props = defineProps({
  transactions: Object,
  stats: Object,
  cryptoAssets: Array,
  filters: Object,
})

// Filters reativos
const filters = ref({
  search: props.filters?.search || '',
  type: props.filters?.type || '',
  crypto_asset_id: props.filters?.crypto_asset_id || '',
  date_range: props.filters?.date_range || '',
  start_date: props.filters?.start_date || '',
  end_date: props.filters?.end_date || '',
})

// Computed para checar filtros
const hasFilters = computed(() => Object.values(filters.value).some(v => v !== ''))

// Funções de controle de filtros/paginação
const applyFilters = () => {
  loading.value = true
  router.get('/transactions', filters.value, {
    preserveState: true,
    onFinish: () => loading.value = false,
  })
}
const clearFilters = () => {
  filters.value = { search: '', type: '', crypto_asset_id: '', date_range: '', start_date: '', end_date: '' }
  applyFilters()
}
const debouncedSearch = debounce(applyFilters, 500)
const goToPage = (page) => {
  loading.value = true
  router.get('/transactions', { ...filters.value, page }, {
    preserveState: true,
    onFinish: () => loading.value = false,
  })
}
const deleteTransaction = (id) => {
  if (confirm('Tem certeza que deseja excluir esta transação?')) {
    router.delete(`/transactions/${id}`)
  }
}
const exportTransactions = () => window.open(`/transactions/export?${new URLSearchParams(filters.value)}`)

// Helpers (tipo, ícone, formatação)
const getTypeLabel = type => ({
  buy: 'Compra', sell: 'Venda', transfer: 'Transferência',
  mining: 'Mineração', staking: 'Staking', airdrop: 'Airdrop'
}[type] || type)
const getTypeClass = type => ({
  buy: 'bg-green-100 text-green-800', sell: 'bg-red-100 text-red-800',
  transfer: 'bg-blue-100 text-blue-800', mining: 'bg-yellow-100 text-yellow-800',
  staking: 'bg-purple-100 text-purple-800', airdrop: 'bg-indigo-100 text-indigo-800'
}[type] || 'bg-gray-100 text-gray-800')
const getTypeIconClass = type => ({
  buy: 'bg-green-500', sell: 'bg-red-500',
  transfer: 'bg-blue-500', mining: 'bg-yellow-500',
  staking: 'bg-purple-500', airdrop: 'bg-indigo-500'
}[type] || 'bg-gray-500')

const formatQuantity = qty => new Intl.NumberFormat('pt-BR', {
  minimumFractionDigits: 2, maximumFractionDigits: 8
}).format(qty)
const formatCurrency = amt => new Intl.NumberFormat('pt-BR', {
  style: 'currency', currency: 'BRL'
}).format(amt)
const formatDate = value => {
  console.log('Data recebida:', value)
  const dt = new Date(value)
  if (isNaN(dt.getTime())) return 'Data inválida'
  return new Intl.DateTimeFormat('pt-BR', {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  }).format(dt)
}

// Log para diagnóstico
onMounted(() => {
  console.log('Transações recebidas:', props.transactions?.data)
  props.transactions?.data?.forEach((tx, idx) => {
    console.log(`#${idx}`, tx)
    if (!tx.id) console.warn(`⚠️ Transação sem ID no índice ${idx}`)
  })
})

// Debounce
function debounce(fn, wait) {
  let timeout
  return (...args) => {
    clearTimeout(timeout)
    timeout = setTimeout(() => fn(...args), wait)
  }
}
</script>


<style scoped>
.bg-primary {
  background-color: var(--primary-color, #3b82f6);
}

.text-primary {
  color: var(--primary-color, #3b82f6);
}

.border-primary {
  border-color: var(--primary-color, #3b82f6);
}

.ring-primary {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

.hover\\:bg-primary-dark:hover {
  background-color: var(--primary-dark, #2563eb);
}

.hover\\:text-primary-dark:hover {
  color: var(--primary-dark, #2563eb);
}

.focus\\:ring-primary:focus {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

.focus\\:border-primary:focus {
  border-color: var(--primary-color, #3b82f6);
}
</style>

