<?php
session_start();
require_once 'config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'count' => 0,
        'message' => 'User not logged in'
    ]);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    // Get total number of items in cart
    $stmt = $pdo->prepare("SELECT SUM(quantity) as total_items FROM carts WHERE user_id = ? AND status = 1");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();

    $cart_count = $result['total_items'] ? (int) $result['total_items'] : 0;

    echo json_encode([
        'success' => true,
        'count' => $cart_count
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'count' => 0,
        'message' => 'Database error'
    ]);

    // Log error for debugging
    error_log('Get cart count error: ' . $e->getMessage());
}
?>