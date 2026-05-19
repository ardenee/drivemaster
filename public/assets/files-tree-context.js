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

    function showToast(text) {
        let toast = document.getElementById('tree-loading-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'tree-loading-toast';
            toast.className = 'tree-loading-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            document.body.appendChild(toast);
        }
        toast.textContent = text || 'Loading folder data...';
        toast.classList.add('visible');
        clearTimeout(window.__driveMasterTreeToastTimer);
        window.__driveMasterTreeToastTimer = setTimeout(function () { toast.classList.remove('visible'); }, 1200);
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
        const toggle = row.querySelector('.tree-toggle');
        if (toggle) {
            toggle.textContent = '▸';
        }
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
        loadIgnoredFolderFilter();
        applyTreeCategoryColours(nodes);
        return nodes;
    }

    function loadChildren(row) {
        if (!row) {
            return Promise.resolve([]);
        }
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
        showToast('Loading folder data...');
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
            });
    }

    function expandAllFolders(row) {
        return loadChildren(row).then(function (children) {
            return children.reduce(function (promise, child) {
                return promise.then(function () { return expandAllFolders(child); });
            }, Promise.resolve());
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
            loadChildren(row);
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
                toggleFolder(row);
                return;
            }
            if (row.dataset.url) {
                showToast('Opening folder. Loading files...');
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
                    driveMenu.classList.remove('visible');
                    showToast('Expanding full drive tree...');
                    expandAllFolders(contextDriveRow || document.querySelector('.tree-drive.selected-row'));
                }
                if (button.dataset.driveAction === 'collapse_drive') {
                    event.preventDefault();
                    event.stopPropagation();
                    driveMenu.classList.remove('visible');
                    removeDriveDescendants(contextDriveRow || document.querySelector('.tree-drive.selected-row'));
                }
            }, true);
        }
    }

    function wireNavigationNotice() {
        document.addEventListener('click', function (event) {
            const link = event.target.closest('a[href*="files.php"], a[href^="?drive_id"], a[href*="drive_id="]');
            if (!link || link.closest('.context-menu') || link.target === '_blank') {
                return;
            }
            showToast('Loading files...');
        }, true);
        window.addEventListener('beforeunload', function () { showToast('Loading...'); });
    }

    loadIgnoredFolderFilter();
    loadAjaxExplorerController();
    loadContextActionsController();
    ensureDriveMenuActions();
    ensureFolderMenuActions();
    wireTreeActions();
    wireNavigationNotice();
    applyTreeCategoryColours();
});
