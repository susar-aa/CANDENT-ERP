<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); 

// --- IMAGE COMPRESSION HELPER ---
function compressAndResizeImage($source, $destination, $quality = 85, $maxWidth = 800) {
    $info = getimagesize($source);
    if (!$info) return false;
    
    $width = $info[0];
    $height = $info[1];
    $mime = $info['mime'];
    
    $newWidth = ($width > $maxWidth) ? $maxWidth : $width;
    $newHeight = floor($height * ($newWidth / $width));
    
    $imageResized = imagecreatetruecolor($newWidth, $newHeight);
    
    if ($mime == 'image/png' || $mime == 'image/gif') {
        imagealphablending($imageResized, false);
        imagesavealpha($imageResized, true);
        $transparent = imagecolorallocatealpha($imageResized, 255, 255, 255, 127);
        imagefilledrectangle($imageResized, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    switch ($mime) {
        case 'image/jpeg': $image = imagecreatefromjpeg($source); break;
        case 'image/png': $image = imagecreatefrompng($source); break;
        case 'image/gif': $image = imagecreatefromgif($source); break;
        case 'image/webp': $image = imagecreatefromwebp($source); break;
        default: return false; // Unsupported type for compression
    }
    
    imagecopyresampled($imageResized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    switch ($mime) {
        case 'image/jpeg': imagejpeg($imageResized, $destination, $quality); break;
        case 'image/png': 
            $pngQuality = round((100 - $quality) / 10);
            imagepng($imageResized, $destination, $pngQuality); 
            break;
        case 'image/gif': imagegif($imageResized, $destination); break;
        case 'image/webp': imagewebp($imageResized, $destination, $quality); break;
    }
    
    imagedestroy($image);
    imagedestroy($imageResized);
    return true;
}
// --------------------------------

$message = '';

// --- AUTO DB MIGRATION FOR EMAIL CAMPAIGNS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject VARCHAR(255) NOT NULL,
        headline VARCHAR(255),
        description TEXT,
        image_url VARCHAR(255),
        status ENUM('draft', 'sent') DEFAULT 'draft',
        sent_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) {}

$uploadDir = '../assets/images/campaigns/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Add Campaign
    if ($_POST['action'] == 'add_campaign') {
        $subject = trim($_POST['subject']);
        $headline = trim($_POST['headline']);
        $description = trim($_POST['description']);
        $image_url = '';

        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $image_url = time() . '_' . uniqid() . '.' . $ext;
                $targetFilePath = $uploadDir . $image_url;
                
                // Compress and Resize instead of direct move
                if (!compressAndResizeImage($_FILES['image']['tmp_name'], $targetFilePath, 85, 800)) {
                    move_uploaded_file($_FILES['image']['tmp_name'], $targetFilePath); // Fallback
                }
            }
        }

        if (!empty($subject) && !empty($headline)) {
            $stmt = $pdo->prepare("INSERT INTO email_campaigns (subject, headline, description, image_url, status) VALUES (?, ?, ?, ?, 'draft')");
            if ($stmt->execute([$subject, $headline, $description, $image_url])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Campaign created successfully as Draft!</div>";
            }
        } else {
            $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Subject and Headline are required.</div>";
        }
    }
    
    // Edit Campaign
    if ($_POST['action'] == 'edit_campaign') {
        $id = (int)$_POST['campaign_id'];
        $subject = trim($_POST['subject']);
        $headline = trim($_POST['headline']);
        $description = trim($_POST['description']);

        $campaign = $pdo->prepare("SELECT image_url FROM email_campaigns WHERE id = ?");
        $campaign->execute([$id]);
        $current_image = $campaign->fetchColumn();
        $image_url = $current_image;

        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                if ($current_image && file_exists($uploadDir . $current_image)) unlink($uploadDir . $current_image);
                $image_url = time() . '_' . uniqid() . '.' . $ext;
                $targetFilePath = $uploadDir . $image_url;
                
                // Compress and Resize
                if (!compressAndResizeImage($_FILES['image']['tmp_name'], $targetFilePath, 85, 800)) {
                    move_uploaded_file($_FILES['image']['tmp_name'], $targetFilePath);
                }
            }
        }

        if ($id && !empty($subject)) {
            $stmt = $pdo->prepare("UPDATE email_campaigns SET subject = ?, headline = ?, description = ?, image_url = ? WHERE id = ?");
            if ($stmt->execute([$subject, $headline, $description, $image_url, $id])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Campaign updated successfully!</div>";
            }
        }
    }

    // Delete Campaign
    if ($_POST['action'] == 'delete_campaign') {
        $id = (int)$_POST['campaign_id'];
        $campaign = $pdo->prepare("SELECT image_url FROM email_campaigns WHERE id = ?");
        $campaign->execute([$id]);
        $img = $campaign->fetchColumn();
        
        if ($img && file_exists($uploadDir . $img)) {
            unlink($uploadDir . $img);
        }
        
        $pdo->prepare("DELETE FROM email_campaigns WHERE id = ?")->execute([$id]);
        $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Campaign deleted successfully!</div>";
    }
}

