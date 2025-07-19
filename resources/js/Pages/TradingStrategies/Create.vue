<template>
  <AppLayout title="Nova Estratégia de Trading">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Nova Estratégia de Trading
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Configure uma nova estratégia automatizada de trading
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4">
            <Link
              href="/trading-strategies"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ← Voltar às Estratégias
            </Link>
          </div>
        </div>
      </div>

      <!-- Form -->
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <form @submit.prevent="submit" class="space-y-6">
          
          <!-- Basic Information -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Informações Básicas</h3>
            </div>
            <div class="px-6 py-4 space-y-6">
              
              <!-- Name -->
              <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Nome da Estratégia</label>
                <input
                  id="name"
                  v-model="form.name"
                  type="text"
                  required
                  class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                  placeholder="Ex: Scalping BTC/USDT"
                >
                <p v-if="errors.name" class="mt-1 text-sm text-red-600">{{ errors.name }}</p>
              </div>

              <!-- Description -->
              <div>
                <label for="description" class="block text-sm font-medium text-gray-700">Descrição</label>
                <textarea
                  id="description"
                  v-model="form.description"
                  rows="3"
                  class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                  placeholder="Descreva o objetivo e funcionamento da estratégia..."
                ></textarea>
                <p v-if="errors.description" class="mt-1 text-sm text-red-600">{{ errors.description }}</p>
              </div>

              <!-- Strategy Type -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-3">Tipo de Estratégia</label>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                  <div v-for="type in strategyTypes" :key="type.value" class="relative">
                    <input
                      :id="`type-${type.value}`"
                      v-model="form.type"
                      :value="type.value"
                      type="radio"
                      class="sr-only"
                    >
                    <label
                      :for="`type-${type.value}`"
                      :class="[
                        'relative block w-full p-4 border-2 rounded-lg cursor-pointer hover:border-gray-400 focus:outline-none',
                        form.type === type.value
                          ? 'border-primary bg-primary bg-opacity-5'
                          : 'border-gray-300'
                      ]"
                    >
                      <div class="flex items-center">
                        <div class="text-2xl mr-3">{{ type.icon }}</div>
                        <div>
                          <div class="text-sm font-medium text-gray-900">{{ type.label }}</div>
                          <div class="text-xs text-gray-500">{{ type.description }}</div>
                        </div>
                      </div>
                    </label>
                  </div>
                </div>
                <p v-if="errors.type" class="mt-1 text-sm text-red-600">{{ errors.type }}</p>
              </div>

            </div>
          </div>

          <!-- Trading Configuration -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Configuração de Trading</h3>
            </div>
            <div class="px-6 py-4 space-y-6">
              
              <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                
                <!-- Exchange -->
                <div>
                  <label for="exchange" class="block text-sm font-medium text-gray-700">Exchange</label>
                  <select
                    id="exchange"
                    v-model="form.exchange"
                    required
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                  >
                    <option value="">Selecione uma exchange</option>
                    <option value="binance">Binance</option>
                    <option value="coinbase">Coinbase Pro</option>
                    <option value="kraken">Kraken</option>
                    <option value="mercadobitcoin">Mercado Bitcoin</option>
                  </select>
                  <p v-if="errors.exchange" class="mt-1 text-sm text-red-600">{{ errors.exchange }}</p>
                </div>

                <!-- Trading Pair -->
                <div>
                  <label for="trading_pair" class="block text-sm font-medium text-gray-700">Par de Trading</label>
                  <select
                    id="trading_pair"
                    v-model="form.trading_pair"
                    required
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                  >
                    <option value="">Selecione um par</option>
                    <option value="BTC/USDT">BTC/USDT</option>
                    <option value="ETH/USDT">ETH/USDT</option>
                    <option value="BNB/USDT">BNB/USDT</option>
                    <option value="ADA/USDT">ADA/USDT</option>
                    <option value="SOL/USDT">SOL/USDT</option>
                    <option value="DOT/USDT">DOT/USDT</option>
                  </select>
                  <p v-if="errors.trading_pair" class="mt-1 text-sm text-red-600">{{ errors.trading_pair }}</p>
                </div>

                <!-- Base Amount -->
                <div>
                  <label for="base_amount" class="block text-sm font-medium text-gray-700">Valor Base (USDT)</label>
                  <input
                    id="base_amount"
                    v-model="form.base_amount"
                    type="number"
                    step="0.01"
                    min="10"
                    required
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                    placeholder="100.00"
                  >
                  <p class="mt-1 text-xs text-gray-500">Valor mínimo por operação</p>
                  <p v-if="errors.base_amount" class="mt-1 text-sm text-red-600">{{ errors.base_amount }}</p>
                </div>

                <!-- Max Amount -->
                <div>
                  <label for="max_amount" class="block text-sm font-medium text-gray-700">Valor Máximo (USDT)</label>
                  <input
                    id="max_amount"
                    v-model="form.max_amount"
                    type="number"
                    step="0.01"
                    min="10"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                    placeholder="1000.00"
                  >
                  <p class="mt-1 text-xs text-gray-500">Limite máximo por operação (opcional)</p>
                  <p v-if="errors.max_amount" class="mt-1 text-sm text-red-600">{{ errors.max_amount }}</p>
                </div>

              </div>

            </div>
          </div>

          <!-- Strategy Parameters -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Parâmetros da Estratégia</h3>
            </div>
            <div class="px-6 py-4 space-y-6">
              
              <!-- Dynamic parameters based on strategy type -->
              <div v-if="form.type === 'scalping'" class="space-y-4">
                <h4 class="text-sm font-medium text-gray-900">⚡ Configurações de Scalping</h4>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Profit Target (%)</label>
                    <input
                      v-model="form.parameters.profit_target"
                      type="number"
                      step="0.1"
                      min="0.1"
                      max="10"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                      placeholder="0.5"
                    >
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Stop Loss (%)</label>
                    <input
                      v-model="form.parameters.stop_loss"
                      type="number"
                      step="0.1"
                      min="0.1"
                      max="5"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                      placeholder="0.3"
                    >
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Timeframe</label>
                    <select
                      v-model="form.parameters.timeframe"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                    >
                      <option value="1m">1 minuto</option>
                      <option value="5m">5 minutos</option>
                      <option value="15m">15 minutos</option>
                    </select>
                  </div>
                </div>
              </div>

              <div v-else-if="form.type === 'grid'" class="space-y-4">
                <h4 class="text-sm font-medium text-gray-900">🔲 Configurações de Grid Trading</h4>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Número de Grids</label>
                    <input
                      v-model="form.parameters.grid_count"
                      type="number"
                      min="3"
                      max="20"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                      placeholder="10"
                    >
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Espaçamento (%)</label>
                    <input
                      v-model="form.parameters.grid_spacing"
                      type="number"
                      step="0.1"
                      min="0.5"
                      max="10"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                      placeholder="1.0"
                    >
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Preço Base</label>
                    <input
                      v-model="form.parameters.base_price"
                      type="number"
                      step="0.01"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                      placeholder="Preço atual"
                    >
                  </div>
                </div>
              </div>

              <div v-else-if="form.type === 'dca'" class="space-y-4">
                <h4 class="text-sm font-medium text-gray-900">📊 Configurações de DCA</h4>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Intervalo (horas)</label>
                    <select
                      v-model="form.parameters.interval_hours"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                    >
                      <option value="1">1 hora</option>
                      <option value="4">4 horas</option>
                      <option value="12">12 horas</option>
                      <option value="24">24 horas</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Valor por Compra</label>
                    <input
                      v-model="form.parameters.buy_amount"
                      type="number"
                      step="0.01"
                      min="10"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                      placeholder="50.00"
                    >
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Limite Total</label>
                    <input
                      v-model="form.parameters.total_limit"
                      type="number"
                      step="0.01"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                      placeholder="1000.00"
                    >
                  </div>
                </div>
              </div>

              <!-- Generic parameters for other types -->
              <div v-else class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Take Profit (%)</label>
                    <input
                      v-model="form.parameters.take_profit"
                      type="number"
                      step="0.1"
                      min="0.1"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                      placeholder="2.0"
                    >
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Stop Loss (%)</label>
                    <input
                      v-model="form.parameters.stop_loss"
                      type="number"
                      step="0.1"
                      min="0.1"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                      placeholder="1.0"
                    >
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Risk Management -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Gestão de Risco</h3>
            </div>
            <div class="px-6 py-4 space-y-6">
              
              <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                
                <!-- Max Daily Loss -->
                <div>
                  <label for="max_daily_loss" class="block text-sm font-medium text-gray-700">Perda Máxima Diária (%)</label>
                  <input
                    id="max_daily_loss"
                    v-model="form.max_daily_loss"
                    type="number"
                    step="0.1"
                    min="0.1"
                    max="50"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                    placeholder="5.0"
                  >
                  <p class="mt-1 text-xs text-gray-500">Bot para automaticamente se atingir esta perda</p>
                </div>

                <!-- Max Concurrent Trades -->
                <div>
                  <label for="max_concurrent_trades" class="block text-sm font-medium text-gray-700">Trades Simultâneos</label>
                  <input
                    id="max_concurrent_trades"
                    v-model="form.max_concurrent_trades"
                    type="number"
                    min="1"
                    max="10"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                    placeholder="3"
                  >
                  <p class="mt-1 text-xs text-gray-500">Número máximo de operações abertas</p>
                </div>

              </div>

              <!-- Enable Notifications -->
              <div class="flex items-center">
                <input
                  id="enable_notifications"
                  v-model="form.enable_notifications"
                  type="checkbox"
                  class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded"
                >
                <label for="enable_notifications" class="ml-2 block text-sm text-gray-900">
                  Receber notificações sobre execuções desta estratégia
                </label>
              </div>

            </div>
          </div>

          <!-- Actions -->
          <div class="flex justify-end space-x-3">
            <Link
              href="/trading-strategies"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              Cancelar
            </Link>
            <button
              type="button"
              @click="saveAndTest"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              💾 Salvar e Testar
            </button>
            <button
              type="submit"
              :disabled="processing"
              class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50"
            >
              <span v-if="processing" class="mr-2">
                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
              </span>
              {{ processing ? 'Criando...' : '🚀 Criar Estratégia' }}
            </button>
          </div>

        </form>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  errors: Object,
})

