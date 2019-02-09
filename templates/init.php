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

	<hr/>

	<?php
	if (empty($config['url'])) {
		?>
		<hr/>
		<table>
			<tr>
				<td>
					Path<br/> <input type="text" name="path" value="admin"/>
				</td>
				<td>
					Users Table<br/> <input type="text" name="table" value="admin_users"/>
				</td>
			</tr>
			<tr>
				<td class="label"></td>
				<td class="field">
					<input type="checkbox" name="make-users-table" id="utenti" checked/>
					<label for="utenti">Create users table</label>
				</td>
			</tr>

			<tr>
				<td class="label"></td>
				<td class="field">
					<input type="checkbox" name="make-account" id="account" checked/>
					<label for="account">Create first account</label>
				</td>
			</tr>

			<?php
			$defaults = [
				'username' => 'admin',
				'password' => 'admin',
			];
			?>
			<tr>
				<td class="label">Username</td>
				<td class="field">
					<input type="text" name="username" value="<?= $defaults['username'] ?>"/>
				</td>
			</tr>
			<tr>
				<td class="label">Password</td>
				<td class="field">
					<input type="password" name="password" value="<?= $defaults['password'] ?>"/>
				</td>
			</tr>
			<tr>
				<td class="label">Repeat password</td>
				<td class="field">
					<input type="password" name="repassword" value="<?= $defaults['password'] ?>"/>
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

<div style="display: none">
	<select id="page-prototype">
		<option value=""></option>
		<?php
		$pages = [];

		$pagesGroups = \Model\Core\Autoloader::getFilesByType('AdminPage');
		foreach ($pagesGroups as $module => $modulePages) {
			foreach ($modulePages as $page => $pageFullName)
				$pages[] = $page;
		}
		sort($pages);

		foreach ($pages as $page) {
			?>
			<option value="<?= entities($page) ?>"><?= entities($page) ?></option>
			<?php
		}
		?>
	</select>

	<select id="visualizer-prototype">
		<option value=""></option>
		<?php
		$visualizers = [];

		$visualizersGroups = \Model\Core\Autoloader::getFilesByType('DataVisualizer');
		foreach ($visualizersGroups as $module => $moduleVisualizers) {
			foreach ($moduleVisualizers as $visualizer => $visualizerFullName)
				$visualizers[] = $visualizer;
		}
		sort($visualizers);

		foreach ($visualizers as $visualizer) {
			?>
			<option value="<?= entities($visualizer) ?>"><?= entities($visualizer) ?></option>
			<?php
		}
		?>
	</select>
</div>