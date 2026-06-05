<?php
/**
 * index.php - Main entry point for the Eduvos C2C Marketplace.
 * Handles user authentication (login, registration, logout) and 
 * displays the marketplace item feed with filtering capabilities.
 */

session_start();
require_once __DIR__ . '/../src/database.php';

$db = getDbConnection();
$error = "";
$success = "";

// --- Handle Registration Logic ---
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($username) || empty($email) || empty($password)) {
        $error = "All fields are required.";
    } else {
        // Check if username already exists to prevent duplicates
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = "Username is already taken.";
        } else {
            // Securely hash the password and insert new user into database
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $db->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
                $stmt->execute([$username, $hash, $email]);
                $success = "Registration successful! You can now log in.";
            } catch (PDOException $e) {
                $error = "An error occurred during registration.";
            }
        }
    }
}

// --- Handle Login Logic ---
if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Fetch user by username
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Verify password against the stored hash
    if ($user && password_verify($password, $user['password'])) {
        if (!empty($user['is_banned'])) {
            $error = "Your account has been suspended by an administrator.";
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = (int)($user['is_admin'] ?? 0);
            header('Location: index.php');
            exit;
        }
    } else {
        $error = "Invalid username or password.";
    }
}

// --- Handle Logout Logic ---
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// --- Fetch Marketplace Items with Filtering ---
// Get filter parameters from GET request
$category = $_GET['category'] ?? '';
$min_price = (isset($_GET['min_price']) && $_GET['min_price'] !== '') ? $_GET['min_price'] : null;
$max_price = (isset($_GET['max_price']) && $_GET['max_price'] !== '') ? $_GET['max_price'] : null;

// Build the SQL query dynamically based on filters
$query = "SELECT items.*, users.username as seller, users.is_verified, (SELECT ROUND(AVG(rating), 1) FROM reviews WHERE seller_id = users.id) as avg_rating FROM items JOIN users ON items.user_id = users.id WHERE items.status = 'available' AND users.is_banned = 0";
$params = [];

// Exclude the logged in user's items from the feed
if (isset($_SESSION['user_id'])) {
    $query .= " AND items.user_id != ?";
    $params[] = $_SESSION['user_id'];
}

if (!empty($category)) {
    $query .= " AND items.category = ?";
    $params[] = $category;
}

if ($min_price !== null) {
    $query .= " AND items.price >= ?";
    $params[] = $min_price;
}

