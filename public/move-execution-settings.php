<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\ExecutionSettings;

$message = null;
$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ExecutionSettings::set(db(), 'execution_actual_moves_enabled', isset($_POST['execution_actual_moves_enabled']) ? '1' : '0');
        ExecutionSettings::set(db(), 'execution_require_dry_run', isset($_POST['execution_require_dry_run']) ? '1' : '0');
        ExecutionSettings::set(db(), 'execution_require_final_safety_check', isset($_POST['execution_require_final_safety_check']) ? '1' : '0');
        ExecutionSettings::set(db(), 'execution_require_confirmation_phrase', isset($_POST['execution_require_confirmation_phrase']) ? '1' : '0');
        ExecutionSettings::set(db(), 'execution_confirmation_phrase', trim((string)($_POST['execution_confirmation_phrase'] ?? 'I understand this will move files')));
        $message = 'Execution settings saved.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$settings = ExecutionSettings::all(db());

render_header('Execution Settings');
?>
<?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
<div class="card">
    <h2>Execution safety settings</h2>
    <p class="muted">Actual file moving is disabled by default. Simulation can run without moving files. Do not enable actual moves until the full dry-run/final-safety workflow has been tested cleanly.</p>
    <form method="post">
        <label><input type="checkbox" name="execution_actual_moves_enabled" value="1" <?= (string)$settings['execution_actual_moves_enabled'] === '1' ? 'checked' : '' ?>> Enable actual file moves</label><br>
        <label><input type="checkbox" name="execution_require_dry_run" value="1" <?= (string)$settings['execution_require_dry_run'] === '1' ? 'checked' : '' ?>> Require completed dry-run before execution queue</label><br>
        <label><input type="checkbox" name="execution_require_final_safety_check" value="1" <?= (string)$settings['execution_require_final_safety_check'] === '1' ? 'checked' : '' ?>> Require final safety check before execution queue</label><br>
        <label><input type="checkbox" name="execution_require_confirmation_phrase" value="1" <?= (string)$settings['execution_require_confirmation_phrase'] === '1' ? 'checked' : '' ?>> Require confirmation phrase for actual moves</label><br>
        <label>Confirmation phrase <input type="text" name="execution_confirmation_phrase" value="<?= e((string)$settings['execution_confirmation_phrase']) ?>" style="width:100%;max-width:520px"></label>
        <p><button type="submit">Save settings</button></p>
    </form>
</div>
<div class="card">
    <h2>Current mode</h2>
    <div class="status-bar">Actual moves: <?= (string)$settings['execution_actual_moves_enabled'] === '1' ? '<strong>ENABLED</strong>' : '<strong>Disabled</strong>' ?> | Dry-run required: <?= (string)$settings['execution_require_dry_run'] === '1' ? 'Yes' : 'No' ?> | Final safety required: <?= (string)$settings['execution_require_final_safety_check'] === '1' ? 'Yes' : 'No' ?></div>
</div>
<?php render_footer(); ?>
