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

    function resolveTreeFolder(row) {
        if (row.dataset.id) {
            return Promise.resolve(true);
        }
        const driveId = row.dataset.driveId || getUrlParam(row, 'drive_id');
        const path = row.dataset.path || getUrlParam(row, 'path');
        if (!driveId || !path) {
            return Promise.resolve(false);
        }
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
            .catch(function () { return false; });
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

    sidebar.addEventListener('contextmenu', function (event) {
        const folder = event.target.closest('.tree-folder');
        if (!folder) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        treeFolderRow = folder;

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
    });
});
