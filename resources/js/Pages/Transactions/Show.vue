<template>
  <AppLayout title="Detalhes da Transação">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Transação #{{ transaction.id }}
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              {{ getTypeLabel(transaction.type) }} de {{ transaction.crypto_asset.symbol }}
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <Link
              :href="`/transactions/${transaction.id}/edit`"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
              </svg>
              Editar
            </Link>
                 


            <button
              @click="deleteTransaction"
              class="inline-flex items-center px-4 py-2 border border-red-300 rounded-md shadow-sm text-sm font-medium text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
            >
              <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
              </svg>
              Excluir
            </button>
            <Link
              href="/transactions"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ← Voltar
            </Link>
          </div>
        </div>
      </div>

      <!-- Transaction Status -->
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg p-6">
          <div class="flex items-center">
            <div :class="getTypeIconClass(transaction.type)" class="h-12 w-12 rounded-full flex items-center justify-center">
              <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path v-if="transaction.type === 'buy'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                <path v-else-if="transaction.type === 'sell'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
              </svg>
            </div>
            <div class="ml-4">
              <h3 class="text-lg font-medium text-gray-900">
                {{ getTypeLabel(transaction.type) }} de {{ transaction.crypto_asset.symbol }}
              </h3>
              <p class="text-sm text-gray-500">
                Executada em {{ formatDate(transaction.executed_at) }}
              </p>
            </div>
            <div class="ml-auto">
              <span :class="getTypeClass(transaction.type)" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium">
                {{ getTypeLabel(transaction.type) }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Transaction Details -->
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
          
          <!-- Main Details -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Detalhes da Transação</h3>
            </div>
            <div class="px-6 py-4 space-y-4">
              
              <!-- Crypto Asset -->
              <div class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500">Criptomoeda</dt>
                <dd class="text-sm text-gray-900">
                  <div class="flex items-center">
                    <span class="font-medium">{{ transaction.crypto_asset.symbol }}</span>
                    <span class="ml-2 text-gray-500">{{ transaction.crypto_asset.name }}</span>
                  </div>
                </dd>
              </div>

              <!-- Quantity -->
              <div class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500">Quantidade</dt>
                <dd class="text-sm text-gray-900 font-medium">
                  {{ formatQuantity(transaction.quantity) }} {{ transaction.crypto_asset.symbol }}
                </dd>
              </div>

              <!-- Unit Price -->
              <div class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500">Preço Unitário</dt>
                <dd class="text-sm text-gray-900 font-medium">
                  {{ formatCurrency(transaction.unit_price) }}
                </dd>
              </div>

              <!-- Total Amount -->
              <div class="flex justify-between border-t border-gray-200 pt-4">
                <dt class="text-base font-medium text-gray-900">Valor Total</dt>
                <dd class="text-base font-bold text-gray-900">
                  {{ formatCurrency(transaction.total_amount) }}
                </dd>
              </div>

              <!-- Fees -->
              <div v-if="transaction.fees > 0" class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500">Taxas</dt>
                <dd class="text-sm text-red-600 font-medium">
                  {{ formatCurrency(transaction.fees) }}
                </dd>
              </div>

              <!-- Net Amount -->
              <div v-if="transaction.fees > 0" class="flex justify-between border-t border-gray-200 pt-4">
                <dt class="text-base font-medium text-gray-900">Valor Líquido</dt>
                <dd class="text-base font-bold text-gray-900">
                  {{ formatCurrency(getNetAmount()) }}
                </dd>
              </div>

            </div>
          </div>

          <!-- Additional Info -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Informações Adicionais</h3>
            </div>
            <div class="px-6 py-4 space-y-4">
              
              <!-- Wallet -->
              <div class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500">Carteira</dt>
                <dd class="text-sm text-gray-900">
                  <div class="flex items-center">
                    <span class="font-medium">{{ transaction.wallet.name }}</span>
                    <span class="ml-2 text-gray-500">({{ transaction.wallet.type }})</span>
                  </div>
                </dd>
              </div>

              <!-- Date -->
              <div class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500">Data da Transação</dt>
                <dd class="text-sm text-gray-900">
                  {{ formatDate(transaction.executed_at) }}
                </dd>
              </div>

              <!-- Transaction Hash -->
              <div v-if="transaction.transaction_hash" class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500">Hash da Transação</dt>
                <dd class="text-sm text-gray-900">
                  <div class="flex items-center">
                    <code class="bg-gray-100 px-2 py-1 rounded text-xs">
                      {{ transaction.transaction_hash.substring(0, 20) }}...
                    </code>
                    <button
                      @click="copyToClipboard(transaction.transaction_hash)"
                      class="ml-2 text-gray-400 hover:text-gray-600"
                    >
                      <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                      </svg>
                    </button>
                  </div>
                </dd>
              </div>

              <!-- Created At -->
              <div class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500">Registrada em</dt>
                <dd class="text-sm text-gray-900">
                  {{ formatDate(transaction.created_at) }}
                </dd>
              </div>

              <!-- Updated At -->
              <div v-if="transaction.updated_at !== transaction.created_at" class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500">Última atualização</dt>
                <dd class="text-sm text-gray-900">
                  {{ formatDate(transaction.updated_at) }}
                </dd>
              </div>

            </div>
          </div>

        </div>
      </div>

      <!-- Notes -->
      <div v-if="transaction.notes" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Observações</h3>
          </div>
          <div class="px-6 py-4">
            <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ transaction.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Tax Information -->
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações Fiscais</h3>
          </div>
          <div class="px-6 py-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
              
              <!-- Tax Year -->
              <div>
                <dt class="text-sm font-medium text-gray-500">Ano Fiscal</dt>
                <dd class="mt-1 text-sm text-gray-900">
                  {{ new Date(transaction.executed_at).getFullYear() }}
                </dd>
              </div>

              <!-- Tax Month -->
              <div>
                <dt class="text-sm font-medium text-gray-500">Mês de Referência</dt>
                <dd class="mt-1 text-sm text-gray-900">
                  {{ formatMonth(transaction.executed_at) }}
                </dd>
              </div>

              <!-- Tax Status -->
              <div>
                <dt class="text-sm font-medium text-gray-500">Status Fiscal</dt>
                <dd class="mt-1">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    Registrada
                  </span>
                </dd>
              </div>

            </div>

            <!-- Tax Actions -->
            <div class="mt-6 flex space-x-3">
              <button
                @click="generateTaxReport"
                class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
              >
                <svg class="-ml-0.5 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Gerar Relatório Fiscal
              </button>
              <button
                @click="addToIN1888"
                class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
              >
                <svg class="-ml-0.5 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Incluir na IN 1888
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Related Transactions -->
      <div v-if="relatedTransactions.length > 0" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Transações Relacionadas</h3>
            <p class="mt-1 text-sm text-gray-500">
              Outras transações com {{ transaction.crypto_asset.symbol }}
            </p>
          </div>
          <div class="px-6 py-4">
            <div class="space-y-3">
              <div v-for="related in relatedTransactions" :key="related.id" class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                <div class="flex items-center">
                  <div :class="getTypeIconClass(related.type)" class="h-8 w-8 rounded-full flex items-center justify-center">
                    <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path v-if="related.type === 'buy'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                      <path v-else-if="related.type === 'sell'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                      <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                    </svg>
                  </div>
                  <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">
                      {{ getTypeLabel(related.type) }} de {{ formatQuantity(related.quantity) }} {{ related.crypto_asset.symbol }}
                    </p>
                    <p class="text-sm text-gray-500">
                      {{ formatDate(related.executed_at) }}
                    </p>
                  </div>
                </div>
                <div class="flex items-center space-x-2">
                  <span class="text-sm font-medium text-gray-900">
                    {{ formatCurrency(related.total_amount) }}
                  </span>
                  <Link
                    :href="`/transactions/${related.id}`"
                    class="text-primary hover:text-primary-dark"
                  >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                  </Link>
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
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  transaction: Object,
  relatedTransactions: Array,
})

