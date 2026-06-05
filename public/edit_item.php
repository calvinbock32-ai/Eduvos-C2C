<?php
session_start();
require_once __DIR__ . '/../src/database.php';

// check user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Make sure they passed in the ID into the URL
if (!isset($_GET['id'])) {
    header('Location: profile.php');
    exit;
}

// Pull the users id and the item ID so that we can get this from the DB
$db = getDbConnection();
$user_id = $_SESSION['user_id'];
$item_card = $_GET['id'];

// Get the item from the DB
if (!empty($_SESSION['is_admin'])) {
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$item_card]);
} else {
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ? AND user_id = ?");
    $stmt->execute([$item_card, $user_id]);
}
$item = $stmt->fetch();

// If the item is not found, redirect to appropriate page
if (!$item) {
    $redirect = !empty($_SESSION['is_admin']) ? 'admin.php' : 'profile.php';
    header("Location: $redirect");
    exit;
}

// --- Handle Form Submission ---
if (isset($_POST['update_item'])) {
    // 1. Grab new values from the incoming POST request
    $item_id = $_POST['item_id'];
    $name = $_POST['item_name'];
    $desc = $_POST['description'];
    $price = $_POST['price'];
    $category = $_POST['category'];
    $image_path = $item['image_path'];
    $upload_error = false;

    // Check if the user selected to remove the image
    if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
        if (!empty($item['image_path'])) {
            $old_file = __DIR__ . '/' . $item['image_path'];
            if (file_exists($old_file)) {
                @unlink($old_file);
            }
        }
        $image_path = null;
    }

    // Handle new image upload
    if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $filename = time() . '_' . basename($_FILES['item_image']['name']);
            if (move_uploaded_file($_FILES['item_image']['tmp_name'], $upload_dir . $filename)) {
                // Delete the old image file since it's replaced
                if (!empty($item['image_path'])) {
                    $old_file = __DIR__ . '/' . $item['image_path'];
                    if (file_exists($old_file)) {
                        @unlink($old_file);
                    }
                }
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

    // 2. Basic Validation: Ensure required fields aren't completely empty
    if (!$upload_error) {
        if (!empty($name) && !empty($price)) {
            // 3. Security: The UPDATE query includes "AND user_id = ?" unless they are admin
            if (!empty($_SESSION['is_admin'])) {
                $stmt = $db->prepare("UPDATE items SET item_name = ?, description = ?, price = ?, category = ?, image_path = ? WHERE id = ?");
                $stmt->execute([$name, $desc, $price, $category, $image_path, $item_id]);
            } else {
                $stmt = $db->prepare("UPDATE items SET item_name = ?, description = ?, price = ?, category = ?, image_path = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $desc, $price, $category, $image_path, $item_id, $user_id]);
            }
            
            // 4. Success! Redirect back to the appropriate page
            $redirect = !empty($_SESSION['is_admin']) ? 'admin.php' : 'profile.php';
            header("Location: $redirect");
            exit;
        } else {
            // Validation failed, store an error message to display in the HTML below
            $error = "Name and Price are mandatory fields";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Item</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <h1>Edit Item</h1>
    <nav>
        <?php if (!empty($_SESSION['is_admin'])): ?>
            <a href="admin.php">Back to Admin Console</a>
        <?php else: ?>
            <a href="profile.php">Back to Profile</a>
        <?php endif; ?>
    </nav>
</header>

<main>
    <?php if (isset($error)) echo "<p style='color:red; font-weight:bold;'>$error</p>"; ?>

    <form class="list-item-form" method="POST" enctype="multipart/form-data">
        <!-- Hidden input to pass the ID when submitted -->
        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
        
        <div>
            <label>Item Name:</label>
            <input type="text" name="item_name" value="<?php echo htmlspecialchars($item['item_name']); ?>" required>
        </div>
        <div>
            <label>Description:</label>
            <textarea name="description"><?php echo htmlspecialchars($item['description']); ?></textarea>
        </div>
        <div>
            <label>Price (R):</label>
            <input type="number" step="0.01" name="price" value="<?php echo htmlspecialchars($item['price']); ?>" required>
        </div>
        <div>
            <label>Category:</label>
            <select name="category" required>
                <option value="">Select a category</option>
                <option value="Electronics" <?php if ($item['category'] == 'Electronics') echo 'selected'; ?>>Electronics</option>
                <option value="Books" <?php if ($item['category'] == 'Books') echo 'selected'; ?>>Books</option>
                <option value="Clothing" <?php if ($item['category'] == 'Clothing') echo 'selected'; ?>>Clothing</option>
                <option value="Home" <?php if ($item['category'] == 'Home') echo 'selected'; ?>>Home</option>
                <option value="Other" <?php if ($item['category'] == 'Other') echo 'selected'; ?>>Other</option>
            </select>
        </div>
        
        <!-- Display Current Image and delete option -->
        <?php if (!empty($item['image_path'])): ?>
            <div style="margin-bottom: 15px;">
                <label>Current Image:</label>
                <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="Current Item Image" style="max-width: 150px; height: auto; border-radius: 6px; display: block; margin-bottom: 8px; box-shadow: var(--shadow-sm);">
                <label style="display: inline-flex; align-items: center; font-weight: normal; cursor: pointer; gap: 8px; font-size: 0.9rem; color: #e74c3c;">
                    <input type="checkbox" name="remove_image" value="1" style="width: auto; height: auto; cursor: pointer;">
                    Remove current image
                </label>
            </div>
        <?php endif; ?>

        <div>
            <label>Upload New Image (Optional):</label>
            <input type="file" name="item_image" accept="image/*" style="background-color: transparent; border: none; padding: 0; box-shadow: none;">
        </div>

        <button type="submit" name="update_item">Update Item</button>
    </form>
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

