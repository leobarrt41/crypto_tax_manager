<template>
  <AppLayout :title="`Editar: ${strategy?.name}`">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Editar Estratégia: {{ strategy?.name }}
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Modifique as configurações da estratégia de trading
            </p>
          </div>
          <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
            <Link
              :href="`/trading-strategies/${strategy?.id}`"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              👁️ Visualizar
            </Link>
            <Link
              href="/trading-strategies"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              ← Voltar
            </Link>
          </div>
        </div>
      </div>

      <!-- Changes Alert -->
      <div v-if="hasChanges" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
              </svg>
            </div>
            <div class="ml-3">
              <h3 class="text-sm font-medium text-yellow-800">
                Alterações não salvas
              </h3>
              <div class="mt-2 text-sm text-yellow-700">
                <p>Você tem alterações não salvas. Lembre-se de salvar antes de sair da página.</p>
              </div>
              <div class="mt-4">
                <div class="-mx-2 -my-1.5 flex">
                  <button
                    @click="resetChanges"
                    type="button"
                    class="bg-yellow-50 px-2 py-1.5 rounded-md text-sm font-medium text-yellow-800 hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-yellow-50 focus:ring-yellow-600"
                  >
                    Desfazer alterações
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Form -->
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <form @submit.prevent="submit" class="space-y-6">
          
          <!-- Strategy Status -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900">Status da Estratégia</h3>
            </div>
            <div class="px-6 py-4">
              <div class="flex items-center justify-between">
                <div>
                  <h4 class="text-sm font-medium text-gray-900">Estado Atual</h4>
                  <p class="text-sm text-gray-500">
                    A estratégia está atualmente 
                    <span :class="getStatusColor(strategy?.status)" class="font-medium">
                      {{ getStatusLabel(strategy?.status) }}
                    </span>
                  </p>
                </div>
                <div class="flex items-center space-x-3">
                  <button
                    v-if="strategy?.status === 'inactive'"
                    @click="startStrategy"
                    type="button"
                    class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                  >
                    ▶️ Iniciar
                  </button>
                  <button
                    v-else-if="strategy?.status === 'active'"
                    @click="stopStrategy"
                    type="button"
                    class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                  >
                    ⏹️ Parar
                  </button>
                  <button
                    v-else-if="strategy?.status === 'paused'"
                    @click="resumeStrategy"
                    type="button"
                    class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                  >
                    ▶️ Retomar
                  </button>
                </div>
              </div>
            </div>
          </div>

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
                ></textarea>
                <p v-if="errors.description" class="mt-1 text-sm text-red-600">{{ errors.description }}</p>
              </div>

              <!-- Strategy Type (readonly) -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Tipo de Estratégia</label>
                <div class="mt-1 p-3 bg-gray-50 border border-gray-300 rounded-md">
                  <div class="flex items-center">
                    <span class="text-2xl mr-3">{{ getStrategyIcon(strategy?.type) }}</span>
                    <div>
                      <div class="text-sm font-medium text-gray-900">{{ getStrategyTypeLabel(strategy?.type) }}</div>
                      <div class="text-xs text-gray-500">O tipo da estratégia não pode ser alterado após a criação</div>
                    </div>
                  </div>
                </div>
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
                
                <!-- Exchange (readonly) -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Exchange</label>
                  <div class="mt-1 p-3 bg-gray-50 border border-gray-300 rounded-md">
                    <span class="text-sm text-gray-900">{{ strategy?.exchange }}</span>
                    <p class="text-xs text-gray-500 mt-1">A exchange não pode ser alterada</p>
                  </div>
                </div>

                <!-- Trading Pair (readonly) -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Par de Trading</label>
                  <div class="mt-1 p-3 bg-gray-50 border border-gray-300 rounded-md">
                    <span class="text-sm text-gray-900">{{ strategy?.trading_pair }}</span>
                    <p class="text-xs text-gray-500 mt-1">O par de trading não pode ser alterado</p>
                  </div>
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
              <div v-if="strategy?.type === 'scalping'" class="space-y-4">
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

              <div v-else-if="strategy?.type === 'grid'" class="space-y-4">
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
                    >
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Preço Base</label>
                    <input
                      v-model="form.parameters.base_price"
                      type="number"
                      step="0.01"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
                    >
                  </div>
                </div>
              </div>

              <div v-else-if="strategy?.type === 'dca'" class="space-y-4">
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
                    >
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Limite Total</label>
                    <input
                      v-model="form.parameters.total_limit"
                      type="number"
                      step="0.01"
                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"
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

          <!-- Danger Zone -->
          <div class="bg-white shadow rounded-lg border border-red-200">
            <div class="px-6 py-4 border-b border-red-200 bg-red-50">
              <h3 class="text-lg font-medium text-red-900">Zona de Perigo</h3>
            </div>
            <div class="px-6 py-4">
              <div class="flex items-center justify-between">
                <div>
                  <h4 class="text-sm font-medium text-gray-900">Excluir Estratégia</h4>
                  <p class="text-sm text-gray-500">
                    Esta ação não pode ser desfeita. Todos os dados da estratégia serão perdidos.
                  </p>
                </div>
                <button
                  @click="deleteStrategy"
                  type="button"
                  class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                >
                  🗑️ Excluir Estratégia
                </button>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex justify-end space-x-3">
            <button
              @click="resetChanges"
              type="button"
              class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
            >
              🔄 Desfazer Alterações
            </button>
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
              {{ processing ? 'Salvando...' : '💾 Salvar Alterações' }}
            </button>
          </div>

        </form>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  strategy: Object,
  errors: Object,
})

