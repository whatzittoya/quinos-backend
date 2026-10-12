import '../css/app.css';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { setUnauthorizedHandler } from './api';
import { useAuth } from './stores/auth';

const app = createApp(App);
app.use(createPinia());
app.use(router);

// A session that expires mid-use sends the user back to the login page.
setUnauthorizedHandler(() => {
    useAuth().user = null;
    router.push({ name: 'login' });
});

app.mount('#app');
