import { mount } from 'svelte';
import AdminUploads from './AdminUploads.svelte';
import AdminUsers from './AdminUsers.svelte';
import Dashboard from './Dashboard.svelte';

const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const dashboardTarget = document.querySelector('#dashboard');
const adminTarget = document.querySelector('#admin-uploads');
const adminUsersTarget = document.querySelector('#admin-users');

if (dashboardTarget) {
    mount(Dashboard, {
        target: dashboardTarget,
    });
}

if (adminTarget) {
    mount(AdminUploads, { target: adminTarget, props: { csrf } });
}

if (adminUsersTarget) {
    mount(AdminUsers, { target: adminUsersTarget, props: { csrf } });
}
