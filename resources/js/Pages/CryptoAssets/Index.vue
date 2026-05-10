<template>
  <AppLayout>
    
      <h2 class="text-xl font-semibold text-gray-800 dark:text-white">
        Pares de Trading Cadastrados
      </h2>
    
    <!-- Conteúdo principal -->
    <div class="max-w-6xl mx-auto px-4">

      <!-- Campo de pesquisa -->
      <div class="mb-4">
        <input 
          v-model="searchQuery" 
          type="text" 
          placeholder="Buscar pares (ex: BTCUSDT, BTC, USDT)..." 
          class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
        />
      </div>

      <!-- Botões de importação por Exchange -->
      <div class="mb-6 flex space-x-4"> 
        <button
          v-for="exchange in exchanges"
          :key="exchange.name"
          @click="importCrypto(exchange.name)"
          :disabled="loading"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg shadow-md hover:bg-blue-700 disabled:opacity-50"
        >
          {{ loading && currentExchange === exchange.name ? 'Importando...' : `Importar da ${exchange.label}` }}
        </button>
      </div>

      <!-- Status da importação -->
      <p v-if="statusMessage" class="mt-4 text-sm text-gray-600">{{ statusMessage }}</p>

      <!-- Tabela de pares -->
      <table class="min-w-full bg-white border border-gray-300 mt-4">
        <thead>
          <tr>
            <th class="border border-gray-300 px-4 py-2">Par</th>
            <th class="border border-gray-300 px-4 py-2">Base</th>
            <th class="border border-gray-300 px-4 py-2">Quote</th>
            <th class="border border-gray-300 px-4 py-2">Status</th>
          </tr>
        </thead>
        <tbody v-if="paginatedCryptoAssets.length > 0">
          <tr v-for="pair in paginatedCryptoAssets" :key="pair.id">
            <td class="border border-gray-300 px-4 py-2">{{ pair.symbol }}</td>
            <td class="border border-gray-300 px-4 py-2">{{ pair.base_asset }}</td>
            <td class="border border-gray-300 px-4 py-2">{{ pair.quote_asset }}</td>
            <td class="border border-gray-300 px-4 py-2">{{ pair.status }}</td>
          </tr>
        </tbody>
        <tbody v-else>
          <tr>
            <td colspan="4" class="text-center text-gray-500 py-4">Nenhum par encontrado.</td>
          </tr>
        </tbody>
      </table>

      <!-- Controles de paginação -->
      <div class="mt-4 flex justify-between items-center">
        <button 
          @click="prevPage" 
          :disabled="currentPage === 1" 
          class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg shadow-md disabled:opacity-50"
        >
          Anterior
        </button>
        <p>Página {{ currentPage }} de {{ totalPages }}</p>
        <button 
          @click="nextPage" 
          :disabled="currentPage === totalPages" 
          class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg shadow-md disabled:opacity-50"
        >
          Próxima
        </button>
      </div>

    </div>
  </AppLayout>
</template>



<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

// Lista de exchanges disponíveis
const exchanges = ref([
  { name: 'binance', label: 'Binance' },
  { name: 'binance_smart_chain', label: 'Binance Smart Chain' },
  { name: 'coinbase', label: 'Coinbase' },
  { name: 'kraken', label: 'Kraken' },
]);

//const cryptoAssets = ref([]);
const loading = ref(false);
const statusMessage = ref('');
const currentExchange = ref('');
const searchQuery = ref('');
const currentPage = ref(1);
const itemsPerPage = 10;  // Número de itens por página

// Função para buscar criptoativos do backend
const fetchCryptoAssets = async () => {
  try {
    statusMessage.value = 'Carregando pares...';
    const response = await axios.get('/crypto-assets/all'); // Ex: nova rota que retorna todos
    cryptoAssets.value = response.data;
    allAssetsLoaded.value = true;
    statusMessage.value = '';
  } catch (error) {
    console.error('Erro ao buscar criptoativos:', error);
    statusMessage.value = 'Erro ao carregar pares.';
  }
};


// Função para importar moedas de uma exchange específica
const importCrypto = async (exchangeName) => {
  console.log(`Iniciando importação de: ${exchangeName}`);
  loading.value = true;
  currentExchange.value = exchangeName;
  statusMessage.value = `Importando pares da ${exchangeName}...`;

  try {
    const response = await axios.post(`/import-crypto/${exchangeName}`);

    console.log(`Resposta da API (${exchangeName}):`, response.data);
    statusMessage.value = response.data.message;

    await fetchCryptoAssets();
  } catch (error) {
    console.error(`Erro ao importar moedas de ${exchangeName}:`, error);
    statusMessage.value = `Erro ao importar pares de ${exchangeName}.`;
    console.log('Detalhes do erro:', error.response ? error.response.data : error.message);
  } finally {
    loading.value = false;
    currentExchange.value = '';
  }
};




// Paginação: Filtra os pares com base na pesquisa
const filteredCryptoAssets = computed(() => {
  return cryptoAssets.value.filter(pair =>
    String(pair.symbol || '').toLowerCase().includes(searchQuery.value.toLowerCase()) ||
    String(pair.base_asset || '').toLowerCase().includes(searchQuery.value.toLowerCase()) ||
    String(pair.quote_asset || '').toLowerCase().includes(searchQuery.value.toLowerCase())
  );
});

// Calcular o número total de páginas
const totalPages = computed(() => {
  return Math.ceil(filteredCryptoAssets.value.length / itemsPerPage);
});

// Paginação: Retorna os criptoativos para a página atual
const paginatedCryptoAssets = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage;
  const end = start + itemsPerPage;
  return filteredCryptoAssets.value.slice(start, end);
});

// Mudar para a próxima página
const nextPage = () => {
  if (currentPage.value < totalPages.value) {
    currentPage.value++;
  }
};

// Mudar para a página anterior
const prevPage = () => {
  if (currentPage.value > 1) {
    currentPage.value--;
  }
};

// Buscar criptoativos ao carregar a página
onMounted(() => {
  fetchCryptoAssets();
});


const props = defineProps({
  cryptoAssets: {
    type: Object,
    required: true
  }
});

const cryptoAssets = ref(props.cryptoAssets.data ?? []);
const allAssetsLoaded = ref(false);




</script>
