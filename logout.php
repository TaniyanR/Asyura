<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

\Asyura\Auth::logout();
redirect(app_url('login.php'));
