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
        $cc = preg_replace('/\s+/', '', $_POST['dummy_cc'] ?? '');
        $exp = $_POST['dummy_exp'] ?? '';
        $cvv = $_POST['dummy_cvv'] ?? '';

        if (!preg_match('/^\d{13,19}$/', $cc)) {
            $error = "Invalid credit card number. It must be between 13 and 19 digits and cannot contain letters.";
        } elseif (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $exp)) {
            $error = "Invalid expiry date. Must be MM/YY.";
        } elseif (!preg_match('/^\d{3,4}$/', $cvv)) {
            $error = "Invalid CVV. Must be 3 or 4 digits.";
        }

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
            
            <form method="POST" style="margin-top: 20px;" id="checkoutForm">
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
                    <input type="text" name="dummy_cc" id="dummy_cc" placeholder="1234 5678 9101 1121" maxlength="19" required style="width: 100%; padding: 8px; box-sizing: border-box;">
                    <span id="cc_error" style="color: red; font-size: 0.85rem; display: none; margin-top: 4px; font-weight: 600;"></span>
                </div>
                <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                    <div style="flex: 1;">
                        <label style="display:block; font-weight:bold;">Expiry (MM/YY)</label>
                        <input type="text" name="dummy_exp" id="dummy_exp" placeholder="MM/YY" maxlength="5" required style="width: 100%; padding: 8px; box-sizing: border-box;">
                        <span id="exp_error" style="color: red; font-size: 0.85rem; display: none; margin-top: 4px; font-weight: 600;"></span>
                    </div>
                    <div style="flex: 1;">
                        <label style="display:block; font-weight:bold;">CVV</label>
                        <input type="text" name="dummy_cvv" id="dummy_cvv" placeholder="123" maxlength="4" required style="width: 100%; padding: 8px; box-sizing: border-box;">
                        <span id="cvv_error" style="color: red; font-size: 0.85rem; display: none; margin-top: 4px; font-weight: 600;"></span>
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

<script>
document.addEventListener("DOMContentLoaded", function() {
    const ccInput = document.getElementById("dummy_cc");
    const expInput = document.getElementById("dummy_exp");
    const cvvInput = document.getElementById("dummy_cvv");
    const form = document.getElementById("checkoutForm");
    const ccError = document.getElementById("cc_error");
    const expError = document.getElementById("exp_error");
    const cvvError = document.getElementById("cvv_error");

    if (ccInput) {
        // Automatically format card number with spaces every 4 digits as user types
        ccInput.addEventListener("input", function(e) {
            let value = e.target.value.replace(/\D/g, ''); // Remove all non-digits
            let formatted = value.match(/.{1,4}/g);
            e.target.value = formatted ? formatted.join(" ") : "";
            ccError.style.display = "none";
        });
    }

    if (expInput) {
        // Automatically insert slash for MM/YY expiry format
        expInput.addEventListener("input", function(e) {
            let value = e.target.value.replace(/\D/g, ''); // Remove non-digits
            if (value.length > 2) {
                e.target.value = value.substring(0, 2) + "/" + value.substring(2, 4);
            } else {
                e.target.value = value;
            }
            expError.style.display = "none";
        });
    }

    if (cvvInput) {
        // Limit CVV to numeric values only
        cvvInput.addEventListener("input", function(e) {
            e.target.value = e.target.value.replace(/\D/g, '');
            cvvError.style.display = "none";
        });
    }

    if (form) {
        form.addEventListener("submit", function(e) {
            const rawCC = ccInput.value.replace(/\s+/g, '');
            const rawExp = expInput.value;
            const rawCvv = cvvInput.value;
            let isValid = true;

            // Reset errors
            ccError.style.display = "none";
            expError.style.display = "none";
            cvvError.style.display = "none";

            // 1. Credit Card Number Validation
            if (!/^\d{13,19}$/.test(rawCC)) {
                ccError.textContent = "Please enter a valid credit card number (13-19 digits).";
                ccError.style.display = "block";
                ccInput.focus();
                isValid = false;
            }

            // 2. Expiry MM/YY Validation
            if (isValid && !/^(0[1-9]|1[0-2])\/\d{2}$/.test(rawExp)) {
                expError.textContent = "Enter expiry as MM/YY (e.g. 12/28).";
                expError.style.display = "block";
                expInput.focus();
                isValid = false;
            }

            // 3. CVV Validation
            if (isValid && !/^\d{3,4}$/.test(rawCvv)) {
                cvvError.textContent = "Enter 3 or 4 digits CVV.";
                cvvError.style.display = "block";
                cvvInput.focus();
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault(); // Stop form submission
            }
        });
    }
});
</script>

</body>
</html>
