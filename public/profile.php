<?php
session_start();
require_once __DIR__ . '/../src/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$db = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get User Details
$stmt = $db->prepare("
    SELECT users.*, 
           (SELECT ROUND(AVG(rating), 1) FROM reviews WHERE seller_id = users.id) as avg_rating 
    FROM users 
    WHERE id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get User's Active Items
$stmt = $db->prepare("SELECT * FROM items WHERE user_id = ? AND status = 'available'");
$stmt->execute([$user_id]);
$my_items = $stmt->fetchAll();
// Get User's Sold Items
$stmt_sold = $db->prepare("SELECT items.*, users.username as buyer_name FROM items LEFT JOIN users ON items.buyer_id = users.id WHERE items.user_id = ? AND items.status = 'sold'");
$stmt_sold->execute([$user_id]);
$sold_items = $stmt_sold->fetchAll();

// Get Items the User has Bought (and check if they reviewed or reported them)
$stmt_bought = $db->prepare("
    SELECT items.*, users.username as seller_name, reviews.rating, 
           reports.id as report_id, reports.status as report_status, reports.reason as report_reason
    FROM items 
    JOIN users ON items.user_id = users.id 
    LEFT JOIN reviews ON items.id = reviews.item_id AND reviews.buyer_id = ?
    LEFT JOIN reports ON items.id = reports.item_id AND reports.reporter_id = ?
    WHERE items.buyer_id = ? AND items.status = 'sold'
");
$stmt_bought->execute([$user_id, $user_id, $user_id]);
$bought_items = $stmt_bought->fetchAll();

// Handle New Item Listing
if (isset($_POST['list_item'])) {
    $name = $_POST['item_name'];
    $desc = $_POST['description'];
    $price = $_POST['price'];
    $category = $_POST['category'];
    $image_path = null;
    $upload_error = false;
    
    // Handle the image upload
    if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/';
            // Create the uploads folder if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            // Create a unique filename so images don't overwrite each other
            $filename = time() . '_' . basename($_FILES['item_image']['name']);
            if (move_uploaded_file($_FILES['item_image']['tmp_name'], $upload_dir . $filename)) {
                $image_path = 'uploads/' . $filename;
            } else {
                $error = "Failed to save the uploaded image to the server directory.";
                $upload_error = true;
            }
        } else {
            $err_code = $_FILES['item_image']['error'];
            if ($err_code === UPLOAD_ERR_INI_SIZE || $err_code === UPLOAD_ERR_FORM_SIZE) {
                $error = "The uploaded image is too large. The maximum size allowed by your server's configuration is " . ini_get('upload_max_filesize') . ".";
            } else {
                $error = "Image upload failed with error code: " . $err_code;
            }
            $upload_error = true;
        }
    }

    if (!$upload_error) {
        if (!empty($name) && !empty($price)) {
            $stmt = $db->prepare("INSERT INTO items (user_id, item_name, description, price, category, image_path) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $name, $desc, $price, $category, $image_path]);
            header('Location: profile.php'); 
            exit;
        } else {
            $error = "Please fill in all required fields.";
        }
    }
}

// Luhn Algo for ZA ID : https://medium.com/@ryanneilparker/sa-id-fumble-how-south-africa-managed-to-incorrectly-apply-the-luhn-algorithm-352dd6f10738. Basically we ignore digit 13 and then we cound back from digit 12. Every second digit we double the number and if the number is > 10 we split the digits add them together and add them to the SUM. For example 8*2 = 16 so we say 1+6 = 7 and add 7 to the sum. Once the calculation is done sum % 10 must have a remainder of 0. If its 0 the ID is valid.

if (isset($_POST['verify_id'])) {
    $id_number = $_POST['id_number'];
    // 1. Check if it is EXACTLY 13 digits long using a Regular Expression https://www.w3schools.com/php/php_regex_functions.asp -- preg_match(). 
    if (preg_match('/^\d{13}$/', $id_number)) {
        
        // 2. Perform the Mathematical 'Luhn algorithm' Check
        // South African IDs use this to ensure the digits follow a strict pattern formula.
        $sum = 0;
        $isSecond = false;
        for ($i = 12; $i >= 0; $i--) {
            $d = $id_number[$i];
            if ($isSecond) {
                $d = $d * 2;
                if ($d > 9) {
                    $d -= 9;
                }
                $sum += $d;
            } else {
                $sum += $d;
            }
            $isSecond = !$isSecond;
        }
        // 3. If the math calculates to a multiple of 10, it is genetically a valid number!
        if ($sum % 10 == 0) {
            $stmt = $db->prepare("UPDATE users SET id_number = ?, is_verified = 1 WHERE id = ?");
            $stmt->execute([$id_number, $user_id]);
            
            // Reload the page to reflect the new Verified status
            header('Location: profile.php');
            exit;
        } else {
            $verify_error = "The ID number entered mathematical validation check failed.";
        }
    } else {
        $verify_error = "ID must be exactly 13 digits long.";
    }
}

// Handle Submitting a Review
if (isset($_POST['submit_review'])) {
    $item_id = $_POST['item_id'];
    $seller_id = $_POST['seller_id'];
    $rating = $_POST['rating'];

    // Ensure the review is between 1 and 5
    if ($rating >= 1 && $rating <= 5) {
        $stmt = $db->prepare("INSERT INTO reviews (seller_id, buyer_id, item_id, rating) VALUES (?, ?, ?, ?)");
        $stmt->execute([$seller_id, $user_id, $item_id, $rating]);
        header('Location: profile.php');
        exit;
    }
}

// Handle Submitting a Report
if (isset($_POST['submit_report'])) {
    $item_id = $_POST['item_id'];
    $reported_id = $_POST['reported_id'];
    $reason = trim($_POST['reason'] ?? '');
    $details = trim($_POST['details'] ?? '');

    if (!empty($reason)) {
        $stmt = $db->prepare("INSERT INTO reports (reporter_id, reported_id, item_id, reason, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $reported_id, $item_id, $reason, $details]);
        header('Location: profile.php');
        exit;
    }
}

// Handle Item Deletion
if (isset($_GET['delete_item'])) {
    $item_id = $_GET['delete_item'];
    
    // Ensure the user owns the item before deleting
    $stmt = $db->prepare("DELETE FROM items WHERE id = ? AND user_id = ?");
    $stmt->execute([$item_id, $user_id]);
    
    header('Location: profile.php');
    exit;
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile - C2C</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <h1>My Profile</h1>
    <nav>
        <a href="index.php">Home</a> | 
        <a href="profile.php">My Profile</a> | 
        <?php if (!empty($_SESSION['is_admin'])): ?>
            <a href="admin.php">Admin Console</a> | 
        <?php endif; ?>
        <a href="?logout=1">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a>
    </nav>
</header>

<main>
    <h2>Your Details</h2>
    <p><strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?></p>
    <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
    <p><strong>Overall Rating:</strong> 
        <?php if (!empty($user['avg_rating'])): ?>
            <span style="color: #f39c12; font-weight: bold;">⭐️ <?php echo htmlspecialchars($user['avg_rating']); ?> / 5 stars!</span>
        <?php else: ?>
            <span style="color: #666;">No ratings yet</span>
        <?php endif; ?>
    </p>
    <p><strong>ZA ID Status:</strong>
    <?php if ($user['is_verified']): ?>
        <span style="color: green; font-weight: bold;">Verified</span>
    <?php else: ?>
        <span style="color: red; font-weight: bold;">Not Verified</span>
    <?php endif; ?>
    </p>

    <?php if (!$user['is_verified']): ?>
        <hr>
        <h2>Verify Your ID</h2>
        <?php if (isset($verify_error)) echo "<p style='color:red; font-weight:bold;'>$verify_error</p>"; ?>
        <form class="list-item-form" method="POST">
            <div>
                <label>RSA ID Number (13 Digits):</label>
                <input type="text" name="id_number" required pattern="\d{13}" title="Must be exactly 13 digits">
            </div>
            <button type="submit" name="verify_id">Submit for Verification</button>
        </form>
    <?php endif; ?>

    <hr>
    
    <h2>List a New Item</h2>
    <?php if (isset($error)) echo "<p style='color:red; font-weight:bold;'>$error</p>"; ?>
    <form class="list-item-form" method="POST" enctype="multipart/form-data">
        <div>
            <label>Item Name:</label>
            <input type="text" name="item_name" required>
        </div>
        <div>
            <label>Description:</label>
            <textarea name="description"></textarea>
        </div>
        <div>
            <label>Price (R):</label>
            <input type="number" step="0.01" name="price" required>
        </div>
        <div>
            <label>Category:</label>
            <select name="category" required>
                <option value="">Select a category</option>
                <option value="Electronics">Electronics</option>
                <option value="Books">Books</option>
                <option value="Clothing">Clothing</option>
                <option value="Home">Home</option>
                <option value="Other">Other</option>
            </select>
        </div>
        <div>
            <label>Item Image (Optional):</label>
            <input type="file" name="item_image" accept="image/*">
        </div>
        <button type="submit" name="list_item">List Item</button>
    </form>

    <hr>

    <h2>Your Items for Sale</h2>
    <?php if (empty($my_items)): ?>
        <p>You haven't listed any items yet.</p>
    <?php else: ?>
        <div class="item-list">
            <?php foreach ($my_items as $item): ?>
                <div class="item-card">
                    <h3><?php echo htmlspecialchars($item['item_name']); ?></h3>
                    <?php if (!empty($item['image_path'])): ?>
                        <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="Item Image" style="max-width: 100%; height: auto; border-radius: 4px; margin-bottom: 10px;">
                    <?php endif; ?>
                    <p><?php echo htmlspecialchars($item['description'] ?? ''); ?></p>
                    <p><strong>Category:</strong> <?php echo htmlspecialchars($item['category'] ?? 'N/A'); ?></p>
                    <p><strong>Price: R<?php echo number_format($item['price'], 2); ?></strong></p>

                    <a href="edit_item.php?id=<?php echo $item['id']; ?>" style="color: blue; text-decoration: none; font-weight: bold; margin-right: 10px;">[Edit]</a>
                    
                    <a href="profile.php?delete_item=<?php echo $item['id']; ?>" 
                       onclick="return confirm('Are you sure you want to delete this item?')"
                       style="color: red; text-decoration: none; font-weight: bold;">[Delete]</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <hr>
    <h2>Items You Have Sold</h2>
    <?php if (empty($sold_items)): ?>
        <p>You haven't sold any items yet.</p>
    <?php else: ?>
        <div class="item-list">
            <?php foreach ($sold_items as $item): ?>
                <div class="item-card" style="background-color: #e9ecef; border-color: #ced4da;">
                    <h3 style="color: #6c757d; text-decoration: line-through;"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                    <?php if (!empty($item['image_path'])): ?>
                        <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="Item Image" style="max-width: 100%; height: auto; border-radius: 4px; margin-bottom: 10px; opacity: 0.6; filter: grayscale(50%);">
                    <?php endif; ?>
                    <p><strong>Sold to:</strong> <?php echo htmlspecialchars($item['buyer_name'] ?? 'Unknown'); ?></p>
                    <p><strong>Price: R<?php echo number_format($item['price'], 2); ?></strong></p>
                    <p><span style="color: green; font-weight: bold;">[SOLD]</span></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <hr>
    <h2>Items You Have Bought</h2>
    <?php if (empty($bought_items)): ?>
        <p>You haven't bought any items yet.</p>
    <?php else: ?>
        <div class="item-list">
            <?php foreach ($bought_items as $item): ?>
                <div class="item-card" style="background-color: #f8f9fa;">
                    <h3><?php echo htmlspecialchars($item['item_name']); ?></h3>
                    <?php if (!empty($item['image_path'])): ?>
                        <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="Item Image" style="max-width: 100%; height: auto; border-radius: 4px; margin-bottom: 10px;">
                    <?php endif; ?>
                    <p><strong>Bought from:</strong> <?php echo htmlspecialchars($item['seller_name']); ?></p>
                    
                    <?php if ($item['rating']): ?>
                        <p><strong>Your Rating:</strong> <?php echo str_repeat('⭐️', $item['rating']); ?></p>
                    <?php else: ?>
                        <form method="POST" style="margin-top: 10px; display: inline-block; width: auto; background: none; border: none; box-shadow: none; padding: 0;">
                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                            <input type="hidden" name="seller_id" value="<?php echo $item['user_id']; ?>">
                            <select name="rating" required style="padding: 5px; width: auto; display: inline-block; margin-right: 5px;">
                                <option value="">Rate Seller</option>
                                <option value="5">5 Stars</option>
                                <option value="4">4 Stars</option>
                                <option value="3">3 Stars</option>
                                <option value="2">2 Stars</option>
                                <option value="1">1 Star</option>
                            </select>
                            <button type="submit" name="submit_review" style="padding: 5px 10px; background-color: var(--primary-color); color: white; border: none; border-radius: 4px; cursor: pointer;">Submit</button>
                        </form>
                    <?php endif; ?>

                    <div style="margin-top: 15px; border-top: 1px solid var(--border-color); padding-top: 10px;">
                        <?php if ($item['report_id']): ?>
                            <p><strong>Report Status:</strong> 
                                <span style="padding: 3px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: bold; color: white; background-color: <?php echo $item['report_status'] === 'pending' ? '#e67e22' : ($item['report_status'] === 'resolved' ? '#2ecc71' : '#7f8c8d'); ?>;">
                                    <?php echo ucfirst(htmlspecialchars($item['report_status'])); ?>
                                </span>
                            </p>
                            <p style="font-size: 0.85rem; color: #666; margin-top: 5px;">Reason: <?php echo htmlspecialchars($item['report_reason']); ?></p>
                        <?php else: ?>
                            <button onclick="document.getElementById('report-form-<?php echo $item['id']; ?>').style.display='block'; this.style.display='none';" style="padding: 5px 10px; background-color: #e74c3c; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">Report Purchase</button>
                            
                            <div id="report-form-<?php echo $item['id']; ?>" style="display: none; margin-top: 10px; background: #fff; padding: 12px; border-radius: var(--radius-md); border: 1px solid #ffccd5;">
                                <h4 style="margin-bottom: 8px; color: #c0392b; font-size: 0.9rem; font-weight: 600;">Report Seller / Item</h4>
                                <form method="POST" style="padding: 0; background: none; border: none; box-shadow: none; margin-bottom: 0;">
                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                    <input type="hidden" name="reported_id" value="<?php echo $item['user_id']; ?>">
                                    
                                    <div style="margin-bottom: 8px;">
                                        <select name="reason" required style="padding: 5px; font-size: 0.85rem; background-color: #fff;">
                                            <option value="">Select Reason</option>
                                            <option value="Item not as described">Item not as described</option>
                                            <option value="Item never received">Item never received</option>
                                            <option value="Fraud or Scam">Fraud or Scam</option>
                                            <option value="Inappropriate behavior">Inappropriate behavior</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div style="margin-bottom: 8px;">
                                        <textarea name="details" placeholder="Provide more details (optional)..." style="padding: 5px; height: 60px; font-size: 0.85rem; resize: none; background-color: #fff;"></textarea>
                                    </div>
                                    <div style="display: flex; gap: 5px;">
                                        <button type="submit" name="submit_report" style="padding: 5px 10px; background-color: #e74c3c; font-size: 0.8rem; color: white;">Submit Report</button>
                                        <button type="button" onclick="document.getElementById('report-form-<?php echo $item['id']; ?>').style.display='none'; this.parentElement.parentElement.previousElementSibling.style.display='inline-block';" style="padding: 5px 10px; background-color: #95a5a6; color: white; font-size: 0.8rem; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
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