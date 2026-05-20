document.addEventListener('DOMContentLoaded', function () {
    function showToast(message) {
        let toast = document.getElementById('tree-loading-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'tree-loading-toast';
            toast.className = 'tree-loading-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.classList.add('visible');
    }

    function hideToast() {
        const toast = document.getElementById('tree-loading-toast');
        if (toast) {
            toast.classList.remove('visible');
        }
    }

    function showOverlay(message) {
        let overlay = document.getElementById('ajax-explorer-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'ajax-explorer-overlay';
            overlay.className = 'ajax-explorer-overlay';
            overlay.innerHTML = '<div class="ajax-explorer-overlay-box"></div>';
            document.body.appendChild(overlay);
        }
        const box = overlay.querySelector('.ajax-explorer-overlay-box');
        if (box) {
            box.textContent = message || 'Loading...';
        }
        overlay.classList.add('visible');
    }

    function hideOverlay() {
        const overlay = document.getElementById('ajax-explorer-overlay');
        if (overlay) {
            overlay.classList.remove('visible');
        }
    }

    function removeOldTopRightToast() {
        const oldToast = document.getElementById('tree-loading-toast');
        if (oldToast) {
            oldToast.remove();
        }
    }

    function currentSelectedTreeRow() {
        return document.querySelector('.tree-folder.selected-row') || document.querySelector('.tree-drive.selected-row');
    }

    function treeLevel(row) {
        if (!row || row.classList.contains('tree-drive')) {
            return -1;
        }
        return Number(row.dataset.level || 0);
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

    function removeDriveDescendants(row) {
        if (!row) {
            return;
        }
        let next = row.nextElementSibling;
        while (next && next.classList.contains('tree-folder')) {
            const remove = next;
            next = next.nextElementSibling;
            remove.remove();
        }
        row.dataset.loaded = '0';
        setToggle(row, '▸');
    }

    function loadIgnoredFolderFilter() {
        const controls = document.querySelector('[data-ignore-filter-controls]');
        if (!controls) {
            return;
        }
        const form = controls.closest('form');
        const driveSelect = controls.querySelector('[data-drive-select]');
        const pathInput = controls.querySelector('[data-path-input]');
        const showIgnoredInput = controls.querySelector('[data-show-ignored-input]');
        const showIgnoredToggle = controls.querySelector('[data-show-ignored-toggle]');
        if (!form || !driveSelect || !pathInput || !showIgnoredInput || !showIgnoredToggle) {
            return;
        }
        document.querySelectorAll('.tree-folder[data-path]').forEach(function (row) {
            row.addEventListener('click', function () {
                pathInput.value = row.dataset.path || '';
            });
            row.addEventListener('dblclick', function () {
                pathInput.value = row.dataset.path || '';
                driveSelect.value = row.dataset.driveId || driveSelect.value;
                form.submit();
            });
        });
        document.querySelectorAll('.tree-drive[data-drive-id]').forEach(function (row) {
            row.addEventListener('click', function () {
                driveSelect.value = row.dataset.driveId || driveSelect.value;
                pathInput.value = '';
            });
        });
        showIgnoredToggle.addEventListener('change', function () {
            showIgnoredInput.value = showIgnoredToggle.checked ? '1' : '0';
            form.submit();
        });
    }

    function loadAjaxExplorerController() {
        const explorer = document.querySelector('.ajax-explorer');
        if (!explorer) {
            return;
        }
        const tree = explorer.querySelector('.explorer-sidebar');
        const tableBody = explorer.querySelector('.explorer-table tbody');
        if (!tree || !tableBody) {
            return;
        }
        tree.addEventListener('click', function (event) {
            const row = event.target.closest('.tree-folder, .tree-drive');
            if (!row) {
                return;
            }
            if (event.target.closest('.tree-toggle')) {
                event.preventDefault();
                if (row.dataset.loaded === '1') {
                    if (row.classList.contains('tree-drive')) {
                        removeDriveDescendants(row);
                    } else {
                        removeDescendants(row);
                    }
                }
            }
        });
    }

    function loadContextActionsController() {
        const contextMenu = document.getElementById('context-menu');
        const contextForm = document.getElementById('context-form');
        const contextDialog = document.getElementById('context-dialog');
        if (!contextMenu || !contextForm || !contextDialog) {
            return;
        }
        let currentRows = [];
        let currentDriveId = null;
        const actionInput = document.getElementById('context-action-input');
        const selectedIdsInput = document.getElementById('context-selected-ids');
        const driveIdInput = document.getElementById('context-drive-id');
        const categoryArea = document.getElementById('context-category-area');
        const moveArea = document.getElementById('context-move-area');
        const renameArea = document.getElementById('context-rename-area');
        const dialogTitle = document.getElementById('context-dialog-title');
        const dialogNote = document.getElementById('context-dialog-note');
        const cancelButton = document.getElementById('context-cancel-button');

        function hideContextMenu() {
            contextMenu.classList.remove('visible');
        }

        function selectedRows() {
            const rows = Array.from(document.querySelectorAll('.selectable-row.selected-row[data-id]'));
            if (rows.length > 0) {
                return rows;
            }
            return currentRows;
        }

        function showDialog(action) {
            currentRows = selectedRows();
            if (!currentRows.length) {
                return;
            }
            const ids = currentRows.map(function (row) { return row.dataset.id; }).filter(Boolean);
            if (!ids.length) {
                return;
            }
            currentDriveId = currentRows[0].dataset.driveId || currentDriveId;
            actionInput.value = action;
            selectedIdsInput.value = ids.join(',');
            driveIdInput.value = currentDriveId || '';
            categoryArea.style.display = action === 'add_category' || action === 'remove_category' ? '' : 'none';
            moveArea.style.display = action === 'move' ? '' : 'none';
            renameArea.style.display = action === 'rename' && ids.length === 1 ? '' : 'none';
            const newName = document.getElementById('context-new-name');
            if (newName && action === 'rename' && ids.length === 1) {
                newName.value = currentRows[0].dataset.entryName || '';
            }
            const labels = {
                add_category: 'Add to category',
                remove_category: 'Remove from category',
                rename: 'Rename',
                move: 'Move',
                delete: 'Delete'
            };
            dialogTitle.textContent = labels[action] || 'Action';
            dialogNote.textContent = ids.length + ' item(s) selected.';
            contextDialog.classList.remove('hidden');
        }

        document.addEventListener('contextmenu', function (event) {
            const row = event.target.closest('.selectable-row[data-id]');
            if (!row) {
                return;
            }
            event.preventDefault();
            if (!row.classList.contains('selected-row')) {
                document.querySelectorAll('.selectable-row.selected-row').forEach(function (selected) { selected.classList.remove('selected-row'); });
                row.classList.add('selected-row');
            }
            currentRows = selectedRows();
            currentDriveId = row.dataset.driveId || null;
            contextMenu.style.left = event.pageX + 'px';
            contextMenu.style.top = event.pageY + 'px';
            contextMenu.classList.add('visible');
        });

        contextMenu.addEventListener('click', function (event) {
            const button = event.target.closest('[data-context-action]');
            if (!button) {
                return;
            }
            event.preventDefault();
            hideContextMenu();
            showDialog(button.dataset.contextAction);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('#context-menu')) {
                hideContextMenu();
            }
        });

        if (cancelButton) {
            cancelButton.addEventListener('click', function () {
                contextDialog.classList.add('hidden');
            });
        }
    }

    function loadCompactStatusController() {
        document.querySelectorAll('.selectable-table').forEach(function (table) {
            const targetId = table.dataset.statusTarget;
            if (!targetId) {
                return;
            }
            const status = document.getElementById(targetId);
            if (!status) {
                return;
            }
            table.addEventListener('click', function () {
                let selectedSize = 0;
                const selected = table.querySelectorAll('.selectable-row.selected-row');
                selected.forEach(function (row) {
                    selectedSize += Number(row.dataset.size || 0);
                });
                status.textContent = 'Selected: ' + selected.length + ' | Selected size: ' + selectedSize + ' bytes';
            });
        });
    }

    function ensureDriveMenuActions() {
        const driveMenu = document.getElementById('drive-context-menu');
        if (!driveMenu) {
            return;
        }
        let contextDriveRow = null;
        document.addEventListener('contextmenu', function (event) {
            const driveRow = event.target.closest('.tree-drive');
            if (!driveRow) {
                return;
            }
            contextDriveRow = driveRow;
            driveMenu.style.left = event.pageX + 'px';
            driveMenu.style.top = event.pageY + 'px';
            driveMenu.classList.add('visible');
        });
        document.addEventListener('click', function (event) {
            if (!event.target.closest('#drive-context-menu')) {
                driveMenu.classList.remove('visible');
            }
        });
        driveMenu.addEventListener('click', function (event) {
            const button = event.target.closest('[data-drive-action]');
            if (!button) {
                return;
            }
            if (button.dataset.driveAction === 'remove_drive') {
                event.preventDefault();
                const form = document.getElementById('context-form');
                if (!form || !contextDriveRow) {
                    return;
                }
                document.getElementById('context-action-input').value = 'remove_drive';
                document.getElementById('context-drive-id').value = contextDriveRow.dataset.driveId || '';
                form.submit();
            }
        });
    }

    function ensureFolderMenuActions() {
        return;
    }

    function wireTreeActions() {
        return;
    }

    function wireNavigationNotice() {
        window.addEventListener('beforeunload', function () {
            removeOldTopRightToast();
            showOverlay('Loading...');
        });
    }

    function applyTreeCategoryColours() {
        return;
    }

    if (window.driveMasterPageMode === 'category-status') {
        loadContextActionsController();
        return;
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
