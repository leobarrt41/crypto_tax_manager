<template>
  <AppLayout title="Página não encontrada">
    <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div class="max-w-md w-full space-y-8 text-center">
        
        <!-- Ícone de erro -->
        <div>
          <div class="mx-auto h-32 w-32 text-gray-400">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9.172 16.172a4 4 0 015.656 0M9 12h6m-6-4h6m2 5.291A7.962 7.962 0 0112 15c-2.34 0-4.29-1.007-5.824-2.448M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
          </div>
        </div>

        <!-- Título e descrição -->
        <div>
          <h1 class="text-6xl font-bold text-gray-900 mb-4">404</h1>
          <h2 class="text-2xl font-semibold text-gray-700 mb-2">
            Página não encontrada
          </h2>
          <p class="text-gray-500 mb-8">
            A página que você está procurando não existe ou foi movida.
          </p>
        </div>

        <!-- Ações -->
        <div class="space-y-4">
          <Link 
            :href="safeRoute('dashboard')" 
            class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            Voltar ao Dashboard
          </Link>
          
          <button 
            @click="goBack"
            class="w-full flex justify-center py-3 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar à página anterior
          </button>
        </div>

        <!-- Links úteis -->
        <div class="pt-8 border-t border-gray-200">
          <p class="text-sm text-gray-500 mb-4">Links úteis:</p>
          <div class="flex flex-wrap justify-center gap-4 text-sm">
            <Link :href="safeRoute('transactions.index')" class="text-indigo-600 hover:text-indigo-500">
              Transações
            </Link>
            <Link :href="safeRoute('wallets.index')" class="text-indigo-600 hover:text-indigo-500">
              Carteiras
            </Link>
            <Link :href="safeRoute('portfolio.index')" class="text-indigo-600 hover:text-indigo-500">
              Portfólio
            </Link>
            <Link :href="safeRoute('reports.index')" class="text-indigo-600 hover:text-indigo-500">
              Relatórios
            </Link>
          </div>
        </div>

      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

// Função para voltar à página anterior
const goBack = () => {
  window.history.back()
}

// Função para verificar se uma rota existe de forma segura
const hasRoute = (routeName) => {
  try {
    if (typeof route !== 'function') {
      return false
    }
    
    if (typeof route().has === 'function') {
      return route().has(routeName)
    }
    
    const routes = route().routes || {}
    return routeName in routes
  } catch (error) {
    console.warn(`Erro ao verificar rota ${routeName}:`, error)
    return false
  }
}

// Função para obter uma rota de forma segura
const safeRoute = (routeName, params = {}) => {
  try {
    if (hasRoute(routeName)) {
      return route(routeName, params)
    }
    return '#'
  } catch (error) {
    console.warn(`Erro ao gerar rota ${routeName}:`, error)
    return '#'
  }
}
</script>

<style scoped>
/* Animações suaves */
.transition {
  transition-property: all;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 150ms;
}

/* Efeitos hover */
.hover\:bg-indigo-700:hover {
  background-color: #4338ca;
}

.hover\:bg-gray-50:hover {
  background-color: #f9fafb;
}

.hover\:text-indigo-500:hover {
  color: #6366f1;
}

/* Focus states */
.focus\:outline-none:focus {
  outline: 2px solid transparent;
  outline-offset: 2px;
}

.focus\:ring-2:focus {
  --tw-ring-offset-shadow: var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color);
  --tw-ring-shadow: var(--tw-ring-inset) 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color);
  box-shadow: var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow, 0 0 #0000);
}

.focus\:ring-offset-2:focus {
  --tw-ring-offset-width: 2px;
}

.focus\:ring-indigo-500:focus {
  --tw-ring-opacity: 1;
  --tw-ring-color: rgb(99 102 241 / var(--tw-ring-opacity));
}
</style>