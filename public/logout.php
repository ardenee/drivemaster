<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

DriveMaster\Support\Auth::signOut();
header('Location: login.php');
exit;
