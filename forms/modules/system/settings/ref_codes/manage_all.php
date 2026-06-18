<?php
if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline card-primary">
	<div class="card-header">
		<h3 class="card-title">Manage All Transaction Reference Codes</h3>
		<div class="card-tools">
			<button class="btn btn-flat btn-primary btn-sm" form="manage-all-ref-codes"><span class="fas fa-save"></span> Save All Changes</button>
			<a href="./?page=system/settings/ref_codes" class="btn btn-flat btn-default btn-sm"><span class="fas fa-angle-left"></span> Back to List</a>
		</div>
	</div>
	<div class="card-body">
		<form id="manage-all-ref-codes">
			<div class="container-fluid">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th class="text-center">Type</th>
							<th class="text-center">Prefix</th>
							<th class="text-center">Suffix</th>
							<th class="text-center">Padding</th>
							<th class="text-center">Next #</th>
							<th class="text-center" style="width:150px">Status</th>
						</tr>
					</thead>
					<tbody>
						<?php 
							$qry = $conn->query("SELECT * from `reference_code_settings` order by display_name asc ");
							while($row = $qry->fetch_assoc()):
						?>
						<tr>
							<td>
								<input type="hidden" name="settings[<?php echo $row['id'] ?>][id]" value="<?php echo $row['id'] ?>">
								<b><?php echo $row['display_name'] ?></b>
							</td>
							<td>
								<input type="text" name="settings[<?php echo $row['id'] ?>][prefix]" class="form-control form-control-sm rounded-0" value="<?php echo $row['prefix'] ?>">
							</td>
							<td>
								<input type="text" name="settings[<?php echo $row['id'] ?>][suffix]" class="form-control form-control-sm rounded-0" value="<?php echo $row['suffix'] ?>">
							</td>
							<td>
								<input type="number" name="settings[<?php echo $row['id'] ?>][padding]" class="form-control form-control-sm rounded-0 text-center" value="<?php echo $row['padding'] ?>">
							</td>
							<td>
								<input type="number" name="settings[<?php echo $row['id'] ?>][next_number]" class="form-control form-control-sm rounded-0 text-center" value="<?php echo $row['next_number'] ?>">
							</td>
							<td class="text-center">
								<select name="settings[<?php echo $row['id'] ?>][status]" class="form-control form-control-sm rounded-0" data-no-select2="true">
									<option value="1" <?php echo $row['status'] == 1 ? 'selected' : '' ?>>Active</option>
									<option value="0" <?php echo $row['status'] == 0 ? 'selected' : '' ?>>Inactive</option>
								</select>
							</td>
						</tr>
						<?php endwhile; ?>
					</tbody>
				</table>
			</div>
		</form>
	</div>
</div>
<script>
	$(document).ready(function(){
		$('#manage-all-ref-codes').submit(function(e){
			e.preventDefault();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_bulk_ref_codes",
				method:"POST",
				data:$(this).serialize(),
				dataType:"json",
				error:err=>{
					console.log(err)
					alert_toast("An error occured.",'error');
					end_loader();
				},
				success:function(resp){
					if(typeof resp== 'object' && resp.status == 'success'){
						location.href = "./?page=system/settings/ref_codes";
					}else{
						alert_toast("An error occured.",'error');
						end_loader();
					}
				}
			})
		})
	})
</script>
