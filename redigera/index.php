<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/redigera/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$config_path = __DIR__ . '/config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    die('config.php saknas. Kopiera config.php.example till config.php och sätt ett lösenordshash.');
}
$config = require $config_path;

$data_file = __DIR__ . '/../data/schedule-overrides.json';

function load_overrides(string $data_file): array {
    if (!file_exists($data_file)) {
        return [];
    }
    $data = json_decode(file_get_contents($data_file), true);
    return is_array($data) ? $data : [];
}

function save_overrides(string $data_file, array $overrides): void {
    $today = date('Y-m-d');
    foreach ($overrides as $date => $entry) {
        if ($date < $today) {
            unset($overrides[$date]);
        }
    }
    ksort($overrides);
    file_put_contents($data_file, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT), LOCK_EX);
}

const STANDARDTIDER = [
    2 => ['18:00', '20:00'], // Tisdag
    4 => ['18:00', '20:00'], // Torsdag
    7 => ['14:00', '16:00'], // Söndag
];

function next_training_date(): string {
    for ($days_ahead = 0; $days_ahead < 7; $days_ahead++) {
        $candidate = date('Y-m-d', strtotime("+{$days_ahead} days"));
        $weekday = (int) date('N', strtotime($candidate));
        if (array_key_exists($weekday, STANDARDTIDER)) {
            return $candidate;
        }
    }
    return date('Y-m-d');
}

function standardtid_for_date(string $date): array {
    $weekday = (int) date('N', strtotime($date));
    return STANDARDTIDER[$weekday] ?? ['18:00', '20:00'];
}

function svensk_veckodag(string $date): string {
    $names = ['Måndag', 'Tisdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lördag', 'Söndag'];
    $weekday = (int) date('N', strtotime($date));
    return $names[$weekday - 1];
}

function check_csrf(): void {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('Ogiltig eller utgången session. Gå tillbaka och försök igen.');
    }
}

