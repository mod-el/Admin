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

	.admin-page {
		border-left: solid #F3F3FF 1px;
		padding: 5px;
		margin-bottom: 5px;
	}

	.admin-page-form {
		white-space: nowrap;
	}
</style>

<script>
	function configPages(idx) {
		lightbox('<form action="" onsubmit="saveAdminPages(\'' + idx + '\'); return false"><div id="cont-pages-0"></div><div style="text-align: center; padding: 20px 0"><input type="submit" value="Salva" /></div></form>');

		let pages = document.configForm[idx + '-pages'].value;
		if (pages) {
			pages = JSON.parse(pages);
			if (!pages)
				pages = [];
		} else {
			pages = [];
		}

		fillPagesCont(pages, 0);
	}

	function fillPagesCont(pages, idx, lvl) {
		if (typeof lvl === 'undefined')
			lvl = 0;

		let cont = document.getElementById('cont-pages-' + idx);

		let div = document.createElement('div');
		div.className = 'admin-page';
		div.style.paddingLeft = (lvl * 25) + 'px';
		div.innerHTML = '<a href="#" onclick="var idx = addAdminPage(this.parentNode.parentNode, {}, ' + lvl + ', \'' + idx + '\'); fillPagesCont([], idx, ' + (lvl + 1) + '); return false">[ + ] Add page</a>';
		cont.appendChild(div);

		pages.forEach(function (p, i) {
			let new_idx = idx + '-' + i;

			addAdminPage(cont, p, lvl, idx, new_idx);

			let sub = [];
			if (typeof p['sub'] !== 'undefined')
				sub = p['sub'];
			fillPagesCont(sub, new_idx, lvl + 1);
		});
	}

	function addAdminPage(cont, p, lvl, parent_idx, idx) {
		if (typeof idx === 'undefined') {
			var i = 0;
			while (document.getElementById('cont-pages-' + parent_idx + '-' + i)) {
				i++;
			}
			idx = parent_idx + '-' + i;
		}

		let div = document.createElement('div');
		div.className = 'admin-page';
		div.id = 'page-' + idx;
		div.style.paddingLeft = (lvl * 25) + 'px';
		div.innerHTML = `<div class="admin-page-form">
            [<a href="#" onclick="if(confirm('Are you sure?')) deleteAdminPage('` + idx + `'); return false"> x </a>]
            <input type="text" name="name" data-parent="` + parent_idx + `" data-idx="` + idx + `" />
            Rule: <input type="text" name="rule" data-parent="` + parent_idx + `" data-idx="` + idx + `" />
            Page: <select name="page" data-parent="` + parent_idx + `" data-idx="` + idx + `"></select>
            Visualizer: <select name="visualizer" data-parent="` + parent_idx + `" data-idx="` + idx + `"></select>
            Mobile Visualizer: <select name="mobile-visualizer" data-parent="` + parent_idx + `" data-idx="` + idx + `"></select>
            Direct element: <input type="number" name="direct" data-parent="` + parent_idx + `" data-idx="` + idx + `" style="width: 50px" />
            <input type="checkbox" name="hidden" id="hidden-` + parent_idx + `-` + idx + `" data-parent="` + parent_idx + `" data-idx="` + idx + `" />
            <label for="hidden-` + parent_idx + `-` + idx + `">Hidden</label>
        </div>
        <div id="cont-pages-` + idx + `"></div>`;
		cont.insertBefore(div, cont.lastChild);

		let pageSelect = div.querySelector('select[name="page"]');
		pageSelect.innerHTML = document.getElementById('page-prototype').innerHTML;

		let visualizerSelect = div.querySelector('select[name="visualizer"]');
		visualizerSelect.innerHTML = document.getElementById('visualizer-prototype').innerHTML;

		let mobileVisualizerSelect = div.querySelector('select[name="mobile-visualizer"]');
		mobileVisualizerSelect.innerHTML = document.getElementById('visualizer-prototype').innerHTML;

		if (typeof p['name'] !== 'undefined')
			div.querySelector('input[name="name"]').value = p['name'];
		if (typeof p['rule'] !== 'undefined')
			div.querySelector('input[name="rule"]').value = p['rule'];
		if (typeof p['page'] !== 'undefined') {
			Array.from(pageSelect.options).some((option, idx) => {
				if (option.value == p['page']) {
					pageSelect.selectedIndex = idx;
					return true;
				}
				return false;
			});
		}
		if (typeof p['hidden'] !== 'undefined' && p['hidden'])
			div.querySelector('input[name="hidden"]').checked = true;
		if (typeof p['direct'] !== 'undefined')
			div.querySelector('input[name="direct"]').value = p['direct'];

		if (typeof p['visualizer'] === 'undefined' || !p['visualizer'])
			p['visualizer'] = 'Table';
		if (typeof p['mobile-visualizer'] === 'undefined' || !p['mobile-visualizer'])
			p['mobile-visualizer'] = 'Table';

		Array.from(visualizerSelect.options).some((option, idx) => {
			if (option.value == p['visualizer']) {
				visualizerSelect.selectedIndex = idx;
				return true;
			}
			return false;
		});
		Array.from(mobileVisualizerSelect.options).some((option, idx) => {
			if (option.value == p['mobile-visualizer']) {
				mobileVisualizerSelect.selectedIndex = idx;
				return true;
			}
			return false;
		});

		return idx;
	}

	function deleteAdminPage(idx) {
		let page = document.getElementById('page-' + idx);
		page.parentNode.removeChild(page);
	}

	function saveAdminPages(idx) {
		let pages = collectPages(0);
		document.configForm[idx + '-pages'].value = JSON.stringify(pages);
		closeLightbox();
	}

	function collectPages(idx) {
		let pages = [];
		document.querySelectorAll('input[data-parent="' + idx + '"][name="name"]').forEach(function (field) {
			let page = {
				'name': field.value
			};
			let ruleField = document.querySelector('input[data-idx="' + field.getAttribute('data-idx') + '"][name="rule"]');
			if (ruleField.value)
				page['rule'] = ruleField.value;

			let pageField = document.querySelector('select[data-idx="' + field.getAttribute('data-idx') + '"][name="page"]');
			if (pageField.selectedIndex)
				page['page'] = pageField.options[pageField.selectedIndex].value;

			let visualizerField = document.querySelector('select[data-idx="' + field.getAttribute('data-idx') + '"][name="visualizer"]');
			if (visualizerField.selectedIndex)
				page['visualizer'] = visualizerField.options[visualizerField.selectedIndex].value;

			let mobileVisualizerField = document.querySelector('select[data-idx="' + field.getAttribute('data-idx') + '"][name="mobile-visualizer"]');
			if (mobileVisualizerField.selectedIndex)
				page['mobile-visualizer'] = mobileVisualizerField.options[mobileVisualizerField.selectedIndex].value;

			let directField = document.querySelector('input[data-idx="' + field.getAttribute('data-idx') + '"][name="direct"]');
			if (directField.value)
				page['direct'] = directField.value;

			let hiddenField = document.querySelector('input[data-idx="' + field.getAttribute('data-idx') + '"][name="hidden"]');
			page['hidden'] = hiddenField.checked;

			let subPages = collectPages(field.getAttribute('data-idx'));
			if (subPages)
				page['sub'] = subPages;

			pages.push(page);
		});
		return pages;
	}
