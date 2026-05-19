document.addEventListener('DOMContentLoaded', function () {
    function normaliseName(value) {
        return String(value || '').replace(/[▸▾·…]/g, '').replace(/[📁📄💽↩]/g, '').trim().toLowerCase();
    }

    function pathHasIgnoredFolder(path, ignored) {
        const parts = String(path || '').replace(/\\/g, '/').split('/').map(function (part) { return part.trim().toLowerCase(); }).filter(Boolean);
        return parts.some(function (part) { return ignored.has(part); });
    }

    function rowName(row) {
        if (row.dataset.entryName) {
            return row.dataset.entryName;
        }
        const firstCell = row.querySelector('td:first-child');
        if (firstCell) {
            return firstCell.textContent;
        }
        return row.textContent;
    }

    function rowPath(row) {
        if (row.dataset.path) {
            return row.dataset.path;
        }
        try {
            if (row.dataset.url) {
                const url = new URL(row.dataset.url, window.location.href);
                return url.searchParams.get('path') || '';
            }
        } catch (e) {}
        const pathCell = row.querySelector('.path-cell') || row.children[4] || row.children[3];
        return pathCell ? pathCell.textContent : '';
    }

    function hideIgnoredRows(ignoredNames) {
        const ignored = new Set(ignoredNames.map(normaliseName).filter(Boolean));
        if (!ignored.size) {
            return;
        }

        document.querySelectorAll('.tree-folder, .explorer-row.selectable-row, .search-result-row.selectable-row').forEach(function (row) {
            const name = normaliseName(rowName(row));
            const path = rowPath(row);
            if (ignored.has(name) || pathHasIgnoredFolder(path, ignored)) {
                row.classList.add('ignored-folder-hidden');
                row.style.display = 'none';
            }
        });
    }

    function updateAfterLazyLoad(ignoredNames) {
        const sidebar = document.querySelector('.explorer-sidebar');
        if (!sidebar) {
            return;
        }
        const observer = new MutationObserver(function () {
            hideIgnoredRows(ignoredNames);
        });
        observer.observe(sidebar, { childList: true, subtree: true });
    }

    fetch('ignored-folders-list.php?ts=' + Date.now(), { cache: 'no-store' })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            const ignoredNames = data && data.ok && Array.isArray(data.folders) ? data.folders : ['@eaDir', '@tmp', '$RECYCLE.BIN', 'System Volume Information'];
            hideIgnoredRows(ignoredNames);
            updateAfterLazyLoad(ignoredNames);
        })
        .catch(function () {
            const fallback = ['@eaDir', '@tmp', '$RECYCLE.BIN', 'System Volume Information'];
            hideIgnoredRows(fallback);
            updateAfterLazyLoad(fallback);
        });
});
