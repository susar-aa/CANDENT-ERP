<?php
session_start();
require_once 'config/db.php';

// Ensure user is logged in
if (!isset($_SESSION['customer_id'])) {
    header("Location: index.php");
    exit;
}

// --- IMAGE COMPRESSION HELPER ---
function compressAndResizeImage($source, $destination, $quality = 85, $maxWidth = 1000) {
    $info = @getimagesize($source);
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
        case 'image/jpeg': $image = @imagecreatefromjpeg($source); break;
        case 'image/png': $image = @imagecreatefrompng($source); break;
        case 'image/gif': $image = @imagecreatefromgif($source); break;
        case 'image/webp': $image = @imagecreatefromwebp($source); break;
        default: return false; // Unsupported type
    }
    
    if(!$image) return false;
    imagecopyresampled($imageResized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    $success = false;
    switch ($mime) {
        case 'image/jpeg': $success = imagejpeg($imageResized, $destination, $quality); break;
        case 'image/png': 
            $pngQuality = round((100 - $quality) / 10);
            $success = imagepng($imageResized, $destination, $pngQuality); 
            break;
        case 'image/gif': $success = imagegif($imageResized, $destination); break;
        case 'image/webp': $success = imagewebp($imageResized, $destination, $quality); break;
    }
    
    imagedestroy($image);
    imagedestroy($imageResized);
    return $success;
}

