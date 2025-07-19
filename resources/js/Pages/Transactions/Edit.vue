<template>
  <AppLayout title="Editar Transação">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Editar Transação
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Atualize os dados da transação #{{ transaction.id }}
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <Link
              :href="`/transactions/${transaction.id}`"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
              </svg>
              Visualizar
            </Link>
            <Link
              href="/transactions"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ← Voltar
            </Link>
          </div>
        </div>
      </div>

      <!-- Transaction Info Card -->
      <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
            </div>
            <div class="ml-3">
              <h3 class="text-sm font-medium text-blue-800">
                Informações da Transação Original
              </h3>
              <div class="mt-2 text-sm text-blue-700">
                <p><strong>Criada em:</strong> {{ formatDate(transaction.created_at) }}</p>
                <p><strong>Última atualização:</strong> {{ formatDate(transaction.updated_at) }}</p>
                <p v-if="transaction.transaction_hash"><strong>Hash:</strong> {{ transaction.transaction_hash }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Form -->
      <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <form @submit.prevent="submit" class="space-y-6 p-6">
            
            <!-- Transaction Type -->
            <div>
              <label class="text-base font-medium text-gray-900">Tipo de Transação</label>
              <p class="text-sm leading-5 text-gray-500">Selecione o tipo de operação realizada</p>
              <fieldset class="mt-4">
                <legend class="sr-only">Tipo de transação</legend>
                <div class="space-y-4 sm:flex sm:items-center sm:space-y-0 sm:space-x-10">
                  <div v-for="type in transactionTypes" :key="type.value" class="flex items-center">
                    <input
                      :id="type.value"
                      v-model="form.type"
                      :value="type.value"
                      name="type"
                      type="radio"
                      class="focus:ring-primary h-4 w-4 text-primary border-gray-300"
                    />
                    <label :for="type.value" class="ml-3 block text-sm font-medium text-gray-700">
                      {{ type.label }}
                    </label>
                  </div>
                </div>
              </fieldset>
              <div v-if="form.errors.type" class="mt-1 text-sm text-red-600">
                {{ form.errors.type }}
              </div>
            </div>

            <!-- Crypto Asset -->
            <div>
              <label for="crypto_asset_id" class="block text-sm font-medium text-gray-700">
                Criptomoeda
              </label>
              <select
                id="crypto_asset_id"
                v-model="form.crypto_asset_id"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                required
              >
                <option value="">Selecione uma criptomoeda</option>
                <option v-for="asset in cryptoAssets" :key="asset.id" :value="asset.id">
                  {{ asset.symbol }} - {{ asset.name }}
                </option>
              </select>
              <div v-if="form.errors.crypto_asset_id" class="mt-1 text-sm text-red-600">
                {{ form.errors.crypto_asset_id }}
              </div>
            </div>

            <!-- Quantity and Price -->
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
              
              <!-- Quantity -->
              <div>
                <label for="quantity" class="block text-sm font-medium text-gray-700">
                  Quantidade
                </label>
                <div class="mt-1 relative rounded-md shadow-sm">
                  <input
                    id="quantity"
                    v-model="form.quantity"
                    type="number"
                    step="0.00000001"
                    min="0"
                    class="block w-full pr-12 border-gray-300 rounded-md focus:ring-primary focus:border-primary sm:text-sm"
                    placeholder="0.00000000"
                    required
                    @input="calculateTotal"
                  />
                  <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                    <span class="text-gray-500 sm:text-sm">
                      {{ selectedAsset?.symbol || 'CRYPTO' }}
                    </span>
                  </div>
                </div>
                <div v-if="form.errors.quantity" class="mt-1 text-sm text-red-600">
                  {{ form.errors.quantity }}
                </div>
              </div>

              <!-- Unit Price -->
              <div>
                <label for="unit_price" class="block text-sm font-medium text-gray-700">
                  Preço Unitário
                </label>
                <div class="mt-1 relative rounded-md shadow-sm">
                  <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <span class="text-gray-500 sm:text-sm">R$</span>
                  </div>
                  <input
                    id="unit_price"
                    v-model="form.unit_price"
                    type="number"
                    step="0.01"
                    min="0"
                    class="block w-full pl-8 border-gray-300 rounded-md focus:ring-primary focus:border-primary sm:text-sm"
                    placeholder="0.00"
                    required
                    @input="calculateTotal"
                  />
                </div>
                <div v-if="form.errors.unit_price" class="mt-1 text-sm text-red-600">
                  {{ form.errors.unit_price }}
                </div>
              </div>

            </div>

            <!-- Total Amount (calculated) -->
            <div>
              <label class="block text-sm font-medium text-gray-700">
                Valor Total
              </label>
              <div class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-gray-50 rounded-md sm:text-sm">
                {{ formatCurrency(totalAmount) }}
              </div>
              <div v-if="originalTotal !== totalAmount" class="mt-1 text-sm text-blue-600">
                Valor original: {{ formatCurrency(originalTotal) }}
              </div>
            </div>

            <!-- Fees -->
            <div>
              <label for="fees" class="block text-sm font-medium text-gray-700">
                Taxas (opcional)
              </label>
              <div class="mt-1 relative rounded-md shadow-sm">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <span class="text-gray-500 sm:text-sm">R$</span>
                </div>
                <input
                  id="fees"
                  v-model="form.fees"
                  type="number"
                  step="0.01"
                  min="0"
                  class="block w-full pl-8 border-gray-300 rounded-md focus:ring-primary focus:border-primary sm:text-sm"
                  placeholder="0.00"
                />
              </div>
              <p class="mt-2 text-sm text-gray-500">
                Taxas cobradas pela exchange ou corretora
              </p>
              <div v-if="form.errors.fees" class="mt-1 text-sm text-red-600">
                {{ form.errors.fees }}
              </div>
            </div>

            <!-- Wallet -->
            <div>
              <label for="wallet_id" class="block text-sm font-medium text-gray-700">
                Carteira
              </label>
              <select
                id="wallet_id"
                v-model="form.wallet_id"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                required
              >
                <option value="">Selecione uma carteira</option>
                <option v-for="wallet in wallets" :key="wallet.id" :value="wallet.id">
                  {{ wallet.name }} ({{ wallet.type }})
                </option>
              </select>
              <div v-if="form.errors.wallet_id" class="mt-1 text-sm text-red-600">
                {{ form.errors.wallet_id }}
              </div>
            </div>

            <!-- Date and Time -->
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
              
              <!-- Date -->
              <div>
                <label for="executed_date" class="block text-sm font-medium text-gray-700">
                  Data da Transação
                </label>
                <input
                  id="executed_date"
                  v-model="form.executed_date"
                  type="date"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                  required
                />
                <div v-if="form.errors.executed_date" class="mt-1 text-sm text-red-600">
                  {{ form.errors.executed_date }}
                </div>
              </div>

              <!-- Time -->
              <div>
                <label for="executed_time" class="block text-sm font-medium text-gray-700">
                  Horário da Transação
                </label>
                <input
                  id="executed_time"
                  v-model="form.executed_time"
                  type="time"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                  required
                />
                <div v-if="form.errors.executed_time" class="mt-1 text-sm text-red-600">
                  {{ form.errors.executed_time }}
                </div>
              </div>

            </div>

            <!-- Transaction Hash (optional) -->
            <div>
              <label for="transaction_hash" class="block text-sm font-medium text-gray-700">
                Hash da Transação (opcional)
              </label>
              <input
                id="transaction_hash"
                v-model="form.transaction_hash"
                type="text"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                placeholder="0x..."
              />
              <p class="mt-2 text-sm text-gray-500">
                Hash da transação na blockchain (para verificação)
              </p>
              <div v-if="form.errors.transaction_hash" class="mt-1 text-sm text-red-600">
                {{ form.errors.transaction_hash }}
              </div>
            </div>

            <!-- Notes -->
            <div>
              <label for="notes" class="block text-sm font-medium text-gray-700">
                Observações (opcional)
              </label>
              <textarea
                id="notes"
                v-model="form.notes"
                rows="3"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                placeholder="Adicione observações sobre esta transação..."
              ></textarea>
              <div v-if="form.errors.notes" class="mt-1 text-sm text-red-600">
                {{ form.errors.notes }}
              </div>
            </div>

            <!-- Change Log -->
            <div v-if="hasChanges" class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-yellow-800">
                    Alterações detectadas
                  </h3>
                  <div class="mt-2 text-sm text-yellow-700">
                    <p>Esta transação será atualizada com as novas informações. Certifique-se de que todos os dados estão corretos antes de salvar.</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200">
              <Link
                :href="`/transactions/${transaction.id}`"
                class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
              >
                Cancelar
              </Link>
              <button
                type="button"
                @click="resetForm"
                class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
              >
                Resetar
              </button>
              <button
                type="submit"
                :disabled="form.processing || !hasChanges"
                class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span v-if="form.processing" class="flex items-center">
                  <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  Salvando...
                </span>
                <span v-else>Salvar Alterações</span>
              </button>
            </div>

          </form>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  transaction: Object,
  cryptoAssets: Array,
  wallets: Array,
})

