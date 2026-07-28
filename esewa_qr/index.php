<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic eSewa & EMVCo QR Code Generator</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- App Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Client-side QR Scanner Library -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
</head>
<body>

    <!-- Background Decorative Gradient Mesh -->
    <div class="bg-mesh">
        <div class="bg-mesh-circle circle-1"></div>
        <div class="bg-mesh-circle circle-2"></div>
    </div>

    <!-- App Header Navbar -->
    <header class="app-header">
        <div class="brand-box">
            <div class="esewa-badge">
                <i class="fas fa-qrcode"></i> eSewa Dynamic QR
            </div>
            <div class="brand-title">EMVCo Static to Dynamic QR Converter</div>
        </div>
        <button type="button" class="theme-toggle-btn" onclick="toggleTheme()">
            <i class="fas fa-sun" id="theme-icon"></i> <span id="theme-text">Light Mode</span>
        </button>
    </header>

    <!-- Main Content Container -->
    <main class="main-wrapper">
        <div class="app-grid">
            
            <!-- Left Card: Input & Configuration -->
            <div class="glass-card">
                <div class="card-title">
                    <i class="fas fa-sliders"></i> 1. Static QR Input & Merchant Details
                </div>

                <!-- Input Mode Tabs -->
                <div class="input-mode-tabs">
                    <button class="tab-btn active" id="tab-upload" onclick="switchTab('upload')">
                        <i class="fas fa-cloud-upload-alt"></i> Upload Static Image
                    </button>
                    <button class="tab-btn" id="tab-text" onclick="switchTab('text')">
                        <i class="fas fa-code"></i> Paste EMVCo Payload
                    </button>
                    <button class="tab-btn" id="tab-sample" onclick="switchTab('sample')">
                        <i class="fas fa-flask"></i> Sample Test QRs
                    </button>
                </div>

                <!-- Section: File Upload -->
                <div id="section-upload">
                    <div class="dropzone" id="dropzone">
                        <div class="dropzone-icon">
                            <i class="fas fa-qrcode"></i>
                        </div>
                        <div class="dropzone-text">Drag & drop static eSewa QR image here</div>
                        <div class="dropzone-subtext">or click to browse from device / paste image from clipboard (Ctrl+V)</div>
                        <input type="file" id="file-input" class="file-input-hidden" accept="image/*">
                    </div>

                    <div id="upload-preview-box" style="display:none; margin-top:16px; align-items:center; gap:16px; background:rgba(0,0,0,0.15); padding:12px; border-radius:12px; border:1px solid var(--border-card);">
                        <img id="uploaded-img-preview" style="width:70px; height:70px; object-fit:contain; border-radius:8px; border:1px solid var(--border-card);">
                        <div>
                            <div id="upload-status-text" style="font-weight:700; font-size:13px; color:var(--esewa-green);">Image Processed Successfully</div>
                            <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">Original QR Code detected & parsed</div>
                        </div>
                    </div>
                </div>

                <!-- Section: Raw Text Paste -->
                <div id="section-text" style="display:none;">
                    <div class="form-group">
                        <label class="form-label">Raw eSewa / EMVCo QR String Payload</label>
                        <textarea id="raw-payload-input" class="custom-input" rows="4" style="font-family:monospace; font-size:12px; line-height:1.4;" 
                                  placeholder="e.g. 00020101021138580016np.com.fonepay...53035245802NP5914MNS LIQUORS6009Kathmandu63048C3F"
                                  oninput="processPayloadText(this.value.trim())"></textarea>
                    </div>
                </div>

                <!-- Section: Sample Test QRs -->
                <div id="section-sample" style="display:none;">
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <button type="button" class="btn-secondary" onclick="loadSampleQr('esewa')">
                            <i class="fas fa-bolt" style="color:var(--esewa-green);"></i> Load Sample eSewa Merchant Static QR
                        </button>
                        <button type="button" class="btn-secondary" onclick="loadSampleQr('fonepay')">
                            <i class="fas fa-qrcode" style="color:var(--accent-blue);"></i> Load Sample Fonepay / NepalPay Static QR
                        </button>
                    </div>
                </div>

                <!-- Decoded Merchant Information Card -->
                <div id="decoded-info-card" style="display:none;">
                    <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:8px; letter-spacing:0.5px;">
                        <i class="fas fa-check-circle" style="color:var(--esewa-green);"></i> Parsed Merchant Info
                    </div>
                    <div class="decoded-info-grid">
                        <div class="info-item">
                            <span class="info-item-lbl">Merchant Name</span>
                            <span class="info-item-val" id="info-merchant-name" style="color:var(--esewa-green);">--</span>
                        </div>
                        <div class="info-item">
                            <span class="info-item-lbl">Merchant Code / ID</span>
                            <span class="info-item-val" id="info-merchant-id">--</span>
                        </div>
                        <div class="info-item">
                            <span class="info-item-lbl">Currency</span>
                            <span class="info-item-val" id="info-currency">NPR (524)</span>
                        </div>
                        <div class="info-item">
                            <span class="info-item-lbl">Initiation Mode</span>
                            <span class="info-item-val" id="info-initiation">Static (11)</span>
                        </div>
                    </div>
                </div>

                <div class="card-title" style="margin-top:10px;">
                    <i class="fas fa-coins"></i> 2. Amount & Payment Details
                </div>

                <!-- Amount Input -->
                <div class="form-group">
                    <label class="form-label">
                        <span>Set Dynamic Payment Amount</span>
                        <span style="color:var(--esewa-green); font-size:12px;">Auto-fills on customer scan</span>
                    </label>
                    <div class="amount-input-wrap">
                        <span class="currency-prefix">रु</span>
                        <input type="number" id="amount-input" class="custom-input amount-input" placeholder="0.00" step="0.01" value="15.00">
                    </div>
                </div>

                <!-- Quick Amount Presets -->
                <div class="amount-presets">
                    <button type="button" class="preset-btn" data-val="100" data-set="true">रु 100</button>
                    <button type="button" class="preset-btn" data-val="500" data-set="true">रु 500</button>
                    <button type="button" class="preset-btn" data-val="1000" data-set="true">रु 1,000</button>
                    <button type="button" class="preset-btn" data-val="1500" data-set="true">रु 1,500</button>
                    <button type="button" class="preset-btn" data-val="5000" data-set="true">रु 5,000</button>
                </div>

                <!-- Additional Optional Details -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Invoice / Bill Number (Optional)</label>
                        <input type="text" id="bill-number-input" class="custom-input" placeholder="e.g. INV-2026-001" oninput="debounce(generateDynamicQr, 300)()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reference ID (Optional)</label>
                        <input type="text" id="ref-id-input" class="custom-input" placeholder="e.g. REF-9801987220" oninput="debounce(generateDynamicQr, 300)()">
                    </div>
                </div>

                <!-- Action Button -->
                <button type="button" class="btn-primary" id="btn-generate-qr" onclick="generateDynamicQr()">
                    <i class="fas fa-qrcode"></i> Generate Dynamic eSewa QR Code
                </button>
            </div>

            <!-- Right Card: Output & Export -->
            <div class="glass-card">
                <div class="card-title">
                    <i class="fas fa-qrcode"></i> Dynamic QR Code Output
                </div>

                <div id="output-card" style="display:none; flex-direction:column; align-items:center;">
                    
                    <div class="qr-preview-container">
                        <div style="display:flex; align-items:center; gap:6px; font-weight:800; font-size:13px; color:var(--esewa-dark); text-transform:uppercase; letter-spacing:0.5px;">
                            <i class="fas fa-bolt"></i> SCAN TO PAY VIA ESEWA / PHONEPAY
                        </div>

                        <!-- High Resolution QR Barcode Image -->
                        <img id="output-qr-img" src="" alt="Dynamic eSewa QR Code">

                        <div class="qr-merchant-name" id="output-merchant-name">MNS LIQUORS</div>
                        <div class="qr-amount-badge" id="output-amount-badge">रु 15.00</div>
                        <div style="font-size:11px; color:#64748b; margin-top:4px;">
                            Amount is auto-filled when scanned with eSewa / Banking Apps
                        </div>

                        <div class="qr-payload-code" id="output-payload-code">--</div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="actions-row">
                        <button type="button" class="btn-secondary" onclick="downloadQrImage()">
                            <i class="fas fa-download"></i> Download PNG
                        </button>
                        <button type="button" class="btn-secondary" onclick="copyPayload()">
                            <i class="fas fa-copy"></i> Copy Payload
                        </button>
                        <button type="button" class="btn-secondary" onclick="printQrReceipt()">
                            <i class="fas fa-print"></i> Print Slip
                        </button>
                    </div>

                </div>

                <div id="output-placeholder" style="padding:60px 20px; text-align:center; color:var(--text-muted);">
                    <i class="fas fa-qrcode" style="font-size:64px; opacity:0.2; margin-bottom:16px;"></i>
                    <h3 style="font-size:16px; margin-bottom:6px; color:var(--text-main);">No Dynamic QR Generated Yet</h3>
                    <p style="font-size:13px;">Upload or paste your static eSewa QR code on the left and enter an amount to generate the dynamic QR code.</p>
                </div>
            </div>

        </div>
    </main>

    <!-- App Script -->
    <script src="assets/js/app.js"></script>

    <script>
        // Auto-load sample QR on first visit for instant demo
        document.addEventListener('DOMContentLoaded', () => {
            loadSampleQr('esewa');
        });
    </script>

</body>
</html>
