<?php
/**
 * API Endpoint: Generate and Send Digital Receipt via Brevo API
 */
require_once '../config/db.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['order_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid Request']);
    exit;
}

$order_id = (int)$_POST['order_id'];

try {
    // 1. Fetch Order and Customer Data
    $stmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name, c.email 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id 
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order || empty($order['email'])) {
        echo json_encode(['success' => false, 'error' => 'Valid customer email not found for this order.']);
        exit;
    }

    // 1.5 Fetch Customer's Total Outstanding Balance
    $outStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount), 0) 
        FROM orders 
        WHERE customer_id = ? AND payment_status != 'paid'
    ");
    $outStmt->execute([$order['customer_id']]);
    $total_outstanding = (float)$outStmt->fetchColumn();

    // 2. Fetch Order Items
    $itemStmt = $pdo->prepare("
        SELECT oi.*, p.name as product_name 
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = ?
    ");
    $itemStmt->execute([$order_id]);
    $items = $itemStmt->fetchAll();

    // 3. Build Dynamic URLs for Invoice and QR Code
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $root_path = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\'); // Gets the base path of the app
    
    $invoice_url = $protocol . "://" . $host . $root_path . "/pages/view_invoice.php?id=" . $order_id;
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($invoice_url);

    // 4. Build HTML Receipt Content (Items)
    $itemsHtml = '';
    foreach($items as $item) {
        $net = ($item['quantity'] * $item['price']) - $item['discount'];
        $itemsHtml .= "
            <tr>
                <td style='padding: 14px 16px; border-bottom: 1px solid #E5E5EA; color: #1C1C1E; font-size: 14px; font-weight: 600;'>{$item['product_name']}</td>
                <td style='padding: 14px 16px; border-bottom: 1px solid #E5E5EA; text-align: center; color: #3C3C43; font-size: 14px;'>{$item['quantity']}</td>
                <td style='padding: 14px 16px; border-bottom: 1px solid #E5E5EA; text-align: right; color: #1C1C1E; font-size: 14px; font-weight: 700;'>Rs " . number_format($net, 2) . "</td>
            </tr>
        ";
    }

    $discountHtml = '';
    if ($order['discount_amount'] > 0) {
        $discountHtml = "
            <tr>
                <td colspan='2' style='padding: 12px 16px 4px; text-align: right; color: #CC2200; font-size: 14px; font-weight: 600;'>Bill Discount:</td>
                <td style='padding: 12px 16px 4px; text-align: right; color: #CC2200; font-size: 14px; font-weight: 700;'>- Rs " . number_format($order['discount_amount'], 2) . "</td>
            </tr>
        ";
    }

    // Build Payment Breakdown HTML
    $paymentBreakdownHtml = '';
    if ($order['paid_cash'] > 0) {
        $paymentBreakdownHtml .= "<tr><td colspan='2' style='padding: 6px 16px; text-align: right; color: #8E8E93; font-size: 13px; font-weight: 500;'>Paid via Cash:</td><td style='padding: 6px 16px; text-align: right; color: #3C3C43; font-size: 13px; font-weight: 600;'>Rs " . number_format($order['paid_cash'], 2) . "</td></tr>";
    }
    if ($order['paid_bank'] > 0) {
        $paymentBreakdownHtml .= "<tr><td colspan='2' style='padding: 6px 16px; text-align: right; color: #8E8E93; font-size: 13px; font-weight: 500;'>Paid via Bank Transfer:</td><td style='padding: 6px 16px; text-align: right; color: #3C3C43; font-size: 13px; font-weight: 600;'>Rs " . number_format($order['paid_bank'], 2) . "</td></tr>";
    }
    if ($order['paid_cheque'] > 0) {
        $paymentBreakdownHtml .= "<tr><td colspan='2' style='padding: 6px 16px; text-align: right; color: #8E8E93; font-size: 13px; font-weight: 500;'>Paid via Cheque:</td><td style='padding: 6px 16px; text-align: right; color: #3C3C43; font-size: 13px; font-weight: 600;'>Rs " . number_format($order['paid_cheque'], 2) . "</td></tr>";
    }

    $balance = $order['total_amount'] - $order['paid_amount'];
    $balanceHtml = '';
    if ($balance > 0) {
        $balanceHtml = "
            <tr>
                <td colspan='2' style='padding: 16px 16px; text-align: right; font-weight: 700; color: #CC2200; font-size: 15px;'>Remaining Invoice Balance:</td>
                <td style='padding: 16px 16px; text-align: right; font-weight: 800; color: #CC2200; font-size: 16px;'>Rs " . number_format($balance, 2) . "</td>
            </tr>
        ";
    } elseif ($balance < 0) {
        $balanceHtml = "
            <tr>
                <td colspan='2' style='padding: 16px 16px; text-align: right; font-weight: 700; color: #1A9A3A; font-size: 15px;'>Change Due:</td>
                <td style='padding: 16px 16px; text-align: right; font-weight: 800; color: #1A9A3A; font-size: 16px;'>Rs " . number_format(abs($balance), 2) . "</td>
            </tr>
        ";
    }

    // Build Total Account Outstanding HTML
    $outstandingHtml = '';
    if ($total_outstanding > 0) {
        $outstandingHtml = "
            <tr>
                <td style='padding-top: 12px; border-top: 1px solid #E5E5EA; color: #CC2200; font-size: 13px; font-weight: 600;'>Total Account Outstanding:</td>
                <td style='padding-top: 12px; border-top: 1px solid #E5E5EA; text-align: right; color: #CC2200; font-size: 14px; font-weight: 800;'>Rs " . number_format($total_outstanding, 2) . "</td>
            </tr>
        ";
    }

    $htmlBody = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Candent Receipt</title>
    </head>
    <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #F2F2F7; -webkit-font-smoothing: antialiased;'>
        <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #F2F2F7; padding: 40px 20px;'>
            <tr>
                <td align='center'>
                    
                    <!-- Main Email Card -->
                    <table width='100%' max-width='600' border='0' cellspacing='0' cellpadding='0' style='background-color: #FFFFFF; border-radius: 20px; box-shadow: 0 8px 24px rgba(0,0,0,0.04); overflow: hidden; max-width: 600px; border: 1px solid #E5E5EA;'>
                        
                        <!-- Top Accent Line -->
                        <tr>
                            <td height='4' style='background: linear-gradient(90deg, #30C88A, #007AFF); font-size: 0; line-height: 0;'>&nbsp;</td>
                        </tr>

                        <!-- Premium Dark Header -->
                        <tr>
                            <td align='center' style='padding: 0; background-color: #1C1C1E; border-bottom: 1px solid #1C1C1E;'>
                                <img src='https://candent.suzxlabs.com/images/logo/croped-white-logo.png' alt='Candent' style='display: block; border: 0; width: 100%; height: auto;' onerror=\"this.src='https://via.placeholder.com/600x150/1c1c1e/ffffff?text=CANDENT'\">
                            </td>
                        </tr>

                        <!-- Body Content -->
                        <tr>
                            <td style='padding: 40px 32px; color: #1C1C1E; font-size: 16px; line-height: 1.6;'>
                                
                                <h2 style='margin: 0 0 16px; color: #1C1C1E; font-size: 22px; font-weight: 800; letter-spacing: -0.5px; text-align: center;'>Digital Purchase Receipt</h2>
                                
                                <!-- Meta Details -->
                                <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #F8F8F9; border-radius: 12px; margin-bottom: 32px; border: 1px solid #E5E5EA;'>
                                    <tr>
                                        <td style='padding: 20px;'>
                                            <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                                <tr>
                                                    <td style='padding-bottom: 8px; color: #8E8E93; font-size: 14px; font-weight: 500;'>Invoice #:</td>
                                                    <td style='padding-bottom: 8px; text-align: right; color: #1C1C1E; font-size: 14px; font-weight: 700;'>" . str_pad($order['id'], 6, '0', STR_PAD_LEFT) . "</td>
                                                </tr>
                                                <tr>
                                                    <td style='padding-bottom: 8px; color: #8E8E93; font-size: 14px; font-weight: 500;'>Date:</td>
                                                    <td style='padding-bottom: 8px; text-align: right; color: #1C1C1E; font-size: 14px; font-weight: 600;'>" . date('M d, Y h:i A', strtotime($order['created_at'])) . "</td>
                                                </tr>
                                                <tr>
                                                    <td style='padding-bottom: 8px; color: #8E8E93; font-size: 14px; font-weight: 500;'>Billed To:</td>
                                                    <td style='padding-bottom: 8px; text-align: right; color: #1C1C1E; font-size: 14px; font-weight: 700;'>" . htmlspecialchars($order['customer_name']) . "</td>
                                                </tr>
                                                {$outstandingHtml}
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                <!-- Items Table -->
                                <table width='100%' border='0' cellspacing='0' cellpadding='0' style='margin-bottom: 24px; border: 1px solid #E5E5EA; border-radius: 12px; overflow: hidden;'>
                                    <thead>
                                        <tr>
                                            <th style='background-color: #F8F8F9; color: #8E8E93; padding: 12px 16px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; border-bottom: 1px solid #E5E5EA;'>Item Description</th>
                                            <th style='background-color: #F8F8F9; color: #8E8E93; padding: 12px 16px; text-align: center; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; border-bottom: 1px solid #E5E5EA;'>Qty</th>
                                            <th style='background-color: #F8F8F9; color: #8E8E93; padding: 12px 16px; text-align: right; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; border-bottom: 1px solid #E5E5EA;'>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {$itemsHtml}
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan='2' style='padding: 16px 16px 4px; text-align: right; font-weight: 600; color: #3C3C43; font-size: 14px;'>Sub Total:</td>
                                            <td style='padding: 16px 16px 4px; text-align: right; font-weight: 700; color: #1C1C1E; font-size: 14px;'>Rs " . number_format($order['subtotal'], 2) . "</td>
                                        </tr>
                                        {$discountHtml}
                                        <tr>
                                            <td colspan='2' style='padding: 12px 16px; text-align: right; font-weight: 800; font-size: 16px; color: #1C1C1E; border-bottom: 1px solid #E5E5EA;'>Net Bill Amount:</td>
                                            <td style='padding: 12px 16px; text-align: right; font-weight: 800; font-size: 18px; color: #25A872; border-bottom: 1px solid #E5E5EA;'>Rs " . number_format($order['total_amount'], 2) . "</td>
                                        </tr>
                                        <!-- Payment Breakdown -->
                                        <tr><td colspan='3' style='padding-top: 12px;'></td></tr>
                                        {$paymentBreakdownHtml}
                                        <tr>
                                            <td colspan='2' style='padding: 8px 16px 16px; text-align: right; font-weight: 700; color: #1A9A3A; font-size: 14px;'>Total Paid:</td>
                                            <td style='padding: 8px 16px 16px; text-align: right; font-weight: 800; color: #1A9A3A; font-size: 15px;'>Rs " . number_format($order['paid_amount'], 2) . "</td>
                                        </tr>
                                        {$balanceHtml}
                                    </tfoot>
                                </table>
                                
                                <!-- QR Code & Action -->
                                <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #F8F8F9; border-radius: 16px; padding: 32px 20px; text-align: center; border: 1px solid #E5E5EA;'>
                                    <tr>
                                        <td align='center'>
                                            <p style='color: #3C3C43; font-size: 15px; margin: 0 0 16px; font-weight: 600;'>Scan to view or download your official PDF invoice:</p>
                                            <img src='{$qr_url}' alt='Invoice QR Code' style='width: 120px; height: 120px; border: 1px solid #E5E5EA; border-radius: 12px; padding: 10px; background: #fff; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);'>
                                            <br>
                                            <a href='{$invoice_url}' style='background-color: #007AFF; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 50px; font-weight: 700; font-size: 15px; display: inline-block; box-shadow: 0 4px 14px rgba(0,122,255,0.25);'>View Full Invoice Online</a>
                                        </td>
                                    </tr>
                                </table>

                                <p style='margin: 32px 0 0; color: #3C3C43; font-size: 15px; text-align: center;'>
                                    Thank you for your business!<br>
                                    <strong style='color: #1C1C1E; display: block; margin-top: 8px;'>The Candent Team</strong>
                                </p>
                            </td>
                        </tr>

                        <!-- Footer Details -->
                        <tr>
                            <td align='center' style='background-color: #F8F8F9; padding: 30px; border-top: 1px solid #E5E5EA;'>
                                <p style='margin: 0 0 12px; font-size: 13px; color: #8E8E93; line-height: 1.5;'>
                                    <strong style='color: #3C3C43;'>Candent</strong><br>
                                    79, Dambakanda Estate, Boyagane, Kurunegala.<br>
                                    WhatsApp/Tel: 076 140 7876 | Email: candentlk@gmail.com
                                </p>
                                
                                <!-- Developer Credit -->
                                <p style='margin: 0; font-size: 11px; color: #AEAEB2;'>
                                    System Developed by <a href='https://suzxlabs.com' style='color: #8E8E93; text-decoration: none; font-weight: 600;'>Suzxlabs</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>
    </body>
    </html>
    ";

    // 5. Dispatch via Brevo API
    $brevo_api_key = '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';
    $sender_email = 'suz.xlabs@gmail.com';
    $sender_name = 'Fintrix Distributions';

    $payload = [
        "sender" => ["name" => $sender_name, "email" => $sender_email],
        "to" => [["email" => $order['email'], "name" => $order['customer_name']]],
        "subject" => "Your Receipt (Invoice #" . str_pad($order['id'], 6, '0', STR_PAD_LEFT) . ") - Candent",
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
    
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode >= 200 && $httpcode < 300) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to send email. Provider returned ' . $httpcode]);
    }

} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>