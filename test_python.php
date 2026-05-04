<?php
// ============================================================
// test_python.php — Diagnostic Python / exec()
// SUPPRIMER après diagnostic !
// ============================================================
echo '<pre>';

// 1. exec() activé ?
echo "1. exec() disponible : ";
if (function_exists('exec')) {
    echo "OUI\n";
} else {
    echo "NON — désactivé dans php.ini (disable_functions)\n";
}

// 2. Python disponible ?
echo "\n2. Recherche de Python :\n";
$pythons = ['python3', 'python', 'py'];
foreach ($pythons as $cmd) {
    $out = []; $code = -1;
    @exec("$cmd --version 2>&1", $out, $code);
    echo "   $cmd : " . (implode(' ',$out) ?: 'introuvable') . " (code=$code)\n";
}

// 3. PATH complet
echo "\n3. PATH : ";
$out = []; @exec('echo %PATH% 2>&1', $out);
echo implode("\n         ", $out) . "\n";

// 4. Où est python3 ?
echo "\n4. where python3 : ";
$out = []; @exec('where python3 2>&1', $out);
echo implode(' | ', $out) . "\n";

echo "\n5. where python : ";
$out = []; @exec('where python 2>&1', $out);
echo implode(' | ', $out) . "\n";

// 6. Test exec simple
echo "\n6. Test exec('dir') : ";
$out = []; @exec('dir /b "C:\\Python*" 2>&1', $out);
echo implode(' | ', $out ?: ['rien trouvé']) . "\n";

// 7. Test shell_exec
echo "\n7. shell_exec : ";
$r = @shell_exec('python3 --version 2>&1');
echo ($r ?: 'NULL') . "\n";

// 8. Répertoires Python courants Windows
echo "\n8. Répertoires Python Windows :\n";
$dirs = [
    'C:\\Python39', 'C:\\Python310', 'C:\\Python311', 'C:\\Python312', 'C:\\Python313',
    'C:\\Users\\' . ($_SERVER['USERNAME'] ?? 'user') . '\\AppData\\Local\\Programs\\Python',
    'C:\\Program Files\\Python39', 'C:\\Program Files\\Python310', 'C:\\Program Files\\Python311',
];
foreach ($dirs as $d) {
    if (is_dir($d)) echo "   TROUVÉ : $d\n";
}

// 9. reportlab installé ?
echo "\n9. Test script Python complet :\n";
$script = tempnam(sys_get_temp_dir(), 'test_') . '.py';
file_put_contents($script, "import sys\nprint('Python ' + sys.version)\ntry:\n    import reportlab\n    print('reportlab OK: ' + reportlab.Version)\nexcept ImportError as e:\n    print('reportlab ABSENT: ' + str(e))\n");
$out = []; $code = -1;
@exec("python3 \"$script\" 2>&1", $out, $code);
if (empty($out)) @exec("python \"$script\" 2>&1", $out, $code);
echo "   " . implode("\n   ", $out) . "\n";
@unlink($script);

echo '</pre>';
