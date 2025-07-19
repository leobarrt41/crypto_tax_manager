import '../css/app.css'; // Estilos globais
import './bootstrap'; // Script de bootstrap (pode estar vazio ou com inicializações)

import { createInertiaApp } from '@inertiajs/vue3'; // Cria app Inertia com Vue 3
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'; // Resolve os componentes de página dinamicamente
import { createApp, h } from 'vue'; // Criação da aplicação Vue
import { ZiggyVue } from 'ziggy-js'; // Integração com rotas do Laravel via Ziggy

const appName = import.meta.env.VITE_APP_NAME || 'Laravel'; // Nome da aplicação
const el = document.getElementById('app');
const initialPage = el ? JSON.parse(el.dataset.page) : {};
console.log(initialPage); // Log para depuração


createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue')
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, {
                Ziggy: window.Ziggy, // Conecta com as rotas do Laravel via @routes
            })
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
