<template>
    <div>
        <!-- Cabeçalho -->
        <nav class="border-b border-gray-100 bg-white">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between">
                    <!-- Logo -->
                    <div class="flex items-center">
                        <Link :href="route('dashboard')">
                            <ApplicationLogo class="h-9 w-auto" />
                        </Link>
                    </div>

                    <!-- Links de Navegação -->
                    <div class="hidden sm:flex sm:space-x-8">
                        <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                            Dashboard
                        </NavLink>
                    </div>

                    <!-- Menu do Usuário -->
                    <div class="hidden sm:flex sm:items-center">
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button class="flex items-center text-gray-500 hover:text-gray-700 focus:outline-none">
                                    {{ $page.props.auth.user.name }}
                                    <svg class="ml-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                                <DropdownLink :href="route('logout')" method="post" as="button">Log Out</DropdownLink>
                            </template>
                        </Dropdown>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Cabeçalho da Página -->
        <header v-if="$slots.header" class="bg-white shadow">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Conteúdo -->
        <main class="py-6">
            <slot />
        </main>
    </div>
</template>