</script>

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

	<table>
		<tr style="color: #2693FF">
			<td>
				Delete?
			</td>
			<td>
				Path
			</td>
			<td>
				Users Table
			</td>
			<td>
				Users Element
			</td>
			<td>
				Users Admin Page
			</td>
			<td>
				ModEl managed
			</td>
			<td>
			</td>
		</tr>
		<?php
		foreach (($config['url'] ?? []) as $idx => $url) {
			?>
			<tr>
				<td>
					<input type="checkbox" name="delete-<?= $idx ?>" value="1"/>
				</td>
				<td>
					<input type="text" name="<?= $idx ?>-path" value="<?= entities($url['path']) ?>"/>
				</td>
				<td>
					<input type="text" name="<?= $idx ?>-table" value="<?= entities($url['table']) ?>"/>
				</td>
				<td>
					<input type="text" name="<?= $idx ?>-element" value="<?= entities($url['element'] ?? '') ?>"/>
				</td>
				<td>
					<input type="text" name="<?= $idx ?>-admin-page" value="<?= entities($url['admin-page'] ?? '') ?>"/>
				</td>
				<td>
					<input type="checkbox" name="<?= $idx ?>-model-managed"<?= ($url['model-managed'] ?? true) ? ' checked' : '' ?> value="1"/>
				</td>
				<td>
					[<a href="#" onclick="configPages('<?= $idx ?>'); return false"> config pages </a>]
					<input type="hidden" name="<?= $idx ?>-pages" value="<?= entities(json_encode($url['pages'], JSON_PRETTY_PRINT)) ?>"/>
				</td>
			</tr>
			<?php
		}
		?>
		<tr>
			<td>
				New:
			</td>
			<td>
				<input type="text" name="path"/>
			</td>
			<td>
				<input type="text" name="table"/>
			</td>
			<td>
				<input type="text" name="element"/>
			</td>
			<td>
				<input type="text" name="admin-page"/>
			</td>
		</tr>
	</table>

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