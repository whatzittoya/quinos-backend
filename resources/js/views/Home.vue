<script setup>
import { useAuth } from '../stores/auth';

const auth = useAuth();
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>

        <div v-if="auth.isAdmin && !auth.activeClient" class="card p-6 text-sm text-slate-600">
            Pick a client in the top bar, or add one under <RouterLink class="underline" to="/admin/clients">Clients</RouterLink>.
        </div>

        <div v-else class="card p-6 text-sm text-slate-600">
            Signed in for <strong>{{ auth.activeClient?.name }}</strong>.
            Go to <RouterLink class="underline" to="/sync">Sync</RouterLink> to copy data from Quinos. Report pages arrive in the next steps.
        </div>
    </div>
</template>
