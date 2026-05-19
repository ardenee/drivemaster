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

        function treeLevel(row) {
            return Number(row.dataset.level || (row.classList.contains('tree-drive') ? -1 : 0));
        }

        function setToggle(row, value) {
            const toggle = row ? row.querySelector('.tree-toggle') : null;
            if (toggle) {
                toggle.textContent = value;
            }
        }

        function collapseOtherDrives(driveId) {
            document.querySelectorAll('.tree-drive').forEach(function (driveRow) {
                if (String(driveRow.dataset.driveId || '') === String(driveId)) {
                    return;
                }
                let next = driveRow.nextElementSibling;
                while (next && next.classList.contains('tree-folder')) {
                    const remove = next;
                    next = next.nextElementSibling;
                    remove.remove();
                }
                driveRow.dataset.loaded = '0';
                setToggle(driveRow, '▸');
            });
        }

        function rowForPath(driveId, path) {
            if (!path) {
                return document.querySelector('.tree-drive[data-drive-id="' + String(driveId).replace(/"/g, '\\"') + '"]');
            }
            return Array.from(document.querySelectorAll('.tree-folder')).find(function (row) {
                return String(row.dataset.driveId || '') === String(driveId) && String(row.dataset.path || '') === String(path || '');
            }) || null;
        }

        function insertTreeChildrenAfter(row, html) {
            const template = document.createElement('template');
            template.innerHTML = html || '';
            const nodes = Array.from(template.content.children);
            let anchor = row;
            nodes.forEach(function (node) {
                anchor.insertAdjacentElement('afterend', node);
                anchor = node;
            });
            if (window.__driveMasterIgnoredFolderFilterLoaded !== true) {
                const script = document.createElement('script');
                script.src = 'assets/ignored-folders-client.js?ts=' + Date.now();
                script.defer = true;
                window.__driveMasterIgnoredFolderFilterLoaded = true;
                document.head.appendChild(script);
            }
            return nodes;
        }

        function removeTreeDescendants(row) {
            if (!row) {
                return;
            }
            const level = treeLevel(row);
            let next = row.nextElementSibling;
            while (next && next.classList.contains('tree-folder') && treeLevel(next) > level) {
                const remove = next;
                next = next.nextElementSibling;
                remove.remove();
            }
            row.dataset.loaded = '0';
            setToggle(row, '▸');
        }

        function loadTreeChildrenForRow(row) {
            if (!row) {
                return Promise.resolve([]);
            }
            const driveId = row.classList.contains('tree-drive') ? row.dataset.driveId : row.dataset.driveId;
            const parentPath = row.classList.contains('tree-drive') ? '' : (row.dataset.path || '');
            const level = row.classList.contains('tree-drive') ? -1 : treeLevel(row);
            if (!driveId) {
                return Promise.resolve([]);
            }
            setToggle(row, '…');
            return fetch('files-tree-children.php?drive_id=' + encodeURIComponent(driveId) + '&parent_path=' + encodeURIComponent(parentPath) + '&level=' + encodeURIComponent(level), { cache: 'no-store' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        return [];
                    }
                    removeTreeDescendants(row);
                    const nodes = insertTreeChildrenAfter(row, data.html || '');
                    row.dataset.loaded = '1';
                    setToggle(row, nodes.length ? '▾' : '·');
                    return nodes;
                })
                .catch(function () {
                    row.dataset.loaded = '0';
                    setToggle(row, '▸');
                    return [];
                });
        }

        function selectTreeRow(driveId, path) {
            document.querySelectorAll('.tree-drive,.tree-folder').forEach(function (row) {
                row.classList.remove('selected-row', 'selected');
            });
            const target = rowForPath(driveId, path) || rowForPath(driveId, '');
            if (target) {
                target.classList.add('selected-row', 'selected');
                target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }

        function expandTreeToPath(driveId, path) {
            driveId = Number(driveId || 0);
            path = String(path || '').replace(/^\/+|\/+$/g, '');
            if (!driveId) {
                return Promise.resolve();
            }
            collapseOtherDrives(driveId);
            const driveRow = rowForPath(driveId, '');
            if (!driveRow) {
                return Promise.resolve();
            }

            const parts = path ? path.split('/').filter(Boolean) : [];
            let chain = Promise.resolve(driveRow);
            let currentPath = '';

            if (parts.length === 0) {
                selectTreeRow(driveId, '');
                return Promise.resolve();
            }

            chain = chain.then(function (row) {
                if (row.dataset.loaded === '1' && rowForPath(driveId, parts[0])) {
                    return row;
                }
                return loadTreeChildrenForRow(row).then(function () { return row; });
            });

            parts.forEach(function (part, index) {
                currentPath = currentPath ? currentPath + '/' + part : part;
                const expectedPath = currentPath;
                chain = chain.then(function () {
                    const row = rowForPath(driveId, expectedPath);
                    if (!row) {
                        return null;
                    }
                    if (index < parts.length - 1) {
                        const nextPath = expectedPath + '/' + parts[index + 1];
                        if (row.dataset.loaded === '1' && rowForPath(driveId, nextPath)) {
                            setToggle(row, '▾');
                            return row;
                        }
                        return loadTreeChildrenForRow(row).then(function () { return row; });
                    }
                    return row;
                });
            });

            return chain.then(function () {
                selectTreeRow(driveId, path);
            });
        }

        function setSelectedTreePath(driveId, path) {
            selectTreeRow(driveId, path || '');
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
                    return expandTreeToPath(currentDriveId, currentPath);
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
        expandTreeToPath(currentDriveId, currentPath);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAjaxExplorer);
    } else {
        initAjaxExplorer();
    }
})();
