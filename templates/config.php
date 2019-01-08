<h2>Admin settings</h2>

<form action="" method="post" name="configForm">
	<hr/>
	<table>
		<tr>
			<td>API path:</td>
			<td>
				<input type="text" name="api-path" value="<?= entities($config['api-path'] ?? 'api') ?>"/>
			</td>
		</tr>
	</table>

	<p>
		<input type="submit" value="Save"/>
	</p>
</form>