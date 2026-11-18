import '../css/responsive-table.css';

// Observe the available space as well as the viewport (for example, after a
// sidebar or container changes size). Keep DataTables' measurements in sync.
export function adaptManagementTable(table) {
    const container = table.table().container();
    container.classList.add('portal-responsive-table');
    let frame;
    let lastWidth = -1;
    const observer = new ResizeObserver(([entry]) => {
        const width = Math.round(entry.contentRect.width);
        if (width === lastWidth || width === 0) return;
        lastWidth = width;
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => table.columns.adjust().responsive.recalc());
    });
    observer.observe(container.parentElement);
    table.on('destroy', () => {
        observer.disconnect();
        cancelAnimationFrame(frame);
    });
}
