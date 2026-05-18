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
        return { r: parseInt(match[1], 16), g: parseInt(match[2], 16), b: parseInt(match[3], 16) };
    }

    function formatBytesLocal(bytes) {
        bytes = Number(bytes || 0);
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        let index = 0;
        while (bytes >= 1024 && index < units.length - 1) {
            bytes /= 1024;
            index++;
        }
        return index === 0 ? Math.round(bytes) + ' ' + units[index] : bytes.toFixed(2) + ' ' + units[index];
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
        return cell ? cell.textContent.trim() : '';
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
                    headers.forEach(function (header) { header.textContent = cleanHeaderText(header); });
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

    function extractUrlParam(row, key) {
        try {
            const rawUrl = row.dataset.url || '';
            const url = new URL(rawUrl, window.location.href);
            return url.searchParams.get(key) || '';
        } catch (e) {
            return '';
        }
    }

    function normaliseServerTreeRows() {
        document.querySelectorAll('.tree-drive').forEach(function (drive) {
            drive.classList.add('lazy-tree-drive');
            drive.dataset.loaded = drive.nextElementSibling && drive.nextElementSibling.classList.contains('tree-folder') ? '1' : '0';
            const toggle = drive.querySelector('.tree-toggle');
            if (toggle && drive.dataset.loaded !== '1') {
                toggle.textContent = '▸';
            }
        });
        document.querySelectorAll('.tree-folder').forEach(function (folder) {
            folder.classList.add('lazy-tree-folder');
            folder.dataset.driveId = folder.dataset.driveId || extractUrlParam(folder, 'drive_id');
            folder.dataset.path = folder.dataset.path || extractUrlParam(folder, 'path');
            const padding = parseInt(folder.style.paddingLeft || '18', 10);
            folder.dataset.level = folder.dataset.level || String(Math.max(0, Math.round((padding - 18) / 18)));
            folder.dataset.loaded = folder.nextElementSibling && folder.nextElementSibling.classList.contains('tree-folder') && Number(folder.nextElementSibling.dataset.level || '0') > Number(folder.dataset.level || '0') ? '1' : '0';
        });
    }

    function insertHtmlAfter(row, html) {
        if (!html) {
            return [];
        }
        const template = document.createElement('template');
        template.innerHTML = html;
        const inserted = Array.from(template.content.children);
        let anchor = row;
        inserted.forEach(function (node) {
            anchor.insertAdjacentElement('afterend', node);
            anchor = node;
        });
        normaliseServerTreeRows();
        return inserted;
    }

    function removeDescendants(row) {
        const level = Number(row.dataset.level || '-1');
        let next = row.nextElementSibling;
        while (next && next.classList.contains('tree-folder') && Number(next.dataset.level || '0') > level) {
            const remove = next;
            next = next.nextElementSibling;
            remove.remove();
        }
        const toggle = row.querySelector('.tree-toggle');
        if (toggle) {
            toggle.textContent = '▸';
        }
        row.dataset.loaded = '0';
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

    function loadTreeChildren(row, recursive) {
        const isDrive = row.classList.contains('tree-drive');
        const driveId = isDrive ? row.dataset.driveId : (row.dataset.driveId || extractUrlParam(row, 'drive_id'));
        const parentPath = isDrive ? '' : (row.dataset.path || extractUrlParam(row, 'path'));
        const level = isDrive ? -1 : Number(row.dataset.level || '0');
        if (!driveId) {
            return Promise.resolve([]);
        }
        if (row.dataset.loaded === '1' && !recursive) {
            return Promise.resolve([]);
        }
        row.dataset.loading = '1';
        const toggle = row.querySelector('.tree-toggle');
        if (toggle) {
            toggle.textContent = '…';
        }
        return fetch('files-tree-children.php?drive_id=' + encodeURIComponent(driveId) + '&parent_path=' + encodeURIComponent(parentPath) + '&level=' + encodeURIComponent(level), { cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) {
                    throw new Error(data.error || 'Tree load failed');
                }
                removeDescendants(row);
                const inserted = insertHtmlAfter(row, data.html || '');
                row.dataset.loaded = '1';
                if (toggle) {
                    toggle.textContent = inserted.length ? '▾' : '·';
                }
                if (recursive && inserted.length) {
                    return inserted.reduce(function (promise, child) {
                        return promise.then(function () { return loadTreeChildren(child, true); });
                    }, Promise.resolve()).then(function () { return inserted; });
                }
                return inserted;
            })
            .catch(function () {
                if (toggle) {
                    toggle.textContent = '▸';
                }
                return [];
            })
            .finally(function () { row.dataset.loading = '0'; });
    }

    function addTreeControls() {
        const sidebar = document.querySelector('.explorer-sidebar');
        if (!sidebar || sidebar.dataset.controlsAdded === '1') {
            return;
        }
        sidebar.dataset.controlsAdded = '1';
        const controls = document.createElement('div');
        controls.className = 'tree-control-row';
        controls.innerHTML = '<button type="button" id="tree-expand-all">Expand all</button><button type="button" id="tree-collapse-all">Collapse all</button>';
        const heading = sidebar.querySelector('h3');
        if (heading) {
            heading.insertAdjacentElement('afterend', controls);
        } else {
            sidebar.insertAdjacentElement('afterbegin', controls);
        }
        controls.querySelector('#tree-expand-all').addEventListener('click', function () {
            const drives = Array.from(sidebar.querySelectorAll('.tree-drive'));
            drives.reduce(function (promise, drive) {
                return promise.then(function () { return loadTreeChildren(drive, true); });
            }, Promise.resolve());
        });
        controls.querySelector('#tree-collapse-all').addEventListener('click', function () {
            sidebar.querySelectorAll('.tree-drive').forEach(removeDriveDescendants);
        });
    }

    function openDriveContextMenu(row, event) {
        const driveMenu = document.getElementById('drive-context-menu');
        const menu = document.getElementById('context-menu');
        if (!driveMenu) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        driveMenu.dataset.driveId = row.dataset.driveId || '';
        driveMenu.style.left = event.pageX + 'px';
        driveMenu.style.top = event.pageY + 'px';
        driveMenu.classList.add('visible');
        if (menu) {
            menu.classList.remove('visible');
        }
    }

    function openDriveRemoveDialog(driveId) {
        const dialog = document.getElementById('context-dialog');
        const actionInput = document.getElementById('context-action-input');
        const selectedInput = document.getElementById('context-selected-ids');
        const driveInput = document.getElementById('context-drive-id');
        const title = document.getElementById('context-dialog-title');
        const note = document.getElementById('context-dialog-note');
        if (!dialog || !actionInput || !selectedInput || !driveInput) {
            return;
        }
        actionInput.value = 'remove_drive';
        selectedInput.value = '1';
        driveInput.value = driveId;
        ['context-category-area', 'context-move-area', 'context-rename-area'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) {
                el.style.display = 'none';
            }
        });
        if (title) {
            title.textContent = 'Remove drive from DB';
        }
        if (note) {
            note.textContent = 'This removes the drive record, file inventory, scan runs, and related scan jobs from the database. It does not delete files from disk.';
        }
        dialog.classList.remove('hidden');
    }

    function addLazyTreeHandlers() {
        normaliseServerTreeRows();
        addTreeControls();
        const sidebar = document.querySelector('.explorer-sidebar');
        if (!sidebar || sidebar.dataset.lazyHandlersAdded === '1') {
            return;
        }
        sidebar.dataset.lazyHandlersAdded = '1';
        sidebar.addEventListener('click', function (event) {
            const toggle = event.target.closest('.tree-toggle');
            if (!toggle) {
                return;
            }
            const row = toggle.closest('.tree-drive,.tree-folder');
            if (!row) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            if (row.dataset.loaded === '1') {
                if (row.classList.contains('tree-drive')) {
                    removeDriveDescendants(row);
                } else {
                    removeDescendants(row);
                }
            } else {
                loadTreeChildren(row, false);
            }
        }, true);
        sidebar.addEventListener('dblclick', function (event) {
            const row = event.target.closest('.tree-drive,.tree-folder');
            if (!row || event.target.closest('.tree-toggle')) {
                return;
            }
            event.preventDefault();
            if (row.dataset.url) {
                window.location.href = row.dataset.url;
            }
        }, true);
        sidebar.addEventListener('contextmenu', function (event) {
            const row = event.target.closest('.tree-drive');
            if (!row) {
                return;
            }
            openDriveContextMenu(row, event);
        }, true);

        const driveMenu = document.getElementById('drive-context-menu');
        if (driveMenu && driveMenu.dataset.delegatedClickAdded !== '1') {
            driveMenu.dataset.delegatedClickAdded = '1';
            driveMenu.addEventListener('click', function (event) {
                const button = event.target.closest('[data-drive-action="remove_drive"]');
                if (!button) {
                    return;
                }
                const driveId = driveMenu.dataset.driveId || '';
                if (!driveId) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                driveMenu.classList.remove('visible');
                openDriveRemoveDialog(driveId);
            });
        }
    }

    function addDriveStatusInfo() {
        const status = document.getElementById('explorer-status');
        const selectedDrive = document.querySelector('.tree-drive.selected') || document.querySelector('.tree-drive');
        if (!status || !selectedDrive || !selectedDrive.dataset.driveId || status.dataset.driveInfoAdded === '1') {
            return;
        }
        status.dataset.driveInfoAdded = '1';
        let rendering = false;
        const renderStatus = function () {
            if (rendering) {
                return;
            }
            const driveInfo = status.dataset.driveInfoText || '';
            if (!driveInfo) {
                return;
            }
            rendering = true;
            const currentText = status.textContent.replace(status.dataset.driveInfoPlain || '', '').trim();
            status.innerHTML = '<span class="status-left"></span><span class="status-right"></span>';
            status.querySelector('.status-left').textContent = currentText;
            status.querySelector('.status-right').textContent = driveInfo;
            rendering = false;
        };
        fetch('files-drive-info.php?drive_id=' + encodeURIComponent(selectedDrive.dataset.driveId), { cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok || !data.drive) {
                    return;
                }
                const plain = 'Drive: ' + data.drive.friendly_name + ' | Total: ' + formatBytesLocal(data.drive.total_bytes) + ' | Free: ' + formatBytesLocal(data.drive.free_bytes) + ' | Used: ' + formatBytesLocal(data.drive.used_bytes);
                status.dataset.driveInfoPlain = plain;
                status.dataset.driveInfoText = plain;
                renderStatus();
                const observer = new MutationObserver(function () {
                    if (!rendering && !status.querySelector('.status-right')) {
                        renderStatus();
                    }
                });
                observer.observe(status, { childList: true, characterData: true, subtree: true });
            })
            .catch(function () {});
    }

    document.querySelectorAll('.explorer-row.selectable-row').forEach(function (row) {
        row.addEventListener('contextmenu', function () { updateRenameMenuState(row); });
    });

    enhanceCategoryColourPicker();
    enableSharedTableSorting();
    addLazyTreeHandlers();
    addDriveStatusInfo();

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
