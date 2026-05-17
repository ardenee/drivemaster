document.addEventListener('DOMContentLoaded', function () {
    function explorerSelectionRows(row) {
        const table = row.closest('.selectable-table');
        if (!table) {
            return [row];
        }
        const selected = Array.from(table.querySelectorAll('.selectable-row.selected-row'));
        return selected.includes(row) ? selected : [row];
    }

    function updateRenameMenuState(row) {
        const renameButton = document.querySelector('[data-context-action="rename"]');
        if (!renameButton || !row) {
            return;
        }
        renameButton.disabled = explorerSelectionRows(row).length !== 1;
    }

    document.querySelectorAll('.explorer-row.selectable-row').forEach(function (row) {
        row.addEventListener('contextmenu', function () {
            updateRenameMenuState(row);
        });
    });
});
