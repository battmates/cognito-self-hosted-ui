import DataTable from 'datatables.net-dt';
import 'datatables.net-dt/css/dataTables.dataTables.css';
import '../css/admin-users.css';

(() => {
    const form = document.getElementById('admin-search-form');
    const results = document.getElementById('admin-search-results');
    const status = document.getElementById('admin-search-status');
    const query = form.elements.q;
    const field = form.elements.field;
    let timer;
    let controller;
    let revision = 0;
    const tableElement = results.querySelector('[data-users-table]');
    // DataTables owns its empty state and requires ordinary rows without colspan.
    tableElement.querySelector('[data-empty-row]')?.remove();
    const table = new DataTable(tableElement, {
        paging: false,
        searching: false,
        info: false,
        autoWidth: false,
        order: [],
        layout: { topStart: null, topEnd: null, bottomStart: null, bottomEnd: null },
        columnDefs: [{ targets: 5, orderable: false, searchable: false }],
        language: { emptyTable: 'No accounts loaded.' },
    });

    const updateCursor = (cursor) => {
        let button = results.querySelector('[data-load-more]');
        if (!cursor) { button?.remove(); return; }
        if (!button) {
            button = document.createElement('button');
            button.type = 'button';
            button.dataset.loadMore = '';
            button.className = 'mt-5 rounded-xl bg-[#3da7c7] px-6 py-3 font-semibold text-white';
            button.textContent = 'Load more';
            results.querySelector('section').append(button);
        }
        button.dataset.cursor = cursor;
    };

    const describeResults = (cursor) => {
        const count = table.rows().count();
        status.textContent = count === 0 ? (cursor ? 'Searching more accounts…' : 'No matching accounts.')
            : `Showing ${count} matching account${count === 1 ? '' : 's'}.${cursor ? ' More accounts to search.' : ''}`;
    };

    const setBusy = (busy) => {
        results.setAttribute('aria-busy', String(busy));
        results.inert = busy;
    };

    const loadUsers = (delay = 0, cursor = null) => {
        const append = cursor !== null;
        clearTimeout(timer);
        controller?.abort();
        const current = ++revision;
        setBusy(true);
        status.textContent = append ? 'Loading more accounts…' : 'Searching…';
        timer = setTimeout(async () => {
            controller = new AbortController();
            const url = new URL(form.action);
            url.search = new URLSearchParams({ field: field.value, q: query.value });
            if (append) url.searchParams.set('cursor', cursor);
            try {
                const response = await fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
                });
                if (current !== revision) return;
                if (!response.ok) {
                    const message = response.status === 401 ? 'Your session expired. Sign in again to search users.'
                        : response.status === 403 ? 'Administrator access is required to search users.'
                        : response.status === 429 ? 'Too many requests. Wait a moment and try again.'
                        : 'User search is unavailable. Please try again.';
                    throw new Error(message);
                }
                const data = await response.json();
                if (current !== revision) return;
                if (typeof data.html !== 'string' || typeof data.rows !== 'string' || !Number.isInteger(data.count)
                    || (data.cursor !== null && typeof data.cursor !== 'string')) {
                    throw new Error('User search is unavailable. Please try again.');
                }
                const incoming = document.createElement('tbody');
                incoming.innerHTML = data.rows;
                if (!append) table.clear();
                const existing = new Set(table.rows().nodes().toArray().map(row => row.dataset.userRow));
                const newRows = [...incoming.querySelectorAll('[data-user-row]')].filter(row => {
                    if (existing.has(row.dataset.userRow)) return false;
                    existing.add(row.dataset.userRow);
                    return true;
                });
                // Use DataTables' API so sorting and its internal row cache stay in sync.
                table.rows.add(newRows).draw(false);
                updateCursor(data.cursor);
                if (!append) history.replaceState({}, '', url);
                describeResults(data.cursor);
                // Continue through empty filtered batches so a later match is not mistaken for no results.
                if (data.count === 0 && data.cursor) loadUsers(0, data.cursor);
            } catch (error) {
                if (current !== revision || error.name === 'AbortError') return;
                // Preserve loaded accounts on a failed append so the same batch can be retried.
                if (!append) { table.clear().draw(); updateCursor(null); }
                status.textContent = error instanceof TypeError ? 'User search is unavailable. Please try again.' : error.message;
            } finally {
                if (current === revision) setBusy(false);
            }
        }, delay);
    };

    query.addEventListener('input', event => { if (!event.isComposing) loadUsers(350); });
    query.addEventListener('compositionend', () => loadUsers(350));
    field.addEventListener('change', () => loadUsers());
    form.addEventListener('submit', event => { event.preventDefault(); loadUsers(); });
    results.addEventListener('click', event => {
        const button = event.target.closest('[data-load-more]');
        if (button && !results.inert) loadUsers(0, button.dataset.cursor);
    });
    const initialMore = results.querySelector('[data-load-more]');
    describeResults(initialMore?.dataset.cursor);
    if (initialMore && table.rows().count() === 0) loadUsers(0, initialMore.dataset.cursor);
})();
