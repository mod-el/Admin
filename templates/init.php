<style>
	.label {
		width: 50%;
		text-align: right;
		padding-right: 10px;
		padding-bottom: 5px;
		padding-top: 5px;
	}

	.field {
		text-align: left;
		padding-bottom: 5px;
		padding-top: 5px;
	}

	td {
		padding: 5px;
	}
</style>

<h2>Admin settings</h2>

<form action="" method="post" name="configForm">
	<hr/>
	<table>
		<tr>
			<td>API path:</td>
			<td>
				<input type="text" name="api-path" value="<?= entities($config['api-path'] ?? 'admin-api') ?>"/>
			</td>
		</tr>
	</table>

	<?php
	if (empty($config['url'])) {
		?>
		<hr/>

		<table>
			<tr>
				<td>
					Path<br/>
					<input type="text" name="path" value="admin"/>
				</td>
				<td>
					Users Table<br/>
					<input type="text" name="users-tables-prefix" value="admin_users"/>
				</td>
			</tr>
			<tr>
				<td></td>
				<td>
					<input type="checkbox" name="model-managed-table" id="model-managed-table" checked/> <label for="model-managed-table">Let ModEl manage the users table (uncheck if you wanna use a custom table)</label>
				</td>
			</tr>
		</table>
		<?php
	}
	?>

	<p>
		<input type="submit" value="Save"/>
	</p>
</form>