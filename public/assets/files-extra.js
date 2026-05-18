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

    function normaliseHex(value) {
        value = String(value || '').trim();
        if (value !== '' && value[0] !== '#') {
            value = '#' + value;
        }
        return /^#[0-9a-fA-F]{6}$/.test(value) ? value.toLowerCase() : '';
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

    function randomReadableColour() {
        const hue = Math.floor(Math.random() * 360);
        const saturation = 62 + Math.floor(Math.random() * 18);
        const lightness = 72 + Math.floor(Math.random() * 10);
        return hslToHex(hue, saturation, lightness);
    }

    function hslToHex(h, s, l) {
        s /= 100;
        l /= 100;
        const c = (1 - Math.abs(2 * l - 1)) * s;
        const x = c * (1 - Math.abs((h / 60) % 2 - 1));
        const m = l - c / 2;
        let r = 0, g = 0, b = 0;
        if (h < 60) { r = c; g = x; b = 0; }
        else if (h < 120) { r = x; g = c; b = 0; }
        else if (h < 180) { r = 0; g = c; b = x; }
        else if (h < 240) { r = 0; g = x; b = c; }
        else if (h < 300) { r = x; g = 0; b = c; }
        else { r = c; g = 0; b = x; }
        const toHex = function (value) { return Math.round((value + m) * 255).toString(16).padStart(2, '0'); };
        return '#' + toHex(r) + toHex(g) + toHex(b);
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

    function updateCategoryColourPreview() {
        const colorInput = document.querySelector('input[name="color_hex"]');
        const hexInput = document.getElementById('category-hex-input');
        const preview = document.getElementById('category-colour-preview');
        const name = document.querySelector('input[name="name"]');
        if (!colorInput || !preview) {
            return;
        }

        const colour = normaliseHex(colorInput.value) || '#dbeafe';
        if (hexInput && document.activeElement !== hexInput) {
            hexInput.value = colour;
        }
        preview.style.backgroundColor = colour;
        preview.style.borderColor = colour;
        preview.textContent = name && name.value.trim() ? name.value.trim() : 'Category';
    }

    function enhanceCategoryColourPicker() {
        const colorInput = document.querySelector('input[name="color_hex"]');
        if (!colorInput || colorInput.dataset.enhanced === '1') {
            return;
        }
        colorInput.dataset.enhanced = '1';

        const parentLabel = colorInput.closest('label');
        if (parentLabel) {
            parentLabel.classList.add('colour-picker-label');
            parentLabel.addEventListener('click', function (event) {
                if (event.target === parentLabel) {
                    event.preventDefault();
                }
            });
        }

        const wrapper = document.createElement('span');
        wrapper.className = 'colour-custom-control';
        colorInput.parentNode.insertBefore(wrapper, colorInput);
        wrapper.appendChild(colorInput);

        const hexInput = document.createElement('input');
        hexInput.type = 'text';
        hexInput.id = 'category-hex-input';
        hexInput.className = 'colour-hex-input';
        hexInput.maxLength = 7;
        hexInput.placeholder = '#dbeafe';
        hexInput.value = normaliseHex(colorInput.value) || '#dbeafe';
        wrapper.appendChild(hexInput);

        const randomButton = document.createElement('button');
        randomButton.type = 'button';
        randomButton.className = 'colour-random-button';
        randomButton.textContent = 'Random';
        wrapper.appendChild(randomButton);

        const note = document.createElement('span');
        note.className = 'colour-picker-note';
        note.textContent = 'Use the square picker, enter any #RRGGBB colour, or generate a readable random colour. The palette below is only quick picks.';
        colorInput.parentNode.appendChild(note);

        colorInput.addEventListener('input', updateCategoryColourPreview);
        hexInput.addEventListener('input', function () {
            const normalised = normaliseHex(hexInput.value);
            if (normalised) {
                colorInput.value = normalised;
                updateCategoryColourPreview();
            }
        });
        randomButton.addEventListener('click', function () {
            const colour = randomReadableColour();
            colorInput.value = colour;
            hexInput.value = colour;
            updateCategoryColourPreview();
        });

        updateCategoryColourPreview();
    }

    function cleanHeaderText(th) {
        return th.textContent.replace(/\s*[▲▼]$/, '').trim();
    }

    function cellSortValue(row, index) {
        const cell = row.children[index];
        if (!cell) {
            return '';
        }
        return cell.textContent.trim();
    }

    function numericValue(value) {
        const text = String(value || '').trim().toLowerCase();
        if (text === '') {
            return NaN;
        }
        const bytesMatch = text.match(/^([0-9,.]+)\s*(b|kb|mb|gb|tb|pb)$/i);
        if (bytesMatch) {
            const number = Number(bytesMatch[1].replace(/,/g, ''));
            const units = { b: 1, kb: 1024, mb: 1024 ** 2, gb: 1024 ** 3, tb: 1024 ** 4, pb: 1024 ** 5 };
            return number * units[bytesMatch[2].toLowerCase()];
        }
        const plain = Number(text.replace(/,/g, '').replace(/[^0-9.-]/g, ''));
        return Number.isFinite(plain) && /[0-9]/.test(text) ? plain : NaN;
    }

    function shouldSkipSortableHeader(label) {
        return ['actions', 'select', ''].includes(label.toLowerCase());
    }

    function enableSharedTableSorting() {
        document.querySelectorAll('table').forEach(function (table) {
            if (table.dataset.sortableEnhanced === '1') {
                return;
            }
            const tbody = table.tBodies && table.tBodies[0];
            const headers = Array.from(table.querySelectorAll('thead th'));
            if (!tbody || headers.length < 2) {
                return;
            }
            table.dataset.sortableEnhanced = '1';
            headers.forEach(function (th, index) {
                const label = cleanHeaderText(th);
                if (shouldSkipSortableHeader(label)) {
                    return;
                }
                th.classList.add('sortable-click');
                th.title = th.title || 'Click to sort';
                th.addEventListener('click', function (event) {
                    if (event.target.closest('a, button, input, select, textarea, form')) {
                        return;
                    }
                    const current = table.dataset.sortIndex === String(index) ? table.dataset.sortDir : 'desc';
                    const next = current === 'asc' ? 'desc' : 'asc';
                    table.dataset.sortIndex = String(index);
                    table.dataset.sortDir = next;
                    headers.forEach(function (header) {
                        header.textContent = cleanHeaderText(header);
                    });
                    th.textContent = cleanHeaderText(th) + (next === 'asc' ? ' ▲' : ' ▼');
                    const rows = Array.from(tbody.rows);
                    rows.sort(function (a, b) {
                        const av = cellSortValue(a, index);
                        const bv = cellSortValue(b, index);
                        const an = numericValue(av);
                        const bn = numericValue(bv);
                        let result;
                        if (!Number.isNaN(an) && !Number.isNaN(bn)) {
                            result = an - bn;
                        } else {
                            result = av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
                        }
                        return next === 'asc' ? result : -result;
                    });
                    rows.forEach(function (row) { tbody.appendChild(row); });
                });
            });
        });
    }

    document.querySelectorAll('.explorer-row.selectable-row').forEach(function (row) {
        row.addEventListener('contextmenu', function () {
            updateRenameMenuState(row);
        });
    });

    enhanceCategoryColourPicker();
    enableSharedTableSorting();

    document.querySelectorAll('.colour-swatch').forEach(function (swatch) {
        swatch.addEventListener('click', function () {
            const input = document.querySelector('input[name="color_hex"]');
            const hexInput = document.getElementById('category-hex-input');
            if (input) {
                input.value = swatch.dataset.colour;
                if (hexInput) {
                    hexInput.value = swatch.dataset.colour;
                }
                updateCategoryColourPreview();
            }
        });
    });
    document.querySelectorAll('input[name="color_hex"], input[name="name"]').forEach(function (input) {
        input.addEventListener('input', updateCategoryColourPreview);
    });

    fetch('category-colors.php?ts=' + Date.now(), { cache: 'no-store' })
        .then(function (response) { return response.ok ? response.json() : { colors: {} }; })
        .then(function (data) { applyCategoryColours(data.colors || {}); })
        .catch(function () {});
});
