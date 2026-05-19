(function () {
    function initCompactExplorerStatus() {
        if (window.__driveMasterCompactExplorerStatusInitialised) {
            return;
        }
        window.__driveMasterCompactExplorerStatusInitialised = true;

        const status = document.getElementById('explorer-status');
        if (!status) {
            return;
        }

        let rendering = false;
        let currentDriveId = '';
        let currentDriveText = '';

        function formatBytes(bytes) {
            bytes = Number(bytes || 0);
            const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
            let index = 0;
            while (bytes >= 1024 && index < units.length - 1) {
                bytes /= 1024;
                index++;
            }
            return index === 0 ? Math.round(bytes) + ' ' + units[index] : bytes.toFixed(2) + ' ' + units[index];
        }

        function valueSpan(title, value) {
            const span = document.createElement('span');
            span.className = 'status-value';
            span.title = title;
            span.textContent = value;
            return span;
        }

        function separator() {
            const span = document.createElement('span');
            span.className = 'status-separator';
            span.textContent = ' | ';
            return span;
        }

        function appendValues(parent, values) {
            values.forEach(function (item, index) {
                if (index > 0) {
                    parent.appendChild(separator());
                }
                parent.appendChild(valueSpan(item[0], item[1]));
            });
        }

        function selectedRows() {
            const table = document.querySelector('.explorer-table.selectable-table');
            if (!table) {
                return [];
            }
            return Array.from(table.querySelectorAll('.selectable-row.selected-row'));
        }

        function selectedSize(rows) {
            return rows.reduce(function (total, row) {
                return row.dataset.isFile === '1' ? total + Number(row.dataset.size || 0) : total;
            }, 0);
        }

        function resolveDriveId() {
            const params = new URLSearchParams(window.location.search);
            return params.get('drive_id') || document.querySelector('.tree-drive.selected')?.dataset.driveId || document.querySelector('.tree-drive.selected-row')?.dataset.driveId || document.querySelector('.tree-drive')?.dataset.driveId || '';
        }

        function render() {
            if (rendering) {
                return;
            }
            rendering = true;
            const rows = selectedRows();
            const left = document.createElement('span');
            left.className = 'status-left compact-status-left';
            appendValues(left, [
                ['Items', status.dataset.totalCount || '0'],
                ['Files', status.dataset.fileCount || '0'],
                ['Folders', status.dataset.folderCount || '0'],
                ['Total file size', formatBytes(status.dataset.totalSize || 0)],
                ['Selected', String(rows.length)],
                ['Selected size', formatBytes(selectedSize(rows))]
            ]);

            const right = document.createElement('span');
            right.className = 'status-right compact-status-right';
            right.innerHTML = currentDriveText || '';

            status.innerHTML = '';
            status.appendChild(left);
            status.appendChild(right);
            rendering = false;
        }

        function refreshDriveInfo() {
            const driveId = resolveDriveId();
            if (!driveId || driveId === currentDriveId) {
                render();
                return;
            }
            currentDriveId = driveId;
            fetch('files-drive-info.php?drive_id=' + encodeURIComponent(driveId), { cache: 'no-store' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok || !data.drive) {
                        currentDriveText = '';
                        render();
                        return;
                    }
                    const holder = document.createElement('span');
                    appendValues(holder, [
                        ['Drive', data.drive.friendly_name || 'Drive'],
                        ['Total drive size', formatBytes(data.drive.total_bytes || 0)],
                        ['Free drive space', formatBytes(data.drive.free_bytes || 0)],
                        ['Used drive space', formatBytes(data.drive.used_bytes || 0)]
                    ]);
                    currentDriveText = holder.innerHTML;
                    render();
                })
                .catch(function () {
                    currentDriveText = '';
                    render();
                });
        }

        function scheduleRender() {
            if (rendering) {
                return;
            }
            window.requestAnimationFrame(function () {
                refreshDriveInfo();
            });
        }

        const observer = new MutationObserver(scheduleRender);
        observer.observe(status, { childList: true, characterData: true, subtree: true });

        document.addEventListener('click', function (event) {
            if (event.target.closest('.explorer-table')) {
                setTimeout(scheduleRender, 0);
            }
            if (event.target.closest('.tree-drive,.tree-folder,.breadcrumbs')) {
                setTimeout(function () {
                    currentDriveId = '';
                    refreshDriveInfo();
                }, 0);
            }
        }, true);

        ['pushState', 'replaceState'].forEach(function (method) {
            const original = history[method];
            history[method] = function () {
                const result = original.apply(this, arguments);
                currentDriveId = '';
                setTimeout(refreshDriveInfo, 0);
                return result;
            };
        });

        window.addEventListener('popstate', function () {
            currentDriveId = '';
            setTimeout(refreshDriveInfo, 0);
        });

        refreshDriveInfo();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCompactExplorerStatus);
    } else {
        initCompactExplorerStatus();
    }
})();
