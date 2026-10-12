<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter, RouterLink, RouterView } from 'vue-router';
import { useAuth } from './stores/auth';

const auth = useAuth();
const route = useRoute();
const router = useRouter();

const showShell = computed(() => auth.user && !route.meta.guest);

const links = computed(() => [
    { to: '/', label: 'Dashboard' },
    { to: '/sync', label: 'Sync' },
    {
        label: 'Master',
        children: [
            { to: '/master/items', label: 'Items' },
            { to: '/master/departments', label: 'Departments' },
            { to: '/master/categories', label: 'Categories' },
            { to: '/master/sales-types', label: 'Sales types' },
            { to: '/master/contacts', label: 'Contacts' },
        ],
    },
    {
        label: 'Report',
        children: [
            { to: '/reports/purchase', label: 'Purchase report' },
            { to: '/reports/sales-weekly', label: 'Weekly summary sales' },
        ],
    },
    { to: '/settings/shifts', label: 'Shifts' },
    ...(auth.isAdmin
        ? [{ to: '/admin/clients', label: 'Clients' }, { to: '/admin/users', label: 'Users' }]
        : []),
]);

const openMenu = ref(null);
watch(() => route.fullPath, () => { openMenu.value = null; });

async function logout() {
    await auth.logout();
    router.push({ name: 'login' });
}

function onClientChange(e) {
    auth.setClient(e.target.value || null);
    // Reload the current page's data for the newly selected client.
    router.go(0);
}
</script>

<template>
    <div v-if="showShell" class="min-h-screen">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <span class="font-semibold tracking-tight">Quinos Reports</span>
                <nav class="flex flex-wrap gap-1 text-sm">
                    <template v-for="link in links" :key="link.label">
                        <div v-if="link.children" class="relative" @mouseenter="openMenu = link.label" @mouseleave="openMenu = null">
                            <button
                                class="rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100"
                                :class="link.children.some((c) => route.path === c.to) && '!bg-slate-900 !text-white'"
                                @click="openMenu = link.label"
                            >{{ link.label }} ▾</button>
                            <div v-if="openMenu === link.label" class="absolute left-0 top-full z-20 w-56 pt-1">
                                <div class="card p-1">
                                    <RouterLink
                                        v-for="c in link.children" :key="c.to" :to="c.to"
                                        class="block rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100"
                                        exact-active-class="!bg-slate-900 !text-white"
                                    >{{ c.label }}</RouterLink>
                                </div>
                            </div>
                        </div>
                        <RouterLink
                            v-else
                            :to="link.to"
                            class="rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100"
                            active-class="!bg-slate-900 !text-white"
                            exact-active-class="!bg-slate-900 !text-white"
                        >{{ link.label }}</RouterLink>
                    </template>
                </nav>

                <div class="ml-auto flex items-center gap-3 text-sm">
                    <select v-if="auth.isAdmin" :value="auth.clientId ?? ''" class="field !w-auto" @change="onClientChange">
                        <option value="">Select client…</option>
                        <option v-for="c in auth.clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <span v-else class="text-slate-500">{{ auth.user.client?.name }}</span>
                    <span class="hidden text-slate-400 sm:inline">{{ auth.user.email }}</span>
                    <button class="btn-ghost" @click="logout">Log out</button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-6">
            <RouterView :key="auth.clientId" />
        </main>
    </div>

    <RouterView v-else />
</template>
