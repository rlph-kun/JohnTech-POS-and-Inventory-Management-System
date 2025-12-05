<?php
function log_audit($conn, $user_id, $role, $action, $details) {
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, role, action, details) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isss', $user_id, $role, $action, $details);
    $stmt->execute();
    $stmt->close();
}
