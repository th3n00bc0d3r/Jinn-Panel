<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(Config::APP_NAME) ?> &mdash; Sign in</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="flex items-center justify-center gap-2 mb-8">
            <div class="h-9 w-9 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center font-bold text-white">J</div>
            <span class="text-lg font-semibold tracking-tight"><?= e(Config::APP_NAME) ?></span>
        </div>
        <?= $content ?>
        <p class="mt-6 text-center text-xs text-slate-500">Hosting management for <?= e(Config::SERVER_HOSTNAME) ?></p>
    </div>
</div>
</body>
</html>
