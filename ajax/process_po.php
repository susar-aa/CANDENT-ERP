<?php
/**
 * API Endpoint: Processes Purchase Orders (PO).
 * Upgraded to support automated Server-Side PDF generation and Email Delivery.
 */
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['cart'])) {
    echo json_encode(['success' => false, 'message' => 'Cart is empty.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $supplier_id = !empty($input['supplier_id']) ? (int)$input['supplier_id'] : null;
    $po_number = trim($input['po_number']);
    $expected_date = $input['expected_date'];
    $notes = trim($input['notes']);
    $created_by = $_SESSION['user_id'];
    
    // Deductions extraction
    $discount_amount = (float)($input['discount_amount'] ?? 0);
    $tax_amount = (float)($input['tax_amount'] ?? 0);


    $subtotal = 0;

    // 1. Calculate totals robustly handling all possible cart keys
    foreach ($input['cart'] as $item) {
        $cost = (float)($item['unit_cost'] ?? $item['cost_price'] ?? $item['cost'] ?? $item['price'] ?? 0);
        $qty = (int)($item['quantity'] ?? $item['qty'] ?? 0);
        $subtotal += ($qty * $cost);
    }

    if ($discount_amount > $subtotal) $discount_amount = $subtotal;
    $grand_total = $subtotal - $discount_amount + $tax_amount;

    // 2. Insert PO Parent Record
    $stmt = $pdo->prepare("INSERT INTO purchase_orders (supplier_id, po_number, expected_date, subtotal, discount_amount, tax_amount, total_amount, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$supplier_id, $po_number, $expected_date, $subtotal, $discount_amount, $tax_amount, $grand_total, $notes, $created_by]);
    $po_id = $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");

    foreach ($input['cart'] as $item) {
        $product_id = (int)($item['product_id'] ?? $item['id'] ?? 0);
        $qty = (int)($item['quantity'] ?? $item['qty'] ?? 0);
        $cost = (float)($item['unit_cost'] ?? $item['cost_price'] ?? $item['cost'] ?? $item['price'] ?? 0); 
        
        $itemStmt->execute([$po_id, $product_id, $qty, $cost]);
    }

    $pdo->commit();

    // --- 4. OPTIONAL: PDF GENERATION & EMAIL DELIVERY ---
    $pdf_url = null;
    $email_sent = false;
    $email_error = null;

    $send_email = !empty($input['send_email']);
    $generate_pdf = !empty($input['generate_pdf']) || $send_email;

    if ($generate_pdf) {
        if (file_exists('../vendor/autoload.php')) {
            require_once '../vendor/autoload.php';
            
            // Fetch Supplier Information
            $suppStmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
            $suppStmt->execute([$supplier_id]);
            $supplier = $suppStmt->fetch();

            // Build Items HTML for PDF
            $itemsHtml = '';
            $counter = 1;
            foreach ($input['cart'] as $item) {
                $cost = (float)($item['unit_cost'] ?? $item['cost_price'] ?? $item['cost'] ?? $item['price'] ?? 0);
                $qty = (int)($item['quantity'] ?? $item['qty'] ?? 0);
                $net = $qty * $cost;
                $prodName = htmlspecialchars($item['name'] ?? $item['product_name'] ?? 'Product');
                $sku = htmlspecialchars($item['sku'] ?? 'N/A');
                
                $itemsHtml .= "
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #E0E0E0; text-align: center; color: #555;'>{$counter}</td>
                    <td style='padding: 10px; border-bottom: 1px solid #E0E0E0;'>
                        <strong style='color: #1A1A1A;'>{$prodName}</strong><br>
                        <span style='font-size: 11px; color: #777;'>SKU: {$sku}</span>
                    </td>
                    <td style='padding: 10px; border-bottom: 1px solid #E0E0E0; text-align: center;'><strong>{$qty}</strong></td>
                    <td style='padding: 10px; border-bottom: 1px solid #E0E0E0; text-align: right; color: #555;'>Rs " . number_format($cost, 2) . "</td>
                    <td style='padding: 10px; border-bottom: 1px solid #E0E0E0; text-align: right; color: #1A1A1A; font-weight: bold;'>Rs " . number_format($net, 2) . "</td>
                </tr>";
                $counter++;
            }

            // GUARANTEED LOGO EMBED FOR DOMPDF (Fetches directly via URL or Local file to Base64)
            $base64Logo = '';
            $logoUrl = 'https://candent.suzxlabs.com/images/logo/logo.png';
            $logoData = @file_get_contents($logoUrl);
            if ($logoData) {
                $base64Logo = 'data:image/png;base64,' . base64_encode($logoData);
            } else {
                $localPath = __DIR__ . '/../images/logo/logo.png';
                if (file_exists($localPath)) {
                    $mime = mime_content_type($localPath);
                    $base64Logo = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($localPath));
                }
            }
            $pdfLogoTag = $base64Logo ? "<img src='{$base64Logo}' class='logo' alt='Candent Logo'>" : "<h1 style='color:#0055CC; margin:0;'>CANDENT</h1>";

            $pdfDeductionsHtml = '';
            if ($discount_amount > 0) {
                $pdfDeductionsHtml .= "<tr><td class='text-right' style='color:#dc3545;'>Less: Discounts -</td><td class='text-right' style='color:#dc3545; font-weight:bold;'>Rs " . number_format($discount_amount, 2) . "</td></tr>";
            }
            
            $pdfTaxHtml = '';
            if ($tax_amount > 0) {
                $pdfTaxHtml = "<tr><td class='text-right'>Tax Amount:</td><td class='text-right' style='font-weight:bold;'>+ Rs " . number_format($tax_amount, 2) . "</td></tr>";
            }

            // Construct True PDF HTML (DomPDF Optimized)
            $pdfHtml = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    @page { margin: 40px; }
                    body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 13px; color: #1A1A1A; }
                    .header-table { width: 100%; margin-bottom: 30px; }
                    .header-table td { vertical-align: top; border: none; padding: 0; }
                    .logo { width: 100%; max-width: 250px; height: auto; margin-bottom: 10px; }
                    .brand-details { font-size: 11px; color: #555; line-height: 1.4; }
                    .title { font-size: 26px; font-weight: bold; color: #0055CC; margin-bottom: 5px; }
                    .po-number { font-size: 16px; font-weight: bold; color: #333; }
                    
                    .info-table { width: 100%; margin-bottom: 30px; }
                    .info-table td { vertical-align: top; border: none; padding: 0; }
                    .info-box { background: #F8F9FA; padding: 15px; border: 1px solid #E0E0E0; border-radius: 4px; }
                    
                    .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                    .items-table th { background: #F8F9FA; padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase; border-bottom: 2px solid #1A1A1A; border-top: 1px solid #E0E0E0; }
                    .items-table td { padding: 10px; border-bottom: 1px solid #E0E0E0; font-size: 12px; }
                    
                    .totals-table { width: 350px; float: right; border-collapse: collapse; }
                    .totals-table td { padding: 6px 0; font-size: 12px; border: none; }
                    .grand-total td { font-size: 15px; font-weight: bold; border-top: 2px solid #1A1A1A; border-bottom: 2px solid #E0E0E0; padding: 10px 0; }
                    
                    .text-right { text-align: right; }
                    .text-center { text-align: center; }
                    .clear { clear: both; }
                </style>
            </head>
            <body>
                <table class='header-table'>
                    <tr>
                        <td style='width: 50%;'>
                            {$pdfLogoTag}
                            <div class='brand-details'>
                                <strong>Candent</strong><br>
                                79, Dambakanda Estate, Boyagane,<br>
                                Kurunegala, Sri Lanka.<br>
                                Tel: 076 140 7876 | Email: candentlk@gmail.com<br>
                                Web: candent.suzxlabs.com
                            </div>
                        </td>
                        <td class='text-right' style='width: 50%;'>
                            <div class='title'>PURCHASE ORDER</div>
                            <div class='po-number'>PO-" . str_pad($po_id, 6, '0', STR_PAD_LEFT) . "</div>
                        </td>
                    </tr>
                </table>

                <table class='info-table'>
                    <tr>
                        <td style='width: 55%;'>
                            <div style='font-size: 10px; color: #777; text-transform: uppercase; font-weight: bold; margin-bottom: 5px; letter-spacing: 1px;'>Order Issued To</div>
                            <div style='font-size: 15px; font-weight: bold; color: #1A1A1A; margin-bottom: 4px;'>" . htmlspecialchars($supplier['company_name'] ?? 'Unknown Supplier') . "</div>
                            <div style='color: #555; font-size: 12px; line-height: 1.5;'>
                                Attn: " . htmlspecialchars($supplier['contact_person'] ?? 'Sales Dept') . "<br>
                                " . htmlspecialchars($supplier['phone'] ?? '') . "
                            </div>
                        </td>
                        <td style='width: 45%;'>
                            <div class='info-box'>
                                <table style='width: 100%; margin: 0;'>
                                    <tr>
                                        <td style='color: #555; padding-bottom: 5px;'>Order Date:</td>
                                        <td class='text-right' style='font-weight: bold;'>" . date('M d, Y') . "</td>
                                    </tr>
                                    <tr>
                                        <td style='color: #555;'>Expected By:</td>
                                        <td class='text-right' style='font-weight: bold;'>" . ($expected_date ? date('M d, Y', strtotime($expected_date)) : 'ASAP') . "</td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                </table>

                <table class='items-table'>
                    <thead>
                        <tr>
                            <th style='width: 5%;' class='text-center'>#</th>
                            <th style='width: 45%;'>Product Description</th>
                            <th style='width: 15%;' class='text-center'>Order Qty</th>
                            <th style='width: 15%;' class='text-right'>Unit Cost (Rs)</th>
                            <th style='width: 20%;' class='text-right'>Line Total (Rs)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$itemsHtml}
                    </tbody>
                </table>

                <table class='totals-table'>
                    <tr>
                        <td class='text-right' style='color: #555; width: 60%;'>Gross Subtotal:</td>
                        <td class='text-right' style='font-weight: bold; width: 40%;'>Rs " . number_format($subtotal, 2) . "</td>
                    </tr>
                    {$pdfDeductionsHtml}
                    {$pdfTaxHtml}
                    <tr class='grand-total'>
                        <td class='text-right'>NET PAYABLE:</td>
                        <td class='text-right'>Rs " . number_format($grand_total, 2) . "</td>
                    </tr>
                </table>
                
                <div class='clear'></div>

                <div style='margin-top: 40px; font-size: 11px;'>
                    <div style='font-weight: bold; color: #777; text-transform: uppercase; margin-bottom: 5px;'>Notes / Instructions</div>
                    <div style='color: #555; line-height: 1.5;'>" . nl2br(htmlspecialchars($notes ?: 'Please confirm receipt of this Purchase Order and expected delivery date.')) . "</div>
                </div>

                <div style='margin-top: 50px; width: 200px; border-top: 1px solid #1A1A1A; padding-top: 5px; text-align: center;'>
                    <div style='font-size: 10px; color: #555;'>System Generated Signature</div>
                    <div style='font-weight: bold; font-size: 12px; margin-top: 2px;'>Candent</div>
                </div>
                
                <div style='position: fixed; bottom: -20px; left: 0; right: 0; text-align: center; font-size: 10px; color: #777;'>
                    System Developed & Maintained by Suzxlabs
                </div>
            </body>
            </html>";

            // Generate PDF via DomPDF
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->set_option('isHtml5ParserEnabled', true);
            $dompdf->set_option('isRemoteEnabled', true);
            $dompdf->set_option('defaultFont', 'Helvetica');
            $dompdf->loadHtml($pdfHtml);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            
            $pdfOutput = $dompdf->output();
            $pdfBase64 = base64_encode($pdfOutput);
            $pdfName = 'Purchase_Order_' . str_pad($po_id, 6, '0', STR_PAD_LEFT) . '.pdf';

            // Save PDF to Server for Optional Download
            $pdfDir = '../assets/pdfs/purchase_orders/';
            if (!is_dir($pdfDir)) {
                mkdir($pdfDir, 0777, true);
            }
            $pdfFilePath = $pdfDir . $pdfName;
            file_put_contents($pdfFilePath, $pdfOutput);
            
            // Generate public URL to download the PDF
            $pdf_url = 'assets/pdfs/purchase_orders/' . $pdfName;

            // Optional: Dispatch Email via Brevo API
            if ($send_email && !empty($supplier['email'])) {
                $brevo_api_key = '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';
                $sender_email = 'suz.xlabs@gmail.com'; 
                $sender_name = 'Candent Purchasing';

                $emailDeductionsHtml = '';
                if ($discount_amount > 0) {
                    $emailDeductionsHtml .= "<tr><td style='padding: 4px 0; color: #dc3545;'>Less: Discounts -</td><td style='text-align: right; color: #dc3545; font-weight: bold;'>Rs " . number_format($discount_amount, 2) . "</td></tr>";
                }

                $emailBody = "
                <!DOCTYPE html>
                <html>
                <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; padding: 20px; background-color: #F4F6F9; margin: 0;'>
                    <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; border: 1px solid #E0E0E0; overflow: hidden;'>
                        <!-- Clean direct logo FIXED TO FULL SIZE without black padding -->
                        <img src='https://candent.suzxlabs.com/images/logo/logo.png' style='width: 100%; display: block; border: none; margin: 0; padding: 0;' alt='Candent Logo'>
                        
                        <div style='padding: 30px;'>
                            <h2 style='color: #1A1A1A; margin: 0 0 20px 0; font-size: 24px;'>New Purchase Order</h2>
                            <p style='color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 15px 0;'>Hello <strong>" . htmlspecialchars($supplier['company_name']) . "</strong>,</p>
                            <p style='color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 15px 0;'>Please find attached our new Purchase Order (<strong>PO-" . str_pad($po_id, 6, '0', STR_PAD_LEFT) . "</strong>).</p>
                            <p style='color: #555; font-size: 16px; line-height: 1.6; margin: 0 0 25px 0;'>We expect delivery by <strong>" . ($expected_date ? date('M d, Y', strtotime($expected_date)) : 'ASAP') . "</strong>.</p>
                            
                            <div style='background-color: #F8F9FA; padding: 20px; border-radius: 8px; margin-bottom: 25px;'>
                                <h4 style='margin: 0 0 15px 0; color: #1A1A1A; font-size: 16px;'>Order Summary</h4>
                                <table width='100%' style='font-size: 14px; color: #555; border-collapse: collapse;'>
                                    <tr>
                                        <td style='padding: 4px 0;'>Gross Subtotal:</td>
                                        <td style='text-align: right; font-weight: bold;'>Rs " . number_format($subtotal, 2) . "</td>
                                    </tr>
                                    {$emailDeductionsHtml}
                                    <tr>
                                        <td style='padding: 12px 0 0 0; border-top: 1px solid #E0E0E0; font-weight: bold; color: #1A1A1A; margin-top: 8px;'>Net Payable:</td>
                                        <td style='padding: 12px 0 0 0; border-top: 1px solid #E0E0E0; text-align: right; font-weight: bold; color: #1A1A1A; font-size: 16px;'>Rs " . number_format($grand_total, 2) . "</td>
                                    </tr>
                                </table>
                            </div>
                            
                            <div style='border-top: 1px solid #E0E0E0; padding-top: 20px;'>
                                <p style='color: #1A1A1A; font-size: 16px; font-weight: 600; margin: 0;'>Best regards,</p>
                                <p style='color: #555; font-size: 14px; margin: 5px 0 0 0;'>Candent Distribution Team</p>
                            </div>
                        </div>
                    </div>
                </body>
                </html>
                ";

                $payload = [
                    "sender" => ["name" => $sender_name, "email" => $sender_email],
                    "to" => [["email" => $supplier['email'], "name" => $supplier['company_name']]],
                    "subject" => "New Purchase Order (PO-" . str_pad($po_id, 6, '0', STR_PAD_LEFT) . ") - Candent",
                    "htmlContent" => $emailBody,
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
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: application/json',
                    'api-key: ' . $brevo_api_key,
                    'content-type: application/json'
                ]);
                
                $result = curl_exec($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpcode >= 200 && $httpcode < 300) {
                    $email_sent = true;
                    // Update PO status automatically
                    $pdo->prepare("UPDATE purchase_orders SET status = 'sent' WHERE id = ?")->execute([$po_id]);
                } else {
                    $email_error = 'Email failed to send. HTTP Code: ' . $httpcode;
                }
            } elseif ($send_email && empty($supplier['email'])) {
                $email_error = "Supplier has no email address configured.";
            }
        } else {
            $email_error = "DomPDF library is missing. Run 'composer require dompdf/dompdf' to enable PDF generation.";
        }
    }
    // ----------------------------------------------------

    // Return extended JSON response with URL and email status
    echo json_encode([
        'success' => true, 
        'po_id' => $po_id, 
        'message' => 'Purchase Order Created Successfully!',
        'pdf_url' => $pdf_url,
        'email_sent' => $email_sent,
        'email_error' => $email_error
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("PO API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Processing Error: ' . $e->getMessage()]);
}
?>