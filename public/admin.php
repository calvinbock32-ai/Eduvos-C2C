<?php
session_start();
require_once __DIR__ . '/../src/database.php';

// Enforce admin-only access
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}

$db = getDbConnection();
$admin_id = $_SESSION['user_id'];
$success_message = "";
$error_message = "";

// --- Handle Action: Dismiss Report ---
if (isset($_GET['dismiss_report'])) {
    $report_id = (int)$_GET['dismiss_report'];
    $stmt = $db->prepare("UPDATE reports SET status = 'dismissed' WHERE id = ?");
    $stmt->execute([$report_id]);
    $success_message = "Report #$report_id has been dismissed.";
}

// --- Handle Action: Delete Posting ---
if (isset($_GET['delete_posting'])) {
    $item_id = (int)$_GET['delete_posting'];
    
    // Resolve any pending reports for this item
    $stmt = $db->prepare("UPDATE reports SET status = 'resolved' WHERE item_id = ?");
    $stmt->execute([$item_id]);
    
    // Delete the item listing
    $stmt = $db->prepare("DELETE FROM items WHERE id = ?");
    $stmt->execute([$item_id]);
    
    $success_message = "Item #$item_id listing has been deleted.";
}

// --- Handle Action: Ban User ---
if (isset($_GET['ban_user'])) {
    $user_id = (int)$_GET['ban_user'];
    
    if ($user_id === $admin_id) {
        $error_message = "You cannot ban yourself.";
    } else {
        // Mark user as banned
        $stmt = $db->prepare("UPDATE users SET is_banned = 1 WHERE id = ?");
        $stmt->execute([$user_id]);
        
        // Remove all active listings for this user
        $stmt = $db->prepare("DELETE FROM items WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Resolve any pending reports targeting this user
        $stmt = $db->prepare("UPDATE reports SET status = 'resolved' WHERE reported_id = ?");
        $stmt->execute([$user_id]);
        
        $success_message = "User has been banned and their listings removed.";
    }
}

// --- Handle Action: Unban User ---
if (isset($_GET['unban_user'])) {
    $user_id = (int)$_GET['unban_user'];
    $stmt = $db->prepare("UPDATE users SET is_banned = 0 WHERE id = ?");
    $stmt->execute([$user_id]);
    $success_message = "User has been unbanned.";
}

// --- Handle Action: Toggle Admin ---
if (isset($_GET['toggle_admin'])) {
    $user_id = (int)$_GET['toggle_admin'];
    
    if ($user_id === $admin_id) {
        $error_message = "You cannot demote yourself.";
    } else {
        $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user) {
            $new_role = $user['is_admin'] ? 0 : 1;
            $stmt = $db->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
            $stmt->execute([$new_role, $user_id]);
            $success_message = "User role has been updated.";
        }
    }
}

// --- Fetch Statistics ---
$total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_items = $db->query("SELECT COUNT(*) FROM items")->fetchColumn();
$pending_reports = $db->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();

