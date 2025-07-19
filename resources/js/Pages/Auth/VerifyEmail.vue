<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      
      <!-- Header -->
      <div>
        <div class="mx-auto h-12 w-auto flex justify-center">
          <h1 class="text-3xl font-bold text-primary">Crypto Tax Manager</h1>
        </div>
        <h2 class="mt-6 text-center text-3xl font-bold text-gray-900">
          Verifique seu email
        </h2>
        <p class="mt-2 text-center text-sm text-gray-600">
          Enviamos um link de verificação para seu endereço de email.
        </p>
      </div>

      <!-- Email Icon -->
      <div class="flex justify-center">
        <div class="bg-blue-100 rounded-full p-6">
          <svg class="h-16 w-16 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
          </svg>
        </div>
      </div>

      <!-- Success Message -->
      <div v-if="status === 'verification-link-sent'" class="rounded-md bg-green-50 p-4">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3">
            <p class="text-sm font-medium text-green-800">
              Um novo link de verificação foi enviado para seu email!
            </p>
          </div>
        </div>
      </div>

      <!-- Instructions -->
      <div class="text-center space-y-4">
        <p class="text-sm text-gray-600">
          Clique no link no email para verificar sua conta e começar a usar o Crypto Tax Manager.
        </p>
        
        <div class="bg-blue-50 border border-blue-200 rounded-md p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="ml-3">
              <p class="text-sm text-blue-700">
                <strong>Não recebeu o email?</strong> Verifique sua caixa de spam ou lixo eletrônico.
                O email pode levar alguns minutos para chegar.
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="space-y-4">
        
        <!-- Resend Button -->
        <form @submit.prevent="resend">
          <button
            type="submit"
            :disabled="form.processing"
            class="w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span v-if="form.processing" class="absolute left-0 inset-y-0 flex items-center pl-3">
              <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            </span>
            {{ form.processing ? 'Enviando...' : 'Reenviar email de verificação' }}
          </button>
        </form>

        <!-- Logout Button -->
        <form @submit.prevent="logout">
          <button
            type="submit"
            class="w-full flex justify-center py-2 px-4 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
          >
            Sair e usar outro email
          </button>
        </form>

      </div>

      <!-- Help -->
      <div class="text-center">
        <p class="text-xs text-gray-500">
          Problemas com a verificação?
          <a href="mailto:suporte@cryptotaxmanager.com" class="text-primary hover:text-primary-dark">
            Entre em contato conosco
          </a>
        </p>
      </div>

      <!-- Timer -->
      <div v-if="countdown > 0" class="text-center">
        <p class="text-sm text-gray-500">
          Você pode solicitar um novo email em {{ countdown }} segundos
        </p>
      </div>

    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import { ref, onMounted, onUnmounted } from 'vue'

// Props
defineProps({
  status: String,
})

// Form
const form = useForm({})

// Countdown timer
const countdown = ref(0)
let timer = null

// Methods
const resend = () => {
  if (countdown.value > 0) return
  
  form.post('/email/verification-notification', {
    onSuccess: () => {
      startCountdown()
    }
  })
}

const logout = () => {
  form.post('/logout')
}

const startCountdown = () => {
  countdown.value = 60
  timer = setInterval(() => {
    countdown.value--
    if (countdown.value <= 0) {
      clearInterval(timer)
    }
  }, 1000)
}

// Lifecycle
onMounted(() => {
  startCountdown()
})

onUnmounted(() => {
  if (timer) {
    clearInterval(timer)
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

.hover\\:text-primary-dark:hover {
  color: var(--primary-dark, #2563eb);
}

.focus\\:ring-primary:focus {
  --tw-ring-color: var(--primary-color, #3b82f6);
}
</style>

