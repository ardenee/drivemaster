document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.explorer-sidebar');
    const menu = document.getElementById('context-menu');
    const driveMenu = document.getElementById('drive-context-menu');
    const dialog = document.getElementById('context-dialog');

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
        window.__driveMasterTreeToastTimer = setTimeout(function () {
            toast.classList.remove('visible');
        }, 1200);
    }

    function getUrlParam(row, key) {
        try {
            const url = new URL(row.dataset.url || '', window.location.href);
            return url.searchParams.get(key) || '';
        } catch (e) {
            return '';
        }
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
        loadIgnoredFolderFilter();
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
        showToast('Loading folder data...');
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
            });
    }

    function expandAllFolders(row) {
        return loadChildren(row).then(function (children) {
            return children.reduce(function (promise, child) {
                return promise.then(function () { return expandAllFolders(child); });
            }, Promise.resolve());
        });
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
        showToast('Loading folder details...');
        return fetch('tree-resolve.php?drive_id=' + encodeURIComponent(driveId) + '&path=' + encodeURIComponent(path), { cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) {
                    return false;
                }
                row.dataset.id = String(data.id);
                row.dataset.entryType = 'folder';
                row.dataset.entryName = data.name || row.textContent.trim().replace(/[▸▾·…📁]/g, '').trim();
                row.dataset.size = '0';
                row.dataset.isFile = '0';
                row.dataset.driveId = driveId;
                row.dataset.path = path;
                return true;
            })
            .catch(function () { return false; });
    }

    function openTreeFolderMenu(folder, event) {
        if (!menu) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        document.querySelectorAll('.tree-folder.selected-row, .tree-drive.selected-row').forEach(function (row) {
            row.classList.remove('selected-row');
        });
        folder.classList.add('selected-row');
        resolveTreeFolder(folder).then(function (ok) {
            if (!ok) {
                return;
            }
            const selectedInput = document.getElementById('context-selected-ids');
            const renameButton = menu.querySelector('[data-context-action="rename"]');
            if (selectedInput) {
                selectedInput.value = folder.dataset.id;
            }
            if (renameButton) {
                renameButton.disabled = false;
            }
            menu.style.left = event.pageX + 'px';
            menu.style.top = event.pageY + 'px';
            menu.classList.add('visible');
            if (driveMenu) {
                driveMenu.classList.remove('visible');
            }
        });
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
                if (row.dataset.loaded === '1') {
                    if (row.classList.contains('tree-drive')) {
                        removeDriveDescendants(row);
                    }
                } else {
                    loadChildren(row);
                }
                return;
            }
            if (row.dataset.url) {
                showToast('Opening folder. Loading files...');
            }
        }, true);

        sidebar.addEventListener('contextmenu', function (event) {
            const folder = event.target.closest('.tree-folder');
            if (folder) {
                openTreeFolderMenu(folder, event);
                return;
            }
            const drive = event.target.closest('.tree-drive');
            if (drive) {
                contextDriveRow = drive;
            }
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
                    driveMenu.classList.remove('visible');
                    showToast('Expanding full drive tree...');
                    expandAllFolders(contextDriveRow);
                }
                if (button.dataset.driveAction === 'collapse_drive') {
                    event.preventDefault();
                    event.stopPropagation();
                    driveMenu.classList.remove('visible');
                    removeDriveDescendants(contextDriveRow);
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
        window.addEventListener('beforeunload', function () {
            showToast('Loading...');
        });
    }

    loadIgnoredFolderFilter();
    loadAjaxExplorerController();
    ensureDriveMenuActions();
    wireTreeActions();
    wireNavigationNotice();
});
