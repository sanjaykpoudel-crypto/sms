<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title">List of Transaction Reference Codes</h3>
		<div class="card-tools">
			<a href="./?page=system/settings/ref_codes/manage_all" class="btn btn-flat btn-primary btn-sm"><span class="fas fa-edit"></span> Manage All</a>
		</div>
	</div>
	<div class="card-body">
		<div class="container-fluid">
        <div class="container-fluid">
			<table class="table table-bordered table-striped">
				<colgroup>
					<col width="5%">
					<col width="20%">
					<col width="15%">
					<col width="15%">
					<col width="15%">
					<col width="10%">
					<col width="10%">
					<col width="10%">
				</colgroup>
				<thead>
					<tr>
						<th>#</th>
						<th>Transaction Type</th>
						<th>Prefix</th>
						<th>Suffix</th>
						<th>Padding (Digits)</th>
						<th>Current #</th>
						<th>Next #</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php 
					$i = 1;
						$qry = $conn->query("SELECT * from `reference_code_settings` order by display_name asc ");
						while($row = $qry->fetch_assoc()):
					?>
						<tr>
							<td class="text-center"><?php echo $i++; ?></td>
							<td><?php echo $row['display_name'] ?></td>
							<td><?php echo $row['prefix'] ?></td>
							<td><?php echo $row['suffix'] ?></td>
							<td class="text-center"><?php echo $row['padding'] ?></td>
							<td class="text-center"><?php echo $row['current_number'] ?></td>
							<td class="text-center"><?php echo $row['next_number'] ?></td>
							<td align="center">
								 <button type="button" class="btn btn-flat btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">
				                  		Action
				                    <span class="sr-only">Toggle Dropdown</span>
				                  </button>
				                  <div class="dropdown-menu" role="menu">
				                    <a class="dropdown-item edit_data" href="javascript:void(0)" data-id="<?php echo $row['id'] ?>"><span class="fa fa-edit text-primary"></span> Edit</a>
				                  </div>
							</td>
						</tr>
					<?php endwhile; ?>
				</tbody>
			</table>
		</div>
		</div>
	</div>
</div>
<script>
	$(document).ready(function(){
		$('.edit_data').click(function(){
			uni_modal("<i class='fa fa-edit'></i> Edit Reference Code Details","modules/system/settings/ref_codes/manage.php?id="+$(this).attr('data-id'))
		})
		$('.table').dataTable();
	})
</script>