// Reactive data
const processing = ref(false)

const form = reactive({
  name: '',
  description: '',
  type: '',
  exchange: '',
  trading_pair: '',
  base_amount: '',
  max_amount: '',
  max_daily_loss: '',
  max_concurrent_trades: 3,
  enable_notifications: true,
  parameters: {}
})

// Strategy types
const strategyTypes = [
  {
    value: 'scalping',
    label: 'Scalping',
    icon: '⚡',
    description: 'Operações rápidas e frequentes'
  },
  {
    value: 'swing',
    label: 'Swing Trading',
    icon: '📈',
    description: 'Operações de médio prazo'
  },
  {
    value: 'arbitrage',
    label: 'Arbitragem',
    icon: '⚖️',
    description: 'Explorar diferenças de preço'
  },
  {
    value: 'grid',
    label: 'Grid Trading',
    icon: '🔲',
    description: 'Grade de ordens automáticas'
  },
  {
    value: 'dca',
    label: 'DCA',
    icon: '📊',
    description: 'Dollar Cost Average'
  },
  {
    value: 'momentum',
    label: 'Momentum',
    icon: '🚀',
    description: 'Seguir tendências de mercado'
  }
]

// Methods
const submit = () => {
  processing.value = true
  
  router.post('/trading-strategies', form, {
    onFinish: () => {
      processing.value = false
    }
  })
}

const saveAndTest = () => {
  processing.value = true
  
  router.post('/trading-strategies', {
    ...form,
    test_mode: true
  }, {
    onFinish: () => {
      processing.value = false
    }
  })
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

.focus\\:ring-primary:focus {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

.focus\\:border-primary:focus {
  border-color: var(--primary-color, #3b82f6);
}
</style>

