(function () {
    function initFilesContextActions() {
        if (window.__driveMasterFilesContextActionsInitialised) {
            return;
        }
        window.__driveMasterFilesContextActionsInitialised = true;

        const fileMenu = document.getElementById('context-menu');
        const driveMenu = document.getElementById('drive-context-menu');
        const dialog = document.getElementById('context-dialog');
        const form = document.getElementById('context-form');
        const actionInput = document.getElementById('context-action-input');
        const selectedInput = document.getElementById('context-selected-ids');
        const driveInput = document.getElementById('context-drive-id');
        const newNameInput = document.getElementById('context-new-name');
        const title = document.getElementById('context-dialog-title');
        const note = document.getElementById('context-dialog-note');
        const cancel = document.getElementById('context-cancel-button');

        if (!fileMenu || !driveMenu || !dialog || !form || !actionInput || !selectedInput || !driveInput) {
            return;
        }

        let contextRows = [];
        let contextDriveId = '';
        let contextTreeFolder = null;

        function refreshReturnUrl() {
            if (typeof window.driveMasterSyncFilesReturnUrl === 'function') {
                window.driveMasterSyncFilesReturnUrl();
            }
        }

        function hideMenus() {
            fileMenu.classList.remove('visible');
            driveMenu.classList.remove('visible');
            delete fileMenu.dataset.treeFolderMenu;
        }

        function preserveOrSelect(row) {
            const table = row.closest('.selectable-table');
            if (!table) {
                row.classList.add('selected-row');
                return [row];
            }
            const selected = Array.from(table.querySelectorAll('.selectable-row.selected-row'));
            if (selected.includes(row)) {
                return selected;
            }
            table.querySelectorAll('.selected-row').forEach(function (selectedRow) { selectedRow.classList.remove('selected-row'); });
            row.classList.add('selected-row');
            return [row];
        }

        function rowIds(rows) {
            return rows.map(function (row) { return row.dataset.id || ''; }).filter(Boolean);
        }

        function showFileMenu(row, event, isTreeFolder) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();

            if (!row.dataset.id && !isTreeFolder) {
                return;
            }

            refreshReturnUrl();
            contextTreeFolder = isTreeFolder ? row : null;
            if (!isTreeFolder) {
                contextRows = preserveOrSelect(row);
            } else {
                document.querySelectorAll('.tree-folder.selected-row, .tree-drive.selected-row').forEach(function (selected) { selected.classList.remove('selected-row'); });
                row.classList.add('selected-row');
                contextRows = [row];
            }
            contextDriveId = '';

            const ids = rowIds(contextRows);
            selectedInput.value = ids.join(',');
            driveInput.value = '';
            const renameButton = fileMenu.querySelector('[data-context-action="rename"]');
            if (renameButton) {
                renameButton.disabled = ids.length !== 1;
            }

            fileMenu.style.left = event.pageX + 'px';
            fileMenu.style.top = event.pageY + 'px';
            if (isTreeFolder) {
                fileMenu.dataset.treeFolderMenu = '1';
            } else {
                delete fileMenu.dataset.treeFolderMenu;
            }
            fileMenu.classList.add('visible');
            driveMenu.classList.remove('visible');
        }

        function showDriveMenu(row, event) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();

            refreshReturnUrl();
            contextRows = [];
            contextTreeFolder = null;
            contextDriveId = row.dataset.driveId || '';
            driveMenu.dataset.driveId = contextDriveId;

            document.querySelectorAll('.tree-drive.selected-row, .tree-folder.selected-row').forEach(function (selected) { selected.classList.remove('selected-row'); });
            row.classList.add('selected-row');

            driveMenu.style.left = event.pageX + 'px';
            driveMenu.style.top = event.pageY + 'px';
            driveMenu.classList.add('visible');
            fileMenu.classList.remove('visible');
        }

        function setAreaVisible(id, visible) {
            const area = document.getElementById(id);
            if (area) {
                area.style.display = visible ? 'block' : 'none';
            }
        }

        function openActionDialog(action, buttonText) {
            refreshReturnUrl();
            const ids = rowIds(contextRows);
            if (!ids.length) {
                return;
            }

            selectedInput.value = ids.join(',');
            driveInput.value = '';
            if (newNameInput) {
                newNameInput.value = contextRows.length === 1 ? (contextRows[0].dataset.entryName || '') : '';
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
                    ? 'Confirm delete job for selected item(s). This will queue a background job.'
                    : (['move', 'rename'].includes(action)
                        ? 'This queues a background operation job. The disk-modifying executor still needs to be completed safely.'
                        : 'Manual category changes are separate from optional rules.');
            }

            hideMenus();
            dialog.classList.remove('hidden');
        }

        function openRemoveDriveDialog() {
            refreshReturnUrl();
            if (!contextDriveId) {
                contextDriveId = driveMenu.dataset.driveId || '';
            }
            if (!contextDriveId) {
                return;
            }
            actionInput.value = 'remove_drive';
            selectedInput.value = '1';
            driveInput.value = contextDriveId;
            setAreaVisible('context-category-area', false);
            setAreaVisible('context-move-area', false);
            setAreaVisible('context-rename-area', false);
            if (title) {
                title.textContent = 'Remove drive from DB';
            }
            if (note) {
                note.textContent = 'This removes the drive record, file inventory, scan runs, and related scan jobs from the database. It does not delete files from disk.';
            }
            hideMenus();
            dialog.classList.remove('hidden');
        }

        document.addEventListener('contextmenu', function (event) {
            const folder = event.target.closest('.tree-folder');
            if (folder && folder.dataset.id) {
                showFileMenu(folder, event, true);
                return;
            }

            const drive = event.target.closest('.tree-drive');
            if (drive) {
                showDriveMenu(drive, event);
                return;
            }

            const row = event.target.closest('.explorer-row.selectable-row, .search-result-row.selectable-row');
            if (row) {
                showFileMenu(row, event, false);
            }
        }, true);

        document.addEventListener('click', function (event) {
            const contextButton = event.target.closest('#context-menu [data-context-action]');
            if (contextButton) {
                const action = contextButton.dataset.contextAction || '';
                if (contextButton.disabled) {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();
                    return;
                }
                if (action === 'expand_folder' || action === 'collapse_folder') {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                openActionDialog(action, contextButton.textContent.trim());
                return;
            }

            const removeDriveButton = event.target.closest('#drive-context-menu [data-drive-action="remove_drive"]');
            if (removeDriveButton) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                openRemoveDriveDialog();
                return;
            }

            if (!event.target.closest('.context-menu')) {
                hideMenus();
            }
        }, true);

        form.addEventListener('submit', function (event) {
            refreshReturnUrl();
            const action = actionInput.value;
            if ((action === 'queue_delete' || action === 'remove_drive') && !confirm('Are you sure?')) {
                event.preventDefault();
            }
        });

        if (cancel) {
            cancel.addEventListener('click', function () {
                dialog.classList.add('hidden');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFilesContextActions);
    } else {
        initFilesContextActions();
    }
})();