// Transaction types
const transactionTypes = [
  { value: 'buy', label: 'Compra' },
  { value: 'sell', label: 'Venda' },
  { value: 'transfer', label: 'Transferência' },
  { value: 'mining', label: 'Mineração' },
  { value: 'staking', label: 'Staking' },
  { value: 'airdrop', label: 'Airdrop' },
]

// Original values for comparison
const originalValues = ref({})

// Form
const form = useForm({
  type: props.transaction.type,
  crypto_asset_id: props.transaction.crypto_asset_id,
  quantity: props.transaction.quantity,
  unit_price: props.transaction.unit_price,
  fees: props.transaction.fees || '',
  wallet_id: props.transaction.wallet_id,
  executed_date: props.transaction.executed_at.split(' ')[0],
  executed_time: props.transaction.executed_at.split(' ')[1].substring(0, 5),
  transaction_hash: props.transaction.transaction_hash || '',
  notes: props.transaction.notes || '',
})

// Computed
const selectedAsset = computed(() => {
  return props.cryptoAssets.find(asset => asset.id == form.crypto_asset_id)
})

const totalAmount = computed(() => {
  const quantity = parseFloat(form.quantity) || 0
  const unitPrice = parseFloat(form.unit_price) || 0
  return quantity * unitPrice
})