// --- Fetch Reports ---
$reports = $db->query("
    SELECT reports.*, 
           u_rep.username as reporter_name, 
           u_acc.username as reported_name, 
           items.item_name 
    FROM reports
    JOIN users u_rep ON reports.reporter_id = u_rep.id
    JOIN users u_acc ON reports.reported_id = u_acc.id
    LEFT JOIN items ON reports.item_id = items.id
    ORDER BY reports.created_at DESC
")->fetchAll();

// --- Fetch All Listings ---
$all_items = $db->query("
    SELECT items.*, users.username as seller_name, users.is_banned as seller_banned
    FROM items
    JOIN users ON items.user_id = users.id
    ORDER BY items.id DESC
")->fetchAll();

// --- Fetch All Users ---
$all_users = $db->query("
    SELECT users.*, 
           (SELECT COUNT(*) FROM items WHERE user_id = users.id) as item_count,
           (SELECT ROUND(AVG(rating), 1) FROM reviews WHERE seller_id = users.id) as avg_rating
    FROM users
    ORDER BY users.id ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Console - MzansiBuys</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Modern Admin Stats Styles */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            text-align: center;
        }
        .stat-card h3 {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 10px;
        }
        .stat-card .value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        /* Admin Navigation Tabs */
        .admin-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }
        .tab-btn {
            background: transparent;
            color: var(--text-color);
            border: none;
            padding: 10px 20px;
            font-weight: 600;
            border-radius: var(--radius-md);
            cursor: pointer;
            box-shadow: none !important;
            transform: none !important;
            transition: all 0.2s ease;
        }
        .tab-btn:hover {
            background: #e2e8f0;
            color: var(--primary-color);
        }
        .tab-btn.active {
            background: var(--primary-color);
            color: white;
        }

        /* Data Tables */
        .admin-table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            overflow-x: auto;
            box-shadow: var(--shadow-sm);
            margin-bottom: 40px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }
        th, td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
        }
        tr:hover {
            background-color: #f8fafc;
        }

        /* Action Badges & Buttons */
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 700;
            color: white;
            text-transform: uppercase;
        }
        .badge-pending { background-color: #e67e22; }
        .badge-resolved { background-color: #2ecc71; }
        .badge-dismissed { background-color: #94a3b8; }
        .badge-banned { background-color: #ef4444; }
        .badge-active { background-color: #10b981; }

        .action-link {
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 5px 10px;
            border-radius: 4px;
            margin-right: 5px;
            display: inline-block;
            transition: opacity 0.2s;
        }
        .action-link:hover {
            opacity: 0.8;
        }
        .btn-dismiss { background-color: #64748b; color: white; }
        .btn-resolve { background-color: #10b981; color: white; }
        .btn-ban { background-color: #ef4444; color: white; }
        .btn-edit { background-color: #3b82f6; color: white; }

        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>

<header>
    <h1>Admin Console</h1>
    <nav>
        <a href="index.php">Home</a> | 
        <a href="profile.php">My Profile</a> | 
        <a href="index.php?logout=1">Logout</a>
    </nav>
</header>

<main>
    <h2>Welcome to the Control Panel</h2>

    <?php if ($success_message): ?>
        <div style="background-color: #d1fae5; border: 1px solid #10b981; color: #065f46; padding: 15px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 600;">
            ✓ <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div style="background-color: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 15px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 600;">
            ✗ <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- Summary Statistics Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Users</h3>
            <div class="value"><?php echo $total_users; ?></div>
        </div>
        <div class="stat-card">
            <h3>Active Listings</h3>
            <div class="value"><?php echo $total_items; ?></div>
        </div>
        <div class="stat-card">
            <h3>Pending Reports</h3>
            <div class="value" style="color: #e67e22;"><?php echo $pending_reports; ?></div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="admin-tabs">
        <button class="tab-btn active" onclick="switchTab('reports')">Reports Dashboard</button>
        <button class="tab-btn" onclick="switchTab('listings')">Active Listings</button>
        <button class="tab-btn" onclick="switchTab('users')">User Management</button>
    </div>

    <!-- TAB 1: Reports Dashboard -->
    <div id="tab-reports" class="tab-content active">
        <h2>Reports Dashboard</h2>
        <div class="admin-table-container">
            <?php if (empty($reports)): ?>
                <p style="padding: 20px; text-align: center; color: #64748b;">No reports have been submitted yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Reporter</th>
                            <th>Reported User</th>
                            <th>Related Item</th>
                            <th>Reason</th>
                            <th>Details</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $rep): ?>
                            <tr>
                                <td>#<?php echo $rep['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($rep['reporter_name']); ?></strong></td>
                                <td><span style="color: #c0392b; font-weight: bold;"><?php echo htmlspecialchars($rep['reported_name']); ?></span></td>
                                <td>
                                    <?php if ($rep['item_name']): ?>
                                        <?php echo htmlspecialchars($rep['item_name']); ?> (ID #<?php echo $rep['item_id']; ?>)
                                    <?php else: ?>
                                        <em style="color: #94a3b8;">[Deleted Listing]</em>
                                    <?php endif; ?>
                                </td>
                                <td><span style="color: #e74c3c; font-weight: 600;"><?php echo htmlspecialchars($rep['reason']); ?></span></td>
                                <td><?php echo htmlspecialchars($rep['details'] ?: 'N/A'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo htmlspecialchars($rep['status']); ?>">
                                        <?php echo htmlspecialchars($rep['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($rep['status'] === 'pending'): ?>
                                        <a href="?dismiss_report=<?php echo $rep['id']; ?>" class="action-link btn-dismiss" onclick="return confirm('Dismiss this report?')">Dismiss</a>
                                        <?php if ($rep['item_id']): ?>
                                            <a href="?delete_posting=<?php echo $rep['item_id']; ?>" class="action-link btn-resolve" onclick="return confirm('Delete reported posting?')">Delete Posting</a>
                                        <?php endif; ?>
                                        <a href="?ban_user=<?php echo $rep['reported_id']; ?>" class="action-link btn-ban" onclick="return confirm('Ban the accused seller? All listings will be deleted.')">Ban Seller</a>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-size: 0.85rem;">None</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB 2: Active Listings -->
    <div id="tab-listings" class="tab-content">
        <h2>Active Listings</h2>
        <div class="admin-table-container">
            <?php if (empty($all_items)): ?>
                <p style="padding: 20px; text-align: center; color: #64748b;">No active listings found.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Image</th>
                            <th>Seller</th>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_items as $itm): ?>
                            <tr>
                                <td>#<?php echo $itm['id']; ?></td>
                                <td>
                                    <?php if (!empty($itm['image_path'])): ?>
                                        <img src="<?php echo htmlspecialchars($itm['image_path']); ?>" alt="Thumb" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid var(--border-color); display: block;">
                                    <?php else: ?>
                                        <span style="font-size: 0.75rem; color: #94a3b8; font-style: italic;">No Image</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($itm['seller_name']); ?></strong>
                                    <?php if ($itm['seller_banned']): ?>
                                        <span class="badge badge-banned" style="font-size: 0.65rem;">Banned</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($itm['item_name']); ?></td>
                                <td><?php echo htmlspecialchars($itm['category'] ?: 'Other'); ?></td>
                                <td>R<?php echo number_format($itm['price'], 2); ?></td>
                                <td>
                                    <span class="badge <?php echo $itm['status'] === 'sold' ? 'badge-dismissed' : 'badge-active'; ?>">
                                        <?php echo htmlspecialchars($itm['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="edit_item.php?id=<?php echo $itm['id']; ?>" class="action-link btn-edit">Edit</a>
                                    <a href="?delete_posting=<?php echo $itm['id']; ?>" class="action-link btn-ban" onclick="return confirm('Delete this posting permanently?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB 3: User Management -->
    <div id="tab-users" class="tab-content">
        <h2>User Management</h2>
        <div class="admin-table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Listings</th>
                        <th>Overall Rating</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_users as $usr): ?>
                        <tr>
                            <td>#<?php echo $usr['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($usr['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($usr['email']); ?></td>
                            <td>
                                <span class="badge" style="background-color: <?php echo $usr['is_admin'] ? '#4f46e5' : '#94a3b8'; ?>;">
                                    <?php echo $usr['is_admin'] ? 'ADMIN' : 'USER'; ?>
                                </span>
                            </td>
                            <td><?php echo $usr['item_count']; ?> items</td>
                            <td>
                                <?php echo $usr['avg_rating'] ? "⭐️ " . $usr['avg_rating'] : '<span style="color:#94a3b8;">None</span>'; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $usr['is_banned'] ? 'badge-banned' : 'badge-active'; ?>">
                                    <?php echo $usr['is_banned'] ? 'Banned' : 'Active'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($usr['id'] !== $admin_id): ?>
                                    <a href="?toggle_admin=<?php echo $usr['id']; ?>" class="action-link btn-dismiss">
                                        Toggle Admin
                                    </a>
                                    <?php if ($usr['is_banned']): ?>
                                        <a href="?unban_user=<?php echo $usr['id']; ?>" class="action-link btn-resolve">Unban</a>
                                    <?php else: ?>
                                        <a href="?ban_user=<?php echo $usr['id']; ?>" class="action-link btn-ban" onclick="return confirm('Ban user and remove their active items?')">Ban</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #64748b; font-size: 0.85rem; font-style: italic;">You (Current Session)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
    // Tab switching utility
    function switchTab(tabId) {
        // Deactivate all tabs and contents
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        
        // Find correct button and content to activate
        const activeBtn = Array.from(document.querySelectorAll('.tab-btn')).find(btn => btn.getAttribute('onclick').includes(tabId));
        if (activeBtn) activeBtn.classList.add('active');
        document.getElementById('tab-' + tabId).classList.add('active');
    }
</script>

</body>
</html>
