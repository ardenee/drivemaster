document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.explorer-sidebar');
    const menu = document.getElementById('context-menu');
    const driveMenu = document.getElementById('drive-context-menu');
    const dialog = document.getElementById('context-dialog');
    const form = document.getElementById('context-form');

    if (!sidebar || !menu || !dialog || !form) {
        return;
    }

    let treeFolderRow = null;
    let contextDriveRow = null;
    let toastShownAt = 0;
    let toastHideTimer = null;
    let activeTreeFetches = 0;

    function hideMenus() {
        menu.classList.remove('visible');
        if (driveMenu) {
            driveMenu.classList.remove('visible');
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

    function ensureTreeToast() {
        let toast = document.getElementById('tree-loading-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'tree-loading-toast';
            toast.className = 'tree-loading-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            document.body.appendChild(toast);
        }
        return toast;
    }

    function showTreeToast(text) {
        const toast = ensureTreeToast();
        if (toastHideTimer) {
            clearTimeout(toastHideTimer);
            toastHideTimer = null;
        }
        toastShownAt = Date.now();
        toast.textContent = text || 'Loading folder data...';
        toast.classList.add('visible');
    }

    function hideTreeToast() {
        const toast = document.getElementById('tree-loading-toast');
        if (!toast) {
            return;
        }
        const elapsed = Date.now() - toastShownAt;
        const delay = Math.max(0, 450 - elapsed);
        if (toastHideTimer) {
            clearTimeout(toastHideTimer);
        }
        toastHideTimer = setTimeout(function () {
            if (activeTreeFetches <= 0) {
                toast.classList.remove('visible');
            }
        }, delay);
    }

    function isTreeFetch(input) {
        const url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
        return url.includes('files-tree-children.php') || url.includes('tree-resolve.php') || url.includes('files-entry-resolve.php');
    }

    function installTreeFetchNotice() {
        if (window.__driveMasterTreeFetchNoticeInstalled || !window.fetch) {
            return;
        }
        window.__driveMasterTreeFetchNoticeInstalled = true;
        const originalFetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            if (!isTreeFetch(input)) {
                return originalFetch(input, init);
            }
            activeTreeFetches++;
            showTreeToast('Loading folder data...');
            return originalFetch(input, init).finally(function () {
                activeTreeFetches = Math.max(0, activeTreeFetches - 1);
                hideTreeToast();
            });
        };
    }

    function resolveTreeFolder(row) {
        if (row.dataset.id) {
            return Promise.resolve(true);
        }
        const driveId = row.dataset.driveId || getUrlParam(row, 'drive_id');
        const path = row.dataset.path || getUrlParam(row, 'path');
        if (!driveId || !path) {
            return Promise.resolve(false);
        }
        showTreeToast('Loading folder details...');
        return fetch('tree-resolve.php?drive_id=' + encodeURIComponent(driveId) + '&path=' + encodeURIComponent(path), { cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) {
                    return false;
                }
                row.dataset.id = String(data.id);
                row.dataset.entryType = 'folder';
                row.dataset.entryName = data.name || row.textContent.trim().replace(/^▸|^▾|^·|^…/, '').replace(/^📁/, '').trim();
                row.dataset.size = '0';
                row.dataset.isFile = '0';
                row.dataset.driveId = driveId;
                row.dataset.path = path;
                return true;
            })
            .catch(function () { return false; })
            .finally(hideTreeToast);
    }

    function selectedTreeFolderIds() {
        if (!treeFolderRow || !treeFolderRow.dataset.id) {
            return [];
        }
        return [treeFolderRow.dataset.id];
    }

    function setAreaVisible(id, visible) {
        const element = document.getElementById(id);
        if (element) {
            element.style.display = visible ? 'block' : 'none';
        }
    }

    function openActionDialog(action, buttonText) {
        const ids = selectedTreeFolderIds();
        if (!ids.length) {
            return;
        }

        const actionInput = document.getElementById('context-action-input');
        const selectedInput = document.getElementById('context-selected-ids');
        const driveInput = document.getElementById('context-drive-id');
        const newNameInput = document.getElementById('context-new-name');
        const title = document.getElementById('context-dialog-title');
        const note = document.getElementById('context-dialog-note');

        if (!actionInput || !selectedInput || !driveInput) {
            return;
        }

        selectedInput.value = ids.join(',');
        driveInput.value = '';
        if (newNameInput) {
            newNameInput.value = treeFolderRow.dataset.entryName || '';
        }

        setAreaVisible('context-category-area', action === 'add_category' || action === 'remove_category');
        setAreaVisible('context-move-area', action === 'move');
        setAreaVisible('context-rename-area', action === 'rename');

        const actionMap = {
            add_category: 'add_category',
            remove_category: 'remove_category',
            rename: 'queue_rename',
            move: 'queue_move',
            delete: 'queue_delete'
        };
        actionInput.value = actionMap[action] || '';

        if (title) {
            title.textContent = buttonText || 'Action';
        }
        if (note) {
            note.textContent = action === 'delete'
                ? 'Confirm delete job for selected folder. This will queue a background job.'
                : (['move', 'rename'].includes(action)
                    ? 'This queues a background operation job. The disk-modifying executor still needs to be completed safely.'
                    : 'Manual category changes are separate from optional rules.');
        }

        hideMenus();
        dialog.classList.remove('hidden');
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

    function treeLevel(row) {
        return Number(row.dataset.level || (row.classList.contains('tree-drive') ? -1 : 0));
    }

    function removeDriveDescendants(driveRow) {
        let next = driveRow.nextElementSibling;
        while (next && next.classList.contains('tree-folder')) {
            const remove = next;
            next = next.nextElementSibling;
            remove.remove();
        }
        const toggle = driveRow.querySelector('.tree-toggle');
        if (toggle) {
            toggle.textContent = '▸';
        }
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
        return nodes;
    }

    function loadChildren(row) {
        const driveId = row.classList.contains('tree-drive') ? row.dataset.driveId : (row.dataset.driveId || getUrlParam(row, 'drive_id'));
        const parentPath = row.classList.contains('tree-drive') ? '' : (row.dataset.path || getUrlParam(row, 'path'));
        const level = row.classList.contains('tree-drive') ? -1 : treeLevel(row);
        if (!driveId) {
            return Promise.resolve([]);
        }
        const toggle = row.querySelector('.tree-toggle');
        if (toggle) {
            toggle.textContent = '…';
        }
        showTreeToast('Loading folder data...');
        return fetch('files-tree-children.php?drive_id=' + encodeURIComponent(driveId) + '&parent_path=' + encodeURIComponent(parentPath || '') + '&level=' + encodeURIComponent(level), { cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) {
                    return [];
                }
                if (row.classList.contains('tree-drive')) {
                    removeDriveDescendants(row);
                }
                const nodes = insertChildrenAfter(row, data.html || '');
                row.dataset.loaded = '1';
                if (toggle) {
                    toggle.textContent = nodes.length ? '▾' : '·';
                }
                return nodes;
            })
            .catch(function () {
                if (toggle) {
                    toggle.textContent = '▸';
                }
                return [];
            })
            .finally(hideTreeToast);
    }

    function expandDrive(driveRow) {
        if (!driveRow) {
            return;
        }
        loadChildren(driveRow);
    }

    function collapseDrive(driveRow) {
        if (!driveRow) {
            return;
        }
        removeDriveDescendants(driveRow);
    }

    function showNavigationNotice(row) {
        if (!row || !row.dataset.url) {
            return;
        }
        showTreeToast('Opening folder. Loading files...');
        const toggle = row.querySelector('.tree-toggle');
        if (toggle) {
            toggle.textContent = '…';
        }
    }

    ensureTreeToast();
    installTreeFetchNotice();
    ensureDriveMenuActions();

    sidebar.addEventListener('click', function (event) {
        const row = event.target.closest('.tree-drive,.tree-folder');
        if (!row || event.target.closest('.tree-toggle')) {
            return;
        }
        showNavigationNotice(row);
    }, true);

    sidebar.addEventListener('dblclick', function (event) {
        const row = event.target.closest('.tree-drive,.tree-folder');
        if (!row || event.target.closest('.tree-toggle')) {
            return;
        }
        showNavigationNotice(row);
    }, true);

    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[href*="files.php"], a[href^="?drive_id"], a[href*="drive_id="]');
        if (!link) {
            return;
        }
        if (link.closest('.context-menu') || link.target === '_blank') {
            return;
        }
        showTreeToast('Loading files...');
    }, true);

    window.addEventListener('beforeunload', function () {
        showTreeToast('Loading...');
    });

    sidebar.addEventListener('contextmenu', function (event) {
        const folder = event.target.closest('.tree-folder');
        if (!folder) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        treeFolderRow = folder;
        contextDriveRow = null;

        document.querySelectorAll('.tree-folder.selected-row, .tree-drive.selected-row').forEach(function (row) {
            row.classList.remove('selected-row');
        });
        folder.classList.add('selected-row');

        const renameButton = menu.querySelector('[data-context-action="rename"]');
        if (renameButton) {
            renameButton.disabled = false;
        }

        resolveTreeFolder(folder).then(function (ok) {
            if (!ok) {
                return;
            }
            menu.style.left = event.pageX + 'px';
            menu.style.top = event.pageY + 'px';
            menu.classList.add('visible');
            if (driveMenu) {
                driveMenu.classList.remove('visible');
            }
        });
    }, true);

    sidebar.addEventListener('contextmenu', function (event) {
        const drive = event.target.closest('.tree-drive');
        if (!drive) {
            return;
        }
        contextDriveRow = drive;
        treeFolderRow = null;
    }, true);

    if (driveMenu) {
        driveMenu.addEventListener('click', function (event) {
            const button = event.target.closest('[data-drive-action]');
            if (!button) {
                return;
            }
            if (button.dataset.driveAction === 'expand_drive') {
                event.preventDefault();
                event.stopPropagation();
                hideMenus();
                expandDrive(contextDriveRow);
                return;
            }
            if (button.dataset.driveAction === 'collapse_drive') {
                event.preventDefault();
                event.stopPropagation();
                hideMenus();
                collapseDrive(contextDriveRow);
            }
        }, true);
    }

    menu.addEventListener('click', function (event) {
        if (!treeFolderRow) {
            return;
        }

        const button = event.target.closest('[data-context-action]');
        if (!button || button.disabled) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        openActionDialog(button.dataset.contextAction, button.textContent.trim());
    }, true);

    document.addEventListener('click', function (event) {
        if (!menu.contains(event.target)) {
            treeFolderRow = null;
        }
        if (driveMenu && !driveMenu.contains(event.target)) {
            contextDriveRow = null;
        }
    });
});
