<?php
/**
 * API Endpoint: Emails a Purchase Order directly to the supplier using Brevo.
 * Generates Server-Side PDF using DomPDF.
 */
require_once '../config/db.php';
session_start();
header('Content-Type: application/json');

// --- 1. CRITICAL SAFETY CHECK FOR DOMPDF ---
if (!file_exists('../vendor/autoload.php')) {
    echo json_encode(['success' => false, 'error' => 'CRITICAL ERROR: DomPDF library is missing! You must run "composer require dompdf/dompdf" on your server.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['po_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid Request']);
    exit;
}

$po_id = (int)$_POST['po_id'];

try {
    // 2. Fetch PO and Supplier Data
    $stmt = $pdo->prepare("
        SELECT p.*, s.company_name, s.email, s.name as contact_person, s.phone, s.address
        FROM purchase_orders p 
        LEFT JOIN suppliers s ON p.supplier_id = s.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch();

    if (!$po || empty($po['email'])) {
        echo json_encode(['success' => false, 'error' => 'Supplier email not found for this PO.']);
        exit;
    }

    // 3. Fetch PO Items
    $itemStmt = $pdo->prepare("
        SELECT pi.*, pr.name as product_name, pr.sku 
        FROM purchase_order_items pi 
        JOIN products pr ON pi.product_id = pr.id 
        WHERE pi.po_id = ?
    ");
    $itemStmt->execute([$po_id]);
    $items = $itemStmt->fetchAll();



    // Generate Dynamic URLs
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $root_path = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
    $po_url = $protocol . "://" . $host . $root_path . "/pages/view_po.php?id=" . $po_id;
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($po_url);

    // --- FIX: Securely Fetch QR Code and Logo as Base64 for DomPDF ---
    // This prevents "Image not found" errors in DomPDF caused by server SSL/firewall blocks.
    
    // QR Code
    $ch_qr = curl_init($qr_url);
    curl_setopt($ch_qr, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_qr, CURLOPT_SSL_VERIFYPEER, false);
    $qr_image_data = curl_exec($ch_qr);
    curl_close($ch_qr);
    $qr_base64 = $qr_image_data ? 'data:image/png;base64,' . base64_encode($qr_image_data) : $qr_url;

    // Logo
    $logo_url = 'https://candent.suzxlabs.com/images/logo/croped-white-logo.png';
    $ch_logo = curl_init($logo_url);
    curl_setopt($ch_logo, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_logo, CURLOPT_SSL_VERIFYPEER, false);
    $logo_image_data = curl_exec($ch_logo);
    curl_close($ch_logo);
    $logo_base64 = $logo_image_data ? 'data:image/png;base64,' . base64_encode($logo_image_data) : $logo_url;
    // --------------------------------------------------------

    // Safely format expected date
    $expected_date_display = (!empty($po['expected_date']) && $po['expected_date'] !== '0000-00-00' && $po['expected_date'] !== '1970-01-01') 
                             ? date('M d, Y', strtotime($po['expected_date'])) 
                             : 'ASAP';

    // 4. Build HTML Content for Email & PDF
    $emailItemsHtml = '';
    $pdfItemsHtml = '';

    foreach($items as $item) {
        $net = $item['quantity'] * $item['unit_price'];
        
        // For Email
        $emailItemsHtml .= "
            <tr>
                <td style='padding: 14px 16px; border-bottom: 1px solid #E5E5EA; color: #1C1C1E; font-size: 14px; font-weight: 600;'>{$item['product_name']}</td>
                <td style='padding: 14px 16px; border-bottom: 1px solid #E5E5EA; text-align: center; color: #3C3C43; font-size: 14px;'>{$item['quantity']}</td>
                <td style='padding: 14px 16px; border-bottom: 1px solid #E5E5EA; text-align: right; color: #3C3C43; font-size: 14px;'>Rs " . number_format($item['unit_price'], 2) . "</td>
                <td style='padding: 14px 16px; border-bottom: 1px solid #E5E5EA; text-align: right; color: #1C1C1E; font-size: 14px; font-weight: 700;'>Rs " . number_format($net, 2) . "</td>
            </tr>
        ";

        // For PDF
        $pdfItemsHtml .= "
            <tr>
                <td>
                    <strong style='color: #1A1A1A;'>" . htmlspecialchars($item['product_name']) . "</strong><br>
                    <span style='font-size: 11px; color: #555;'>SKU: " . htmlspecialchars($item['sku'] ?: 'N/A') . "</span>
                </td>
                <td class='text-center' style='font-weight: bold;'>{$item['quantity']}</td>
                <td class='text-right' style='color: #555;'>Rs " . number_format($item['unit_price'], 2) . "</td>
                <td class='text-right' style='font-weight: bold; color: #1A1A1A;'>Rs " . number_format($net, 2) . "</td>
            </tr>
        ";
    }

    // Build Email Totals Footer
    $emailTotalsHtml = "
        <tr>
            <td colspan='3' style='padding: 16px 16px 8px 16px; text-align: right; font-weight: 600; font-size: 14px; color: #3C3C43;'>Gross Subtotal:</td>
            <td style='padding: 16px 16px 8px 16px; text-align: right; font-weight: 600; font-size: 14px; color: #1C1C1E;'>Rs " . number_format($po['subtotal'], 2) . "</td>
        </tr>
    ";



    $emailTotalsHtml .= "
        <tr>
            <td colspan='3' style='padding: 16px 16px; text-align: right; font-weight: 800; font-size: 16px; color: #1C1C1E; border-top: 1px solid #E5E5EA;'>Net Payable:</td>
            <td style='padding: 16px 16px; text-align: right; font-weight: 800; font-size: 18px; color: #0055CC; border-top: 1px solid #E5E5EA;'>Rs " . number_format($po['total_amount'], 2) . "</td>
        </tr>
    ";

    $notes = nl2br(htmlspecialchars($po['notes'] ?: 'Please confirm receipt of this Purchase Order and your expected delivery date.'));

    // ================= EMAIL HTML DESIGN =================
    $htmlBody = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Purchase Order</title>
    </head>
    <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #F2F2F7; -webkit-font-smoothing: antialiased;'>
        <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #F2F2F7; padding: 40px 20px;'>
            <tr>
                <td align='center'>
                    <table width='100%' max-width='600' border='0' cellspacing='0' cellpadding='0' style='background-color: #FFFFFF; border-radius: 20px; box-shadow: 0 8px 24px rgba(0,0,0,0.04); overflow: hidden; max-width: 600px; border: 1px solid #E5E5EA;'>
                        
                        <!-- Top Accent Line -->
                        <tr>
                            <td height='4' style='background: linear-gradient(90deg, #30C88A, #007AFF); font-size: 0; line-height: 0;'>&nbsp;</td>
                        </tr>

                        <!-- Premium Dark Header -->
                        <tr>
                            <td align='center' style='padding: 0; background-color: #1C1C1E; border-bottom: 1px solid #1C1C1E;'>
                                <img src='https://candent.suzxlabs.com/images/logo/croped-white-logo.png' alt='Candent' style='display: block; border: 0; width: 100%; max-width: 600px; height: auto;' onerror=\"this.onerror=null; this.src='https://via.placeholder.com/600x150/1c1c1e/ffffff?text=CANDENT'\">
                            </td>
                        </tr>

                        <!-- Body Content -->
                        <tr>
                            <td style='padding: 40px 32px; color: #1C1C1E; font-size: 16px; line-height: 1.6;'>
                                <h2 style='margin: 0 0 16px; color: #1C1C1E; font-size: 22px; font-weight: 800; letter-spacing: -0.5px; text-align: center;'>New Purchase Order</h2>
                                <p style='margin: 0 0 24px; color: #3C3C43; font-size: 16px;'>Hello <strong style='color: #1C1C1E;'>" . htmlspecialchars($po['company_name']) . "</strong>,</p>
                                <p style='margin: 0 0 32px; color: #3C3C43; font-size: 16px;'>Please find the details of our new Purchase Order below. An official PDF document is also attached to this email. We expect delivery by <strong style='color: #1C1C1E;'>{$expected_date_display}</strong>.</p>
                                
                                <!-- Meta Details -->
                                <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #F8F8F9; border-radius: 12px; margin-bottom: 32px; border: 1px solid #E5E5EA;'>
                                    <tr>
                                        <td style='padding: 20px;'>
                                            <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                                <tr>
                                                    <td style='padding-bottom: 8px; color: #8E8E93; font-size: 14px; font-weight: 500;'>PO Number:</td>
                                                    <td style='padding-bottom: 8px; text-align: right; color: #0055CC; font-size: 14px; font-weight: 700;'>PO-" . str_pad($po['id'], 6, '0', STR_PAD_LEFT) . "</td>
                                                </tr>
                                                <tr>
                                                    <td style='color: #8E8E93; font-size: 14px; font-weight: 500;'>Order Date:</td>
                                                    <td style='text-align: right; color: #1C1C1E; font-size: 14px; font-weight: 600;'>" . date('M d, Y', strtotime($po['po_date'])) . "</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                <!-- Items Table -->
                                <table width='100%' border='0' cellspacing='0' cellpadding='0' style='margin-bottom: 24px; border: 1px solid #E5E5EA; border-radius: 12px; overflow: hidden;'>
                                    <thead>
                                        <tr>
                                            <th style='background-color: #F8F8F9; color: #8E8E93; padding: 12px 16px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; border-bottom: 1px solid #E5E5EA;'>Description</th>
                                            <th style='background-color: #F8F8F9; color: #8E8E93; padding: 12px 16px; text-align: center; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; border-bottom: 1px solid #E5E5EA;'>Qty</th>
                                            <th style='background-color: #F8F8F9; color: #8E8E93; padding: 12px 16px; text-align: right; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; border-bottom: 1px solid #E5E5EA;'>Rate</th>
                                            <th style='background-color: #F8F8F9; color: #8E8E93; padding: 12px 16px; text-align: right; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; border-bottom: 1px solid #E5E5EA;'>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {$emailItemsHtml}
                                    </tbody>
                                    <tfoot>
                                        {$emailTotalsHtml}
                                    </tfoot>
                                </table>

                                <!-- QR Code & Action -->
                                <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #F8F8F9; border-radius: 16px; padding: 32px 20px; text-align: center; border: 1px solid #E5E5EA;'>
                                    <tr>
                                        <td align='center'>
                                            <p style='color: #3C3C43; font-size: 15px; margin: 0 0 16px; font-weight: 600;'>Scan to view the live online document:</p>
                                            <img src='{$qr_url}' alt='PO QR Code' style='width: 120px; height: 120px; border: 1px solid #E5E5EA; border-radius: 12px; padding: 10px; background: #fff; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);'>
                                            <br>
                                            <a href='{$po_url}' style='background-color: #007AFF; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 50px; font-weight: 700; font-size: 15px; display: inline-block; box-shadow: 0 4px 14px rgba(0,122,255,0.25);'>View Live PO Online</a>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p style='margin: 32px 0 0; color: #3C3C43; font-size: 15px; text-align: center;'>
                                    Best Regards,<br>
                                    <strong style='color: #1C1C1E; display: block; margin-top: 8px;'>Candent Purchasing Team</strong>
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
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    "; 

    // ================= 5. SERVER-SIDE PDF GENERATION (DomPDF) =================
    require_once '../vendor/autoload.php';
    
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->set_option('isHtml5ParserEnabled', true);
    $dompdf->set_option('isRemoteEnabled', true);
    // Use Helvetica as standard for pristine professional generation
    $dompdf->set_option('defaultFont', 'Helvetica');

    // Build PDF HTML Structure based tightly on the clean corporate view_po.php styles
    $pdfHtml = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 13px; color: #1A1A1A; margin: 0; padding: 20px; }
            table { width: 100%; border-collapse: collapse; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            
            .meta-table td { padding: 4px 0; font-size: 13px; color: #555; }
            .meta-table td.lbl { width: 100px; }
            .meta-table td.val { font-weight: bold; text-align: right; color: #1A1A1A; }
            
            .invoice-table { margin-top: 30px; margin-bottom: 30px; }
            .invoice-table th { background-color: #F8F9FA; border-top: 1px solid #1A1A1A; border-bottom: 1px solid #1A1A1A; padding: 12px 10px; font-size: 11px; text-transform: uppercase; color: #1A1A1A; text-align: left; }
            .invoice-table td { padding: 14px 10px; border-bottom: 1px solid #E0E0E0; vertical-align: top; }
            
            .totals-table { width: 320px; float: right; border-collapse: collapse; }
            .totals-table td { padding: 8px 0; font-size: 13px; }
            .totals-table td.lbl { text-align: right; padding-right: 20px; color: #555; }
            .totals-table td.val { text-align: right; font-weight: bold; width: 100px; color: #1A1A1A; }
            .totals-table tr.grand-total td { border-top: 2px solid #1A1A1A; border-bottom: 2px solid #E0E0E0; font-size: 16px; padding: 12px 0; font-weight: bold; color: #1A1A1A; }
            .totals-table tr.claims td { color: #CC2200; }
        </style>
    </head>
    <body>
        <table style='margin-bottom: 30px;'>
            <tr>
                <td style='vertical-align: top; width: 50%;'>
                    <img src='{$logo_base64}' height='65' alt='Candent Logo' style='display: block; margin-bottom: 20px;'>
                    <div style='color: #555; line-height: 1.5;'>
                        <strong style='color: #1A1A1A; font-size: 16px;'>Candent</strong><br>
                        79, Dambakanda Estate, Boyagane,<br>
                        Kurunegala, Sri Lanka.<br>
                        Tel: 076 140 7876<br>
                        Email: candentlk@gmail.com
                    </div>
                </td>
                <td style='vertical-align: top; text-align: right; width: 50%;'>
                    <h1 style='margin: 0 0 15px 0; font-size: 28px; font-weight: 300; letter-spacing: 1px; color: #1A1A1A;'>PURCHASE ORDER</h1>
                    <table class='meta-table' style='width: 250px; float: right;'>
                        <tr><td class='lbl'>PO Number:</td><td class='val'>PO-" . str_pad($po['id'], 6, '0', STR_PAD_LEFT) . "</td></tr>
                        <tr><td class='lbl'>Order Date:</td><td class='val'>" . date('M d, Y', strtotime($po['po_date'])) . "</td></tr>
                        <tr><td class='lbl'>Expected By:</td><td class='val'>" . $expected_date_display . "</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <div style='border-top: 2px solid #1A1A1A; margin-bottom: 25px;'></div>

        <table style='margin-bottom: 20px;'>
            <tr>
                <td style='vertical-align: top; width: 50%;'>
                    <div style='font-size: 11px; text-transform: uppercase; color: #555; font-weight: bold; margin-bottom: 5px; letter-spacing: 1px;'>Order Issued To</div>
                    <strong style='font-size: 16px; color: #1A1A1A; display: block; margin-bottom: 4px;'>" . htmlspecialchars($po['company_name']) . "</strong>
                    <div style='color: #1A1A1A; line-height: 1.5;'>
                        Attn: " . htmlspecialchars($po['contact_person'] ?: 'Sales Dept') . "<br>
                        " . nl2br(htmlspecialchars($po['address'])) . "<br>
                        Tel: " . htmlspecialchars($po['phone']) . "
                    </div>
                </td>
            </tr>
        </table>

        <table class='invoice-table'>
            <thead>
                <tr>
                    <th style='width: 50%; text-align: left;'>Product Description</th>
                    <th class='text-center' style='width: 15%;'>Order Qty</th>
                    <th class='text-right' style='width: 15%;'>Unit Cost (Rs)</th>
                    <th class='text-right' style='width: 20%;'>Line Total (Rs)</th>
                </tr>
            </thead>
            <tbody>
                {$pdfItemsHtml}
            </tbody>
        </table>

        <div>
            <div style='float: left; width: 40%;'>
                <div style='font-size: 11px; color: #555; font-weight: bold; text-transform: uppercase; margin-bottom: 5px;'>Authorized By</div>
                <div style='border-top: 1px solid #E0E0E0; width: 220px; margin-top: 40px; padding-top: 8px; text-align: center; color: #555; font-size: 12px;'>
                    System Generated Signature<br>
                    <strong style='color: #1A1A1A;'>Candent</strong>
                </div>
            </div>
            
            <div style='float: right; width: 55%;'>
                <table class='totals-table'>
                    <tr>
                        <td class='lbl'>Gross Subtotal:</td>
                        <td class='val'>Rs " . number_format($po['subtotal'], 2) . "</td>
                    </tr>";



    $pdfHtml .= "
                    <tr class='grand-total'>
                        <td class='lbl'>Net Payable:</td>
                        <td class='val'>Rs " . number_format($po['total_amount'], 2) . "</td>
                    </tr>
                </table>
            </div>
            <div style='clear: both;'></div>
        </div>
        
        <table style='width: 100%; margin-top: 50px; font-size: 12px; color: #777;'>
            <tr>
                <td style='vertical-align: top; border: none; padding: 0;'>
                    <strong>Notes / Instructions:</strong><br>{$notes}<br><br>
                    Authorized Purchase Order<br>
                    System Developed & Maintained by <a href='https://suzxlabs.com' style='color: #555; text-decoration: none; font-weight: bold;'>Suzxlabs</a>
                </td>
                <td style='vertical-align: top; text-align: center; border: none; padding: 0; width: 160px;'>
                    <!-- PDF uses the Base64 Embedded Image -->
                    <img src='{$qr_base64}' alt='QR Code' style='width: 110px; height: 110px; border: 1px solid #ddd; padding: 5px; border-radius: 5px; background: #fff;'><br>
                    <div style='margin-top: 8px; font-weight: bold; color: #333;'>Scan to view live online</div>
                    <a href='{$po_url}' style='color: #0055CC; text-decoration: none; word-break: break-all;'>{$po_url}</a>
                </td>
            </tr>
        </table>
    </body>
    </html>";

    $dompdf->loadHtml($pdfHtml);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $pdfBase64 = base64_encode($dompdf->output());
    $pdfName = 'Purchase_Order_' . str_pad($po['id'], 6, '0', STR_PAD_LEFT) . '.pdf';

    // 6. Dispatch via Brevo API
    $brevo_api_key = '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';
    $sender_email = 'suz.xlabs@gmail.com'; 
    $sender_name = 'Candent Purchasing';

    $payload = [
        "sender" => ["name" => $sender_name, "email" => $sender_email],
        "to" => [["email" => $po['email'], "name" => $po['company_name']]],
        "subject" => "New Purchase Order (PO-" . str_pad($po['id'], 6, '0', STR_PAD_LEFT) . ") - Candent",
        "htmlContent" => $htmlBody,
        "attachment" => [
            [
                "name" => $pdfName,
                "content" => $pdfBase64
            ]
        ]
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
        $pdo->prepare("UPDATE purchase_orders SET status = 'sent' WHERE id = ?")->execute([$po_id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to send email. HTTP ' . $httpcode]);
    }

} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>