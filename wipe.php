<?php
$pdo = new PDO('mysql:host=localhost;dbname=family_tree_db;charset=utf8', 'root', '');
$pdo->exec('DELETE FROM relations');
$pdo->exec('DELETE FROM people');
echo "Wiped successfully.";
?>
