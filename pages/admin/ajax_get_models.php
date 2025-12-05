<?php
/*
======================================================================
  Endpoint: ajax_get_models.php
  Description: Returns a JSON array of distinct product model names for
               a given branch, filtered by a free-text query.
  Notes:
  - Used for autocomplete/search features.
  - No UI output; JSON only.
======================================================================
*/

header('Content-Type: application/json; charset=utf-8');
// Minimal includes to get DB connection (matches other pages)
require_once __DIR__ . '/../../config.php';

// ====================================================================
// Inputs
// ====================================================================
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 1;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// ====================================================================
// Query and output
// ====================================================================
try {
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("SELECT DISTINCT model FROM products WHERE branch_id = ? AND model IS NOT NULL AND model <> '' AND model LIKE ? ORDER BY model ASC LIMIT 50");
    $stmt->bind_param('is', $branch_id, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $models = [];
    while ($row = $res->fetch_assoc()) {
        $models[] = $row['model'];
    }
    echo json_encode($models);
} catch (Exception $e) {
    echo json_encode([]);
}

exit;
