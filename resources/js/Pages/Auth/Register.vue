<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      
      <!-- Header -->
      <div>
        <div class="mx-auto h-12 w-auto flex justify-center">
          <h1 class="text-3xl font-bold text-primary">Crypto Tax Manager</h1>
        </div>
        <h2 class="mt-6 text-center text-3xl font-bold text-gray-900">
          Crie sua conta
        </h2>
        <p class="mt-2 text-center text-sm text-gray-600">
          Ou
          <Link href="/login" class="font-medium text-primary hover:text-primary-dark">
            entre na sua conta existente
          </Link>
        </p>
      </div>

      <!-- Form -->
      <form class="mt-8 space-y-6" @submit.prevent="submit">
        
        <!-- Name -->
        <div>
          <label for="name" class="sr-only">Nome completo</label>
          <input
            id="name"
            v-model="form.name"
            name="name"
            type="text"
            autocomplete="name"
            required
            class="relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary focus:border-primary focus:z-10 sm:text-sm"
            placeholder="Nome completo"
          />
          <div v-if="form.errors.name" class="mt-1 text-sm text-red-600">
            {{ form.errors.name }}
          </div>
        </div>

        <!-- Email -->
        <div>
          <label for="email" class="sr-only">Email</label>
          <input
            id="email"
            v-model="form.email"
            name="email"
            type="email"
            autocomplete="email"
            required
            class="relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary focus:border-primary focus:z-10 sm:text-sm"
            placeholder="Endereço de email"
          />
          <div v-if="form.errors.email" class="mt-1 text-sm text-red-600">
            {{ form.errors.email }}
          </div>
        </div>

        <!-- Password -->
        <div>
          <label for="password" class="sr-only">Senha</label>
          <input
            id="password"
            v-model="form.password"
            name="password"
            type="password"
            autocomplete="new-password"
            required
            class="relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary focus:border-primary focus:z-10 sm:text-sm"
            placeholder="Senha (mínimo 8 caracteres)"
          />
          <div v-if="form.errors.password" class="mt-1 text-sm text-red-600">
            {{ form.errors.password }}
          </div>
        </div>

        <!-- Confirm Password -->
        <div>
          <label for="password_confirmation" class="sr-only">Confirmar senha</label>
          <input
            id="password_confirmation"
            v-model="form.password_confirmation"
            name="password_confirmation"
            type="password"
            autocomplete="new-password"
            required
            class="relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary focus:border-primary focus:z-10 sm:text-sm"
            placeholder="Confirmar senha"
          />
          <div v-if="form.errors.password_confirmation" class="mt-1 text-sm text-red-600">
            {{ form.errors.password_confirmation }}
          </div>
        </div>

        <!-- CPF -->
        <div>
          <label for="cpf" class="sr-only">CPF</label>
          <input
            id="cpf"
            v-model="form.cpf"
            name="cpf"
            type="text"
            autocomplete="off"
            required
            maxlength="14"
            class="relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary focus:border-primary focus:z-10 sm:text-sm"
            placeholder="CPF (000.000.000-00)"
            @input="formatCPF"
          />
          <div v-if="form.errors.cpf" class="mt-1 text-sm text-red-600">
            {{ form.errors.cpf }}
          </div>
        </div>

        <!-- Terms -->
        <div class="flex items-center">
          <input
            id="terms"
            v-model="form.terms"
            name="terms"
            type="checkbox"
            required
            class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded"
          />
          <label for="terms" class="ml-2 block text-sm text-gray-900">
            Eu concordo com os
            <a href="#" class="text-primary hover:text-primary-dark">Termos de Uso</a>
            e
            <a href="#" class="text-primary hover:text-primary-dark">Política de Privacidade</a>
          </label>
        </div>
        <div v-if="form.errors.terms" class="mt-1 text-sm text-red-600">
          {{ form.errors.terms }}
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
            {{ form.processing ? 'Criando conta...' : 'Criar conta' }}
          </button>
        </div>

        <!-- Error Message -->
        <div v-if="form.errors.general" class="rounded-md bg-red-50 p-4">
          <div class="text-sm text-red-700">
            {{ form.errors.general }}
          </div>
        </div>

      </form>

      <!-- Footer -->
      <div class="text-center">
        <p class="text-xs text-gray-500">
          Seus dados estão protegidos com criptografia de ponta a ponta.
          <br>
          Nunca compartilhamos suas informações com terceiros.
        </p>
      </div>

    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import { Link } from '@inertiajs/vue3'

// Form
const form = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  cpf: '',
  terms: false,
})

// Methods
const submit = () => {
  form.post('/register', {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}

const formatCPF = (event) => {
  let value = event.target.value.replace(/\D/g, '')
  
  if (value.length <= 11) {
    value = value.replace(/(\d{3})(\d)/, '$1.$2')
    value = value.replace(/(\d{3})(\d)/, '$1.$2')
    value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2')
  }
  
  form.cpf = value
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

