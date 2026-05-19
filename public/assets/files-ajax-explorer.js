(function () {
    function initAjaxExplorer() {
        if (window.__driveMasterAjaxExplorerInitialised) {
            return;
        }
        window.__driveMasterAjaxExplorerInitialised = true;

        const explorerPanel = document.getElementById('explorer-panel');
        if (!explorerPanel) {
            return;
        }

        const main = explorerPanel.querySelector('.explorer-main');
        const breadcrumbs = main ? main.querySelector('.breadcrumbs') : null;
        const table = main ? main.querySelector('.explorer-table') : null;
        const tbody = table ? table.querySelector('tbody') : null;
        const status = document.getElementById('explorer-status');

        if (!main || !breadcrumbs || !table || !tbody || !status) {
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const urlDriveId = Number(urlParams.get('drive_id') || 0);
        const selectedDriveId = Number(document.querySelector('.tree-drive.selected')?.dataset.driveId || 0);
        let currentDriveId = Number(urlDriveId || selectedDriveId || document.querySelector('.tree-drive')?.dataset.driveId || 0);
        let currentPath = urlParams.has('path') ? (urlParams.get('path') || '') : '';
        let currentPage = Number(urlParams.get('explorer_page') || 1);
        let currentSort = urlParams.get('sort') || 'name';
        let currentDir = urlParams.get('dir') || 'asc';
        let loading = false;
        let queuedLoad = null;
        let treeClickTimer = null;

        function formatBytes(bytes) {
            bytes = Number(bytes || 0);
            const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
            let index = 0;
            while (bytes >= 1024 && index < units.length - 1) {
                bytes /= 1024;
                index++;
            }
            return index === 0 ? Math.round(bytes) + ' ' + units[index] : bytes.toFixed(2) + ' ' + units[index];
        }

        function ensureOverlay() {
            let overlay = document.getElementById('ajax-explorer-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'ajax-explorer-overlay';
                overlay.className = 'ajax-explorer-overlay';
                overlay.innerHTML = '<div class="ajax-explorer-overlay-box">Loading folder data...</div>';
                document.body.appendChild(overlay);
            }
            return overlay;
        }

        function showOverlay(text) {
            const overlay = ensureOverlay();
            const box = overlay.querySelector('.ajax-explorer-overlay-box');
            if (box) {
                box.textContent = text || 'Loading folder data...';
            }
            overlay.classList.add('visible');
        }

        function hideOverlay() {
            const overlay = document.getElementById('ajax-explorer-overlay');
            if (overlay) {
                overlay.classList.remove('visible');
            }
        }

        function setSelectedTreePath(driveId, path) {
            document.querySelectorAll('.tree-drive,.tree-folder').forEach(function (row) {
                row.classList.remove('selected-row', 'selected');
            });
            const folder = Array.from(document.querySelectorAll('.tree-folder')).find(function (row) {
                return String(row.dataset.driveId || '') === String(driveId) && String(row.dataset.path || '') === String(path || '');
            });
            if (folder) {
                folder.classList.add('selected-row', 'selected');
                return;
            }
            const drive = document.querySelector('.tree-drive[data-drive-id="' + String(driveId).replace(/"/g, '\\"') + '"]');
            if (drive) {
                drive.classList.add('selected-row', 'selected');
            }
        }

        function updateStatus(data) {
            const s = data.status || {};
            status.dataset.totalCount = String(s.total_count || 0);
            status.dataset.fileCount = String(s.file_count || 0);
            status.dataset.folderCount = String(s.folder_count || 0);
            status.dataset.totalSize = String(s.total_size || 0);
            status.textContent = 'Items: ' + status.dataset.totalCount + ' | Files: ' + status.dataset.fileCount + ' | Folders: ' + status.dataset.folderCount + ' | Total file size: ' + formatBytes(status.dataset.totalSize) + ' | Selected: 0 | Selected size: 0 B';
        }

        function wireNewRows() {
            tbody.querySelectorAll('.explorer-row').forEach(function (row) {
                if (row.dataset.ajaxWired === '1') {
                    return;
                }
                row.dataset.ajaxWired = '1';
                row.addEventListener('click', function (event) {
                    if (!row.classList.contains('selectable-row')) {
                        return;
                    }
                    if (!event.ctrlKey && !event.metaKey) {
                        tbody.querySelectorAll('.selected-row').forEach(function (selected) { selected.classList.remove('selected-row'); });
                    }
                    row.classList.add('selected-row');
                    let selectedSize = 0;
                    const selectedRows = tbody.querySelectorAll('.selectable-row.selected-row');
                    selectedRows.forEach(function (selected) {
                        if (selected.dataset.isFile === '1') {
                            selectedSize += Number(selected.dataset.size || 0);
                        }
                    });
                    status.textContent = 'Items: ' + status.dataset.totalCount + ' | Files: ' + status.dataset.fileCount + ' | Folders: ' + status.dataset.folderCount + ' | Total file size: ' + formatBytes(status.dataset.totalSize) + ' | Selected: ' + selectedRows.length + ' | Selected size: ' + formatBytes(selectedSize);
                });
                row.addEventListener('dblclick', function (event) {
                    if (row.dataset.ajaxPath !== undefined) {
                        event.preventDefault();
                        event.stopPropagation();
                        loadExplorer(Number(row.dataset.driveId || currentDriveId), row.dataset.ajaxPath || '', 1, currentSort, currentDir, true, 'Opening folder. Loading files...');
                    }
                });
            });
        }

        function updateUrl(driveId, path, page, sort, dir, pushState) {
            const url = new URL(window.location.href);
            url.searchParams.set('drive_id', String(driveId));
            url.searchParams.set('path', path || '');
            url.searchParams.delete('expanded_drive_id');
            url.searchParams.set('explorer_page', String(page || 1));
            url.searchParams.set('sort', sort || 'name');
            url.searchParams.set('dir', dir || 'asc');
            url.hash = 'explorer-panel';
            const state = { driveId: Number(driveId), path: path || '', page: Number(page || 1), sort: sort || 'name', dir: dir || 'asc' };
            if (pushState) {
                window.history.pushState(state, '', url.toString());
            } else {
                window.history.replaceState(state, '', url.toString());
            }
        }

        function loadExplorer(driveId, path, page, sort, dir, pushState, message) {
            driveId = Number(driveId || 0);
            path = path || '';
            page = Number(page || 1);
            sort = sort || 'name';
            dir = dir || 'asc';
            if (!driveId) {
                return;
            }
            updateUrl(driveId, path, page, sort, dir, pushState);
            currentDriveId = driveId;
            currentPath = path;
            currentPage = page;
            currentSort = sort;
            currentDir = dir;
            setSelectedTreePath(driveId, path);

            if (loading) {
                queuedLoad = { driveId: driveId, path: path, page: page, sort: sort, dir: dir, pushState: false, message: 'Loading requested folder next...' };
                showOverlay('Loading requested folder next...');
                return;
            }

            loading = true;
            showOverlay(message || 'Loading folder data...');
            const params = new URLSearchParams();
            params.set('drive_id', String(driveId));
            params.set('path', path);
            params.set('explorer_page', String(page));
            params.set('sort', sort);
            params.set('dir', dir);
            fetch('files-explorer-data.php?' + params.toString(), { cache: 'no-store' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        throw new Error(data.error || 'Could not load folder.');
                    }
                    currentDriveId = Number(data.drive_id || driveId);
                    currentPath = data.path || '';
                    currentPage = Number(data.page || page || 1);
                    currentSort = sort;
                    currentDir = dir;
                    breadcrumbs.innerHTML = data.breadcrumbs_html || '';
                    tbody.innerHTML = data.rows_html || '';
                    main.querySelectorAll('.ajax-explorer-pager').forEach(function (pager) { pager.remove(); });
                    if (data.pager_top_html) {
                        table.insertAdjacentHTML('beforebegin', data.pager_top_html);
                    }
                    if (data.pager_bottom_html) {
                        status.insertAdjacentHTML('beforebegin', data.pager_bottom_html);
                    }
                    updateStatus(data);
                    wireNewRows();
                    setSelectedTreePath(currentDriveId, currentPath);
                })
                .catch(function (error) {
                    alert(error.message || 'Could not load folder.');
                })
                .finally(function () {
                    loading = false;
                    hideOverlay();
                    if (queuedLoad) {
                        const next = queuedLoad;
                        queuedLoad = null;
                        loadExplorer(next.driveId, next.path, next.page, next.sort, next.dir, next.pushState, next.message);
                    }
                });
        }

        explorerPanel.addEventListener('click', function (event) {
            const treeRow = event.target.closest('.tree-drive,.tree-folder');
            if (treeRow && !event.target.closest('.tree-toggle')) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                if (treeClickTimer) {
                    clearTimeout(treeClickTimer);
                }
                treeClickTimer = setTimeout(function () {
                    treeClickTimer = null;
                    loadExplorer(Number(treeRow.dataset.driveId || currentDriveId), treeRow.dataset.path || '', 1, currentSort, currentDir, true, 'Opening folder. Loading files...');
                }, 220);
                return;
            }
            const breadcrumbLink = event.target.closest('.breadcrumbs a[data-ajax-path]');
            if (breadcrumbLink) {
                event.preventDefault();
                loadExplorer(Number(breadcrumbLink.dataset.driveId || currentDriveId), breadcrumbLink.dataset.ajaxPath || '', 1, currentSort, currentDir, true, 'Opening folder. Loading files...');
                return;
            }
            const pageLink = event.target.closest('[data-ajax-page]');
            if (pageLink) {
                event.preventDefault();
                loadExplorer(currentDriveId, currentPath, Number(pageLink.dataset.ajaxPage || 1), currentSort, currentDir, true, 'Loading page...');
                return;
            }
        }, true);

        explorerPanel.addEventListener('dblclick', function (event) {
            const treeRow = event.target.closest('.tree-drive,.tree-folder');
            if (!treeRow) {
                return;
            }
            if (treeClickTimer) {
                clearTimeout(treeClickTimer);
                treeClickTimer = null;
            }
            event.preventDefault();
        }, true);

        table.querySelectorAll('thead a').forEach(function (link) {
            link.addEventListener('click', function (event) {
                const url = new URL(link.href, window.location.href);
                const sort = url.searchParams.get('sort') || 'name';
                const dir = url.searchParams.get('dir') || 'asc';
                event.preventDefault();
                loadExplorer(currentDriveId, currentPath, 1, sort, dir, true, 'Sorting files...');
            });
        });

        window.addEventListener('popstate', function (event) {
            const state = event.state;
            if (state && state.driveId) {
                loadExplorer(Number(state.driveId), state.path || '', Number(state.page || 1), state.sort || 'name', state.dir || 'asc', false, 'Loading folder data...');
            }
        });

        wireNewRows();
        updateUrl(currentDriveId, currentPath, currentPage, currentSort, currentDir, false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAjaxExplorer);
    } else {
        initAjaxExplorer();
    }
})();
