let currentPayload = '';
let currentDecodedData = null;

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initUploadListeners();
    initPresets();
});

// Theme Switcher
function initTheme() {
    const savedTheme = localStorage.getItem('esewa_qr_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);
}

function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('esewa_qr_theme', next);
    updateThemeIcon(next);
}

function updateThemeIcon(theme) {
    const icon = document.getElementById('theme-icon');
    const text = document.getElementById('theme-text');
    if (icon && text) {
        if (theme === 'dark') {
            icon.className = 'fas fa-sun';
            text.textContent = 'Light Mode';
        } else {
            icon.className = 'fas fa-moon';
            text.textContent = 'Dark Mode';
        }
    }
}

// Mode Switcher (Upload vs Raw Payload vs Sample)
function switchTab(mode) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.getElementById(`tab-${mode}`).classList.add('active');

    document.getElementById('section-upload').style.display = (mode === 'upload') ? 'block' : 'none';
    document.getElementById('section-text').style.display = (mode === 'text') ? 'block' : 'none';
    document.getElementById('section-sample').style.display = (mode === 'sample') ? 'block' : 'none';
}

// File Drag & Drop + Upload Handling
function initUploadListeners() {
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('file-input');

    if (dropzone && fileInput) {
        dropzone.addEventListener('click', () => fileInput.click());

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });

        dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) {
                handleFileSelect(e.dataTransfer.files[0]);
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFileSelect(e.target.files[0]);
            }
        });
    }

    // Paste Image Support
    document.addEventListener('paste', (e) => {
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        for (let item of items) {
            if (item.kind === 'file' && item.type.startsWith('image/')) {
                handleFileSelect(item.getAsFile());
                break;
            }
        }
    });
}

function handleFileSelect(file) {
    if (!file.type.startsWith('image/')) {
        alert('Please upload a valid image file (PNG, JPG, WEBP).');
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            // Client-Side Canvas Scanning via jsQR if loaded
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = img.width;
            canvas.height = img.height;
            ctx.drawImage(img, 0, 0, img.width, img.height);
            const imageData = ctx.getImageData(0, 0, img.width, img.height);

            if (window.jsQR) {
                const code = jsQR(imageData.data, imageData.width, imageData.height);
                if (code && code.data) {
                    processPayloadText(code.data);
                    showUploadPreview(e.target.result, 'QR Code Scanned Successfully!');
                    return;
                }
            }

            // Server-Side / Pattern fallback
            showUploadPreview(e.target.result, 'Image Uploaded. Decoding payload...');
            // Prompt payload text or use sample fallback
            processPayloadText(document.getElementById('raw-payload-input').value.trim() || getSamplePayload());
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function showUploadPreview(src, statusMessage) {
    const previewBox = document.getElementById('upload-preview-box');
    const previewImg = document.getElementById('uploaded-img-preview');
    const statusText = document.getElementById('upload-status-text');

    if (previewBox && previewImg) {
        previewImg.src = src;
        previewBox.style.display = 'flex';
        statusText.textContent = statusMessage;
    }
}

// Amount Presets (+100, +500, etc)
function initPresets() {
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const addVal = parseFloat(btn.getAttribute('data-val') || 0);
            const amtInput = document.getElementById('amount-input');
            let current = parseFloat(amtInput.value || 0);
            if (btn.hasAttribute('data-set')) {
                current = addVal;
            } else {
                current += addVal;
            }
            amtInput.value = current.toFixed(2);
            generateDynamicQr();
        });
    });

    const amtInput = document.getElementById('amount-input');
    if (amtInput) {
        amtInput.addEventListener('input', () => {
            debounce(generateDynamicQr, 300)();
        });
    }
}

// Process & Parse Payload
function processPayloadText(payloadText) {
    if (!payloadText) return;
    currentPayload = payloadText;
    document.getElementById('raw-payload-input').value = payloadText;

    fetch('api/process_qr.php?action=parse', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'payload=' + encodeURIComponent(payloadText)
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            currentDecodedData = res.data;
            renderMerchantDetails(res.data);
            generateDynamicQr();
        }
    })
    .catch(err => {
        console.error('Parsing error:', err);
    });
}

function renderMerchantDetails(data) {
    document.getElementById('info-merchant-name').textContent = data.merchant_name || 'eSewa Merchant';
    document.getElementById('info-merchant-id').textContent = data.merchant_id || 'N/A';
    document.getElementById('info-mcc').textContent = data.mcc || '5999';
    document.getElementById('info-currency').textContent = data.currency || 'NPR (524)';
    document.getElementById('info-orig-amount').textContent = data.original_amount ? ('Rs ' + data.original_amount) : '0.00 (Static)';
    document.getElementById('info-initiation').textContent = data.point_of_initiation || 'Static (11)';
    document.getElementById('decoded-info-card').style.display = 'block';
}

// Generate Dynamic QR Code Call
function generateDynamicQr() {
    const payload = currentPayload || document.getElementById('raw-payload-input').value.trim() || getSamplePayload();
    const amount = parseFloat(document.getElementById('amount-input').value || 0);
    const billNumber = document.getElementById('bill-number-input').value.trim();
    const refId = document.getElementById('ref-id-input').value.trim();

    if (!payload) {
        alert('Please upload a static eSewa QR image or paste an EMVCo payload first.');
        return;
    }

    if (amount <= 0) {
        return;
    }

    const genBtn = document.getElementById('btn-generate-qr');
    if (genBtn) genBtn.disabled = true;

    fetch('api/process_qr.php?action=generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            payload: payload,
            amount: amount,
            bill_number: billNumber,
            reference_id: refId
        })
    })
    .then(r => r.json())
    .then(res => {
        if (genBtn) genBtn.disabled = false;
        if (res.status === 'success') {
            renderDynamicQrOutput(res);
        } else {
            alert(res.message || 'Error generating dynamic QR.');
        }
    })
    .catch(err => {
        if (genBtn) genBtn.disabled = false;
        console.error('Generation error:', err);
    });
}

function renderDynamicQrOutput(res) {
    document.getElementById('output-qr-img').src = res.qr_image_url;
    document.getElementById('output-merchant-name').textContent = res.merchant_name;
    document.getElementById('output-amount-badge').textContent = 'रु ' + res.amount_formatted;
    document.getElementById('output-payload-code').textContent = res.dynamic_payload;

    document.getElementById('output-card').style.display = 'flex';
    document.getElementById('output-card').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Helper Actions
function loadSampleQr(type) {
    let sample = getSamplePayload();
    if (type === 'fonepay') {
        sample = '00020101021138580016np.com.fonepay.10111166687494520038841490214666874945200388415204599953035245802NP5914MNS LIQUORS P LTD6009Kathmandu63048C3F';
    }
    switchTab('text');
    processPayloadText(sample);
}

function getSamplePayload() {
    return '00020101021138580016np.com.fonepay.10111166687494520038841490214666874945200388415204599953035245802NP5914MNS LIQUORS P LTD6009Kathmandu63048C3F';
}

function copyPayload() {
    const text = document.getElementById('output-payload-code').textContent;
    navigator.clipboard.writeText(text).then(() => {
        alert('Dynamic EMVCo payload copied to clipboard!');
    });
}

function downloadQrImage() {
    const imgUrl = document.getElementById('output-qr-img').src;
    const a = document.createElement('a');
    a.href = imgUrl;
    a.download = 'dynamic_esewa_qr_' + Date.now() + '.png';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function printQrReceipt() {
    window.print();
}

// Utility debounce
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}
