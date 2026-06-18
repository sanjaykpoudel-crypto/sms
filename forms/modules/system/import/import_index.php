<?php
require_once __DIR__ . '/../../classes/DBConnection.php';
require_once __DIR__ . '/../../classes/Login.php';
require_once __DIR__ . '/../../classes/Master.php';

$login = new Login();
if(!isset($_SESSION['userdata']))
{
    header('location:../../../../index.php');
    exit;
}

$master = new Master();
// If this file is requested directly, redirect into admin wrapper so header/sidebar/footer remain consistent
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    $type = isset($_GET['type']) ? $_GET['type'] : 'items';
    header('Location: ' . base_url . 'admin/?page=import&type=' . urlencode($type));
    exit;
}
?>
<!-- Page fragment for admin wrapper: Bulk Import Data -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0">Bulk Import Data</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="../home.php">Home</a></li>
                    <li class="breadcrumb-item active">Import</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">
                <!-- Items Import -->
                <div class="card import-section">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-box"></i> Import Items</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mt-2" id="itemsAlert" style="display:none;"></div>
                        <div class="progress import-progress mb-3" id="itemsProgress" style="display:none; height: 1.5rem;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="itemsProgressBar" role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <form id="importItemsForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label>Upload CSV File</label>
                                <input type="file" class="form-control" name="items_file" accept=".csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Import Items</button>
                            <a href="javascript:void(0)" onclick="downloadSample('items')" class="btn btn-info">Download Sample CSV</a>
                        </form>
                        <div class="sample-csv mt-3">
                            <strong>CSV Format:</strong><br>
                            <code style="word-break: break-all;">sku,item_name,category,brand,bottle_size_ml,unit_type,units_per_case,cost_price,selling_price,tax_rate,reorder_level,reorder_qty,cogs_account_code,income_account_code,inventory_account_code</code><br>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i>
                                <code>category</code>: spirits, beer, wine, etc. | 
                                <code>unit_type</code>: bottle, case, can, keg | 
                                <code>account_codes</code>: e.g. 5100, 4100, 1200
                            </small>
                        </div>
                        <div id="itemsErrorBtn" style="display:none;" class="mt-2">
                            <a href="javascript:void(0)" onclick="downloadErrors('items')" class="btn btn-warning btn-sm">
                                <i class="fas fa-download"></i> Download Error Report
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Suppliers Import -->
                <div class="card import-section">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-industry"></i> Import Suppliers</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mt-2" id="suppliersAlert" style="display:none;"></div>
                        <div class="progress import-progress mb-3" id="suppliersProgress" style="display:none; height: 1.5rem;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="suppliersProgressBar" role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <form id="importSuppliersForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label>Upload CSV File</label>
                                <input type="file" class="form-control" name="suppliers_file" accept=".csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Import Suppliers</button>
                            <a href="javascript:void(0)" onclick="downloadSample('suppliers')" class="btn btn-info">Download Sample CSV</a>
                        </form>
                        <div class="sample-csv mt-3">
                            <strong>CSV Format:</strong><br>
                            <code style="word-break: break-all;">vendor_code, company_name, contact_name, phone, email, address, pan_number</code><br>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i>
                                <code>vendor_code</code>: e.g. V-001 (optional, auto-updates if exists) &nbsp;|
                                <code>pan_number</code>: Tax registration number (optional)
                            </small>
                        </div>
                        <div id="suppliersErrorBtn" style="display:none;" class="mt-2">
                            <a href="javascript:void(0)" onclick="downloadErrors('suppliers')" class="btn btn-warning btn-sm">
                                <i class="fas fa-download"></i> Download Error Report
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Customers Import -->
                <div class="card import-section">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-users"></i> Import Customers</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mt-2" id="customersAlert" style="display:none;"></div>
                        <div class="progress import-progress mb-3" id="customersProgress" style="display:none; height: 1.5rem;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="customersProgressBar" role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <form id="importCustomersForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label>Upload CSV File</label>
                                <input type="file" class="form-control" name="customers_file" accept=".csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Import Customers</button>
                            <a href="javascript:void(0)" onclick="downloadSample('customers')" class="btn btn-info">Download Sample CSV</a>
                        </form>
                        <div class="sample-csv mt-3">
                            <strong>CSV Format:</strong><br>
                            <code style="word-break: break-all;">customer_code, full_name, customer_type, phone, email, pan_number, credit_limit</code><br>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i>
                                <code>customer_type</code>: retail, wholesale, bar, hotel &nbsp;|
                                <code>credit_limit</code>: 0 for no credit &nbsp;|
                                <code>customer_code</code>: optional (auto-updates if exists)
                            </small>
                        </div>
                        <div id="customersErrorBtn" style="display:none;" class="mt-2">
                            <a href="javascript:void(0)" onclick="downloadErrors('customers')" class="btn btn-warning btn-sm">
                                <i class="fas fa-download"></i> Download Error Report
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Categories Import -->
                <div class="card import-section">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-layer-group"></i> Import Categories</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mt-2" id="categoriesAlert" style="display:none;"></div>
                        <div class="progress import-progress mb-3" id="categoriesProgress" style="display:none; height: 1.5rem;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="categoriesProgressBar" role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <form id="importCategoriesForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label>Upload CSV File</label>
                                <input type="file" class="form-control" name="categories_file" accept=".csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Import Categories</button>
                            <a href="javascript:void(0)" onclick="downloadSample('categories')" class="btn btn-info">Download Sample CSV</a>
                        </form>
                        <div class="sample-csv mt-3">
                            <strong>CSV Format:</strong><br>
                            <code>name, code, description</code><br>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i>
                                <code>code</code>: Short unique identifier (e.g. SPIR, BEER). Auto-generated from name if left blank. &nbsp;|
                                Categories are saved to <strong>Accounting Lists</strong>.
                            </small>
                        </div>
                        <div id="categoriesErrorBtn" style="display:none;" class="mt-2">
                            <a href="javascript:void(0)" onclick="downloadErrors('categories')" class="btn btn-warning btn-sm">
                                <i class="fas fa-download"></i> Download Error Report
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Accounts Import -->
                <div class="card import-section">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-calculator"></i> Import Accounts</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mt-2" id="accountsAlert" style="display:none;"></div>
                        <div class="progress import-progress mb-3" id="accountsProgress" style="display:none; height: 1.5rem;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="accountsProgressBar" role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <form id="importAccountsForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label>Upload CSV File</label>
                                <input type="file" class="form-control" name="accounts_file" accept=".csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Import Accounts</button>
                            <a href="javascript:void(0)" onclick="downloadSample('accounts')" class="btn btn-info">Download Sample CSV</a>
                        </form>
                        <div class="sample-csv mt-3">
                            <strong>CSV Format:</strong><br>
                            <code style="word-break: break-all;">account_code, account_name, account_type, account_subtype, normal_balance</code><br>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i>
                                <code>account_type</code>: asset, liability, equity, income, expense &nbsp;|
                                <code>account_subtype</code>: cash, bank, receivable, payable, inventory, cogs, sales, tax, other &nbsp;|
                                <code>normal_balance</code>: debit or credit
                            </small>
                        </div>
                        <div id="accountsErrorBtn" style="display:none;" class="mt-2">
                            <a href="javascript:void(0)" onclick="downloadErrors('accounts')" class="btn btn-warning btn-sm">
                                <i class="fas fa-download"></i> Download Error Report
                            </a>
                        </div>
                    </div>
                </div>
            </div>

                <!-- Transactions Import -->
                <div class="card import-section">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-exchange-alt"></i> Import Transactions</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mt-2" id="transactionsAlert" style="display:none;"></div>
                        <div class="progress import-progress mb-3" id="transactionsProgress" style="display:none; height: 1.5rem;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="transactionsProgressBar" role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <form id="importTransactionsForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label>Upload CSV File</label>
                                <input type="file" class="form-control" name="transactions_file" accept=".csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Import Transactions</button>
                            <a href="javascript:void(0)" onclick="downloadSample('transactions')" class="btn btn-info">Download Sample CSV</a>
                        </form>
                        <div class="sample-csv mt-3">
                            <strong>CSV Format:</strong><br>
                            type,reference_code,entity,account,total_amount,discount_perc,tax_perc,remarks,transaction_date<br>
                            sale,SALE-0001,Customer 1,Cash,5000,0,0,Sample Sale,2026-02-24<br>
                            purchase,BILL-0001,Supplier 101,Cash,3000,5,12,Sample Purchase,2026-02-24<br>
                            <small class="text-muted"><i class="fas fa-info-circle"></i>
                                <code>type</code>: <strong>sale</strong> or <strong>purchase</strong> &nbsp;|&nbsp;
                                <code>entity</code>: customer name (for sales) or supplier name (for purchases) &nbsp;|&nbsp;
                                <code>account</code>: account name or leave blank
                            </small>
                        </div>
                        <div id="transactionsErrorBtn" style="display:none;" class="mt-2">
                            <a href="javascript:void(0)" onclick="downloadErrors('transactions')" class="btn btn-warning btn-sm">
                                <i class="fas fa-download"></i> Download Error Report
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        </section>

