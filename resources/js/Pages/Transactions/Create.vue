<template>
  <AppLayout title="Nova Transação">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Nova Transação
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Registre uma nova transação de criptomoeda
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4">
            <Link
              href="/transactions"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ← Voltar
            </Link>
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

            <!-- Form Actions -->
            <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200">
              <Link
                href="/transactions"
                class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
              >
                Cancelar
              </Link>
              <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span v-if="form.processing" class="flex items-center">
                  <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  Salvando...
                </span>
                <span v-else>Salvar Transação</span>
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

// Form
const form = useForm({
  type: 'buy',
  crypto_asset_id: '',
  quantity: '',
  unit_price: '',
  fees: '',
  wallet_id: '',
  executed_date: new Date().toISOString().split('T')[0],
  executed_time: new Date().toTimeString().split(' ')[0].substring(0, 5),
  transaction_hash: '',
  notes: '',
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
  
  form.transform(() => data).post('/transactions', {
    onSuccess: () => {
      // Redirect handled by controller
    }
  })
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

// Lifecycle
onMounted(() => {
  // Set default wallet if only one exists
  if (props.wallets.length === 1) {
    form.wallet_id = props.wallets[0].id
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

