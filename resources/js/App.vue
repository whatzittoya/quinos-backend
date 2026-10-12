<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter, RouterLink, RouterView } from 'vue-router';
import Icon from './components/Icon.vue';
import { useAuth } from './stores/auth';

const auth = useAuth();
const route = useRoute();
const router = useRouter();

const showShell = computed(() => auth.user && !route.meta.guest);

// Sidebar entries. A `children` list makes it an expandable group.
const nav = computed(() => [
    { label: 'Dashboard', icon: 'home', to: '/' },
    { label: 'Sync', icon: 'sync', to: '/sync' },
    {
        label: 'Master', icon: 'master',
        children: [
            { to: '/master/items', label: 'Items' },
            { to: '/master/departments', label: 'Departments' },
            { to: '/master/categories', label: 'Categories' },
            { to: '/master/sales-types', label: 'Sales types' },
            { to: '/master/contacts', label: 'Contacts' },
        ],
    },
    {
        label: 'Report', icon: 'report',
        children: [
            { to: '/reports/purchase', label: 'Purchase report' },
            { to: '/reports/sales-weekly', label: 'Weekly summary sales' },
        ],
    },
    { label: 'Shifts', icon: 'clock', to: '/settings/shifts' },
    ...(auth.isAdmin
        ? [{ label: 'Clients', icon: 'building', to: '/admin/clients' }, { label: 'Users', icon: 'users', to: '/admin/users' }]
        : []),
    { label: 'Change password', icon: 'key', to: '/account/password' },
]);

// ---- collapsible sidebar ---------------------------------------------------

const KEY = 'quinos.sidebarCollapsed';
const read = () => { try { return localStorage.getItem(KEY) === '1'; } catch { return false; } };
const collapsed = ref(read()); // desktop: narrow icon rail
const drawer = ref(false); // mobile: sidebar slides over the page
const open = ref(new Set()); // expanded groups

const toggleCollapsed = () => {
    collapsed.value = !collapsed.value;
    try { localStorage.setItem(KEY, collapsed.value ? '1' : '0'); } catch { /* ignore */ }
};

const isActive = (to) => (to === '/' ? route.path === '/' : route.path === to || route.path.startsWith(`${to}/`));
const groupActive = (item) => item.children.some((c) => isActive(c.to));

// Keep the group of the current page open.
watch(() => route.path, () => {
    nav.value.filter((i) => i.children && groupActive(i)).forEach((i) => open.value.add(i.label));
    drawer.value = false;
}, { immediate: true });

function toggleGroup(item) {
    if (collapsed.value) { // a rail has no room for children: widen it first
        toggleCollapsed();
        open.value.add(item.label);
        return;
    }
    open.value.has(item.label) ? open.value.delete(item.label) : open.value.add(item.label);
}

async function logout() {
    await auth.logout();
    router.push({ name: 'login' });
}

function onClientChange(e) {
    auth.setClient(e.target.value || null);
    // Reload the current page's data for the newly selected client.
    router.go(0);
}

const linkClass = (active) => [
    'flex items-center gap-3 rounded-md px-3 py-2 text-sm transition',
    active ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100',
];
</script>

<template>
    <div v-if="showShell" class="flex min-h-screen">
        <!-- backdrop for the mobile drawer -->
        <div v-if="drawer" class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" @click="drawer = false" />

        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-white transition-all lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
            :class="[drawer ? 'translate-x-0' : '-translate-x-full', collapsed ? 'lg:w-16' : 'lg:w-64']"
        >
            <div class="flex h-14 items-center gap-2 border-b border-slate-200 px-4">
                <span class="truncate font-semibold tracking-tight" :class="collapsed && 'lg:hidden'">Quinos Reports</span>
                <button
                    class="ml-auto hidden rounded-md p-1.5 text-slate-500 hover:bg-slate-100 lg:block"
                    :class="collapsed && '!mx-auto'"
                    :aria-label="collapsed ? 'Expand menu' : 'Collapse menu'"
                    @click="toggleCollapsed"
                >
                    <Icon :name="collapsed ? 'expand' : 'collapse'" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto p-2">
                <template v-for="item in nav" :key="item.label">
                    <template v-if="item.children">
                        <button
                            type="button" :class="[linkClass(groupActive(item) && (collapsed || !open.has(item.label))), 'w-full', collapsed && 'lg:justify-center']"
                            :title="collapsed ? item.label : undefined" @click="toggleGroup(item)"
                        >
                            <Icon :name="item.icon" />
                            <span class="flex-1 text-left" :class="collapsed && 'lg:hidden'">{{ item.label }}</span>
                            <Icon name="chevron" class="!h-4 !w-4 transition" :class="[open.has(item.label) && 'rotate-180', collapsed && 'lg:hidden']" />
                        </button>
                        <div v-if="open.has(item.label)" class="space-y-0.5 pl-4" :class="collapsed && 'lg:hidden'">
                            <RouterLink
                                v-for="c in item.children" :key="c.to" :to="c.to"
                                class="block rounded-md py-1.5 pl-6 pr-3 text-sm transition"
                                :class="isActive(c.to) ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                            >{{ c.label }}</RouterLink>
                        </div>
                    </template>

                    <RouterLink
                        v-else :to="item.to" :class="[linkClass(isActive(item.to)), collapsed && 'lg:justify-center']"
                        :title="collapsed ? item.label : undefined"
                    >
                        <Icon :name="item.icon" />
                        <span :class="collapsed && 'lg:hidden'">{{ item.label }}</span>
                    </RouterLink>
                </template>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4">
                <button class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 lg:hidden" aria-label="Open menu" @click="drawer = true">
                    <Icon name="menu" />
                </button>

                <div class="ml-auto flex items-center gap-3 text-sm">
                    <select v-if="auth.isAdmin" :value="auth.clientId ?? ''" class="field !w-auto" @change="onClientChange">
                        <option value="">Select client…</option>
                        <option v-for="c in auth.clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <span v-else class="text-slate-500">{{ auth.user.client?.name }}</span>
                    <span class="hidden text-slate-400 sm:inline">{{ auth.user.email }}</span>
                    <button class="btn-ghost inline-flex items-center gap-1.5" @click="logout"><Icon name="logout" class="!h-4 !w-4" />Log out</button>
                </div>
            </header>

            <main class="w-full max-w-6xl px-4 py-6">
                <RouterView :key="auth.clientId" />
            </main>
        </div>
    </div>

    <RouterView v-else />
</template>