// Methods
const deleteTransaction = () => {
  if (confirm('Tem certeza que deseja excluir esta transação? Esta ação não pode ser desfeita.')) {
    router.delete(`/transactions/${props.transaction.id}`, {
      onSuccess: () => {
        router.visit('/transactions')
      }
    })
  }
}

const generateTaxReport = () => {
  // Implement tax report generation
  window.open(`/reports/tax?transaction_id=${props.transaction.id}`)
}

const addToIN1888 = () => {
  // Implement IN 1888 inclusion
  router.post(`/transactions/${props.transaction.id}/add-to-in1888`)
}

const copyToClipboard = async (text) => {
  try {
    await navigator.clipboard.writeText(text)
    // Show success message (you could add a toast notification here)
  } catch (err) {
    console.error('Failed to copy: ', err)
  }
}

const getNetAmount = () => {
  const total = parseFloat(props.transaction.total_amount)
  const fees = parseFloat(props.transaction.fees) || 0
  
  if (props.transaction.type === 'buy') {
    return total + fees
  } else {
    return total - fees
  }
}

// Helper functions
const getTypeLabel = (type) => {
  const labels = {
    buy: 'Compra',
    sell: 'Venda',
    transfer: 'Transferência',
    mining: 'Mineração',
    staking: 'Staking',
    airdrop: 'Airdrop'
  }
  return labels[type] || type
}

const getTypeClass = (type) => {
  const classes = {
    buy: 'bg-green-100 text-green-800',
    sell: 'bg-red-100 text-red-800',
    transfer: 'bg-blue-100 text-blue-800',
    mining: 'bg-yellow-100 text-yellow-800',
    staking: 'bg-purple-100 text-purple-800',
    airdrop: 'bg-indigo-100 text-indigo-800'
  }
  return classes[type] || 'bg-gray-100 text-gray-800'
}

const getTypeIconClass = (type) => {
  const classes = {
    buy: 'bg-green-500',
    sell: 'bg-red-500',
    transfer: 'bg-blue-500',
    mining: 'bg-yellow-500',
    staking: 'bg-purple-500',
    airdrop: 'bg-indigo-500'
  }
  return classes[type] || 'bg-gray-500'
}

const formatQuantity = (quantity) => {
  return new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 8
  }).format(quantity)
}

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(amount)
}

const formatDate = (date) => {
  return new Intl.DateTimeFormat('pt-BR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(new Date(date))
}

const formatMonth = (date) => {
  return new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric'
  }).format(new Date(date))
}
</script>

<style scoped>
.bg-primary {
  background-color: var(--primary-color, #3b82f6);
}

.text-primary {
  color: var(--primary-color, #3b82f6);
}

.hover\\:text-primary-dark:hover {
  color: var(--primary-dark, #2563eb);
}

.hover\\:bg-primary-dark:hover {
  background-color: var(--primary-dark, #2563eb);
}

.focus\\:ring-primary:focus {
  --tw-ring-color: var(--primary-color, #3b82f6);
}
</style>

