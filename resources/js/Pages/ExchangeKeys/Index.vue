<template>
  <AppLayout title="Chaves de API">
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="mb-8">
          <div class="flex items-center justify-between">
            <div>
              <h1 class="text-3xl font-bold text-gray-900">
                Cadastro de Contas
              </h1>
              <p class="mt-2 text-gray-600">
                Gerencie suas chaves de API e carteiras para sincronização automática
              </p>
            </div>
            <button 
              @click="showAddModal = true"
              class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center space-x-2 transition duration-150"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
              </svg>
              <span>Adicionar Conta</span>
            </button>
          </div>
        </div>

        <!-- Status Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center">
                  <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                  </svg>
                </div>
              </div>
              <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Exchanges Conectadas</p>
                <p class="text-2xl font-semibold text-gray-900">{{ exchangeKeys.length }}</p>
              </div>
            </div>
          </div>

          <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center">
                  <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                  </svg>
                </div>
              </div>
              <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Carteiras Cadastradas</p>
                <p class="text-2xl font-semibold text-gray-900">{{ wallets.length }}</p>
              </div>
            </div>
          </div>

          <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <div class="w-8 h-8 bg-yellow-500 rounded-full flex items-center justify-center">
                  <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                </div>
              </div>
              <div class="ml-4">
                <p class="text-sm font-medium text-gray-500">Total de Contas</p>
                <p class="text-2xl font-semibold text-gray-900">{{ exchangeKeys.length + wallets.length }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Lista de Contas -->
        <div class="bg-white shadow-xl sm:rounded-lg overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">
              Suas Contas
            </h3>
          </div>
          
          <div v-if="exchangeKeys.length === 0 && wallets.length === 0" class="p-12 text-center">
            <div class="w-16 h-16 mx-auto mb-4 text-gray-400">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
              </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">
              Nenhuma conta cadastrada
            </h3>
            <p class="text-gray-500 mb-6">
              Adicione suas exchanges e carteiras para começar a sincronizar transações automaticamente.
            </p>
            <button 
              @click="showAddModal = true"
              class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg transition duration-150"
            >
              Adicionar Primeira Conta
            </button>
          </div>

          <div v-else class="divide-y divide-gray-200">
            <!-- Exchanges -->
            <div 
              v-for="exchange in exchangeKeys" 
              :key="'exchange-' + exchange.id"
              class="px-6 py-4 hover:bg-gray-50"
            >
              <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                  <div class="flex-shrink-0">
                    <img 
                      :src="getExchangeIcon(exchange.exchange_name)" 
                      :alt="exchange.exchange_name"
                      class="w-12 h-12 rounded-full bg-white p-2 shadow-md"
                    >
                  </div>
                  <div>
                    <h4 class="text-lg font-medium text-gray-900 capitalize">{{ exchange.exchange_name }}</h4>
                   <p class="text-sm text-gray-500">
                      Exchange • API Key: {{ maskApiKey(exchange.api_key) }}
                    </p>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 mt-1">
                      {{ exchange?.exchange_name ?? 'Não informada' }}
                    </span>
                  </div>
                </div>
                
                <div class="flex items-center space-x-2">
                  <button 
                    @click="testConnection(exchange)"
                    class="text-blue-600 hover:text-blue-800 p-2 rounded-lg hover:bg-blue-50 transition duration-150"
                    title="Testar Conexão"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                  </button>
                  
                  <button 
                    @click="editAccount(exchange, 'exchange')"
                    class="text-gray-600 hover:text-gray-800 p-2 rounded-lg hover:bg-gray-50 transition duration-150"
                    title="Editar"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </button>
                  
                  <button 
                    @click="deleteKey(exchange.id, 'exchange')"
                    class="text-red-600 hover:text-red-800 p-2 rounded-lg hover:bg-red-50 transition duration-150"
                    title="Excluir"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </div>
              </div>
            </div>

            <!-- Carteiras -->
            <div 
              v-for="wallet in wallets" 
              :key="'wallet-' + wallet.id"
              class="px-6 py-4 hover:bg-gray-50"
            >
              <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                  <div class="flex-shrink-0">
                    <div class="w-12 h-12 bg-green-500 rounded-full flex items-center justify-center">
                      <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                      </svg>
                    </div>
                  </div>
                  <div>
                    <h4 class="text-lg font-medium text-gray-900">{{ wallet.name }}</h4>
                    <p class="text-sm text-gray-500">
                      {{ getNetworkNameById(wallet.network_id) }} • {{ maskAddress(wallet.address) }}
                    </p>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 mt-1">
                      Carteira
                    </span>
                  </div>
                </div>
                
                <div class="flex items-center space-x-2">
                  <button 
                    @click="editAccount(wallet, 'wallet')"
                    class="text-gray-600 hover:text-gray-800 p-2 rounded-lg hover:bg-gray-50 transition duration-150"
                    title="Editar"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </button>
                  
                  <button 
                    @click="deleteKey(wallet.id, 'wallet')"
                    class="text-red-600 hover:text-red-800 p-2 rounded-lg hover:bg-red-50 transition duration-150"
                    title="Excluir"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- Modal para adicionar/editar conta -->
    <div v-if="showAddModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <h3 class="text-lg font-medium text-gray-900 mb-4">
            {{ editingAccount ? 'Editar Conta' : 'Adicionar Nova Conta' }}
          </h3>
          
          <form @submit.prevent="submit" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Conta</label>
              <select 
                v-model="form.type" 
                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                required
                :disabled="editingAccount"
              >
                <option value="">Selecione uma opção</option>
                <option value="exchange">Exchange</option>
                <option value="wallet">Carteira</option>
              </select>
            </div>

            <!-- Campos para Exchange -->
            <div v-if="form.type === 'exchange'">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Exchange</label>
              <select 
                    v-model="form.exchange_name" 
                    class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    required
                  >
                    <option value="">Selecione uma exchange</option>
                    <option 
                      v-for="exchange in exchanges" 
                      :key="exchange.id" 
                      :value="exchange.name"
                    >
                      {{ exchange.name }}
                    </option>
                  </select>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">API Key</label>
                <input 
                  v-model="form.api_key" 
                  type="text" 
                  autocomplete="off"
                  class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  placeholder="Sua API Key"
                  required
                >
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Secret Key</label>
                <input 
                  v-model="form.api_secret" 
                  type="password" 
                  autocomplete="off"
                  class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  placeholder="Sua Secret Key"
                  required
                >
              </div>
            </div>

            <!-- Campos para Carteira -->
            <div v-if="form.type === 'wallet'">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Nome da Carteira</label>
                <input 
                  v-model="form.wallet_name" 
                  type="text" 
                  autocomplete="off"
                  class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  placeholder="Ex: Minha Carteira Principal"
                  required
                >
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Rede</label>
                 <select 
                    v-model="form.network_id" 
                    class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    required
                  >
                    <option value="">Selecione uma rede</option>
                    <option v-for="network in networks" :key="network.id" :value="network.id">
                      {{ network.name }}
                    </option>
                  </select>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Endereço</label>
                <input 
                  v-model="form.wallet_address" 
                  type="text" 
                  autocomplete="off"
                  class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  placeholder="Endereço da carteira"
                  required
                >
              </div>
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4">
              <button 
                type="button"
                @click="closeModal"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md transition duration-150"
              >
                Cancelar
              </button>
              <button 
                type="submit"
                class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-md transition duration-150"
              >
                {{ editingAccount ? 'Atualizar' : 'Adicionar' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Key } from 'lucide-react'

// Props recebidas do backend
const { exchangeKeys, wallets, exchanges, networks} = usePage().props

// Estado reativo
const showAddModal = ref(false)
const editingAccount = ref(null)
const form = ref({
  type: '',
  exchange_name: '',
  api_key: '',
  api_secret: '',
  wallet_name: '',
  network_id: '',
  wallet_address: '',
})

// Métodos auxiliares
const maskApiKey = (key) => {
  if (!key || typeof key !== 'string') return '****';
  return key.slice(0, 6) + ' **** ' + key.slice(-4);
}

const maskAddress = (address) => {
  if (!address) return ''
  return address.slice(0, 6) + '...' + address.slice(-4)
}



const getExchangeIcon = (exchange) => {
  const icons = {
    binance: 'https://cryptologos.cc/logos/binance-coin-bnb-logo.png',
    coinbase: 'https://cryptologos.cc/logos/coinbase-logo.png',
    kraken: 'https://cryptologos.cc/logos/kraken-logo.png',
    kucoin: 'https://cryptologos.cc/logos/kucoin-logo.png',
    bitfinex: 'https://cryptologos.cc/logos/bitfinex-logo.png',
  }
  return icons[exchange] || 'https://via.placeholder.com/48x48?text=' + exchange.charAt(0).toUpperCase()
}

const getNetworkNameById = (id) => {
  const network = networks.find((n) => n.id === id)
  return network ? network.name : 'Desconhecida'
}

const testConnection = (exchange) => {
  alert(`Testando conexão com ${exchange.exchange_name}...`)
  // Implementar teste de conexão real
}

const editAccount = (account, type) => {
  editingAccount.value = account
  
  if (type === 'exchange') {
    form.value = {
      type: 'exchange',
      exchange_name: account.exchange_name,
      api_key: account.api_key,
      api_secret: '••••••••••••••••', // Mascarar por segurança
      wallet_name: '',
      network: '',
      wallet_address: '',
    }
  } else {
    form.value = {
      type: 'wallet',
      exchange_name: '',
      api_key: '',
      api_secret: '',
      wallet_name: account.name,
      network: account.network,
      wallet_address: account.address,
    }
  }
  
  showAddModal.value = true
}

const deleteKey = (id, type) => {
  const itemName = type === 'exchange' ? 'exchange' : 'carteira'
  if (confirm(`Tem certeza que deseja excluir esta ${itemName}?`)) {
    router.delete(route('exchanges.keys.destroy', { id, type }))
  }
}

const submit = () => {
  if (editingAccount.value) {
    // Atualizar conta existente
    const routeName = editingAccount.value.exchange_name ? 'exchanges.keys.update' : 
    router.put(route(routeName, editingAccount.value.id), form.value, {
      onSuccess: () => {
        closeModal()
        alert('Conta atualizada com sucesso!')
      },
      onError: (errors) => {
        console.error('Erro ao atualizar:', errors)
      }
    })
  } else {
    // Criar nova conta
    router.post(route('exchanges.keys.store'), form.value, {
      onSuccess: () => {
        closeModal()
        alert('Cadastro realizado com sucesso!')
      },
      onError: (errors) => {
        console.error('Erro ao cadastrar:', errors)
      }
    })
  }
}



const closeModal = () => {
  showAddModal.value = false
  editingAccount.value = null
  form.value = {
    type: '',
    exchange_name: '',
    api_key: '',
    api_secret: '',
    wallet_name: '',
    network: '',
    wallet_address: '',
  }
}
</script>

