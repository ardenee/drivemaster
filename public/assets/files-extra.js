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

    function hexToRgb(hex) {
        const match = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
        if (!match) {
            return null;
        }
        return {
            r: parseInt(match[1], 16),
            g: parseInt(match[2], 16),
            b: parseInt(match[3], 16)
        };
    }

    function applyCategoryColours(colors) {
        document.querySelectorAll('.category-badge').forEach(function (badge) {
            const name = badge.textContent.trim();
            const rgb = hexToRgb(colors[name]);
            if (!rgb) {
                return;
            }

            const strong = 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.55)';
            const soft = 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.18)';
            badge.style.backgroundColor = strong;
            badge.style.borderColor = 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.85)';

            const row = badge.closest('tr');
            if (row && !row.dataset.categoryColourApplied) {
                row.style.backgroundColor = soft;
                row.dataset.categoryColourApplied = '1';
            }
        });
    }

    document.querySelectorAll('.explorer-row.selectable-row').forEach(function (row) {
        row.addEventListener('contextmenu', function () {
            updateRenameMenuState(row);
        });
    });

    fetch('category-colors.php?ts=' + Date.now(), { cache: 'no-store' })
        .then(function (response) { return response.ok ? response.json() : { colors: {} }; })
        .then(function (data) { applyCategoryColours(data.colors || {}); })
        .catch(function () {});
});