// --- E-COMMERCE CHECKOUT API ENDPOINT ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'public_checkout') {
    header('Content-Type: application/json');
    
    try {
        $pdo->beginTransaction();
        $customer_id = $_SESSION['customer_id'];
        
        // Parse JSON cart from POST data
        $cart = json_decode($_POST['cart'], true);
        
        $shipping_name = trim($_POST['shipping_name']);
        $shipping_phone = trim($_POST['shipping_phone']);
        $shipping_address = trim($_POST['shipping_address']);
        $payment_method = $_POST['payment_method'] ?? 'Cash on Delivery (COD)';
        
        if (empty($shipping_name) || empty($shipping_phone) || empty($shipping_address)) {
            throw new Exception("Please fill in all delivery details.");
        }

        // Handle Receipt Upload for Bank Transfers (With Compression)
        $receipt_path = null;
        if ($payment_method === 'Bank Transfer') {
            if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Payment receipt is required for Bank Transfer.");
            }
            
            $uploadDir = 'assets/images/receipts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (!in_array($ext, $allowed)) {
                throw new Exception("Invalid file type. Only JPG, PNG, and PDF are allowed.");
            }
            
            $receipt_path = time() . '_' . uniqid() . '.' . $ext;
            $targetFilePath = $uploadDir . $receipt_path;
            
            if ($ext === 'pdf') {
                if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $targetFilePath)) {
                    throw new Exception("Failed to save PDF receipt.");
                }
            } else {
                if (!compressAndResizeImage($_FILES['receipt']['tmp_name'], $targetFilePath, 80, 1000)) {
                    // Fallback to direct move if compression fails
                    if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $targetFilePath)) {
                        throw new Exception("Failed to save image receipt.");
                    }
                }
            }
        }

        $subtotal = 0;
        
        // 1. Verify Stock & Calculate Subtotal
        foreach ($cart as $item) {
            $pid = (int)$item['id'];
            $qty = (int)$item['qty'];
            $price = (float)$item['price'];
            
            $checkStmt = $pdo->prepare("SELECT stock, name FROM products WHERE id = ? FOR UPDATE");
            $checkStmt->execute([$pid]);
            $prod = $checkStmt->fetch();
            
            if (!$prod || $prod['stock'] < $qty) {
                throw new Exception("Not enough stock for: " . ($prod ? $prod['name'] : "Product ID $pid"));
            }
            $subtotal += ($qty * $price);
        }
        
        // 2. Create Pending Order 
        $stmt = $pdo->prepare("INSERT INTO orders (customer_id, shipping_name, shipping_phone, shipping_address, subtotal, total_amount, payment_method, payment_receipt, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$customer_id, $shipping_name, $shipping_phone, $shipping_address, $subtotal, $subtotal, $payment_method, $receipt_path]);
        $order_id = $pdo->lastInsertId();
        
        // 3. Insert Items & Deduct Stock
        $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stockStmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        $logStmt = $pdo->prepare("INSERT INTO stock_logs (product_id, type, reference_id, qty_change, previous_stock, new_stock) VALUES (?, 'sale_out', ?, ?, (SELECT stock + ? FROM products WHERE id = ?), (SELECT stock FROM products WHERE id = ?))");
        
        $itemsHtml = '';
        foreach ($cart as $item) {
            $pid = (int)$item['id'];
            $qty = (int)$item['qty'];
            $price = (float)$item['price'];
            $net = $qty * $price;
            
            $itemStmt->execute([$order_id, $pid, $qty, $price]);
            $stockStmt->execute([$qty, $pid]);
            $logStmt->execute([$pid, $order_id, -$qty, $qty, $pid, $pid]);
            
            $itemsHtml .= "<tr>
                <td style='padding: 10px 8px; border-bottom: 1px solid #eee;'>{$item['name']}</td>
                <td style='padding: 10px 8px; border-bottom: 1px solid #eee; text-align: center;'>{$qty}</td>
                <td style='padding: 10px 8px; border-bottom: 1px solid #eee; text-align: right;'>Rs " . number_format($net, 2) . "</td>
            </tr>";
        }
        
        $pdo->commit();

        // 4. Send Confirmation Email via Brevo API
        try {
            $custStmt = $pdo->prepare("SELECT name, email FROM customers WHERE id = ?");
            $custStmt->execute([$customer_id]);
            $customer = $custStmt->fetch();

            if ($customer && !empty($customer['email'])) {
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
                $host = $_SERVER['HTTP_HOST'];
                $root_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $invoice_url = $protocol . "://" . $host . $root_path . "/pages/view_invoice.php?id=" . $order_id;
                
                $htmlBody = "
                <div style='background-color: #f4f6f9; padding: 40px 0; font-family: Arial, sans-serif;'>
                    <div style='max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
                        <div style='text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0d6efd; padding-bottom: 20px;'>
                            <h2 style='color: #0d6efd; margin: 0; font-size: 26px; letter-spacing: 1px; font-weight: 900;'>FINTRIX</h2>
                            <div style='color: #777; font-size: 13px;'>Distribution & E-commerce</div>
                        </div>
                        <h3 style='text-align: center; color: #212529;'>Order Confirmation #".str_pad($order_id, 6, '0', STR_PAD_LEFT)."</h3>
                        <p style='color: #495057; font-size: 15px;'>Hello <strong>{$customer['name']}</strong>,</p>
                        <p style='color: #495057; font-size: 15px;'>Thank you for your order! We have successfully received your request and it is currently being processed.</p>
                        
                        <div style='background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                            <p style='margin: 0 0 5px 0;'><strong>Payment Method:</strong> {$payment_method}</p>
                            <p style='margin: 0;'><strong>Deliver To:</strong> {$shipping_name} ({$shipping_phone})<br>{$shipping_address}</p>
                        </div>
                        
                        <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                            <thead>
                                <tr style='background: #2c3e50; color: white;'>
                                    <th style='padding: 10px 8px; text-align: left; border-radius: 6px 0 0 0;'>Item</th>
                                    <th style='padding: 10px 8px; text-align: center;'>Qty</th>
                                    <th style='padding: 10px 8px; text-align: right; border-radius: 0 6px 0 0;'>Total</th>
                                </tr>
                            </thead>
                            <tbody>{$itemsHtml}</tbody>
                            <tfoot>
                                <tr>
                                    <td colspan='2' style='padding: 15px 8px 5px 8px; text-align: right; font-weight: bold; font-size: 16px;'>Order Total:</td>
                                    <td style='padding: 15px 8px 5px 8px; text-align: right; font-weight: bold; font-size: 16px; color: #198754;'>Rs " . number_format($subtotal, 2) . "</td>
                                </tr>
                            </tfoot>
                        </table>
                        
                        <div style='text-align: center; margin-top: 35px; margin-bottom: 20px;'>
                            <a href='{$invoice_url}' style='background-color: #0d6efd; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 50px; font-weight: bold; display: inline-block; font-size: 15px;'>View Live Order Status</a>
                        </div>
                        <div style='text-align: center; color: #adb5bd; font-size: 12px; margin-top: 20px;'>
                            Thank you for shopping with Fintrix!<br>fintrix.suzxlabs.com
                        </div>
                    </div>
                </div>";

                $payload = [
                    "sender" => ["name" => "Fintrix Store", "email" => "suz.xlabs@gmail.com"],
                    "to" => [["email" => $customer['email'], "name" => $customer['name']]],
                    "subject" => "Order Confirmation #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " - Fintrix",
                    "htmlContent" => $htmlBody
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://api.brevo.com/v3/smtp/email");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: application/json',
                    'api-key: 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',
                    'content-type: application/json'
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        } catch (Exception $emailErr) { /* Non-blocking failure */ }

        echo json_encode(['success' => true, 'order_id' => $order_id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch Logged in Customer Details for pre-filling delivery details
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$_SESSION['customer_id']]);
$logged_in_customer = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Fintrix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8f9fa; }
        .navbar-brand { font-weight: 900; letter-spacing: 1px; font-size: 1.5rem; color: #0d6efd !important; }
        
        .checkout-wrapper { max-width: 1100px; margin: 2rem auto; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); overflow: hidden; }
        
        .payment-method-card { border: 2px solid #dee2e6; border-radius: 12px; padding: 15px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; }
        .payment-method-card.active { border-color: #0d6efd; background-color: #f8fbff; }
        .payment-method-card.disabled { opacity: 0.5; cursor: not-allowed; background-color: #f8f9fa; }
        
        footer { background: #212529; color: #adb5bd; padding: 40px 0 20px 0; margin-top: 60px; }
        footer a { color: #adb5bd; text-decoration: none; transition: color 0.2s; }
        footer a:hover { color: #fff; }
    </style>
</head>
<body>

    <!-- Navigation -->
    <nav class="navbar navbar-light bg-white py-3 shadow-sm sticky-top">
        <div class="container d-flex justify-content-between">
            <a class="navbar-brand" href="index.php">FINTRIX</a>
            <a href="index.php" class="btn btn-outline-secondary rounded-pill fw-bold"><i class="bi bi-arrow-left"></i> Back to Store</a>
        </div>
    </nav>

    <div class="container">
        <div class="checkout-wrapper row g-0">
            <!-- Left: Shipping & Payment -->
            <div class="col-lg-7 p-4 p-md-5">
                <h4 class="fw-bold text-dark mb-4"><i class="bi bi-shield-lock text-success me-2"></i> Secure Checkout</h4>
                
                <form id="checkoutForm" onsubmit="processCheckout(event)">
                    <h5 class="fw-bold mb-4 border-bottom pb-2">1. Delivery Details</h5>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Full Name <span class="text-danger">*</span></label>
                            <input type="text" id="chk_name" class="form-control fw-bold" required value="<?php echo htmlspecialchars($logged_in_customer['name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Phone Number <span class="text-danger">*</span></label>
                            <input type="tel" id="chk_phone" class="form-control" required value="<?php echo htmlspecialchars($logged_in_customer['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted">Complete Delivery Address <span class="text-danger">*</span></label>
                            <textarea id="chk_address" class="form-control" rows="3" required placeholder="House No, Street, City, District..."><?php echo htmlspecialchars($logged_in_customer['address'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-4 border-bottom pb-2">2. Payment Method</h5>
                    
                    <div class="row g-3 mb-4">
                        <!-- COD Option -->
                        <div class="col-12">
                            <div class="payment-method-card active" id="cardCOD" onclick="selectPaymentMethod('COD')">
                                <input class="form-check-input me-3 mt-0 fs-5" type="radio" name="payment_method" value="Cash on Delivery (COD)" id="radioCOD" checked>
                                <div class="flex-grow-1">
                                    <div class="fw-bold text-dark fs-6">Cash on Delivery (COD)</div>
                                    <small class="text-muted">Pay securely with cash when your package arrives.</small>
                                </div>
                                <i class="bi bi-cash-stack fs-2 text-success opacity-75"></i>
                            </div>
                        </div>
                        
                        <!-- Bank Transfer Option -->
                        <div class="col-12">
                            <div class="payment-method-card" id="cardBank" onclick="selectPaymentMethod('Bank Transfer')">
                                <input class="form-check-input me-3 mt-0 fs-5" type="radio" name="payment_method" value="Bank Transfer" id="radioBank">
                                <div class="flex-grow-1">
                                    <div class="fw-bold text-dark fs-6">Bank Transfer</div>
                                    <small class="text-muted">Direct transfer to our bank account. Receipt required.</small>
                                </div>
                                <i class="bi bi-bank fs-2 text-primary opacity-75"></i>
                            </div>
                        </div>
                        
                        <!-- Bank Transfer Details Block (Hidden by default) -->
                        <div class="col-12 d-none" id="bankDetailsBlock">
                            <div class="bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded p-4 shadow-sm">
                                <h6 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle"></i> Bank Account Details</h6>
                                
                                <div class="mb-2 d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="text-muted small d-block">Account Name</span>
                                        <span class="fw-bold text-dark fs-6" id="bankAccName">G L D Susara Senarathne</span>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 rounded-pill" onclick="copyToClipboard('G L D Susara Senarathne', this)"><i class="bi bi-clipboard"></i> Copy</button>
                                </div>
                                
                                <div class="mb-3 d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="text-muted small d-block">Account Number</span>
                                        <span class="fw-bold text-dark fs-4" id="bankAccNum">8018526390</span>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 rounded-pill" onclick="copyToClipboard('8018526390', this)"><i class="bi bi-clipboard"></i> Copy</button>
                                </div>
                                
                                <div class="mb-4">
                                    <span class="text-muted small d-block">Bank & Branch</span>
                                    <span class="fw-bold text-dark">Commercial Bank - Kurunegala City Office</span>
                                </div>
                                
                                <hr class="border-primary border-opacity-25">
                                
                                <div class="mb-2 mt-3">
                                    <label class="form-label fw-bold small text-dark">Upload Payment Receipt <span class="text-danger">*</span></label>
                                    <input type="file" id="receiptUpload" class="form-control bg-white" accept=".jpg,.jpeg,.png,.pdf">
                                    <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">Allowed formats: JPG, PNG, PDF. High-quality images will be automatically compressed.</small>
                                </div>
                            </div>
                        </div>

                        <!-- Koko Pay (Disabled) -->
                        <div class="col-12">
                            <div class="payment-method-card disabled">
                                <input class="form-check-input me-3 mt-0 fs-5" type="radio" name="payment_method" disabled>
                                <div class="flex-grow-1">
                                    <div class="fw-bold text-dark fs-6">Koko Pay (Buy Now Pay Later)</div>
                                    <small class="text-danger fw-bold">Coming Soon</small>
                                </div>
                                <i class="bi bi-phone fs-2 text-muted opacity-50"></i>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="btnPlaceOrder" class="btn btn-primary w-100 py-3 rounded-pill fw-bold fs-5 shadow-sm mt-3">
                        Place Order Now <i class="bi bi-check2-circle ms-2"></i>
                    </button>
                </form>
            </div>
            
            <!-- Right: Order Summary -->
            <div class="col-lg-5 bg-light p-4 p-md-5 border-start d-flex flex-column">
                <h5 class="fw-bold mb-4">Order Summary</h5>
                
                <div id="checkoutSummaryItems" class="mb-4 flex-grow-1" style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
                    <!-- Items injected here via JS -->
                </div>
                
                <div class="border-top pt-4 mt-auto">
                    <div class="d-flex justify-content-between mb-2 text-muted">
                        <span>Subtotal</span>
                        <span id="checkoutSummarySubtotal">Rs 0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 text-muted">
                        <span>Delivery Fee</span>
                        <span class="text-success fw-bold">FREE</span>
                    </div>
                    <div class="d-flex justify-content-between mt-3 pt-3 border-top">
                        <span class="fw-bold fs-5 text-dark">Total to Pay</span>
                        <span class="fw-bold fs-4 text-primary" id="checkoutSummaryTotal">Rs 0.00</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <div class="container text-center">
            <div class="small">
                &copy; <?php echo date('Y'); ?> Fintrix Distribution Management System. All rights reserved.
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let publicCart = JSON.parse(localStorage.getItem('fintrix_public_cart')) || [];

        document.addEventListener('DOMContentLoaded', function() {
            if (publicCart.length === 0) {
                alert("Your cart is empty! Redirecting to store...");
                window.location.href = 'index.php';
                return;
            }
            populateSummary();
        });

        function populateSummary() {
            const summaryContainer = document.getElementById('checkoutSummaryItems');
            let total = 0;
            summaryContainer.innerHTML = '';
            
            publicCart.forEach(item => {
                const lineTotal = item.price * item.qty;
                total += lineTotal;
                
                let imgSrc = item.image ? item.image : 'data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20fill%3D%22%23eee%22%3E%3Crect%20width%3D%2240%22%20height%3D%2240%22%2F%3E%3C%2Fsvg%3E';

                summaryContainer.innerHTML += `
                    <div class="d-flex align-items-center mb-3 border-bottom pb-3">
                        <img src="${imgSrc}" style="width: 50px; height: 50px; object-fit: contain; background: #fff; border-radius: 6px;" class="me-3 border">
                        <div class="flex-grow-1">
                            <div class="fw-bold text-dark lh-sm mb-1" style="font-size: 0.9rem;">${item.name}</div>
                            <div class="small text-muted">Qty: ${item.qty} × Rs ${parseFloat(item.price).toFixed(2)}</div>
                        </div>
                        <div class="fw-bold text-success ms-2">Rs ${lineTotal.toFixed(2)}</div>
                    </div>
                `;
            });
            
            document.getElementById('checkoutSummarySubtotal').innerText = 'Rs ' + total.toFixed(2);
            document.getElementById('checkoutSummaryTotal').innerText = 'Rs ' + total.toFixed(2);
        }

        function selectPaymentMethod(method) {
            document.getElementById('cardCOD').classList.remove('active');
            document.getElementById('cardBank').classList.remove('active');
            document.getElementById('bankDetailsBlock').classList.add('d-none');

            if (method === 'COD') {
                document.getElementById('cardCOD').classList.add('active');
                document.getElementById('radioCOD').checked = true;
            } else if (method === 'Bank Transfer') {
                document.getElementById('cardBank').classList.add('active');
                document.getElementById('radioBank').checked = true;
                document.getElementById('bankDetailsBlock').classList.remove('d-none');
            }
        }

        function copyToClipboard(text, btn) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text);
            } else {
                let textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed";
                textArea.style.left = "-999999px";
                textArea.style.top = "-999999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try { document.execCommand('copy'); } catch (err) {}
                textArea.remove();
            }
            
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied!';
            btn.classList.replace('btn-outline-primary', 'btn-success');
            btn.classList.add('text-white');
            
            setTimeout(() => {
                btn.innerHTML = originalHtml;
                btn.classList.replace('btn-success', 'btn-outline-primary');
                btn.classList.remove('text-white');
            }, 2000);
        }

        async function processCheckout(e) {
            e.preventDefault();
            
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked').value;
            const receiptInput = document.getElementById('receiptUpload');

            if (paymentMethod === 'Bank Transfer' && receiptInput.files.length === 0) {
                alert("Please upload your bank transfer receipt to complete the order.");
                return;
            }

            const btn = document.getElementById('btnPlaceOrder');
            const origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing Securely...';

            const formData = new FormData();
            formData.append('action', 'public_checkout');
            formData.append('cart', JSON.stringify(publicCart));
            formData.append('shipping_name', document.getElementById('chk_name').value);
            formData.append('shipping_phone', document.getElementById('chk_phone').value);
            formData.append('shipping_address', document.getElementById('chk_address').value);
            formData.append('payment_method', paymentMethod);
            
            if (paymentMethod === 'Bank Transfer') {
                formData.append('receipt', receiptInput.files[0]);
            }

            try {
                const response = await fetch('checkout.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    publicCart = [];
                    localStorage.removeItem('fintrix_public_cart');
                    
                    alert("Order Confirmed! Your receipt has been sent to your email.");
                    window.location.href = 'pages/view_invoice.php?id=' + result.order_id;
                } else {
                    alert("Checkout failed: " + result.error);
                    btn.disabled = false;
                    btn.innerHTML = origText;
                }
            } catch(error) {
                alert("Network error. Please try again.");
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        }
    </script>
</body>
</html>
