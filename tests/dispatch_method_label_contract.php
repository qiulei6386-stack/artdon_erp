<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/dispatch_next.php');
if ($page === false) {
    fwrite(STDERR, "Cannot read dispatch_next.php\n");
    exit(1);
}

$required = [
    "personal:'个人'",
    "private:'私人'",
    "single:'派工'",
    "multi:'多派'",
    "plan:'计派'",
    "recurring:'周派'",
    "['personal','个人'],['private','私人'],['single','派工'],['multi','多派'],['plan','计派'],['recurring','周派']",
    "['personal','个人'],['private','私人'],['single','派工']",
];

foreach ($required as $needle) {
    if (strpos($page, $needle) === false) {
        fwrite(STDERR, "Missing method label marker: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    "['personal','private'].includes(String(v||''))?'个人'",
    "['private','个人']",
    "['multi','多人']",
    "['plan','计划派工']",
    "['recurring','周期派工']",
];

foreach ($forbidden as $needle) {
    if (strpos($page, $needle) !== false) {
        fwrite(STDERR, "Forbidden old method label marker remains: {$needle}\n");
        exit(1);
    }
}

echo "dispatch method label contract ok\n";