$login_error = '';

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $password = $_POST['password'] ?? '';
    if (password_verify($password, $config['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        header('Location: index.php');
        exit;
    }
    sleep(1);
    $login_error = 'Fel lösenord.';
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

$is_authenticated = !empty($_SESSION['authenticated']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$form_error = '';

if ($is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    check_csrf();
    $date = $_POST['date'] ?? '';
    $tid_start = $_POST['tid_start'] ?? '';
    $tid_slut = $_POST['tid_slut'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        $form_error = 'Ogiltigt datum.';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $tid_start) || !preg_match('/^\d{2}:\d{2}$/', $tid_slut)) {
        $form_error = 'Ogiltig tid.';
    } else {
        $overrides = load_overrides($data_file);
        $overrides[$date] = [
            'installt' => isset($_POST['installt']),
            'beskrivning' => trim($_POST['beskrivning'] ?? ''),
            'tid' => "{$tid_start}–{$tid_slut}",
        ];
        save_overrides($data_file, $overrides);
        header('Location: index.php?saved=1');
        exit;
    }
}

if ($is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    check_csrf();
    $overrides = load_overrides($data_file);
    unset($overrides[$_POST['date'] ?? '']);
    save_overrides($data_file, $overrides);
    header('Location: index.php?deleted=1');
    exit;
}

$overrides = load_overrides($data_file);
ksort($overrides);
$today = date('Y-m-d');
$upcoming = array_filter($overrides, fn($date) => $date >= $today, ARRAY_FILTER_USE_KEY);

$edit_date = $_GET['edit'] ?? null;
$edit_entry = ($edit_date && isset($overrides[$edit_date])) ? $overrides[$edit_date] : null;

$default_date = $edit_entry ? $edit_date : next_training_date();
$default_installt = $edit_entry['installt'] ?? false;
$default_beskrivning = $edit_entry['beskrivning'] ?? '';

if (!empty($edit_entry['tid']) && str_contains($edit_entry['tid'], '–')) {
    [$default_tid_start, $default_tid_slut] = explode('–', $edit_entry['tid'], 2);
} else {
    [$default_tid_start, $default_tid_slut] = standardtid_for_date($default_date);
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redigera träningspass — Åmåls Thaiboxningsklubb</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body { padding: 2rem; max-width: 900px; margin: 0 auto; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .top-bar h1 { color: #DC143C; font-size: 1.8rem; }
        a.logout { color: #DC143C; font-weight: bold; }
        .error { color: #000000; background: #FFFFFF; padding: 0.6rem 1rem; border-radius: 4px; margin-bottom: 1rem; border: 2px solid #DC143C; }
        .success { color: #000000; background: #FFFFFF; padding: 0.6rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
        form.pass-form { display: flex; flex-direction: column; gap: 0.75rem; max-width: 500px; margin-bottom: 2rem; }
        form.pass-form label { font-weight: bold; }
        form.pass-form input[type="date"],
        form.pass-form input[type="time"],
        form.pass-form textarea {
            background: #FFFFFF;
            color: #000000;
            border: 1px solid #DC143C;
            border-radius: 4px;
            padding: 0.5rem;
            font-family: inherit;
            font-size: 1rem;
        }
        form.pass-form textarea { min-height: 100px; resize: vertical; }
        .tid-row { display: flex; gap: 1.5rem; }
        .tid-row label { display: block; margin-bottom: 0.25rem; }
        .checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
        .checkbox-row label { font-weight: normal; }
        button {
            background: #DC143C;
            color: #FFFFFF;
            border: none;
            border-radius: 4px;
            padding: 0.6rem 1.2rem;
            font-weight: bold;
            cursor: pointer;
            width: fit-content;
            font-size: 1rem;
        }
        button:hover { background: #FFFFFF; color: #DC143C; }
        button.secondary { background: transparent; border: 1px solid #DC143C; color: #FFFFFF; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: 0.6rem; border-bottom: 1px solid #DC143C; }
        .row-actions { display: flex; gap: 0.5rem; }
        .login-form { max-width: 320px; margin: 4rem auto; display: flex; flex-direction: column; gap: 0.75rem; }
        .login-form input[type="password"] {
            background: #FFFFFF;
            color: #000000;
            border: 1px solid #DC143C;
            border-radius: 4px;
            padding: 0.5rem;
            font-size: 1rem;
        }
    </style>
</head>
<body>
<?php if (!$is_authenticated): ?>

    <h1 style="color:#DC143C; text-align:center;">Logga in</h1>
    <?php if ($login_error): ?><p class="error"><?= htmlspecialchars($login_error) ?></p><?php endif; ?>
    <form class="login-form" method="post">
        <input type="hidden" name="action" value="login">
        <label for="password">Lösenord</label>
        <input type="password" id="password" name="password" required autofocus>
        <button type="submit">Logga in</button>
    </form>

<?php else: ?>

    <div class="top-bar">
        <h1>Redigera träningspass</h1>
        <a class="logout" href="index.php?action=logout">Logga ut</a>
    </div>

    <?php if (isset($_GET['saved'])): ?><p class="success">Passet sparades.</p><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><p class="success">Passet togs bort.</p><?php endif; ?>
    <?php if ($form_error): ?><p class="error"><?= htmlspecialchars($form_error) ?></p><?php endif; ?>

    <h2><?= $edit_entry ? 'Redigera pass' : 'Lägg till pass' ?></h2>
    <form class="pass-form" method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <label for="date">Datum</label>
        <input type="date" id="date" name="date" value="<?= htmlspecialchars($default_date) ?>" min="<?= htmlspecialchars($today) ?>" required>

        <div class="tid-row">
            <div>
                <label for="tid_start">Starttid</label>
                <input type="time" id="tid_start" name="tid_start" value="<?= htmlspecialchars($default_tid_start) ?>" required>
            </div>
            <div>
                <label for="tid_slut">Sluttid</label>
                <input type="time" id="tid_slut" name="tid_slut" value="<?= htmlspecialchars($default_tid_slut) ?>" required>
            </div>
        </div>

        <div class="checkbox-row">
            <input type="checkbox" id="installt" name="installt" <?= $default_installt ? 'checked' : '' ?>>
            <label for="installt">Inställt</label>
        </div>

        <label for="beskrivning">Beskrivning/upplägg</label>
        <textarea id="beskrivning" name="beskrivning" placeholder="T.ex. Fokus på mitts och lätt sparring."><?= htmlspecialchars($default_beskrivning) ?></textarea>

        <button type="submit"><?= $edit_entry ? 'Spara ändringar' : 'Lägg till pass' ?></button>
    </form>

    <h2>Kommande pass</h2>
    <?php if (empty($upcoming)): ?>
        <p>Inga kommande pass inlagda.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Tid</th>
                    <th>Inställt</th>
                    <th>Beskrivning</th>
                    <th>Åtgärder</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($upcoming as $date => $entry): ?>
                    <tr>
                        <td><?= htmlspecialchars($date) ?> (<?= htmlspecialchars(svensk_veckodag($date)) ?>)</td>
                        <td><?= htmlspecialchars($entry['tid'] ?? implode('–', standardtid_for_date($date))) ?></td>
                        <td><?= $entry['installt'] ? 'Ja' : 'Nej' ?></td>
                        <td><?= htmlspecialchars($entry['beskrivning']) ?></td>
                        <td class="row-actions">
                            <a href="index.php?edit=<?= urlencode($date) ?>"><button type="button" class="secondary">Redigera</button></a>
                            <form method="post" onsubmit="return confirm('Ta bort passet <?= htmlspecialchars($date, ENT_QUOTES) ?>?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <button type="submit" class="secondary">Ta bort</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php endif; ?>
</body>
</html>
