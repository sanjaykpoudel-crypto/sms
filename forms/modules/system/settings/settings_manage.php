<?php
$db = db();
$info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
$settings = [];
foreach($info as $row) {
    $settings[$row['meta_field']] = $row['meta_value'];
}

// Mocking chk_flashdata for now if needed, or just remove those blocks
?>

<style>

	img#cimg2{
		max-height: 200px;
		width: 100%;
		object-fit: contain;
		border-radius: 8px;
		border: 1px solid #ddd;
	}
</style>

<div class="ns-card">
	<form action="" id="system-frm">
		<div class="ns-card-header">
			<h2 class="ns-card-title">System Settings</h2>
			<div class="ns-card-tools">
				<button class="ns-btn ns-btn-primary" type="submit">Save Changes</button>
			</div>
		</div>
		<div class="ns-card-body">
			<div id="msg"></div>
			


			<div class="ns-form-section">
				<h3 class="ns-form-section-title">Localization</h3>
				<div class="ns-form-row">
					<div class="ns-form-group">
						<label class="ns-label">System Language</label>
						<select name="language" class="ns-input">
							<option value="en" <?php echo ($settings['language'] ?? '') == 'en' ? 'selected' : '' ?>>English</option>
							<option value="ne" <?php echo ($settings['language'] ?? '') == 'ne' ? 'selected' : '' ?>>Nepali (नेपाली)</option>
							<option value="hi" <?php echo ($settings['language'] ?? '') == 'hi' ? 'selected' : '' ?>>Hindi (हिन्दी)</option>
						</select>
					</div>
					<div class="ns-form-group">
						<label class="ns-label">System Font</label>
						<select name="system_font" class="ns-input">
							<option value="'Inter', sans-serif" <?php echo ($settings['system_font'] ?? '') == "'Inter', sans-serif" ? 'selected' : '' ?>>Inter (Modern)</option>
							<option value="'Roboto', sans-serif" <?php echo ($settings['system_font'] ?? '') == "'Roboto', sans-serif" ? 'selected' : '' ?>>Roboto</option>
							<option value="'Open Sans', sans-serif" <?php echo ($settings['system_font'] ?? '') == "'Open Sans', sans-serif" ? 'selected' : '' ?>>Open Sans</option>
							<option value="'Segoe UI', sans-serif" <?php echo ($settings['system_font'] ?? '') == "'Segoe UI', sans-serif" ? 'selected' : '' ?>>Segoe UI</option>
						</select>
					</div>
				</div>
			</div>

			<div class="ns-form-section">
				<h3 class="ns-form-section-title">Printing Preferences</h3>
				<div class="ns-form-row">

					<div class="ns-form-group">
						<label class="ns-label">Header Columns</label>
						<select name="print_header_cols" class="ns-input">
							<option value="2" <?php echo ($settings['print_header_cols'] ?? '') == 2 ? 'selected' : '' ?>>2 Columns</option>
							<option value="4" <?php echo ($settings['print_header_cols'] ?? '') == 4 ? 'selected' : '' ?>>4 Columns</option>
						</select>
					</div>
					<div class="ns-form-group">
						<label class="ns-label">Remarks Position</label>
						<select name="print_remarks_pos" class="ns-input">
							<option value="above" <?php echo ($settings['print_remarks_pos'] ?? '') == 'above' ? 'selected' : '' ?>>Above Item Table</option>
							<option value="below" <?php echo ($settings['print_remarks_pos'] ?? '') == 'below' ? 'selected' : '' ?>>Below Item Table</option>
						</select>
					</div>
				</div>
			</div>

			<div class="ns-form-section">
				<h3 class="ns-form-section-title">Server & Environment</h3>
				<div class="ns-form-row">
					<div class="ns-form-group">
						<label class="ns-label">Git Executable Path</label>
						<input type="text" class="ns-input" name="git_path" value="<?php echo $settings['git_path'] ?? '' ?>">
					</div>
					<div class="ns-form-group">
						<label class="ns-label">MySQL Bin Directory</label>
						<input type="text" class="ns-input" name="mysql_bin" value="<?php echo $settings['mysql_bin'] ?? '' ?>">
					</div>
				</div>
			</div>

			<div class="ns-form-section">
				<h3 class="ns-form-section-title">Login Screen Visuals</h3>
				<div class="ns-form-row">
					<div class="ns-form-group" style="flex: 1;">
						<label class="ns-label">Cover / Background Image</label>
						<input type="file" name="cover" onchange="displayImg2(this,$(this))">
						<div class="mt-2" style="border: 1px solid #eee; border-radius: 8px; padding: 10px; background: #fdfdfd; margin-top: 10px;">
							<?php $cover_src = (!empty($settings['cover']) && file_exists(dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . $settings['cover'])) ? $settings['cover'] . '?v=' . time() : 'assets/img/no-image.png'; ?>
							<img src="<?php echo $cover_src; ?>" alt="" id="cimg2" class="img-fluid" style="max-height: 200px; width: 100%; object-fit: contain;" onerror="this.src='assets/img/no-image.png'">
						</div>
					</div>

				</div>
			</div>
		</div>
	</form>
</div>

<script>

	function displayImg2(input,_this) {
	    if (input.files && input.files[0]) {
	        var reader = new FileReader();
	        reader.onload = function (e) {
	        	$('#cimg2').attr('src', e.target.result);
	        }
	        reader.readAsDataURL(input.files[0]);
	    }
	}
	$(document).ready(function(){
		$('#system-frm').submit(function(e){
			e.preventDefault()
			const formData = new FormData($(this)[0]);
			
			$.ajax({
				url: 'api/system_settings.php',
				data: formData,
				cache: false,
				contentType: false,
				processData: false,
				method: 'POST',
				success: function(resp){
					try {
						const data = JSON.parse(resp);
						if(data.status === 'success'){
							alert('Settings updated successfully');
							location.reload();
						} else {
							alert('Error: ' + data.message);
						}
					} catch(e) {
						alert('An error occurred while parsing the response.');
					}
				}
			})
		})
	})
</script>