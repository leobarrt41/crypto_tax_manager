<template>
  <div class="flex justify-between gap-3">
    <dt class="text-sm text-gray-500">{{ label }}</dt>
    <dd class="text-sm font-medium text-right" :class="valueClass">{{ value }}</dd>
  </div>
</template>

<script setup>
defineProps({
  label: { type: String, required: true },
  value: { type: String, required: true },
  valueClass: { type: String, default: '' },
})
</script>