const originalTotal = computed(() => {
  return parseFloat(props.transaction.total_amount)
})

const hasChanges = computed(() => {
  return Object.keys(originalValues.value).some(key => {
    if (key === 'executed_date' || key === 'executed_time') {
      const originalDateTime = new Date(props.transaction.executed_at)
      const currentDate = form.executed_date
      const currentTime = form.executed_time
      const currentDateTime = new Date(`${currentDate} ${currentTime}:00`)
      return originalDateTime.getTime() !== currentDateTime.getTime()
    }
    return originalValues.value[key] != form[key]
  })
})

// Methods
const submit = () => {
  // Combine date and time
  const executedAt = `${form.executed_date} ${form.executed_time}:00`
  
  const data = {
    ...form.data(),
    executed_at: executedAt,
    total_amount: totalAmount.value,
  }
  
  delete data.executed_date
  delete data.executed_time
  
  form.transform(() => data).put(`/transactions/${props.transaction.id}`, {
    onSuccess: () => {
      // Redirect handled by controller
    }
  })
}

const resetForm = () => {
  form.reset()
  form.type = props.transaction.type
  form.crypto_asset_id = props.transaction.crypto_asset_id
  form.quantity = props.transaction.quantity
  form.unit_price = props.transaction.unit_price
  form.fees = props.transaction.fees || ''
  form.wallet_id = props.transaction.wallet_id
  form.executed_date = props.transaction.executed_at.split(' ')[0]
  form.executed_time = props.transaction.executed_at.split(' ')[1].substring(0, 5)
  form.transaction_hash = props.transaction.transaction_hash || ''
  form.notes = props.transaction.notes || ''
}

const calculateTotal = () => {
  // Total is calculated automatically via computed property
}

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(amount || 0)
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

// Lifecycle
onMounted(() => {
  // Store original values for comparison
  originalValues.value = {
    type: props.transaction.type,
    crypto_asset_id: props.transaction.crypto_asset_id,
    quantity: props.transaction.quantity,
    unit_price: props.transaction.unit_price,
    fees: props.transaction.fees || '',
    wallet_id: props.transaction.wallet_id,
    executed_date: props.transaction.executed_at.split(' ')[0],
    executed_time: props.transaction.executed_at.split(' ')[1].substring(0, 5),
    transaction_hash: props.transaction.transaction_hash || '',
    notes: props.transaction.notes || '',
  }
})
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

.focus\\:ring-primary:focus {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

.focus\\:border-primary:focus {
  border-color: var(--primary-color, #3b82f6);
}
</style>

