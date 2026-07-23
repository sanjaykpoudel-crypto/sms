<?php
$type = $_GET['type'] ?? '';
$type_labels = [
    'tax' => 'Tax / VAT',
    'currency' => 'Currencies',
    'payment_method' => 'Payment Methods',
    'category' => 'Categories',
    'units' => 'Units',
    'status' => 'Statuses'
];

$db = db();
$items = $db->fetchAll("SELECT * FROM reference_codes ORDER BY name ASC");
?>

<div class="ns-page-header">
    <h1 class="ns-page-title">
        Accounting Lists
        <a href="?page=system/settings/accounting/manage" class="ns-btn ns-btn-primary">
            <i class="fas fa-plus"></i> New Entry
        </a>
    </h1>
    <div class="ns-page-actions" style="margin-left: auto; display: flex; align-items: center; gap: 15px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <label class="ns-label mb-0" style="width: auto; padding-right: 0; font-weight: 600;">Filter Type:</label>
            <select id="type-filter-select" class="ns-select" style="width: 180px;">
                <option value="">-- All Types --</option>
                <?php foreach($type_labels as $val => $lab): ?>
                    <option value="<?php echo $val; ?>"><?php echo $lab; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table" id="accounting-table">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Type</th>
                    <th>Name</th>
                    <th>Code / Symbol</th>
                    <th>Value / Rate</th>
                    <th>Status</th>
                    <th style="width: 80px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($items as $i => $item): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td data-search="<?php echo $item['type']; ?> <?php echo $type_labels[$item['type']] ?? $item['type']; ?>">
                        <span style="font-size: 10px; font-weight: 700; color: #666; background: #eee; padding: 2px 6px; border-radius: 3px; text-transform: uppercase;">
                            <?php echo $type_labels[$item['type']] ?? $item['type']; ?>
                        </span>
                    </td>
                    <td><strong style="color: var(--ns-primary);"><?php echo htmlspecialchars($item['name']); ?></strong></td>
                    <td>
                        <code style="background: #f8f9fa; padding: 2px 4px; border-radius: 3px; border: 1px solid #ddd;">
                            <?php 
                            if($item['type'] == 'currency') echo htmlspecialchars($item['code'] . " (" . $item['symbol'] . ")");
                            else echo htmlspecialchars($item['code']);
                            ?>
                        </code>
                    </td>
                    <td>
                        <span style="font-weight: 600;">
                            <?php 
                            if($item['type'] == 'tax') echo number_format($item['value'], 2) . "%";
                            elseif($item['type'] == 'currency') echo number_format($item['value'], 4);
                            else echo number_format($item['value'], 2);
                            ?>
                        </span>
                    </td>
                    <td>
                        <span style="color: <?php echo $item['is_active'] ? 'var(--ns-success)' : 'var(--ns-danger)'; ?>; font-weight: 600; font-size: 11px;">
                            <i class="fas <?php echo $item['is_active'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                            <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <a href="?page=system/settings/accounting/manage&type=<?php echo $item['type']; ?>&id=<?php echo $item['id']; ?>" class="ns-btn" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    (function() {
        // Function to initialize our custom filter
        function initTypeFilter() {
            var $table = $('#accounting-table');
            var $select = $('#type-filter-select');
            
            // Get DataTables API instance
            var dt = $table.DataTable();
            
            if (!dt) {
                console.warn("DataTable not initialized yet, retrying...");
                setTimeout(initTypeFilter, 200);
                return;
            }

            console.log("DataTable instance found. Attaching filter event.");

            $select.on('change', function() {
                var val = $(this).val();
                console.log("Filter changed to:", val);
                
                if (val === "") {
                    // Clear search on column 1
                    dt.column(1).search('').draw();
                    dt.column(1).visible(true);
                } else {
                    // Search for the type code in column 1 (data-search attribute helps here)
                    // Using regex to match exactly the type code
                    dt.column(1).search(val).draw();
                    dt.column(1).visible(false);
                }
            });

            // Handle initial filter from URL
            var urlParams = new URLSearchParams(window.location.search);
            var typeParam = urlParams.get('type');
            if (typeParam) {
                $select.val(typeParam).trigger('change');
            }
        }

        // Run when DOM is ready, but also wait for DataTables to likely be finished
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", function() {
                setTimeout(initTypeFilter, 800);
            });
        } else {
            setTimeout(initTypeFilter, 800);
        }
    })();
</script>
