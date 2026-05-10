<?php
/**
 * API Endpoint: Brevo Email Campaign Delivery Engine
 * Distributes a specific campaign to all valid customer emails.
 */
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'supervisor'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['campaign_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid Request']);
    exit;
}

$campaign_id = (int)$_POST['campaign_id'];

// ==============================================================================
// 🛑 IMPORTANT CONFIGURATION REQUIRED HERE 🛑
// 1. Get your free API key from https://app.brevo.com/settings/keys/api
// 2. Replace the placeholder below.
// 3. Make sure the Sender Email is a verified sender in your Brevo account.
// ==============================================================================
$brevo_api_key = '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';
$sender_email = 'suz.xlabs@gmail.com'; // Your Verified Sender Email
$sender_name = 'Candent';
// ==============================================================================

try {
    // 1. Fetch Campaign Details
    $stmt = $pdo->prepare("SELECT * FROM email_campaigns WHERE id = ?");
    $stmt->execute([$campaign_id]);
    $campaign = $stmt->fetch();

    if (!$campaign) {
        echo json_encode(['success' => false, 'error' => 'Campaign not found.']);
        exit;
    }

    // 2. Fetch Customer Emails
    $custStmt = $pdo->query("SELECT name, email FROM customers WHERE email IS NOT NULL AND email != ''");
    $customers = $custStmt->fetchAll();

    if (empty($customers)) {
        echo json_encode(['success' => false, 'error' => 'No customers found with valid email addresses.']);
        exit;
    }

    // 3. Construct HTML Template with Dynamic Paths & Premium Branding
    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
    $root_path = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
    
    $image_html = '';
    if (!empty($campaign['image_url'])) {
        $absolute_image_url = $base_url . $root_path . "/assets/images/campaigns/" . $campaign['image_url'];
        $image_html = "<div style='text-align: center; margin-bottom: 30px;'><img src='{$absolute_image_url}' style='max-width: 100%; height: auto; border-radius: 8px; border: 1px solid #E0E0E0;' alt='Campaign Image' /></div>";
    }

    $current_year = date('Y');
    $website_url = "https://candent.suzxlabs.com/";
    
    $headline = htmlspecialchars($campaign['headline']);
    $description = nl2br(htmlspecialchars($campaign['description']));

    // Modern Candent Email Template
    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1'>
    </head>
    <body style='margin: 0; padding: 0; background-color: #F4F6F9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
        <div style='background-color: #F4F6F9; padding: 40px 15px;'>
            <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #E0E0E0;'>
                
                <!-- Premium Edge-to-Edge Logo Header -->
                <img src='https://candent.suzxlabs.com/images/logo/croped-white-logo.png' style='width: 100%; display: block; margin: 0; padding: 0; border: none;' alt='Candent Logo'>
                
                <!-- Main Content Body -->
                <div style='padding: 40px 30px;'>
                    <h1 style='color: #1A1A1A; margin: 0 0 25px 0; font-size: 26px; text-align: center; font-weight: 800;'>
                        {$headline}
                    </h1>
                    
                    {$image_html}
                    
                    <div style='color: #555555; font-size: 16px; line-height: 1.6; text-align: left;'>
                        {$description}
                    </div>
                    
                    <!-- Call to Action Button -->
                    <div style='text-align: center; margin-top: 40px; margin-bottom: 10px;'>
                        <a href='{$website_url}' style='background-color: #0055CC; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;'>
                            Visit Our Website
                        </a>
                    </div>
                </div>
                
                <!-- Professional Footer -->
                <div style='background-color: #F8F9FA; padding: 30px; text-align: center; border-top: 1px solid #E0E0E0;'>
                    <p style='color: #1A1A1A; font-size: 14px; font-weight: 700; margin: 0 0 5px 0;'>Candent</p>
                    <p style='color: #777777; font-size: 13px; margin: 0 0 15px 0; line-height: 1.5;'>
                        79, Dambakanda Estate, Boyagane,<br>
                        Kurunegala, Sri Lanka.<br>
                        Tel: 076 140 7876 | Email: candentlk@gmail.com
                    </p>
                    <p style='color: #555555; font-size: 12px; margin: 0 0 10px 0; line-height: 1.5;'>
                        You are receiving this email because you are a valued customer of <strong>Candent</strong>.
                    </p>
                    <p style='color: #999999; font-size: 11px; margin: 0; line-height: 1.5;'>
                        &copy; {$current_year} Candent. All rights reserved.<br>
                        System Developed & Maintained by <a href='https://suzxlabs.com' style='color: #999999; text-decoration: underline;'>Suzxlabs</a>
                    </p>
                </div>
                
            </div>
        </div>
    </body>
    </html>
    ";

    // 4. Send Emails via Brevo API (Individual loop to avoid massive CC arrays and guarantee delivery)
    $success_count = 0;
    $fail_count = 0;

    foreach ($customers as $c) {
        $payload = [
            "sender" => ["name" => $sender_name, "email" => $sender_email],
            "to" => [["email" => $c['email'], "name" => $c['name']]],
            "subject" => $campaign['subject'],
            "htmlContent" => $htmlBody
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.brevo.com/v3/smtp/email");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $headers = [
            'accept: application/json',
            'api-key: ' . $brevo_api_key,
            'content-type: application/json'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Execute request
        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode >= 200 && $httpcode < 300) {
            $success_count++;
        } else {
            $fail_count++;
            error_log("Brevo API Error for {$c['email']}: HTTP $httpcode - $result");
        }
    }

    // 5. Update Campaign Status
    $pdo->prepare("UPDATE email_campaigns SET status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$campaign_id]);

    echo json_encode([
        'success' => true, 
        'message' => "Campaign successfully dispatched! Sent to $success_count customers ($fail_count failed)."
    ]);

} catch (Exception $e) {
    error_log("Campaign Sending Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>