<template>
  <AppLayout title="Importar Transações">
    <div class="py-6">
      
      <!-- Header -->
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="md:flex md:items-center md:justify-between">
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
              Importar Transações
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              Importe transações de exchanges ou arquivos CSV
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

      <!-- Import Methods -->
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
          
          <!-- Exchange Import -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900 flex items-center">
                <svg class="h-5 w-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                </svg>
                Importar de Exchange
              </h3>
              <p class="mt-1 text-sm text-gray-500">
                Conecte-se diretamente às exchanges para importar suas transações
              </p>
            </div>
            <div class="px-6 py-4">
              
              <!-- Exchange Selection -->
              <div class="mb-4">
                <label for="exchange" class="block text-sm font-medium text-gray-700">
                  Selecione a Exchange
                </label>
               <select
                    id="exchange"
                    v-model="exchangeForm.exchange"
                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                    @change="loadExchangeKeys"
                  >
                    <option value="">Selecione uma exchange</option>
                    <option
                      v-for="exchange in exchanges"
                      :key="exchange.id"
                      :value="exchange.name"
                    >
                      {{ exchange.description }}
                    </option>
                  </select>

              </div>

           
              <!-- API Key Selection -->
              <div v-if="exchangeForm.exchange" class="mb-4">
                <label for="api_key" class="block text-sm font-medium text-gray-700">
                  Chave de API
                </label>
                <select
                  id="api_key"
                  v-model="exchangeForm.api_key_id"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                >
                  <option value="">Selecione uma chave de API</option>
                  <option v-for="key in availableKeys" :key="key.id" :value="key.id">
                    {{ key.name }} ({{ key.exchange }})
                  </option>
                </select>
                <p class="mt-1 text-sm text-gray-500">
                  <Link href="/exchange-keys" class="text-primary hover:text-primary-dark">
                    Gerenciar chaves de API
                  </Link>
                </p>
              </div>

              <!-- Date Range -->
              <div v-if="exchangeForm.api_key_id" class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  Período de Importação
                </label>
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label for="start_date" class="block text-xs font-medium text-gray-500">Data inicial</label>
                    <input
                      id="start_date"
                      v-model="exchangeForm.start_date"
                      type="date"
                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                    />
                  </div>
                  <div>
                    <label for="end_date" class="block text-xs font-medium text-gray-500">Data final</label>
                    <input
                      id="end_date"
                      v-model="exchangeForm.end_date"
                      type="date"
                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                    />
                  </div>
                </div>
              </div>

              <!-- Import Button -->
              <button
                @click="importFromExchange"
                :disabled="!exchangeForm.api_key_id || exchangeImporting"
                class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span v-if="exchangeImporting" class="flex items-center">
                  <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  Importando...
                </span>
                <span v-else>
                  <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
                  </svg>
                  Importar da Exchange
                </span>
              </button>

            </div>
          </div>


           <!-- Wallet Import -->
            <div class="bg-white shadow rounded-lg">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900 flex items-center">
                  <svg class="h-5 w-5 text-purple-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h16v16H4z" />
                  </svg>
                  Importar de Carteira
                </h3>
                <p class="mt-1 text-sm text-gray-500">
                  Importe transações registradas por meio da sua carteira
                </p>
              </div>
              <div class="px-6 py-4">
                <div class="mb-4">
                  <label for="wallet_id" class="block text-sm font-medium text-gray-700">Selecione a Carteira</label>
                  <select v-model="walletForm.wallet_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm sm:text-sm">
                    <option value="">Selecione uma carteira</option>
                    <option v-for="wallet in wallets" :key="wallet.id" :value="wallet.id">
                      {{ wallet.name }} ({{ wallet.network.name }})
                    </option>
                  </select>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                  <div>
                    <label class="block text-xs text-gray-500">Data inicial</label>
                    <input v-model="walletForm.start_date" type="date" class="block w-full border-gray-300 rounded-md shadow-sm sm:text-sm" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-500">Data final</label>
                    <input v-model="walletForm.end_date" type="date" class="block w-full border-gray-300 rounded-md shadow-sm sm:text-sm" />
                  </div>
                </div>

                <button
                  @click="importFromWallet"
                  :disabled="!walletForm.wallet_id || walletImporting"
                  class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 disabled:opacity-50"
                >
                  <span v-if="walletImporting">Importando...</span>
                  <span v-else>Importar da Carteira</span>
                </button>
              </div>
            </div>



          <!-- CSV Import -->
          <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
              <h3 class="text-lg font-medium text-gray-900 flex items-center">
                <svg class="h-5 w-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Importar CSV
              </h3>
              <p class="mt-1 text-sm text-gray-500">
                Faça upload de um arquivo CSV com suas transações
              </p>
            </div>
            <div class="px-6 py-4">
              
              <!-- File Upload -->
              <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  Arquivo CSV
                </label>
                <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                  <div class="space-y-1 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                      <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div class="flex text-sm text-gray-600">
                      <label for="csv_file" class="relative cursor-pointer bg-white rounded-md font-medium text-primary hover:text-primary-dark focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-primary">
                        <span>Selecione um arquivo</span>
                        <input
                          id="csv_file"
                          ref="csvFileInput"
                          type="file"
                          accept=".csv"
                          class="sr-only"
                          @change="handleFileUpload"
                        />
                      </label>
                      <p class="pl-1">ou arraste e solte</p>
                    </div>
                    <p class="text-xs text-gray-500">CSV até 10MB</p>
                  </div>
                </div>
                <div v-if="csvForm.file" class="mt-2 text-sm text-gray-600">
                  Arquivo selecionado: {{ csvForm.file.name }}
                </div>
              </div>

              <!-- CSV Format -->
              <div class="mb-4">
                <label for="csv_format" class="block text-sm font-medium text-gray-700">
                  Formato do CSV
                </label>
                <select
                  id="csv_format"
                  v-model="csvForm.format"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                >
                  <option value="standard">Formato Padrão</option>
                  <option value="binance">Binance</option>
                  <option value="coinbase">Coinbase</option>
                  <option value="kraken">Kraken</option>
                  <option value="custom">Personalizado</option>
                </select>
              </div>

              <!-- Column Mapping (for custom format) -->
              <div v-if="csvForm.format === 'custom' && csvPreview.length > 0" class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  Mapeamento de Colunas
                </label>
                <div class="space-y-2">
                  <div v-for="(field, index) in csvFields" :key="index" class="grid grid-cols-2 gap-2">
                    <div>
                      <label class="block text-xs font-medium text-gray-500">{{ field.label }}</label>
                      <select
                        v-model="csvForm.column_mapping[field.key]"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary text-sm"
                      >
                        <option value="">Selecione a coluna</option>
                        <option v-for="(header, headerIndex) in csvHeaders" :key="headerIndex" :value="headerIndex">
                          {{ header }}
                        </option>
                      </select>
                    </div>
                    <div v-if="csvPreview[0] && csvForm.column_mapping[field.key] !== undefined">
                      <label class="block text-xs font-medium text-gray-500">Exemplo</label>
                      <div class="mt-1 px-2 py-1 bg-gray-100 rounded text-sm">
                        {{ csvPreview[0][csvForm.column_mapping[field.key]] || 'N/A' }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Import Options -->
              <div class="mb-4">
                <div class="flex items-center">
                  <input
                    id="skip_duplicates"
                    v-model="csvForm.skip_duplicates"
                    type="checkbox"
                    class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded"
                  />
                  <label for="skip_duplicates" class="ml-2 block text-sm text-gray-900">
                    Pular transações duplicadas
                  </label>
                </div>
              </div>

              <!-- Origem das Transações -->
                  <div class="mb-4">
                    <label for="csv_source_type" class="block text-sm font-medium text-gray-700">
                      Origem das Transações
                    </label>
                    <select
                      id="csv_source_type"
                      v-model="csvForm.source_type"
                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary sm:text-sm"
                    >
                      <option value="">Selecione a origem</option>
                      <option value="exchange">Exchange</option>
                      <option value="wallet">Carteira</option>
                    </select>
                  </div>

                  <div v-if="csvForm.source_type === 'exchange'" class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Chave de API</label>
                    <select
                      v-model="csvForm.source_id"
                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm sm:text-sm"
                    >
                      <option value="">Selecione uma exchange</option>
                      <option v-for="key in availableKeys" :key="key.id" :value="key.id">
                        {{ key.name }} ({{ key.exchange }})
                      </option>
                    </select>
                  </div>

                  <div v-if="csvForm.source_type === 'wallet'" class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Carteira</label>
                    <select
                      v-model="csvForm.source_id"
                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm sm:text-sm"
                    >
                          <option value="">Selecione uma carteira</option>
                          <option v-for="wallet in wallets" :key="wallet.id" :value="wallet.id">
                            {{ wallet.name }} ({{ wallet.network?.name }})
                          </option>
                        </select>
                      </div>


              <!-- Import Button -->
              <button
                @click="importFromCSV"
                :disabled="!csvForm.file || csvImporting"
                class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span v-if="csvImporting" class="flex items-center">
                  <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  Importando...
                </span>
                <span v-else>
                  <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
                  </svg>
                  Importar CSV
                </span>
              </button>

            </div>
          </div>





        </div>
      </div>

      <!-- CSV Preview -->
      <div v-if="csvPreview.length > 0" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Prévia do Arquivo CSV</h3>
            <p class="mt-1 text-sm text-gray-500">
              Primeiras {{ csvPreview.length }} linhas do arquivo
            </p>
          </div>
          <div class="px-6 py-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th v-for="(header, index) in csvHeaders" :key="index" class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {{ header }}
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="(row, rowIndex) in csvPreview" :key="rowIndex">
                  <td v-for="(cell, cellIndex) in row" :key="cellIndex" class="px-3 py-2 whitespace-nowrap text-sm text-gray-900">
                    {{ cell }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Import History -->
      <div v-if="importHistory && importHistory.length > 0" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="bg-white shadow rounded-lg">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Histórico de Importações</h3>
          </div>
          <div class="px-6 py-4">
            <div class="space-y-3">
              <div v-for="import_record in importHistory" :key="import_record.id" class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                <div>
                  <p class="text-sm font-medium text-gray-900">
                    {{ import_record.source }} - {{ import_record.transactions_count }} transações
                  </p>
                  <p class="text-sm text-gray-500">
                    {{ formatDate(import_record.created_at) }}
                  </p>
                </div>
                <div class="flex items-center space-x-2">
                  <span :class="getStatusClass(import_record.status)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    {{ getStatusLabel(import_record.status) }}
                  </span>
                  <button
                    v-if="import_record.status === 'completed'"
                    @click="viewImportDetails(import_record.id)"
                    class="text-primary hover:text-primary-dark"
                  >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
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
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'

// Props
const props = defineProps({
  availableKeys: {
    type: Array,
    default: () => []
  },
  importHistory: {
    type: Array,
    default: () => []
  },
  exchanges: {
    type: Array,
    default: () => [],
  },
  userApiKeys: {
    type: Array,
    default: () => [],
  },
  wallets: {
  type: Array,
  default: () => [],
}


})



const availableKeys = computed(() =>
  props.userApiKeys.map(key => ({
    id: key.id,
    name: key.name,
    exchange: key.exchange.name
  }))
)




// Reactive data
const exchangeImporting = ref(false)
const csvImporting = ref(false)
const csvPreview = ref([])
const csvHeaders = ref([])
const csvFileInput = ref(null)

// Forms
const exchangeForm = ref({
  exchange: '',
  api_key_id: '',
  start_date: '',
  end_date: '',
})

const csvForm = ref({
  file: null,
  format: 'standard',
  skip_duplicates: true,
  column_mapping: {},

  // 👇 Adicionados para suportar morph
  source_type: '',
  source_id: '',
})

// CSV Fields for custom mapping
const csvFields = [
  { key: 'date', label: 'Data' },
  { key: 'type', label: 'Tipo' },
  { key: 'crypto_asset', label: 'Criptomoeda' },
  { key: 'quantity', label: 'Quantidade' },
  { key: 'unit_price', label: 'Preço Unitário' },
  { key: 'total_amount', label: 'Valor Total' },
  { key: 'fees', label: 'Taxas' },
]



// Methods
const loadExchangeKeys = () => {
   console.log('Exchange selecionada:', exchangeForm.exchange)
  exchangeForm.value.api_key_id = ''
}

const importFromExchange = async () => {
  exchangeImporting.value = true
  
  try {
  await axios.post(`/transactions/import/${exchangeForm.value.exchange}`, {
      api_key_id: exchangeForm.value.api_key_id,
      start_date: exchangeForm.value.start_date,
      end_date: exchangeForm.value.end_date,
    })

    // Limpa o formulário após o sucesso
    exchangeForm.value = {
      exchange: '',
      api_key_id: '',
      start_date: '',
      end_date: '',
    }

  } catch (error) {
    console.error('Import failed:', error.response?.data || error)
  } finally {
    exchangeImporting.value = false
  }
}

const handleFileUpload = (event) => {
  const file = event.target.files[0]
  if (file) {
    csvForm.value.file = file
    parseCSVPreview(file)
  }
}

const parseCSVPreview = (file) => {
  const reader = new FileReader()
  reader.onload = (e) => {
    const text = e.target.result
    const lines = text.split('\n').slice(0, 6) // First 5 lines + header
    const rows = lines.map(line => line.split(',').map(cell => cell.trim().replace(/"/g, '')))
    
    csvHeaders.value = rows[0] || []
    csvPreview.value = rows.slice(1).filter(row => row.some(cell => cell.length > 0))
  }
  reader.readAsText(file)
}

const importFromCSV = async () => {
  csvImporting.value = true
  
  const formData = new FormData()
  formData.append('file', csvForm.value.file)
  formData.append('format', csvForm.value.format)
  formData.append('skip_duplicates', csvForm.value.skip_duplicates)
  formData.append('source_type', csvForm.value.source_type)
  formData.append('source_id', csvForm.value.source_id)


  if (csvForm.value.format === 'custom') {
    formData.append('column_mapping', JSON.stringify(csvForm.value.column_mapping))
  }
  
  try {
    await router.post('/transactions/import/csv', formData, {
      onSuccess: (page) => {
        // Handle success
        csvForm.value = {
          file: null,
          format: 'standard',
          skip_duplicates: true,
          column_mapping: {},
           source_type: '',
            source_id: '',
        }
        csvPreview.value = []
        csvHeaders.value = []
        if (csvFileInput.value) {
          csvFileInput.value.value = ''
        }
      },
      onError: (errors) => {
        // Handle errors
        console.error('Import failed:', errors)
      }
    })
  } finally {
    csvImporting.value = false
  }
}

const viewImportDetails = (importId) => {
  router.visit(`/transactions/import/${importId}`)
}

// Helper functions
const getStatusLabel = (status) => {
  const labels = {
    pending: 'Pendente',
    processing: 'Processando',
    completed: 'Concluído',
    failed: 'Falhou'
  }
  return labels[status] || status
}

const getStatusClass = (status) => {
  const classes = {
    pending: 'bg-yellow-100 text-yellow-800',
    processing: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800'
  }
  return classes[status] || 'bg-gray-100 text-gray-800'
}

const formatDate = (date) => {
  return new Intl.DateTimeFormat('pt-BR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(new Date(date))
}

const walletForm = ref({
  wallet_id: '',
  start_date: '',
  end_date: '',
})



// Lifecycle
onMounted(() => {
  // Set default date range (last 30 days)
  const endDate = new Date()
  const startDate = new Date()
  startDate.setDate(startDate.getDate() - 30)
  
  exchangeForm.value.end_date = endDate.toISOString().split('T')[0]
  exchangeForm.value.start_date = startDate.toISOString().split('T')[0]
})
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

.focus\\:border-primary:focus {
  border-color: var(--primary-color, #3b82f6);
}
</style>

