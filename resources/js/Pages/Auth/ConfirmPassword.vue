<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      
      <!-- Header -->
      <div>
        <div class="mx-auto h-12 w-auto flex justify-center">
          <h1 class="text-3xl font-bold text-primary">Crypto Tax Manager</h1>
        </div>
        <h2 class="mt-6 text-center text-3xl font-bold text-gray-900">
          Confirme sua senha
        </h2>
        <p class="mt-2 text-center text-sm text-gray-600">
          Esta é uma área segura. Por favor, confirme sua senha antes de continuar.
        </p>
      </div>

      <!-- Security Icon -->
      <div class="flex justify-center">
        <div class="bg-yellow-100 rounded-full p-6">
          <svg class="h-16 w-16 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
          </svg>
        </div>
      </div>

      <!-- Form -->
      <form class="mt-8 space-y-6" @submit.prevent="submit">
        
        <!-- Password -->
        <div>
          <label for="password" class="sr-only">Senha atual</label>
          <input
            id="password"
            v-model="form.password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            autofocus
            class="relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary focus:border-primary focus:z-10 sm:text-sm"
            placeholder="Digite sua senha atual"
          />
          <div v-if="form.errors.password" class="mt-1 text-sm text-red-600">
            {{ form.errors.password }}
          </div>
        </div>

        <!-- Submit Button -->
        <div>
          <button
            type="submit"
            :disabled="form.processing"
            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span v-if="form.processing" class="absolute left-0 inset-y-0 flex items-center pl-3">
              <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            </span>
            {{ form.processing ? 'Confirmando...' : 'Confirmar senha' }}
          </button>
        </div>

        <!-- Cancel Button -->
        <div>
          <button
            type="button"
            @click="goBack"
            class="w-full flex justify-center py-2 px-4 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
          >
            Cancelar
          </button>
        </div>

      </form>

      <!-- Security Info -->
      <div class="bg-blue-50 border border-blue-200 rounded-md p-4">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3">
            <h3 class="text-sm font-medium text-blue-800">
              Por que confirmar a senha?
            </h3>
            <div class="mt-2 text-sm text-blue-700">
              <p>
                Você está tentando acessar uma área sensível que contém informações financeiras importantes.
                A confirmação da senha é uma camada extra de segurança para proteger seus dados.
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Forgot Password Link -->
      <div class="text-center">
        <Link href="/forgot-password" class="text-sm font-medium text-primary hover:text-primary-dark">
          Esqueceu sua senha?
        </Link>
      </div>

    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import { Link } from '@inertiajs/vue3'

// Form
const form = useForm({
  password: '',
})

// Methods
const submit = () => {
  form.post('/confirm-password', {
    onFinish: () => form.reset(),
  })
}

const goBack = () => {
  window.history.back()
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

.hover\\:text-primary-dark:hover {
  color: var(--primary-dark, #2563eb);
}

.focus\\:ring-primary:focus {
  --tw-ring-color: var(--primary-color, #3b82f6);
}

.focus\\:border-primary:focus {
  border-color: var(--primary-color, #3b82f6);
}
</style>

