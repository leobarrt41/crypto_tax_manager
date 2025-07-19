<template>
  <div class="min-h-screen bg-gradient-to-br from-blue-900 via-purple-900 to-indigo-900">
    <div class="relative overflow-hidden">
      
      <!-- Header -->
      <header class="relative z-10">
        <nav class="flex items-center justify-between p-6 lg:px-8">
          <div class="flex lg:flex-1">
            <Link href="/" class="-m-1.5 p-1.5">
              <span class="text-2xl font-bold text-white">Crypto Tax Manager</span>
            </Link>
          </div>
          
          <div class="flex lg:flex-1 lg:justify-end space-x-4">
         <Link 
              v-if="$page.props.auth && !$page.props.auth.user"
              href="/login"
              class="text-sm font-semibold leading-6 text-white hover:text-blue-200 transition-colors"
            >
              Entrar
            </Link>

            <Link 
              v-if="$page.props.auth && !$page.props.auth.user"
              href="/register"
              class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700 transition-colors"
            >
              Registrar-se
            </Link>

            <Link 
              v-if="$page.props.auth && $page.props.auth.user"
              href="/dashboard"
              class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-green-700 transition-colors"
            >
              Dashboard
            </Link>

          </div>
        </nav>
      </header>

      <!-- Hero Section -->
      <div class="relative isolate px-6 pt-14 lg:px-8">
        <div class="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl sm:-top-80">
          <div class="relative left-[calc(50%-11rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 rotate-[30deg] bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-30 sm:left-[calc(50%-30rem)] sm:w-[72.1875rem]"></div>
        </div>
        
        <div class="mx-auto max-w-4xl py-32 sm:py-48 lg:py-56">
          <div class="text-center">
            <h1 class="text-4xl font-bold tracking-tight text-white sm:text-6xl">
              Gerencie suas <span class="text-blue-400">Criptomoedas</span> com Inteligência Fiscal
            </h1>
            <p class="mt-6 text-lg leading-8 text-gray-300">
              A solução completa para controlar seu portfolio de criptomoedas, calcular impostos automaticamente e manter-se em compliance com a Receita Federal brasileira.
            </p>
            
            <div class="mt-10 flex items-center justify-center gap-x-6">
              <Link 
                  v-if="!user"
                  href="/register" 
                  class="rounded-md bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-all"
                >
                  Começar Agora
                </Link>

              <Link 
                v-if="$page.props.auth.user"
                href="/dashboard" 
                class="rounded-md bg-green-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-green-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 transition-all"
              >
                Ir para Dashboard
              </Link>
              <Link 
                href="#features" 
                class="text-sm font-semibold leading-6 text-white hover:text-blue-200 transition-colors"
              >
                Saiba mais <span aria-hidden="true">→</span>
              </Link>
            </div>
          </div>
        </div>
        
        <div class="absolute inset-x-0 top-[calc(100%-13rem)] -z-10 transform-gpu overflow-hidden blur-3xl sm:top-[calc(100%-30rem)]">
          <div class="relative left-[calc(50%+3rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-30 sm:left-[calc(50%+36rem)] sm:w-[72.1875rem]"></div>
        </div>
      </div>
    </div>

    <!-- Features Section -->
    <div id="features" class="py-24 sm:py-32 bg-white">
      <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <div class="mx-auto max-w-2xl lg:text-center">
          <h2 class="text-base font-semibold leading-7 text-blue-600">Funcionalidades Completas</h2>
          <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
            Tudo que você precisa para gerenciar suas criptomoedas
          </p>
          <p class="mt-6 text-lg leading-8 text-gray-600">
            Nossa plataforma oferece todas as ferramentas necessárias para controlar, analisar e declarar suas operações com criptomoedas de forma simples e eficiente.
          </p>
        </div>
        
        <div class="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-none">
          <dl class="grid max-w-xl grid-cols-1 gap-x-8 gap-y-16 lg:max-w-none lg:grid-cols-3">
            
            <!-- Feature 1 -->
            <div class="flex flex-col">
              <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-gray-900">
                <div class="h-10 w-10 flex items-center justify-center rounded-lg bg-blue-600">
                  <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                  </svg>
                </div>
                Dashboard Inteligente
              </dt>
              <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-gray-600">
                <p class="flex-auto">Visualize seu portfolio em tempo real com gráficos interativos, métricas de performance e análises detalhadas de seus investimentos.</p>
              </dd>
            </div>

            <!-- Feature 2 -->
            <div class="flex flex-col">
              <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-gray-900">
                <div class="h-10 w-10 flex items-center justify-center rounded-lg bg-blue-600">
                  <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                  </svg>
                </div>
                IN 1888 Automática
              </dt>
              <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-gray-600">
                <p class="flex-auto">Geração automática do arquivo IN 1888 para declaração à Receita Federal, garantindo compliance total com a legislação brasileira.</p>
              </dd>
            </div>

            <!-- Feature 3 -->
            <div class="flex flex-col">
              <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-gray-900">
                <div class="h-10 w-10 flex items-center justify-center rounded-lg bg-blue-600">
                  <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0015 0m-15 0a7.5 7.5 0 1115 0m-15 0H3m16.5 0H21m-1.5 0H12m-8.457 3.077l1.41-.513m14.095-5.13l1.41-.513M5.106 17.785l1.15-.964m11.49-9.642l1.149-.964M7.501 19.795l.75-1.3m7.5-12.99l.75-1.3m-6.063 16.658l.26-1.477m2.605-14.772l.26-1.477m0 17.726l-.26-1.477M10.698 4.614l-.26-1.477M16.5 19.794l-.75-1.299M7.5 4.205L12 12m6.894 5.785l-1.149-.964M6.256 7.178l-1.15-.964m15.352 8.864l-1.41-.513M4.954 9.435l-1.41-.514M12.002 12l-3.75 6.495" />
                  </svg>
                </div>
                Trading Bot Inteligente
              </dt>
              <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-gray-600">
                <p class="flex-auto">Automatize suas estratégias de trading com nosso bot inteligente, incluindo DCA, Grid Trading e backtesting avançado.</p>
              </dd>
            </div>

            <!-- Feature 4 -->
            <div class="flex flex-col">
              <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-gray-900">
                <div class="h-10 w-10 flex items-center justify-center rounded-lg bg-blue-600">
                  <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 002.25-2.25V6a2.25 2.25 0 00-2.25-2.25H6A2.25 2.25 0 003.75 6v2.25A2.25 2.25 0 006 10.5zm0 9.75h2.25A2.25 2.25 0 0010.5 18v-2.25a2.25 2.25 0 00-2.25-2.25H6a2.25 2.25 0 00-2.25 2.25V18A2.25 2.25 0 006 20.25zm9.75-9.75H18a2.25 2.25 0 002.25-2.25V6A2.25 2.25 0 0018 3.75h-2.25A2.25 2.25 0 0013.5 6v2.25a2.25 2.25 0 002.25 2.25z" />
                  </svg>
                </div>
                Múltiplas Exchanges
              </dt>
              <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-gray-600">
                <p class="flex-auto">Integração com Binance, Coinbase, Kraken e Mercado Bitcoin. Importe suas transações automaticamente de todas as exchanges.</p>
              </dd>
            </div>

            <!-- Feature 5 -->
            <div class="flex flex-col">
              <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-gray-900">
                <div class="h-10 w-10 flex items-center justify-center rounded-lg bg-blue-600">
                  <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.623 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                  </svg>
                </div>
                Segurança Total
              </dt>
              <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-gray-600">
                <p class="flex-auto">Suas chaves de API são criptografadas e armazenadas com segurança. Nunca armazenamos senhas ou chaves privadas de carteiras.</p>
              </dd>
            </div>

            <!-- Feature 6 -->
            <div class="flex flex-col">
              <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-gray-900">
                <div class="h-10 w-10 flex items-center justify-center rounded-lg bg-blue-600">
                  <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z" />
                  </svg>
                </div>
                Relatórios Detalhados
              </dt>
              <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-gray-600">
                <p class="flex-auto">Relatórios completos de ganhos de capital, FIFO, performance de portfolio e análises fiscais para facilitar sua declaração de IR.</p>
              </dd>
            </div>

          </dl>
        </div>
      </div>
    </div>

    <!-- CTA Section -->
    <div class="bg-blue-600">
      <div class="px-6 py-24 sm:px-6 sm:py-32 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
          <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            Pronto para começar?
          </h2>
          <p class="mx-auto mt-6 max-w-xl text-lg leading-8 text-blue-200">
            Junte-se a milhares de investidores que já usam nossa plataforma para gerenciar suas criptomoedas com segurança e compliance fiscal.
          </p>
          <div class="mt-10 flex items-center justify-center gap-x-6">
            <Link 
              v-if="!user"
              href="/register" 
              class="rounded-md bg-white px-6 py-3 text-sm font-semibold text-blue-600 shadow-sm hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white transition-all"
            >
              Criar Conta Gratuita
            </Link>
            <Link 
              v-if="user"
              href="/dashboard" 
              class="rounded-md bg-white px-6 py-3 text-sm font-semibold text-blue-600 shadow-sm hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white transition-all"
            >
              Acessar Dashboard
            </Link>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <footer class="bg-gray-900">
      <div class="mx-auto max-w-7xl px-6 py-12 md:flex md:items-center md:justify-between lg:px-8">
        <div class="flex justify-center space-x-6 md:order-2">
          <p class="text-xs leading-5 text-gray-400">
            &copy; 2025 Crypto Tax Manager. Todos os direitos reservados.
          </p>
        </div>
        <div class="mt-8 md:order-1 md:mt-0">
          <p class="text-center text-xs leading-5 text-gray-400">
            Desenvolvido para compliance com a Receita Federal brasileira
          </p>
        </div>
      </div>
    </footer>

  </div>
</template>

<script setup>
import { computed } from 'vue';

import { Link } from '@inertiajs/vue3'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const user = computed(() => page.props.auth?.user)
// Props podem ser passadas pelo controller se necessário
defineProps({
  // Adicione props aqui se necessário
})
</script>