<script>
    // Store errors per import type for downloadable report
    var importErrors = {};

    function downloadErrors(type) {
        var errors = importErrors[type] || [];
        if(!errors.length) return;
        var rows = ['#,Error Message'];
        errors.forEach(function(msg, i) {
            rows.push((i + 1) + ',"' + msg.replace(/"/g, '""') + '"');
        });
        var csv = rows.join('\n');
        var blob = new Blob([csv], { type: 'text/csv' });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'import_errors_' + type + '_' + Date.now() + '.csv';
        a.click();
        window.URL.revokeObjectURL(url);
    }

    function downloadSample(type) {
        let csv = '';
        let filename = '';
        
        if(type === 'items') {
            csv = 'sku,item_name,category,brand,bottle_size_ml,unit_type,units_per_case,cost_price,selling_price,tax_rate,reorder_level,reorder_qty,cogs_account_code,income_account_code,inventory_account_code\n' +
                  'JD-001,Jack Daniels,spirits,Jack Daniels,750,bottle,12,3500,5000,13,10,24,5100,4100,1200\n' +
                  'CB-001,Carlsberg Premium,beer,Carlsberg,650,bottle,12,250,350,13,50,100,5110,4110,1200';
            filename = 'sample_items.csv';
        } else if(type === 'suppliers') {
            csv = 'vendor_code,company_name,contact_name,phone,email,address,pan_number\n' +
                  'V-001,Global Spirits Distributors,Rajesh Sharma,9841000001,info@globalspirits.com,"Lazimpat, Kathmandu",300000001\n' +
                  'V-002,Himalayan Breweries,Sita Thapa,9841000002,sales@himalayanbrew.com,"Pokhara, Nepal",300000002';
            filename = 'sample_suppliers.csv';
        } else if(type === 'customers') {
            csv = 'customer_code,full_name,customer_type,phone,email,pan_number,credit_limit\n' +
                  'C-001,Yeti Lounge Bar,bar,9851000001,accounts@yetilounge.com,600000001,100000\n' +
                  'C-002,Everest View Hotel,hotel,9851000002,purchase@everestview.com,600000002,300000';
            filename = 'sample_customers.csv';
        } else if(type === 'categories') {
            csv = 'name,code,description\n' +
                  'Spirits,SPIR,Whisky Rum and Vodka\n' +
                  'Beer,BEER,Lager Ale and Craft Beer';
            filename = 'sample_categories.csv';
        } else if(type === 'accounts') {
            csv = 'account_code,account_name,account_type,account_subtype,normal_balance\n' +
                  '7100,Staff Welfare,expense,other,debit\n' +
                  '4200,Other Income,income,other,credit';
            filename = 'sample_accounts.csv';
        } else if(type === 'transactions') {
            csv = 'type,reference_code,entity,account,total_amount,discount_perc,tax_perc,remarks,transaction_date\n' +
                  'sale,SALE-0001,Yeti Lounge Bar,Cash,5000,0,13,Sample Sale,2026-05-01\n' +
                  'purchase,BILL-0001,Global Spirits Distributors,Cash,3000,5,13,Sample Purchase,2026-05-02';
            filename = 'sample_transactions.csv';
        }
        
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        window.URL.revokeObjectURL(url);
    }

    function importData(type, fileInput, progressBarId, alertId, formId) {
        const file = $(fileInput)[0].files[0];
        if(!file) return;

        const import_id = Date.now() + Math.floor(Math.random() * 1000);
        
        // Step 1: Validate File
        $(`#${alertId}`).show().html('<i class="fas fa-spinner fa-spin"></i> Checking file...').removeClass('alert-danger alert-success').addClass('alert-info');
        $(`#${progressBarId}`).parent().hide();

        const validateData = new FormData();
        validateData.append('file', file);
        validateData.append('type', type);
        validateData.append('validate_only', 1);

        $.ajax({
            url: 'import/process_import.php',
            method: 'POST',
            data: validateData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(valResponse) {
                if(valResponse.status === 'success') {
                    // Step 2: Proceed with actual import
                    $(`#${alertId}`).html('<i class="fas fa-check"></i> ' + valResponse.message);
                    $(`#${progressBarId}`).parent().show();
                    $(`#${progressBarId}`).css('width', '0%').text('0%');

                    const formData = new FormData();
                    formData.append('file', file);
                    formData.append('type', type);
                    formData.append('import_id', import_id);

                    let progressInterval = setInterval(() => {
                        $.get('import/get_progress.php?id=' + import_id, function(data) {
                            if(data && data.progress !== undefined) {
                                $(`#${progressBarId}`).css('width', data.progress + '%').text(data.progress + '%');
                                if(data.progress >= 100) clearInterval(progressInterval);
                            }
                        });
                    }, 300);

                    $.ajax({
                        url: 'import/process_import.php',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            clearInterval(progressInterval);
                            $(`#${progressBarId}`).css('width', '100%').text('100%');
                            $(`#${alertId}`).show().html(response.message).removeClass('alert-danger alert-info').addClass('alert-success');
                            // Show error download button if there are row errors
                            if(response.errors && response.errors.length > 0) {
                                importErrors[type] = response.errors;
                                $(`#${type}ErrorBtn`).show();
                            } else {
                                $(`#${type}ErrorBtn`).hide();
                            }
                            setTimeout(() => {
                                $(`#${formId}`)[0].reset();
                                $(`#${progressBarId}`).parent().fadeOut();
                            }, 5000);
                        },
                        error: function(xhr) {
                            clearInterval(progressInterval);
                            let msg = "An error occurred during import";
                            try {
                                const response = JSON.parse(xhr.responseText);
                                msg = response.message || msg;
                            } catch(e) {}
                            $(`#${alertId}`).show().html(msg).removeClass('alert-success alert-info').addClass('alert-danger');
                            $(`#${progressBarId}`).parent().hide();
                        }
                    });
                } else {
                    $(`#${alertId}`).show().html('<i class="fas fa-exclamation-triangle"></i> ' + valResponse.message).removeClass('alert-info alert-success').addClass('alert-danger');
                }
            },
            error: function(xhr) {
                let msg = "An error occurred during file check";
                try {
                    const response = JSON.parse(xhr.responseText);
                    msg = response.message || msg;
                } catch(e) {}
                $(`#${alertId}`).show().html('<i class="fas fa-exclamation-triangle"></i> ' + msg).removeClass('alert-info alert-success').addClass('alert-danger');
            }
        });
    }

    $('#importItemsForm').on('submit', function(e) {
        e.preventDefault();
        importData('items', 'input[name="items_file"]', 'itemsProgressBar', 'itemsAlert', 'importItemsForm');
    });

    $('#importSuppliersForm').on('submit', function(e) {
        e.preventDefault();
        importData('suppliers', 'input[name="suppliers_file"]', 'suppliersProgressBar', 'suppliersAlert', 'importSuppliersForm');
    });

    $('#importCustomersForm').on('submit', function(e) {
        e.preventDefault();
        importData('customers', 'input[name="customers_file"]', 'customersProgressBar', 'customersAlert', 'importCustomersForm');
    });

    $('#importCategoriesForm').on('submit', function(e) {
        e.preventDefault();
        importData('categories', 'input[name="categories_file"]', 'categoriesProgressBar', 'categoriesAlert', 'importCategoriesForm');
    });

    $('#importAccountsForm').on('submit', function(e) {
        e.preventDefault();
        importData('accounts', 'input[name="accounts_file"]', 'accountsProgressBar', 'accountsAlert', 'importAccountsForm');
    });

    $('#importTransactionsForm').on('submit', function(e) {
        e.preventDefault();
        importData('transactions', 'input[name="transactions_file"]', 'transactionsProgressBar', 'transactionsAlert', 'importTransactionsForm');
    });
</script>
