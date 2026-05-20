document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('explorer-panel')) {
        return;
    }

    function replaceNode(selector) {
        const node = document.querySelector(selector);
        if (!node || !node.parentNode) {
            return null;
        }
        const clone = node.cloneNode(true);
        node.parentNode.replaceChild(clone, node);
        return clone;
    }

    replaceNode('#explorer-panel');
    replaceNode('#context-menu');
    replaceNode('#drive-context-menu');
    replaceNode('#context-form');
});
