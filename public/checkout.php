<?php
/**
 * checkout.php - Handles the final purchase of an item.
 */

session_start();
require_once __DIR__ . '/../src/database.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$db = getDbConnection();
$error = "";
$success = "";
$item = null;

$item_id = $_GET['id'] ?? null;

if (!$item_id) {
    header('Location: index.php');
    exit;
}

// Fetch item details
$stmt = $db->prepare("SELECT items.*, users.username as seller FROM items JOIN users ON items.user_id = users.id WHERE items.id = ?");
$stmt->execute([$item_id]);
$item = $stmt->fetch();

if (!$item) {
    $error = "Item not found or already sold.";
} else {
    // Prevent user from buying their own item (though it's hidden, they could guess the ID)
    if ($item['user_id'] == $_SESSION['user_id']) {
        $error = "You cannot buy your own item.";
    }
}

// Handle purchase confirmation
if (isset($_POST['confirm_purchase'])) {
    if (!$error) {
        try {
            // In a real app, we'd record an order or transaction.
// Update the item status to 'sold' and record the buyer's user ID
            $update_stmt = $db->prepare("UPDATE items SET status = 'sold', buyer_id = ? WHERE id = ?");
            $update_stmt->execute([$_SESSION['user_id'], $item_id]);
            $success = "Purchase successful! You bought " . htmlspecialchars($item['item_name']) . ".";
            $item = null; // Item no longer exists
        } catch (PDOException $e) {
            $error = "An error occurred during purchase.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Checkout - MzansiBuys Marketplace</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .checkout-box {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 8px;
            max-width: 500px;
        }
        .btn-confirm {
            background-color: #28a745;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
        }
        .btn-confirm:hover {
            background-color: #218838;
        }
        .btn-cancel {
            background-color: #6c757d;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            margin-left: 10px;
        }
        .btn-cancel:hover {
            background-color: #5a6268;
        }
    </style>
</head>
<body>

<header>
    <h1>MzansiBuys Marketplace - Checkout</h1>
    <nav>
        <a href="index.php">Back to Marketplace</a>
    </nav>
</header>

<main>
    <?php if ($error): ?>
        <p style="color:red; font-weight:bold;"><?php echo htmlspecialchars($error); ?></p>
        <a href="index.php">Return to items</a>
    <?php elseif ($success): ?>
        <p style="color:green; font-weight:bold;"><?php echo htmlspecialchars($success); ?></p>
        <a href="index.php">Return to items</a>
    <?php elseif ($item): ?>
        <div class="checkout-box">
            <h2>Review Your Purchase</h2>
            <?php if (!empty($item['image_path'])): ?>
                <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="Item Image" style="max-width: 100%; height: auto; border-radius: 6px; margin: 15px 0; display: block; box-shadow: var(--shadow-sm);">
            <?php endif; ?>
            <p><strong>Item:</strong> <?php echo htmlspecialchars($item['item_name']); ?></p>
            <p><strong>Description:</strong> <?php echo htmlspecialchars($item['description'] ?? 'No description'); ?></p>
            <p><strong>Seller:</strong> <?php echo htmlspecialchars($item['seller']); ?></p>
            <p><strong>Price:</strong> R<?php echo number_format($item['price'], 2); ?></p>
            
            <form method="POST" style="margin-top: 20px;">
                <hr style="border: 1px solid #ddd; margin: 20px 0;">
                <h3>Shipping Details</h3>
                <div style="margin-bottom: 10px;">
                    <label style="display:block; font-weight:bold;">Full Name</label>
                    <input type="text" name="dummy_name" required style="width: 100%; padding: 8px; box-sizing: border-box;">
                </div>
                <div style="margin-bottom: 10px;">
                    <label style="display:block; font-weight:bold;">Shipping Address</label>
                    <textarea name="dummy_address" required style="width: 100%; padding: 8px; box-sizing: border-box;"></textarea>
                </div>
                
                <h3>Payment Details</h3>
                <div style="margin-bottom: 10px;">
                    <label style="display:block; font-weight:bold;">Credit Card Number</label>
                    <input type="text" name="dummy_cc" placeholder="1234 5678 9101 1121" required style="width: 100%; padding: 8px; box-sizing: border-box;">
                </div>
                <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                    <div style="flex: 1;">
                        <label style="display:block; font-weight:bold;">Expiry (MM/YY)</label>
                        <input type="text" name="dummy_exp" placeholder="MM/YY" required style="width: 100%; padding: 8px; box-sizing: border-box;">
                    </div>
                    <div style="flex: 1;">
                        <label style="display:block; font-weight:bold;">CVV</label>
                        <input type="text" name="dummy_cvv" placeholder="123" required style="width: 100%; padding: 8px; box-sizing: border-box;">
                    </div>
                </div>
                <button type="submit" name="confirm_purchase" class="btn-confirm">Confirm Purchase</button>
                <a href="index.php" class="btn-cancel">Cancel</a>
            </form>
        </div>
    <?php endif; ?>
</main>

<footer>
    <p>&copy; <?php echo date('Y'); ?> MzansiBuys Marketplace. All simulation rights reserved.</p>
    <p>
        <a href="index.php#terms">Terms &amp; Conditions</a> | 
        <a href="index.php#safety">Safety Guidelines</a> | 
        <a href="mailto:support@mzansibuys.site">Support Helpdesk</a>
    </p>
</footer>

</body>
</html>
