document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.explorer-sidebar');
    const menu = document.getElementById('context-menu');
    const driveMenu = document.getElementById('drive-context-menu');

    function loadIgnoredFolderFilter() {
        if (window.__driveMasterIgnoredFolderFilterLoaded) {
            return;
        }
        window.__driveMasterIgnoredFolderFilterLoaded = true;
        const script = document.createElement('script');
        script.src = 'assets/ignored-folders-client.js?ts=' + Date.now();
        script.defer = true;
        document.head.appendChild(script);
    }

    function loadAjaxExplorerController() {
        if (window.__driveMasterAjaxExplorerLoaded) {
            return;
        }
        window.__driveMasterAjaxExplorerLoaded = true;
        const script = document.createElement('script');
        script.src = 'assets/files-ajax-explorer.js?ts=' + Date.now();
        script.defer = true;
        document.head.appendChild(script);
    }

    function loadContextActionsController() {
        if (window.__driveMasterFilesContextActionsLoaded) {
            return;
        }
        window.__driveMasterFilesContextActionsLoaded = true;
        const script = document.createElement('script');
        script.src = 'assets/files-context-actions.js?ts=' + Date.now();
        script.defer = true;
        document.head.appendChild(script);
    }

    function loadCompactStatusController() {
        if (window.__driveMasterCompactExplorerStatusLoaded) {
            return;
        }
        window.__driveMasterCompactExplorerStatusLoaded = true;
        const script = document.createElement('script');
        script.src = 'assets/files-status-compact.js?ts=' + Date.now();
        script.defer = true;
        document.head.appendChild(script);
    }

    function removeOldTopRightToast() {
        const toast = document.getElementById('tree-loading-toast');
        if (toast) {
            toast.remove();
        }
    }

    function ensureOverlay() {
        removeOldTopRightToast();
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

    function getUrlParam(row, key) {
        try {
            const url = new URL(row.dataset.url || '', window.location.href);
            return url.searchParams.get(key) || '';
        } catch (e) {
            return '';
        }
    }

    function hexToRgb(hex) {
        const match = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
        if (!match) {
            return null;
        }
        return { r: parseInt(match[1], 16), g: parseInt(match[2], 16), b: parseInt(match[3], 16) };
    }

    function ensureDriveMenuActions() {
        if (!driveMenu || driveMenu.dataset.expandActionsAdded === '1') {
            return;
        }
        driveMenu.dataset.expandActionsAdded = '1';
        const expandButton = document.createElement('button');
        expandButton.type = 'button';
        expandButton.dataset.driveAction = 'expand_drive';
        expandButton.textContent = 'Expand Drive';
        const collapseButton = document.createElement('button');
        collapseButton.type = 'button';
        collapseButton.dataset.driveAction = 'collapse_drive';
        collapseButton.textContent = 'Collapse Drive';
        const separator = document.createElement('hr');
        driveMenu.insertBefore(expandButton, driveMenu.firstChild);
        driveMenu.insertBefore(collapseButton, expandButton.nextSibling);
        driveMenu.insertBefore(separator, collapseButton.nextSibling);
    }

    function ensureFolderMenuActions() {
        if (!menu || menu.dataset.treeExpandActionsAdded === '1') {
            return;
        }
        menu.dataset.treeExpandActionsAdded = '1';
        const expandButton = document.createElement('button');
        expandButton.type = 'button';
        expandButton.dataset.contextAction = 'expand_folder';
        expandButton.textContent = 'Expand Folder';
        const collapseButton = document.createElement('button');
        collapseButton.type = 'button';
        collapseButton.dataset.contextAction = 'collapse_folder';
        collapseButton.textContent = 'Collapse Folder';
        const separator = document.createElement('hr');
        menu.insertBefore(expandButton, menu.firstChild);
        menu.insertBefore(collapseButton, expandButton.nextSibling);
        menu.insertBefore(separator, collapseButton.nextSibling);
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
        setToggle(row, '▸');
        row.dataset.loaded = '0';
    }

    function removeDriveDescendants(driveRow) {
        if (!driveRow) {
            return;
        }
        let next = driveRow.nextElementSibling;
        while (next && next.classList.contains('tree-folder')) {
            const remove = next;
            next = next.nextElementSibling;
            remove.remove();
        }
        setToggle(driveRow, '▸');
        driveRow.dataset.loaded = '0';
    }

    function insertChildrenAfter(row, html) {
        const template = document.createElement('template');
        template.innerHTML = html || '';
        const nodes = Array.from(template.content.children);
        let anchor = row;
        nodes.forEach(function (node) {
            anchor.insertAdjacentElement('afterend', node);
            anchor = node;
        });
        loadIgnoredFolderFilter();
        applyTreeCategoryColours(nodes);
        return nodes;
    }

    function loadChildren(row, message) {
        if (!row) {
            return Promise.resolve([]);
        }
        const driveId = row.classList.contains('tree-drive') ? row.dataset.driveId : (row.dataset.driveId || getUrlParam(row, 'drive_id'));
        const parentPath = row.classList.contains('tree-drive') ? '' : (row.dataset.path || getUrlParam(row, 'path'));
        const level = row.classList.contains('tree-drive') ? -1 : treeLevel(row);
        if (!driveId) {
            return Promise.resolve([]);
        }
        setToggle(row, '…');
        showOverlay(message || 'Loading folder data...');
        return fetch('files-tree-children.php?drive_id=' + encodeURIComponent(driveId) + '&parent_path=' + encodeURIComponent(parentPath || '') + '&level=' + encodeURIComponent(level), { cache: 'no-store' })
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
                const nodes = insertChildrenAfter(row, data.html || '');
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

    function expandAllFolders(row) {
        if (!row) {
            return Promise.resolve();
        }
        showOverlay('Expanding folder tree...');
        return loadChildren(row, 'Expanding folder tree...').then(function (children) {
            return children.reduce(function (promise, child) {
                return promise.then(function () { return expandAllFolders(child); });
            }, Promise.resolve());
        }).finally(function () {
            hideOverlay();
        });
    }

    function toggleFolder(row) {
        if (!row) {
            return;
        }
        if (row.dataset.loaded === '1') {
            if (row.classList.contains('tree-drive')) {
                removeDriveDescendants(row);
            } else {
                removeDescendants(row);
            }
        } else {
            loadChildren(row, row.classList.contains('tree-drive') ? 'Loading drive folders...' : 'Loading folder data...');
        }
    }

    function applyTreeCategoryColours(rows) {
        const candidates = Array.from(rows && rows.length ? rows : document.querySelectorAll('.tree-folder')).filter(function (row) {
            return row.dataset.id && row.dataset.categoryColourApplied !== '1';
        });
        if (!candidates.length) {
            return;
        }
        const ids = candidates.map(function (row) { return row.dataset.id; }).join(',');
        fetch('files-entry-category-colors.php?ids=' + encodeURIComponent(ids), { cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok || !data.colors) {
                    return;
                }
                candidates.forEach(function (row) {
                    const list = data.colors[row.dataset.id] || [];
                    if (!list.length) {
                        row.dataset.categoryColourApplied = '1';
                        return;
                    }
                    const rgb = hexToRgb(list[0].color);
                    if (!rgb) {
                        return;
                    }
                    row.style.backgroundColor = 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.18)';
                    row.style.borderLeft = '4px solid rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.85)';
                    row.title = 'Category: ' + list.map(function (item) { return item.name; }).join(', ');
                    row.dataset.categoryColourApplied = '1';
                });
            })
            .catch(function () {});
    }

    function wireTreeActions() {
        if (!sidebar || sidebar.dataset.treeContextLite === '1') {
            return;
        }
        sidebar.dataset.treeContextLite = '1';
        let contextDriveRow = null;

        sidebar.addEventListener('click', function (event) {
            const row = event.target.closest('.tree-drive,.tree-folder');
            if (!row) {
                return;
            }
            if (event.target.closest('.tree-toggle')) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                toggleFolder(row);
                return;
            }
        }, true);

        sidebar.addEventListener('dblclick', function (event) {
            const row = event.target.closest('.tree-drive,.tree-folder');
            if (!row) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            toggleFolder(row);
        }, true);

        sidebar.addEventListener('contextmenu', function (event) {
            const drive = event.target.closest('.tree-drive');
            if (drive) {
                contextDriveRow = drive;
            }
        }, true);

        if (menu) {
            menu.addEventListener('click', function (event) {
                const button = event.target.closest('[data-context-action]');
                if (!button || !menu.dataset.treeFolderMenu) {
                    return;
                }
                const folder = window.__driveMasterContextFolderRow || document.querySelector('.tree-folder.selected-row');
                if (button.dataset.contextAction === 'expand_folder') {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();
                    menu.classList.remove('visible');
                    expandAllFolders(folder);
                    return;
                }
                if (button.dataset.contextAction === 'collapse_folder') {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();
                    menu.classList.remove('visible');
                    removeDescendants(folder);
                }
            }, true);
        }

        if (driveMenu) {
            driveMenu.addEventListener('click', function (event) {
                const button = event.target.closest('[data-drive-action]');
                if (!button) {
                    return;
                }
                if (button.dataset.driveAction === 'expand_drive') {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();
                    driveMenu.classList.remove('visible');
                    expandAllFolders(contextDriveRow || document.querySelector('.tree-drive.selected-row'));
                }
                if (button.dataset.driveAction === 'collapse_drive') {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();
                    driveMenu.classList.remove('visible');
                    removeDriveDescendants(contextDriveRow || document.querySelector('.tree-drive.selected-row'));
                }
            }, true);
        }
    }

    function wireNavigationNotice() {
        window.addEventListener('beforeunload', function () {
            removeOldTopRightToast();
            showOverlay('Loading...');
        });
    }

    removeOldTopRightToast();
    loadIgnoredFolderFilter();
    loadAjaxExplorerController();
    loadContextActionsController();
    loadCompactStatusController();
    ensureDriveMenuActions();
    ensureFolderMenuActions();
    wireTreeActions();
    wireNavigationNotice();
    applyTreeCategoryColours();
});
