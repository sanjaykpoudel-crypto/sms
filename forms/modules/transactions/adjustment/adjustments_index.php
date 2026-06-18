<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title">Inventory Adjustments</h3>
        <div class="card-tools">
			<a href="javascript:void(0)" id="create_new" class="btn btn-flat btn-primary"><span class="fas fa-plus"></span>  New Adjustment</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
			<table class="table table-bordered table-stripped">
                    <colgroup>
                        <col width="5%">
                        <col width="10%">
                        <col width="20%">
                        <col width="10%">
                        <col width="10%">
                        <col width="10%">
                        <col width="15%">
                        <col width="10%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Item</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th class="text-right">Rate</th>
                            <th class="text-right">Amount</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        $qry = $conn->query("SELECT t.id, t.transaction_date, ti.item_id, ti.quantity, ti.unit_price, (ti.quantity * ti.unit_price) as total_amount, i.name as item_name FROM `transactions` t INNER JOIN `transaction_items` ti ON t.id = ti.transaction_id INNER JOIN `item_list` i ON ti.item_id = i.id WHERE t.`type` = 'adjustment' ORDER BY t.`date_created` DESC");
                        while($row = $qry->fetch_assoc()):
                        ?>
                            <tr>
                                <td class="text-center"><?php echo $i++; ?></td>
                                <td><?php echo date("d-m-Y", strtotime($row['transaction_date'])) ?></td>
                                <td><?php echo $row['item_name'] ?></td>
                                <td class="text-center">
                                    <?php if($row['quantity'] > 0): ?>
                                        <span class="badge badge-success">Addition (+)</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Subtraction (-)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right"><?php echo number_format(abs($row['quantity'])) ?></td>
                                <td class="text-right"><?php echo number_format($row['unit_price'], 2) ?></td>
                                <td class="text-right font-weight-bold"><?php echo number_format($row['total_amount'], 2) ?></td>
                                <td align="center">
                                    <button type="button" class="btn btn-flat btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
                                             Action
                                        <span class="sr-only">Toggle Dropdown</span>
                                    </button>
                                    <div class="dropdown-menu" role="menu">
                                        <a class="dropdown-item" href="./?page=transactions/stock_adjustments/view_adjustment&id=<?php echo $row['id'] ?>"><span class="fa fa-eye text-dark"></span> View</a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item edit_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item delete_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-trash text-danger"></span> Delete</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
		</div>
	</div>
</div>
<script>
	$(document).ready(function(){
		$('#create_new').click(function(){
			uni_modal("New Inventory Adjustment","modules/transactions/stock_adjustments/manage_adjustment.php")
		})
        $('.view_data').click(function(){
			uni_modal("Adjustment Details","modules/transactions/stock_adjustments/view_adjustment.php?id="+$(this).attr('data-id'))
		})
        $('.edit_data').click(function(){
			uni_modal("<i class='fa fa-edit'></i> Update Adjustment","modules/transactions/stock_adjustments/manage_adjustment.php?id="+$(this).attr('data-id'))
		})
        $('.delete_data').click(function(){
			_conf("Are you sure to delete this Adjustment Record permanently?","delete_adjustment",[$(this).attr('data-id')])
		})
		$('.table td,.table th').addClass('py-1 px-2 align-middle')
		$('.table').dataTable();
	})
    function delete_adjustment($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=delete_adjustment",
			method:"POST",
			data:{id: $id},
			dataType:"json",
			error:err=>{
				console.log(err)
				alert_toast("An error occured.",'error');
				end_loader();
			},
			success:function(resp){
				if(typeof resp== 'object' && resp.status == 'success'){
					location.reload();
				}else{
					alert_toast("An error occured.",'error');
					end_loader();
				}
			}
		})
	}
</script>
