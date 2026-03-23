<?php
header('Content-Type: application/json');

$host = 'localhost';
$db   = 'family_tree_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Auto-create DB and Relational Tables
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db`");
    $pdo->exec("USE `$db`");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS people (
        id VARCHAR(50) PRIMARY KEY,
        first_name VARCHAR(100),
        birthday VARCHAR(50),
        avatar VARCHAR(255),
        gender VARCHAR(10)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS relations (
        person_id VARCHAR(50),
        related_id VARCHAR(50),
        relation_type VARCHAR(20)
    )");

} catch (PDOException $e) {
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save data transition
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        die(json_encode(['error' => 'Invalid JSON payload.']));
    }

    try {
        $pdo->beginTransaction();
        
        // Wipe old relational data for a fresh clean state from the full tree payload
        $pdo->exec("DELETE FROM relations");
        
        $inputIds = array_column($input, 'id');
        if (count($inputIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($inputIds), '?'));
            $stmtDelete = $pdo->prepare("DELETE FROM people WHERE id NOT IN ($placeholders)");
            $stmtDelete->execute($inputIds);
        } else {
            $pdo->exec("DELETE FROM people");
        }

        $stmtPerson = $pdo->prepare("INSERT INTO people (id, first_name, avatar, gender) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE first_name=VALUES(first_name), avatar=VALUES(avatar), gender=VALUES(gender)");
        $stmtRel = $pdo->prepare("INSERT INTO relations (person_id, related_id, relation_type) VALUES (?, ?, ?)");

        foreach ($input as $node) {
            $id = $node['id'];
            $data = $node['data'] ?? [];
            $rels = $node['rels'] ?? [];
            
            $stmtPerson->execute([
                $id,
                $data['first name'] ?? '',
                $data['avatar'] ?? '',
                $data['gender'] ?? 'M'
            ]);
            
            // Handle both legacy and modern formats smoothly
            if (!empty($rels['father'])) $stmtRel->execute([$id, $rels['father'], 'parents']);
            if (!empty($rels['mother'])) $stmtRel->execute([$id, $rels['mother'], 'parents']);
            
            if (!empty($rels['parents']) && is_array($rels['parents'])) {
                foreach ($rels['parents'] as $p) $stmtRel->execute([$id, $p, 'parents']);
            }
            
            if (!empty($rels['spouses']) && is_array($rels['spouses'])) {
                foreach ($rels['spouses'] as $s) $stmtRel->execute([$id, $s, 'spouses']);
            }
            if (!empty($rels['children']) && is_array($rels['children'])) {
                foreach ($rels['children'] as $c) $stmtRel->execute([$id, $c, 'children']);
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Failed to save relational data: ' . $e->getMessage()]);
    }
} else {
    // Load relational data identically reconstructed into the JSON structure
    $stmtPeople = $pdo->query("SELECT * FROM people");
    $people = $stmtPeople->fetchAll(PDO::FETCH_ASSOC);

    if (count($people) === 0) {
        // Return blank default if database has no rows
        echo json_encode([
            [
                "id" => "1",
                "data" => [
                    "first name" => "You",
                    "avatar" => "",
                    "gender" => "M"
                ],
                "rels" => new stdClass()
            ]
        ]);
        exit;
    }

    $stmtRelations = $pdo->query("SELECT * FROM relations");
    $relations = $stmtRelations->fetchAll(PDO::FETCH_ASSOC);

    $peopleData = [];
    foreach ($people as $p) {
        $peopleData[$p['id']] = [
            'id' => $p['id'],
            'data' => [
                'first name' => $p['first_name'],
                'avatar' => $p['avatar'],
                'gender' => $p['gender']
            ],
            'rels' => [ 'spouses' => [], 'children' => [], 'parents' => [] ]
        ];
    }

    foreach ($relations as $r) {
        $pid = $r['person_id'];
        $rid = $r['related_id'];
        $type = $r['relation_type'];
        
        if (!isset($peopleData[$pid])) continue;

        if ($type === 'father' || $type === 'mother') $type = 'parents'; // Merge legacy types

        if (!in_array($rid, $peopleData[$pid]['rels'][$type])) {
            $peopleData[$pid]['rels'][$type][] = $rid;
        }
    }

    // JS Library requires array format (not object dictionary)
    echo json_encode(array_values($peopleData));
}
?>