// Fetch Campaigns
$campaigns = $pdo->query("SELECT * FROM email_campaigns ORDER BY created_at DESC")->fetchAll();

// Split into Drafts and Sent
$drafts = [];
$sentHistory = [];
foreach($campaigns as $c) {
    if($c['status'] == 'sent') {
        $sentHistory[] = $c;
    } else {
        $drafts[] = $c;
    }
}

// Count Valid Emails for Badge
$validEmails = $pdo->query("SELECT COUNT(id) FROM customers WHERE email IS NOT NULL AND email != ''")->fetchColumn();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Specific Page Styles */
    .ios-segmented-control {
        display: inline-flex;
        background: rgba(118, 118, 128, 0.12);
        padding: 4px;
        border-radius: 12px;
        margin-bottom: 24px;
        width: auto;
    }
    .ios-segmented-control .nav-link {
        color: var(--ios-label);
        font-weight: 600;
        font-size: 0.85rem;
        padding: 8px 20px;
        border-radius: 8px;
        transition: all 0.2s;
        border: none;
        background: transparent;
    }
    .ios-segmented-control .nav-link:hover { opacity: 0.8; }
    .ios-segmented-control .nav-link.active {
        background: #fff;
        color: var(--ios-label);
        box-shadow: 0 3px 8px rgba(0,0,0,0.12), 0 1px 1px rgba(0,0,0,0.04);
    }
    
    /* Explicit Modal Inputs Visibility */
    .modal-body .ios-input, .modal-body .form-control {
        background: #FFFFFF !important;
        border: 1px solid #C7C7CC !important;
        border-radius: 10px !important;
        padding: 10px 14px !important;
        font-size: 0.95rem !important;
        color: #000000 !important;
        width: 100%;
        outline: none;
        box-shadow: inset 0 1px 3px rgba(0,0,0,0.03) !important;
        transition: border 0.2s;
    }
    .modal-body .ios-input:focus, .modal-body .form-control:focus { 
        border-color: var(--accent) !important; 
        box-shadow: 0 0 0 3px rgba(48,200,138,0.2) !important;
    }
    .modal-body .ios-label-sm { font-size: 0.75rem; font-weight: 600; color: var(--ios-label-2); margin-bottom: 6px; display: block; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Email Campaigns</h1>
        <div class="page-subtitle">Design and broadcast marketing emails to your customer base.</div>
    </div>
    <div>
        <button class="quick-btn px-3" style="background: #0055CC; color: #fff; box-shadow: 0 4px 14px rgba(0,122,255,0.3);" onclick="openAddModal()">
            <i class="bi bi-envelope-plus-fill me-1"></i> Create Campaign
        </button>
    </div>
</div>

<?php echo $message; ?>

<div class="ios-alert mb-4" style="background: rgba(0,122,255,0.08); color: #0055CC; border-radius: 16px; padding: 16px 20px;">
    <div class="d-flex align-items-center">
        <i class="bi bi-people-fill fs-3 me-3"></i>
        <div>
            <div style="font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Audience Reach</div>
            <div style="font-size: 1rem; color: #1c1c1e;">You currently have <span class="ios-badge blue fs-6 mx-1"><?php echo $validEmails; ?></span> customers with a valid registered email address. Campaigns will be dispatched exclusively to them.</div>
        </div>
    </div>
</div>

<!-- Tabs Navigation (Segmented Control) -->
<div class="ios-segmented-control mb-4 nav" role="tablist">
    <button class="nav-link active" id="drafts-tab" data-bs-toggle="tab" data-bs-target="#drafts" type="button" role="tab">
        <i class="bi bi-pencil-square" style="color: #FF9500;"></i> Drafts & Templates (<?php echo count($drafts); ?>)
    </button>
    <button class="nav-link" id="sent-tab" data-bs-toggle="tab" data-bs-target="#sent" type="button" role="tab">
        <i class="bi bi-send-check-fill" style="color: #34C759;"></i> Sent History (<?php echo count($sentHistory); ?>)
    </button>
</div>

<!-- Tabs Content -->
<div class="tab-content" id="campaignTabsContent">
    
    <!-- DRAFTS TAB -->
    <div class="tab-pane fade show active" id="drafts" role="tabpanel">
        <div class="dash-card mb-4 overflow-hidden">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(255,149,0,0.1); color: #C07000;">
                        <i class="bi bi-envelope-paper-fill"></i>
                    </span>
                    Draft Campaigns
                </span>
            </div>
            <div class="table-responsive">
                <table class="ios-table align-middle" style="margin: 0;">
                    <thead>
                        <tr class="table-ios-header">
                            <th style="width: 80px; text-align: center;">Image</th>
                            <th style="width: 40%;">Campaign Details</th>
                            <th style="width: 15%; text-align: center;">Status</th>
                            <th style="width: 15%;">Date Created</th>
                            <th class="text-end pe-4" style="width: 25%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($drafts as $c): ?>
                        <tr>
                            <td style="text-align: center;">
                                <?php if($c['image_url']): ?>
                                    <img src="../assets/images/campaigns/<?php echo htmlspecialchars($c['image_url']); ?>" class="rounded border shadow-sm object-fit-cover" style="width: 50px; height: 50px;">
                                <?php else: ?>
                                    <div class="rounded border d-flex align-items-center justify-content-center text-muted" style="width: 50px; height: 50px; background: var(--ios-surface-2); margin: 0 auto;"><i class="bi bi-image fs-5"></i></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);"><?php echo htmlspecialchars($c['subject']); ?></div>
                                <div style="font-size: 0.8rem; color: var(--ios-label-3); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 300px;">
                                    <?php echo htmlspecialchars($c['headline']); ?>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <span class="ios-badge orange"><i class="bi bi-pencil-square"></i> Draft</span>
                            </td>
                            <td>
                                <span style="font-size: 0.85rem; font-weight: 600; color: var(--ios-label-2);"><?php echo date('M d, Y', strtotime($c['created_at'])); ?></span>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-1 flex-wrap">
                                    <button class="quick-btn quick-btn-ghost" style="padding: 6px 10px;" title="Preview" onclick='openPreviewModal(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)'>
                                        <i class="bi bi-eye-fill" style="color: #0055CC;"></i>
                                    </button>
                                    
                                    <button class="quick-btn" style="padding: 6px 12px; background: rgba(52,199,89,0.15); color: #1A9A3A;" onclick="sendCampaign(<?php echo $c['id']; ?>, this)">
                                        <i class="bi bi-send-fill me-1"></i> Send
                                    </button>

                                    <button class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="Edit" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)'>
                                        <i class="bi bi-pencil-square" style="color: #FF9500;"></i>
                                    </button>

                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this draft permanently?');">
                                        <input type="hidden" name="action" value="delete_campaign">
                                        <input type="hidden" name="campaign_id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;"><i class="bi bi-trash3-fill"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($drafts)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="bi bi-envelope-paper" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                                    <p class="mt-2" style="font-weight: 500;">No drafts available.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- SENT HISTORY TAB -->
    <div class="tab-pane fade" id="sent" role="tabpanel">
        <div class="dash-card mb-4 overflow-hidden">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(52,199,89,0.1); color: #34C759;">
                        <i class="bi bi-send-check-fill"></i>
                    </span>
                    Broadcast History
                </span>
            </div>
            <div class="table-responsive">
                <table class="ios-table align-middle" style="margin: 0;">
                    <thead>
                        <tr class="table-ios-header">
                            <th style="width: 80px; text-align: center;">Image</th>
                            <th style="width: 40%;">Campaign Details</th>
                            <th style="width: 25%;">Sent Timestamp</th>
                            <th class="text-end pe-4" style="width: 25%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($sentHistory as $c): ?>
                        <tr>
                            <td style="text-align: center;">
                                <?php if($c['image_url']): ?>
                                    <img src="../assets/images/campaigns/<?php echo htmlspecialchars($c['image_url']); ?>" class="rounded border shadow-sm object-fit-cover" style="width: 50px; height: 50px;">
                                <?php else: ?>
                                    <div class="rounded border d-flex align-items-center justify-content-center text-muted" style="width: 50px; height: 50px; background: var(--ios-surface-2); margin: 0 auto;"><i class="bi bi-image fs-5"></i></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);"><?php echo htmlspecialchars($c['subject']); ?></div>
                                <div style="font-size: 0.8rem; color: var(--ios-label-3); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 300px;">
                                    <?php echo htmlspecialchars($c['headline']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="ios-badge green mb-1"><i class="bi bi-check2-all"></i> Broadcasted</span>
                                <div style="font-size: 0.75rem; font-weight: 600; color: var(--ios-label-2); margin-top: 4px;">
                                    <?php echo date('M d, Y h:i A', strtotime($c['sent_at'])); ?>
                                </div>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-1 flex-wrap">
                                    <button class="quick-btn quick-btn-ghost" style="padding: 6px 10px;" title="View Sent Content" onclick='openPreviewModal(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)'>
                                        <i class="bi bi-eye-fill" style="color: #0055CC;"></i> View
                                    </button>
                                    
                                    <button class="quick-btn" style="padding: 6px 12px; background: rgba(52,199,89,0.15); color: #1A9A3A;" onclick="sendCampaign(<?php echo $c['id']; ?>, this)">
                                        <i class="bi bi-send-fill me-1"></i> Send Again
                                    </button>

                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this campaign from history?');">
                                        <input type="hidden" name="action" value="delete_campaign">
                                        <input type="hidden" name="campaign_id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;"><i class="bi bi-trash3-fill"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($sentHistory)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="bi bi-send-x" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                                    <p class="mt-2" style="font-weight: 500;">No campaigns have been sent yet.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODALS ================= -->

<!-- Add Campaign Modal -->
<div class="modal fade" id="addCampaignModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #0055CC;"><i class="bi bi-envelope-plus-fill me-2"></i>Create New Campaign</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_campaign">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Email Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="ios-input fw-bold" required placeholder="e.g. Huge Discounts on Groceries!">
                    </div>
                    <div class="mb-3">
                        <label class="ios-label-sm">Main Headline (Inside Email) <span class="text-danger">*</span></label>
                        <input type="text" name="headline" class="ios-input" required placeholder="e.g. Save up to 50% this weekend">
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Campaign Image (Banner)</label>
                        <input type="file" name="image" class="ios-input" style="padding: 7px 10px;" accept="image/*">
                        <small class="text-muted d-block mt-2" style="font-size: 0.75rem;"><i class="bi bi-info-circle-fill me-1"></i> Image will be automatically compressed and resized for optimal email delivery.</small>
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Email Description / Body Text</label>
                        <textarea name="description" class="form-control ios-input" rows="6" placeholder="Dear Customer, check out our latest offers..."></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #0055CC; color: #fff;">Save as Draft</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Campaign Modal -->
<div class="modal fade" id="editCampaignModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #C07000;"><i class="bi bi-pencil-square me-2"></i>Edit Draft Campaign</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="edit_campaign">
                    <input type="hidden" name="campaign_id" id="edit_campaign_id">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Email Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" id="edit_subject" class="ios-input fw-bold" required>
                    </div>
                    <div class="mb-3">
                        <label class="ios-label-sm">Main Headline</label>
                        <input type="text" name="headline" id="edit_headline" class="ios-input" required>
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Replace Banner Image (Optional)</label>
                        <input type="file" name="image" class="ios-input" style="padding: 7px 10px;" accept="image/*">
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Email Description / Body Text</label>
                        <textarea name="description" id="edit_description" class="form-control ios-input" rows="6"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #FF9500; color: #fff;">Update Campaign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Preview Modal (Styled as an Email View) -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: #F2F2F7; border: none;">
            <div class="modal-header border-bottom-0 pb-0" style="background: #F2F2F7;">
                <h5 class="modal-title fw-bold" style="color: var(--ios-label-3); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em;"><i class="bi bi-envelope-paper me-1"></i> Email Layout Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 pt-3">
                <div style="background: #FFFFFF; max-width: 600px; margin: 0 auto; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.05); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    <!-- Email Header mimic -->
                    <div style="background: linear-gradient(145deg, #30C88A, #25A872); padding: 30px 20px; text-align: center;">
                        <img src="/images/logo/logo.png" alt="Candent" height="40" style="display: inline-block; filter: brightness(0) invert(1);" onerror="this.src='https://via.placeholder.com/140x32/30C88A/ffffff?text=CANDENT'">
                    </div>
                    
                    <div style="padding: 30px;">
                        <h2 style="margin: 0 0 20px 0; color: #1c1c1e; font-size: 22px; font-weight: 700; letter-spacing: -0.5px; text-align: center;" id="preview_headline">Headline</h2>
                        
                        <div style="text-align: center; margin-bottom: 24px;" id="preview_image_container">
                            <img id="preview_image" src="" style="max-width: 100%; height: auto; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                        </div>
                        
                        <div style="color: #3c3c43; font-size: 16px; line-height: 1.6; white-space: pre-wrap;" id="preview_description">Description</div>
                        
                        <div style="text-align: center; margin: 30px 0;">
                            <span style="background-color: #30C88A; color: #ffffff; padding: 12px 28px; border-radius: 50px; font-weight: 600; display: inline-block; cursor: pointer;">Shop Now</span>
                        </div>
                        
                        <p style="margin: 0; color: #3c3c43; font-size: 15px;">
                            Best Regards,<br>
                            <strong>The Candent Team</strong>
                        </p>
                    </div>

                    <!-- Email Footer mimic -->
                    <div style="background-color: #fafafa; padding: 20px; border-top: 1px solid #eeeeee; text-align: center;">
                        <p style="margin: 0; font-size: 12px; color: #8e8e93;">
                            <strong>Candent</strong><br>
                            79, Dambakanda Estate, Boyagane, Kurunegala.<br>
                            Sent securely by Fintrix Distribution System
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- AJAX Sending Logic -->
<script>
function openAddModal() {
    new bootstrap.Modal(document.getElementById('addCampaignModal')).show();
}

function openEditModal(c) {
    document.getElementById('edit_campaign_id').value = c.id;
    document.getElementById('edit_subject').value = c.subject;
    document.getElementById('edit_headline').value = c.headline;
    document.getElementById('edit_description').value = c.description;
    new bootstrap.Modal(document.getElementById('editCampaignModal')).show();
}

function openPreviewModal(c) {
    document.getElementById('preview_headline').textContent = c.headline;
    document.getElementById('preview_description').innerText = c.description;
    
    if (c.image_url) {
        document.getElementById('preview_image_container').style.display = 'block';
        document.getElementById('preview_image').src = '../assets/images/campaigns/' + c.image_url;
    } else {
        document.getElementById('preview_image_container').style.display = 'none';
    }
    
    new bootstrap.Modal(document.getElementById('previewModal')).show();
}

function sendCampaign(campaignId, btnElement) {
    if(!confirm("Are you absolutely sure you want to blast this email to ALL your registered customers?")) return;
    
    const originalText = btnElement.innerHTML;
    btnElement.disabled = true;
    btnElement.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';

    fetch('../ajax/send_campaign.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `campaign_id=${campaignId}`
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.error);
            btnElement.disabled = false;
            btnElement.innerHTML = originalText;
        }
    })
    .catch(error => {
        alert('Network or server error occurred.');
        btnElement.disabled = false;
        btnElement.innerHTML = originalText;
    });
}
</script>

<?php include '../includes/footer.php'; ?>