(function () {
    function initCategoryWorkflowAjax() {
        if (window.__driveMasterCategoryWorkflowAjaxInitialised) {
            return;
        }
        window.__driveMasterCategoryWorkflowAjaxInitialised = true;

        const explorerPanel = document.getElementById('workflow-explorer');
        if (!explorerPanel) {
            return;
        }

        const main = explorerPanel.querySelector('.explorer-main');
        const breadcrumbs = main ? main.querySelector('.breadcrumbs') : null;
        const table = main ? main.querySelector('.explorer-table') : null;
        const tbody = table ? table.querySelector('tbody') : null;
        const status = document.getElementById('workflow-status');

        if (!main || !breadcrumbs || !table || !tbody || !status) {
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const urlDriveId = Number(urlParams.get('drive_id') || 0);
        const selectedDriveId = Number(document.querySelector('.tree-drive.selected')?.dataset.driveId || 0);
        let currentDriveId = Number(urlDriveId || selectedDriveId || document.querySelector('.tree-drive')?.dataset.driveId || 0);
        let currentPath = urlParams.has('path') ? (urlParams.get('path') || '') : '';
        let currentPage = Number(urlParams.get('page') || 1);
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
                overlay.innerHTML = '<div class="ajax-explorer-overlay-box">Loading workflow folder...</div>';
                document.body.appendChild(overlay);
            }
            return overlay;
        }

        function showOverlay(text) {
            const overlay = ensureOverlay();
            const box = overlay.querySelector('.ajax-explorer-overlay-box');
            if (box) {
                box.textContent = text || 'Loading workflow folder...';
            }
            overlay.classList.add('visible');
        }

        function hideOverlay() {
            const overlay = document.getElementById('ajax-explorer-overlay');
            if (overlay) {
                overlay.classList.remove('visible');
            }
        }

        function currentWorkflowUrl(driveId, path, page, sort, dir) {
            const url = new URL(window.location.href);
            url.pathname = url.pathname.replace(/[^/]*$/, 'category-workflow.php');
            url.searchParams.set('drive_id', String(driveId));
            url.searchParams.set('path', path || '');
            url.searchParams.set('page', String(page || 1));
            url.searchParams.set('sort', sort || 'name');
            url.searchParams.set('dir', dir || 'asc');
            url.searchParams.delete('selected_entry_id');
            url.hash = 'workflow-explorer';
            return url;
        }

        function syncReturnUrl(driveId, path, page, sort, dir) {
            const input = document.querySelector('#context-form input[name="return_url"]');
            if (!input) {
                return;
            }
            const url = currentWorkflowUrl(driveId || currentDriveId, path ?? currentPath, page || currentPage, sort || currentSort, dir || currentDir);
            input.value = url.pathname + url.search + url.hash;
        }

        window.driveMasterSyncFilesReturnUrl = function () {
            syncReturnUrl(currentDriveId, currentPath, currentPage, currentSort, currentDir);
        };

        function driveRowFor(driveId) {
            return document.querySelector('.tree-drive[data-drive-id="' + String(driveId).replace(/"/g, '\\"') + '"]');
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

        function removeDescendants(row) {
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

        function removeDriveDescendants(driveRow) {
            let next = driveRow ? driveRow.nextElementSibling : null;
            while (next && next.classList.contains('tree-folder')) {
                const remove = next;
                next = next.nextElementSibling;
                remove.remove();
            }
            if (driveRow) {
                driveRow.dataset.loaded = '0';
            }
            setToggle(driveRow, '▸');
        }

        function collapseOtherDrives(driveId) {
            document.querySelectorAll('.tree-drive').forEach(function (driveRow) {
                if (String(driveRow.dataset.driveId || '') === String(driveId)) {
                    return;
                }
                removeDriveDescendants(driveRow);
                driveRow.classList.remove('selected-row', 'selected');
            });
        }

        function selectTreeRow(driveId, path) {
            document.querySelectorAll('.tree-drive,.tree-folder').forEach(function (row) {
                row.classList.remove('selected-row', 'selected');
            });
            const target = path
                ? Array.from(document.querySelectorAll('.tree-folder')).find(function (row) { return String(row.dataset.driveId || '') === String(driveId) && String(row.dataset.path || '') === String(path || ''); })
                : driveRowFor(driveId);
            const fallback = driveRowFor(driveId);
            const row = target || fallback;
            if (row) {
                row.classList.add('selected-row', 'selected');
                row.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }

        function syncTreeToPath(driveId, path) {
            driveId = Number(driveId || 0);
            path = String(path || '').replace(/^\/+|\/+$/g, '');
            const driveRow = driveRowFor(driveId);
            if (!driveRow) {
                return Promise.resolve();
            }

            collapseOtherDrives(driveId);
            setToggle(driveRow, '…');

            const params = new URLSearchParams();
            params.set('drive_id', String(driveId));
            params.set('path', path);

            return fetch('category-workflow-tree-branch.php?' + params.toString(), { cache: 'no-store' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        throw new Error(data.error || 'Could not load workflow tree branch.');
                    }
                    removeDriveDescendants(driveRow);
                    if (data.html) {
                        const template = document.createElement('template');
                        template.innerHTML = data.html;
                        let anchor = driveRow;
                        Array.from(template.content.children).forEach(function (node) {
                            anchor.insertAdjacentElement('afterend', node);
                            anchor = node;
                        });
                    }
                    driveRow.dataset.loaded = data.html ? '1' : '0';
                    setToggle(driveRow, data.html ? '▾' : '·');
                    selectTreeRow(driveId, path);
                })
                .catch(function () {
                    driveRow.dataset.loaded = '0';
                    setToggle(driveRow, '▸');
                    selectTreeRow(driveId, path);
                });
        }

        function updateStatus(data) {
            const s = data.status || {};
            status.dataset.totalCount = String(s.total_count || 0);
            status.dataset.fileCount = String(s.file_count || 0);
            status.dataset.folderCount = String(s.folder_count || 0);
            status.dataset.totalSize = String(s.total_size || 0);
            status.textContent = 'Unassigned in this folder: ' + status.dataset.totalCount + ' | Files: ' + status.dataset.fileCount + ' | Folders: ' + status.dataset.folderCount + ' | File size: ' + formatBytes(status.dataset.totalSize) + ' | Selected: 0 | Selected size: 0 B';
        }

        function updateSelectedStatus() {
            let selectedSize = 0;
            const selectedRows = tbody.querySelectorAll('.selectable-row.selected-row');
            selectedRows.forEach(function (selected) {
                if (selected.dataset.isFile === '1') {
                    selectedSize += Number(selected.dataset.size || 0);
                }
            });
            status.textContent = 'Unassigned in this folder: ' + status.dataset.totalCount + ' | Files: ' + status.dataset.fileCount + ' | Folders: ' + status.dataset.folderCount + ' | File size: ' + formatBytes(status.dataset.totalSize) + ' | Selected: ' + selectedRows.length + ' | Selected size: ' + formatBytes(selectedSize);
        }

        function wireNewRows() {
            tbody.querySelectorAll('.explorer-row').forEach(function (row) {
                if (row.dataset.workflowWired === '1') {
                    return;
                }
                row.dataset.workflowWired = '1';
                row.addEventListener('click', function (event) {
                    if (!row.classList.contains('selectable-row')) {
                        return;
                    }
                    if (!event.ctrlKey && !event.metaKey) {
                        tbody.querySelectorAll('.selected-row').forEach(function (selected) { selected.classList.remove('selected-row'); });
                    }
                    row.classList.add('selected-row');
                    updateSelectedStatus();
                });
                row.addEventListener('dblclick', function (event) {
                    if (row.dataset.workflowPath !== undefined && row.dataset.workflowPath !== '') {
                        event.preventDefault();
                        event.stopPropagation();
                        loadWorkflow(Number(row.dataset.driveId || currentDriveId), row.dataset.workflowPath || '', 1, currentSort, currentDir, true, 'Opening workflow folder...');
                    }
                });
            });
        }

        function updateUrl(driveId, path, page, sort, dir, pushState) {
            const url = currentWorkflowUrl(driveId, path, page, sort, dir);
            const state = { driveId: Number(driveId), path: path || '', page: Number(page || 1), sort: sort || 'name', dir: dir || 'asc' };
            if (pushState) {
                window.history.pushState(state, '', url.toString());
            } else {
                window.history.replaceState(state, '', url.toString());
            }
            syncReturnUrl(driveId, path, page, sort, dir);
        }

        function loadWorkflow(driveId, path, page, sort, dir, pushState, message) {
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
            syncReturnUrl(currentDriveId, currentPath, currentPage, currentSort, currentDir);
            selectTreeRow(driveId, path);

            if (loading) {
                queuedLoad = { driveId: driveId, path: path, page: page, sort: sort, dir: dir, pushState: false, message: 'Loading requested workflow folder next...' };
                showOverlay('Loading requested workflow folder next...');
                return;
            }

            loading = true;
            showOverlay(message || 'Loading workflow folder...');
            const params = new URLSearchParams();
            params.set('drive_id', String(driveId));
            params.set('path', path);
            params.set('page', String(page));
            params.set('sort', sort);
            params.set('dir', dir);
            fetch('category-workflow-data.php?' + params.toString(), { cache: 'no-store' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        throw new Error(data.error || 'Could not load workflow folder.');
                    }
                    currentDriveId = Number(data.drive_id || driveId);
                    currentPath = data.path || '';
                    currentPage = Number(data.page || page || 1);
                    currentSort = sort;
                    currentDir = dir;
                    syncReturnUrl(currentDriveId, currentPath, currentPage, currentSort, currentDir);
                    breadcrumbs.innerHTML = data.breadcrumbs_html || '';
                    tbody.innerHTML = data.rows_html || '';
                    main.querySelectorAll('.workflow-explorer-pager').forEach(function (pager) { pager.remove(); });
                    if (data.pager_top_html) {
                        table.insertAdjacentHTML('beforebegin', data.pager_top_html);
                    }
                    if (data.pager_bottom_html) {
                        status.insertAdjacentHTML('beforebegin', data.pager_bottom_html);
                    }
                    updateStatus(data);
                    wireNewRows();
                    return syncTreeToPath(currentDriveId, currentPath);
                })
                .catch(function (error) {
                    alert(error.message || 'Could not load workflow folder.');
                })
                .finally(function () {
                    loading = false;
                    hideOverlay();
                    if (queuedLoad) {
                        const next = queuedLoad;
                        queuedLoad = null;
                        loadWorkflow(next.driveId, next.path, next.page, next.sort, next.dir, next.pushState, next.message);
                    }
                });
        }

        function loadChildren(row, message) {
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
            showOverlay(message || 'Loading workflow folders...');
            return fetch('category-workflow-tree-children.php?drive_id=' + encodeURIComponent(driveId) + '&parent_path=' + encodeURIComponent(parentPath || '') + '&level=' + encodeURIComponent(level), { cache: 'no-store' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        return [];
                    }
                    if (row.classList.contains('tree-drive')) {
                        removeDriveDescendants(row);
                    } else {
                        removeDescendants(row);
                    }
                    const template = document.createElement('template');
                    template.innerHTML = data.html || '';
                    const nodes = Array.from(template.content.children);
                    let anchor = row;
                    nodes.forEach(function (node) {
                        anchor.insertAdjacentElement('afterend', node);
                        anchor = node;
                    });
                    row.dataset.loaded = nodes.length ? '1' : '0';
                    setToggle(row, nodes.length ? '▾' : '·');
                    return nodes;
                })
                .catch(function () {
                    row.dataset.loaded = '0';
                    setToggle(row, '▸');
                    return [];
                })
                .finally(function () {
                    hideOverlay();
                });
        }

        explorerPanel.addEventListener('click', function (event) {
            const treeRow = event.target.closest('.tree-drive,.tree-folder');
            if (treeRow && event.target.closest('.tree-toggle')) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                loadChildren(treeRow, treeRow.classList.contains('tree-drive') ? 'Loading drive folders...' : 'Loading workflow folders...');
                return;
            }
            if (treeRow) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                if (treeClickTimer) {
                    clearTimeout(treeClickTimer);
                }
                treeClickTimer = setTimeout(function () {
                    treeClickTimer = null;
                    loadWorkflow(Number(treeRow.dataset.driveId || currentDriveId), treeRow.dataset.path || '', 1, currentSort, currentDir, true, 'Opening workflow folder...');
                }, 220);
                return;
            }
            const breadcrumbLink = event.target.closest('.breadcrumbs a[data-workflow-path]');
            if (breadcrumbLink) {
                event.preventDefault();
                loadWorkflow(Number(breadcrumbLink.dataset.driveId || currentDriveId), breadcrumbLink.dataset.workflowPath || '', 1, currentSort, currentDir, true, 'Opening workflow folder...');
                return;
            }
            const pageLink = event.target.closest('[data-workflow-page]');
            if (pageLink) {
                event.preventDefault();
                loadWorkflow(currentDriveId, currentPath, Number(pageLink.dataset.workflowPage || 1), currentSort, currentDir, true, 'Loading workflow page...');
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
                loadWorkflow(currentDriveId, currentPath, 1, sort, dir, true, 'Sorting workflow entries...');
            });
        });

        window.addEventListener('popstate', function (event) {
            const state = event.state;
            if (state && state.driveId) {
                loadWorkflow(Number(state.driveId), state.path || '', Number(state.page || 1), state.sort || 'name', state.dir || 'asc', false, 'Loading workflow folder...');
            }
        });

        wireNewRows();
        updateUrl(currentDriveId, currentPath, currentPage, currentSort, currentDir, false);
        syncTreeToPath(currentDriveId, currentPath);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCategoryWorkflowAjax);
    } else {
        initCategoryWorkflowAjax();
    }
})();
