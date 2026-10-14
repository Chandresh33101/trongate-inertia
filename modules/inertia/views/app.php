<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($inertia_title ?? 'My App') ?></title>
    <?php foreach ($inertia_meta as $name => $content): ?>
    <meta name="<?= htmlspecialchars($name) ?>" content="<?= htmlspecialchars($content) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>css/trongate.css">
    <script>window.TG = { baseUrl: <?= json_encode($base_path, JSON_HEX_TAG | JSON_HEX_AMP) ?> };</script>
    <?= $assets ?>
</head>
<body>
    <div id="app" data-page="<?= $page_attr ?>"></div>
</body>
</html>