if ($max_price !== null) {
    $query .= " AND items.price <= ?";
    $params[] = $max_price;
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Distinct categories for the filter dropdown
$categories_stmt = $db->query("SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category != ''");
$available_categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MzansiBuys Marketplace</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <h1>MzansiBuys Marketplace</h1>
    <?php if (isset($_SESSION['user_id'])): ?>
        <!-- Navigation for logged-in users -->
        <nav>
            <a href="index.php">Home</a> | 
            <a href="profile.php">My Profile</a> | 
            <?php if (!empty($_SESSION['is_admin'])): ?>
                <a href="admin.php">Admin Console</a> | 
            <?php endif; ?>
            <a href="?logout=1">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a>
        </nav>
    <?php endif; ?>
</header>

<main>
    <?php if (!isset($_SESSION['user_id'])): ?>
        <!-- Display Login and Registration forms for guests -->
        <div class="guest-container">
            <!-- Hero Promo Area -->
            <div class="guest-hero">
                <div class="hero-tag">Customer-To-Customer</div>
                <h2>The Smartest Way to Trade</h2>
                <p class="hero-subtitle">Welcome to the official MzansiBuys Marketplace. Buy, sell, and exchange textbooks, electronics, clothing, and other essentials securely within your community.</p>
                
                <div class="feature-list">
                    <div class="feature-item">
                        <span class="feature-icon">🛡️</span>
                        <div>
                            <strong>RSA ID Verified Profiles</strong>
                            <p>Trade with confidence. Sellers verify their identities using secure South African ID validation.</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">⭐</span>
                        <div>
                            <strong>Peer Rating & Feedback</strong>
                            <p>Rate your transaction partners and check feedback ratings before buying.</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">🔒</span>
                        <div>
                            <strong>Admin-Moderated Trades</strong>
                            <p>Our safe community is protected by proactive moderation and report handling.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Forms Container -->
            <div class="guest-forms">
                <!-- Login Section -->
                <section class="auth-section">
                    <h3>Welcome Back</h3>
                    <p class="auth-desc">Log in to browse items and manage listings.</p>
                    <?php if ($error && isset($_POST['login'])) echo "<p style='color:red; font-weight:bold; font-size:0.9rem; margin-bottom:10px;'>✗ $error</p>"; ?>
                    <form method="POST">
                        <div style="margin-bottom: 12px;">
                            <label>Username:</label>
                            <input type="text" name="username" required placeholder="Username">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label>Password:</label>
                            <input type="password" name="password" required placeholder="••••••••">
                        </div>
                        <button type="submit" name="login" style="width: 100%;">Login</button>
                    </form>
                </section>

                <!-- Registration Section -->
                <section class="auth-section">
                    <h3>Create Account</h3>
                    <p class="auth-desc">Join your community today.</p>
                    <?php if ($error && isset($_POST['register'])) echo "<p style='color:red; font-weight:bold; font-size:0.9rem; margin-bottom:10px;'>✗ $error</p>"; ?>
                    <?php if ($success) echo "<p style='color:green; font-weight:bold; font-size:0.9rem; margin-bottom:10px;'>✓ $success</p>"; ?>
                    <form method="POST">
                        <div style="margin-bottom: 12px;">
                            <label>Username:</label>
                            <input type="text" name="username" required placeholder="Choose a username">
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label>Email Address:</label>
                            <input type="email" name="email" required placeholder="user@example.com">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label>Password:</label>
                            <input type="password" name="password" required placeholder="Create a password">
                        </div>
                        <button type="submit" name="register" style="width: 100%; background-color: var(--secondary-color);">Register</button>
                    </form>
                </section>
            </div>
        </div>

    <!-- Beautiful Mock Terms and Safety Section -->
    <section id="terms-section" style="margin-top: 60px; background: var(--card-bg); border: 1px solid var(--border-color); padding: 30px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
        <h2 style="font-size: 1.5rem; margin-bottom: 20px; color: var(--primary-color); text-align: center;">MzansiBuys Trading Agreement & Safety Rules</h2>
        <div class="terms-grid">
            <div class="terms-card">
                <h3 id="terms">Fictitious Terms & Conditions</h3>
                <p style="font-size: 0.85rem; color: #64748b; line-height: 1.5; margin-bottom: 10px;">
                    By listing or purchasing items on the MzansiBuys platform, you agree to these mock rules designed for educational simulation:
                </p>
                <ul style="font-size: 0.85rem; color: #64748b; padding-left: 20px;">
                    <li>This is a simulated classroom/academic environment. No real money or actual products should be exchanged.</li>
                    <li>Sellers represent that listings are honest, safe, and community-appropriate. No prohibited materials are allowed.</li>
                    <li>The university holds zero liability for simulated trades, disputes, or agreements negotiated on this platform.</li>
                    <li>Users agree to complete ID verification (simulated South African ID validation) prior to selling items.</li>
                </ul>
            </div>
            <div class="terms-card">
                <h3 id="safety">Campus Safety Guidelines</h3>
                <p style="font-size: 0.85rem; color: #64748b; line-height: 1.5; margin-bottom: 10px;">
                    To ensure safety during trade handovers, please follow these guidelines:
                </p>
                <ul style="font-size: 0.85rem; color: #64748b; padding-left: 20px;">
                    <li><strong>Meet in Public:</strong> Always negotiate handovers in crowded public areas (e.g., local coffee shop, library, public square).</li>
                    <li><strong>Inspect First:</strong> Thoroughly inspect the item before confirming any transactions or payments.</li>
                    <li><strong>Bring a Friend:</strong> Whenever possible, have a classmate or friend accompany you to handovers.</li>
                    <li><strong>Report Abuse:</strong> Use the built-in purchase reporting system immediately if a transaction feels suspicious or dishonest.</li>
                </ul>
            </div>
        </div>
    </section>

    <?php else: ?>
        <!-- Filter Section -->
        <section class="filter-section" style="background: #f4f4f4; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <form method="GET" action="index.php" id="filterForm">
                <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: center;">
                    <!-- Category Dropdown -->
                    <div>
                        <label for="category">Category:</label><br>
                        <select name="category" id="category" style="padding: 5px; border-radius: 4px;">
                            <option value="">All Categories</option>
                            <?php foreach ($available_categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($category === $cat) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Dual Price Slider -->
                    <div>
                        <label>Price Range:</label>
                        <div class="price-slider-container">
                            <input type="range" id="min_range" min="0" max="10000" step="10" value="<?php echo ($min_price !== null) ? $min_price : 0; ?>">
                            <input type="range" id="max_range" min="0" max="10000" step="10" value="<?php echo ($max_price !== null) ? $max_price : 10000; ?>">
                        </div>
                        <div class="slider-values">
                            <span>R<span id="min_val"><?php echo ($min_price !== null) ? (int)$min_price : 0; ?></span></span>
                            <span>R<span id="max_val"><?php echo ($max_price !== null) ? (int)$max_price : 10000; ?></span></span>
                        </div>
                        <!-- Hidden inputs for PHP -->
                        <input type="hidden" name="min_price" id="hidden_min" value="<?php echo ($min_price !== null) ? $min_price : 0; ?>">
                        <input type="hidden" name="max_price" id="hidden_max" value="<?php echo ($max_price !== null) ? $max_price : 10000; ?>">
                    </div>

                    <div>
                        <button type="submit">Apply Filters</button>
                        <a href="index.php" style="margin-left: 10px; text-decoration: none; color: #666;">Clear</a>
                    </div>
                </div>
            </form>
        </section>

        <!-- Display Marketplace items for logged-in users -->
        <h2>Items for Sale</h2>
        <div style="margin-bottom: 20px;">
            <input type="text" id="live_search" placeholder="Search for items, categories, or sellers..." style="width: 100%; padding: 10px; font-size: 16px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;">
        </div>
        <div class="item-list">
            <?php if (empty($items)): ?>
                <p>No items found matching your criteria.</p>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <div class="item-card">
                        <h3><?php echo htmlspecialchars($item['item_name']); ?></h3>
                        <?php if (!empty($item['image_path'])): ?>
                            <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="Item Image" style="max-width: 100%; height: auto; border-radius: 4px; margin-bottom: 10px;">
                        <?php endif; ?>
                        <!-- Added truncate-text class -->
                        
                        <p><strong style="color: #2c3e50;">Price: R<?php echo number_format($item['price'], 2); ?></strong></p>
                        <p><small>Category: <?php echo htmlspecialchars($item['category'] ?? 'N/A'); ?></small></p>
                        <p>
                            <small>Seller: <?php echo htmlspecialchars($item['seller']); ?></small>
                            <?php if (!empty($item['avg_rating'])): ?>
                                <span style="font-size: 0.85em; margin-left: 5px; color: #f39c12; font-weight: bold;">(⭐️ <?php echo htmlspecialchars($item['avg_rating']); ?>)</span>
                            <?php endif; ?>
                            <?php if ($item['is_verified'] == 1): ?>
                                <span style="color: green; font-weight: bold; font-size: 0.85em; margin-left: 5px;">[Verified]</span>
                            <?php else: ?>
                                <span style="color: red; font-weight: bold; font-size: 0.85em; margin-left: 5px;">[Unverified]</span>
                            <?php endif; ?>
                        </p>
                        <p style="margin-top: 15px;">
                            <!-- New View Details Button -->
                            <button type="button" class="view-details-btn" 
                                    data-title="<?php echo htmlspecialchars($item['item_name']); ?>"
                                    data-desc="<?php echo htmlspecialchars($item['description'] ?? 'No description available.'); ?>"
                                    data-price="<?php echo number_format($item['price'], 2); ?>"
                                    data-seller="<?php echo htmlspecialchars($item['seller']); ?>"
                                    data-category="<?php echo htmlspecialchars($item['category'] ?? 'N/A'); ?>"
                                    data-image="<?php echo htmlspecialchars($item['image_path'] ?? ''); ?>"
                                    style="padding: 6px 12px; background-color: #f39c12; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; margin-right: 5px;">
                                View Details
                            </button>
                            <!-- Existing Buy Now Link -->
                            <a href="checkout.php?id=<?php echo $item['id']; ?>" style="display: inline-block; padding: 6px 12px; background-color: #28a745; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">Buy Now</a>
                        </p>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <!-- Hidden Modal Overlay -->
    <div id="itemModal" class="modal-overlay">
        <div class="modal-content">
            <span class="close-modal" id="closeModal">&times;</span>
            <h2 id="modalTitle">Item Title</h2>
            <img id="modalImage" src="" alt="Item Image" style="max-width: 100%; height: auto; border-radius: 4px; margin-bottom: 15px; display: none;">
            <p><strong style="color: #2c3e50;">Price: R<span id="modalPrice"></span></strong></p>
            <p><small>Category: <span id="modalCategory"></span></small></p>
            <p><small>Seller: <span id="modalSeller"></span></small></p>
            <hr style="border: 1px solid #ddd; margin: 15px 0;">
            <p id="modalDesc" style="white-space: pre-wrap; line-height: 1.6;">Item description goes here...</p>
        </div>
    </div>
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
/**
 * Dual Range Slider Logic
 */
const minRange = document.getElementById('min_range');
const maxRange = document.getElementById('max_range');
const minValDisplay = document.getElementById('min_val');
const maxValDisplay = document.getElementById('max_val');
const hiddenMin = document.getElementById('hidden_min');
const hiddenMax = document.getElementById('hidden_max');

function updateSlider() {
    let minVal = parseInt(minRange.value);
    let maxVal = parseInt(maxRange.value);

    // Prevent handles from crossing
    if (minVal > maxVal) {
        let temp = minVal;
        minVal = maxVal;
        maxVal = temp;
    }

    // Update displays
    minValDisplay.textContent = minVal;
    maxValDisplay.textContent = maxVal;

    // Update hidden inputs for form submission
    hiddenMin.value = minVal;
    hiddenMax.value = maxVal;
}

// Add event listeners to both sliders
minRange.addEventListener('input', () => {
    if (parseInt(minRange.value) > parseInt(maxRange.value)) {
        minRange.value = maxRange.value;
    }
    updateSlider();
});

maxRange.addEventListener('input', () => {
    if (parseInt(maxRange.value) < parseInt(minRange.value)) {
        maxRange.value = minRange.value;
    }
    updateSlider();
});

// Initialize on page load in case of pre-filled values from PHP
window.addEventListener('DOMContentLoaded', updateSlider);

// Live Search Logic
const searchInput = document.getElementById('live_search');
if (searchInput) {
    searchInput.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        // Grab every item card on the page
        const cards = document.querySelectorAll('.item-card');
        cards.forEach(card => {
            // Get all the text inside the card (title, desc, seller, etc.)
            const text = card.textContent.toLowerCase();
            // If the text includes what the user typed, show it. Otherwise, hide it.
            if (text.includes(filter)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });
}

// Modal Logic
const modal = document.getElementById('itemModal');
const closeModal = document.getElementById('closeModal');
const detailButtons = document.querySelectorAll('.view-details-btn');
if (modal && detailButtons) {
    // Listen for clicks on any "View Details" button
    detailButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            // Read data from the button's data-* attributes and inject into the modal
            document.getElementById('modalTitle').textContent = this.dataset.title;
            document.getElementById('modalPrice').textContent = this.dataset.price;
            document.getElementById('modalCategory').textContent = this.dataset.category;
            document.getElementById('modalSeller').textContent = this.dataset.seller;
            document.getElementById('modalDesc').textContent = this.dataset.desc;
            
            const modalImg = document.getElementById('modalImage');
            if (this.dataset.image) {
                modalImg.src = this.dataset.image;
                modalImg.style.display = 'block';
            } else {
                modalImg.style.display = 'none';
            }
            // Finally, show the modal
            modal.style.display = 'flex';
        });
    });
    // Close the modal when clicking the 'X'
    closeModal.addEventListener('click', () => {
        modal.style.display = 'none';
    });
    // Close the modal when clicking on the dark background overlay
    window.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
}
</script>

</body>
</html>