<template>
  <div class="text-center py-8 w-full">
    <svg v-if="!compact" class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m0 0h6m-6 0V9a2 2 0 012-2h2a2 2 0 012 2v10m0 0a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2h-2" />
    </svg>
    <p class="mt-2 text-sm font-medium text-gray-900">{{ title }}</p>
    <p class="mt-1 text-xs text-gray-500">{{ description }}</p>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, required: true },
  compact: { type: Boolean, default: false },
})
</script>
