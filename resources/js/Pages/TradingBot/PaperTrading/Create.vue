<template>
  <AppLayout title="Nova sessão simulada">
    <div class="py-8">
      <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="rounded-xl border border-violet-200 bg-violet-50 p-5 text-violet-950">
          <h1 class="text-2xl font-bold">Nova sessão de paper trading</h1>
          <p class="mt-1 text-sm leading-6">Você criará uma carteira fictícia. O sistema usa apenas candles públicos fechados e nunca consulta API key, conta, saldo ou envia ordem para uma exchange.</p>
        </section>

        <form class="space-y-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200" @submit.prevent="submit">
          <p v-if="markets.length === 0" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">A exchange Binance ainda não está cadastrada. Cadastre a fonte pública de mercado antes de criar uma sessão.</p>

          <div>
            <label for="strategy-version" class="block text-sm font-medium text-slate-800">Versão imutável da estratégia</label>
            <select id="strategy-version" v-model="form.strategy_version_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
              <option value="" disabled>Selecione uma versão</option>
              <optgroup v-for="strategy in strategies" :key="strategy.id" :label="strategy.name">
                <option v-for="version in strategy.versions" :key="version.id" :value="String(version.id)">Versão {{ version.version }} · {{ version.definition_hash.slice(0, 12) }}</option>
              </optgroup>
            </select>
            <p class="mt-1 text-xs text-slate-500">Par, timeframe e capital pertencem a esta sessão simulada; não serão inseridos na estratégia.</p>
            <p v-if="form.errors.strategy_version_id" class="mt-1 text-sm text-rose-700">{{ form.errors.strategy_version_id }}</p>
          </div>

          <div class="grid gap-5 sm:grid-cols-3">
            <div>
              <label for="exchange" class="block text-sm font-medium text-slate-800">Fonte pública</label>
              <select id="exchange" v-model="form.exchange_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm" required>
                <option value="" disabled>Selecione</option>
                <option v-for="market in markets" :key="market.exchange_id" :value="String(market.exchange_id)">{{ market.exchange_label }}</option>
              </select>
            </div>
            <div>
              <label for="symbol" class="block text-sm font-medium text-slate-800">Par</label>
              <select id="symbol" v-model="form.symbol" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm" required>
                <option v-for="symbol in selectedMarket?.symbols || []" :key="symbol" :value="symbol">{{ symbol }}</option>
              </select>
            </div>
            <div>
              <label for="timeframe" class="block text-sm font-medium text-slate-800">Timeframe</label>
              <select id="timeframe" v-model="form.timeframe" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm" required>
                <option v-for="timeframe in selectedMarket?.timeframes || []" :key="timeframe" :value="timeframe">{{ timeframe }}</option>
              </select>
            </div>
          </div>

          <div class="grid gap-5 sm:grid-cols-2">
            <div>
              <label for="capital" class="block text-sm font-medium text-slate-800">Capital inicial fictício (USDT)</label>
              <input id="capital" v-model="form.initial_capital" type="number" min="0.00000001" step="0.00000001" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm" required>
            </div>
            <div>
              <label for="allocation" class="block text-sm font-medium text-slate-800">Alocação por entrada (%)</label>
              <input id="allocation" v-model="form.allocation_pct" type="number" min="0" max="100" step="0.00000001" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm" required>
            </div>
            <div>
              <label for="fee" class="block text-sm font-medium text-slate-800">Taxa simulada (%)</label>
              <input id="fee" v-model="form.fee_rate" type="number" min="0" max="100" step="0.00000001" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm" required>
            </div>
            <div>
              <label for="slippage" class="block text-sm font-medium text-slate-800">Slippage simulado (%)</label>
              <input id="slippage" v-model="form.slippage_rate" type="number" min="0" max="100" step="0.00000001" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm" required>
            </div>
          </div>

          <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-700">
            <p class="font-semibold text-slate-900">Como funcionará</p>
            <p class="mt-1">O sistema reserva automaticamente histórico de aquecimento para os indicadores e somente cria fills fictícios na abertura do candle seguinte ao sinal. Você iniciará cada ciclo manualmente na tela da sessão.</p>
          </div>

          <p v-if="form.errors.paper_trading" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{{ form.errors.paper_trading }}</p>
          <div class="flex items-center justify-end gap-3">
            <Link :href="route('trading-bot.paper-trading.index')" class="text-sm font-semibold text-slate-700 hover:text-slate-950">Cancelar</Link>
            <button type="submit" :disabled="form.processing || markets.length === 0" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">{{ form.processing ? 'Criando…' : 'Criar sessão simulada' }}</button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  strategies: { type: Array, default: () => [] },
  markets: { type: Array, default: () => [] },
  defaults: { type: Object, required: true },
  executionEnabled: { type: Boolean, default: false },
  mode: { type: String, default: 'manual_paper_trading_only' },
})

const firstMarket = props.markets[0]
const firstVersion = props.strategies.flatMap((strategy) => strategy.versions || [])[0]
const form = useForm({
  strategy_version_id: firstVersion ? String(firstVersion.id) : '',
  exchange_id: firstMarket ? String(firstMarket.exchange_id) : '',
  symbol: firstMarket?.symbols?.[0] || '',
  timeframe: firstMarket?.timeframes?.[0] || '',
  initial_capital: props.defaults.initial_capital,
  allocation_pct: props.defaults.allocation_pct,
  fee_rate: props.defaults.fee_rate,
  slippage_rate: props.defaults.slippage_rate,
})
const selectedMarket = computed(() => props.markets.find((market) => String(market.exchange_id) === String(form.exchange_id)))
watch(selectedMarket, (market) => {
  if (market && !market.symbols.includes(form.symbol)) form.symbol = market.symbols[0]
  if (market && !market.timeframes.includes(form.timeframe)) form.timeframe = market.timeframes[0]
})
const submit = () => form.post(route('trading-bot.paper-trading.store'))
</script>
