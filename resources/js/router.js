import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from './stores/auth';

const routes = [
    { path: '/login', name: 'login', component: () => import('./views/Login.vue'), meta: { guest: true } },
    { path: '/', name: 'home', component: () => import('./views/Home.vue') },
    { path: '/sync', name: 'sync', component: () => import('./views/Sync.vue') },
    { path: '/master/:kind', name: 'master', component: () => import('./views/Master.vue') },
    { path: '/reports/purchase', name: 'report-purchase', component: () => import('./views/reports/Purchase.vue') },
    { path: '/reports/sales-weekly', name: 'report-sales-weekly', component: () => import('./views/reports/SalesWeekly.vue') },
    { path: '/account/password', name: 'password', component: () => import('./views/Account.vue') },
    { path: '/settings/shifts', name: 'shifts', component: () => import('./views/Shifts.vue') },
    { path: '/admin/clients', name: 'clients', component: () => import('./views/admin/Clients.vue'), meta: { admin: true } },
    { path: '/admin/users', name: 'users', component: () => import('./views/admin/Users.vue'), meta: { admin: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach(async (to) => {
    const auth = useAuth();
    if (!auth.loaded) await auth.fetchUser();

    if (to.meta.guest) return auth.user ? { name: 'home' } : true;
    if (!auth.user) return { name: 'login' };
    if (to.meta.admin && !auth.isAdmin) return { name: 'home' };
    return true;
});

export default router;