// Reactive data
const processing = ref(false)
const originalForm = ref({})

const form = reactive({
  name: '',
  description: '',
  base_amount: '',
  max_amount: '',
  max_daily_loss: '',
  max_concurrent_trades: 3,
  enable_notifications: true,
  parameters: {}
})

// Computed
const hasChanges = computed(() => {
  return JSON.stringify(form) !== JSON.stringify(originalForm.value)
})

// Methods
const initializeForm = () => {
  if (props.strategy) {
    form.name = props.strategy.name || ''
    form.description = props.strategy.description || ''
    form.base_amount = props.strategy.base_amount || ''
    form.max_amount = props.strategy.max_amount || ''
    form.max_daily_loss = props.strategy.max_daily_loss || ''
    form.max_concurrent_trades = props.strategy.max_concurrent_trades || 3
    form.enable_notifications = props.strategy.enable_notifications ?? true
    form.parameters = props.strategy.parameters || {}
    
    // Store original values
    originalForm.value = JSON.parse(JSON.stringify(form))
  }
}

const submit = () => {
  processing.value = true
  
  router.put(`/trading-strategies/${props.strategy.id}`, form, {
    onFinish: () => {
      processing.value = false
    },
    onSuccess: () => {
      originalForm.value = JSON.parse(JSON.stringify(form))
    }
  })
}

const saveAndTest = () => {
  processing.value = true
  
  router.put(`/trading-strategies/${props.strategy.id}`, {
    ...form,
    test_mode: true
  }, {
    onFinish: () => {
      processing.value = false
    }
  })
}

const resetChanges = () => {
  Object.assign(form, originalForm.value)
}

const startStrategy = () => {
  router.post(`/trading-strategies/${props.strategy.id}/start`)
}

const stopStrategy = () => {
  router.post(`/trading-strategies/${props.strategy.id}/stop`)
}

const resumeStrategy = () => {
  router.post(`/trading-strategies/${props.strategy.id}/resume`)
}

const deleteStrategy = () => {
  if (confirm('Tem certeza que deseja excluir esta estratégia? Esta ação não pode ser desfeita.')) {
    router.delete(`/trading-strategies/${props.strategy.id}`)
  }
}

// Helper functions
const getStatusColor = (status) => {
  const colors = {
    'active': 'text-green-600',
    'inactive': 'text-gray-600',
    'paused': 'text-yellow-600',
    'testing': 'text-blue-600'
  }
  return colors[status] || 'text-gray-600'
}

const getStatusLabel = (status) => {
  const labels = {
    'active': 'ativa',
    'inactive': 'inativa',
    'paused': 'pausada',
    'testing': 'em teste'
  }
  return labels[status] || status
}

const getStrategyIcon = (type) => {
  const icons = {
    'scalping': '⚡',
    'swing': '📈',
    'arbitrage': '⚖️',
    'grid': '🔲',
    'dca': '📊',
    'momentum': '🚀'
  }
  return icons[type] || '🤖'
}

const getStrategyTypeLabel = (type) => {
  const labels = {
    'scalping': 'Scalping',
    'swing': 'Swing Trading',
    'arbitrage': 'Arbitragem',
    'grid': 'Grid Trading',
    'dca': 'DCA',
    'momentum': 'Momentum'
  }
  return labels[type] || type
}

// Lifecycle
onMounted(() => {
  initializeForm()
  
  // Warn user about unsaved changes
  window.addEventListener('beforeunload', (e) => {
    if (hasChanges.value) {
      e.preventDefault()
      e.returnValue = ''
    }
  })
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', () => {})
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

