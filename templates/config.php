<style>
    .label{
        width: 50%;
        text-align: right;
        padding-right: 10px;
        padding-bottom: 5px;
        padding-top: 5px;
    }

    .field{
        text-align: left;
        padding-bottom: 5px;
        padding-top: 5px;
    }

    td{
        padding: 5px;
    }

    .admin-page{
        border-left: solid #F3F3FF 1px;
        padding: 5px;
        margin-bottom: 5px;
    }

    .admin-page-form{
        white-space: nowrap;
    }
</style>

<script>
    function configPages(idx){
		lightbox('<form action="" onsubmit="saveAdminPages(\''+idx+'\'); return false"><div id="cont-pages-0"></div><div style="text-align: center; padding: 20px 0"><input type="submit" value="Salva" /></div></form>');

		var pages = document.configForm[idx+'-pages'].value;
		if(pages){
			pages = JSON.parse(pages);
			if(!pages)
				pages = [];
		}else{
			pages = [];
        }

        fillPagesCont(pages, 0);
    }

    function fillPagesCont(pages, idx, lvl){
    	if(typeof lvl==='undefined')
			lvl = 0;

		var cont = document.getElementById('cont-pages-'+idx);

		var div = document.createElement('div');
		div.className = 'admin-page';
		div.style.paddingLeft = (lvl*25)+'px';
		div.innerHTML = '<a href="#" onclick="var idx = addAdminPage(this.parentNode.parentNode, {}, '+lvl+', \''+idx+'\'); fillPagesCont([], idx, '+(lvl+1)+'); return false">[ + ] Add page</a>';
		cont.appendChild(div);

		pages.forEach(function(p, i){
			var new_idx = idx+'-'+i;

			addAdminPage(cont, p, lvl, idx, new_idx);

			var sub = [];
			if(typeof p['sub']!=='undefined')
				sub = p['sub'];
			fillPagesCont(sub, new_idx, lvl+1);
		});
    }

    function addAdminPage(cont, p, lvl, parent_idx, idx){
    	if(typeof idx==='undefined'){
    		var i = 0;
    		while(document.getElementById('cont-pages-'+parent_idx+'-'+i)){
    			i++;
            }
            idx = parent_idx+'-'+i;
        }

		var div = document.createElement('div');
		div.className = 'admin-page';
		div.id = 'page-'+idx;
		div.style.paddingLeft = (lvl*25)+'px';
		div.innerHTML = '<div class="admin-page-form">[<a href="#" onclick="if(confirm(\'Are you sure?\')) deleteAdminPage(\''+idx+'\'); return false"> x </a>] <input type="text" name="name" data-parent="'+parent_idx+'" data-idx="'+idx+'" /> Rule: <input type="text" name="rule" data-parent="'+parent_idx+'" data-idx="'+idx+'" /> Controller: <input type="text" name="controller" data-parent="'+parent_idx+'" data-idx="'+idx+'" /></div><div id="cont-pages-'+idx+'"></div>';
		cont.insertBefore(div, cont.lastChild);

		if(typeof p['name']!=='undefined')
			div.querySelector('input[name="name"]').value = p['name'];
		if(typeof p['rule']!=='undefined')
			div.querySelector('input[name="rule"]').value = p['rule'];
		if(typeof p['controller']!=='undefined')
			div.querySelector('input[name="controller"]').value = p['controller'];

		return idx;
    }

    function deleteAdminPage(idx){
    	var page = document.getElementById('page-'+idx);
		page.parentNode.removeChild(page);
    }

    function saveAdminPages(idx){
        var pages = collectPages(0);
		document.configForm[idx+'-pages'].value = JSON.stringify(pages);
		closeLightbox();
    }

    function collectPages(idx){
    	var pages = [];
		document.querySelectorAll('input[data-parent="'+idx+'"][name="name"]').forEach(function(field){
            var page = {
            	'name': field.value
            };
			var ruleField = document.querySelector('input[data-idx="'+field.getAttribute('data-idx')+'"][name="rule"]');
			if(ruleField.value)
				page['rule'] = ruleField.value;
			var controllerField = document.querySelector('input[data-idx="'+field.getAttribute('data-idx')+'"][name="controller"]');
			if(controllerField.value)
				page['controller'] = controllerField.value;

			var subPages = collectPages(field.getAttribute('data-idx'));
			if(subPages)
				page['sub'] = subPages;

			pages.push(page);
        });
		return pages;
    }
</script>

<h2>Admin settings</h2>

<form action="" method="post" name="configForm">
	<?php $this->model->_CSRF->csrfInput(); ?>
    <hr />
	<?php
	$template = $this->options['config']['template'];
	$hideMenu = $this->options['config']['hide-menu'];
	$dateFormat = $this->options['config']['dateFormat'];
	$priceFormat = $this->options['config']['priceFormat'];
	$stringaLogin1 = $this->options['config']['stringaLogin1'];
	$stringaLogin2 = $this->options['config']['stringaLogin2'];
	?>
    <table>
        <tr>
            <td>Template module:</td>
            <td>
                <select name="template">
                    <option value=""></option>
					<?php
                    $templates = $this->options['config-class']->searchTemplates();
					foreach($templates as $t=>$t_name){
                        ?><option value="<?=entities($t)?>"<?=$t==$template ? ' selected' : ''?>><?=entities($t_name)?></option><?php
					}
					?>
                </select>
            </td>
            <td>Left menu:</td>
            <td>
                <select name="hide-menu">
                    <option value="always"<?=$hideMenu=='always' ? ' selected': ''?>>Always</option>
                    <option value="mobile"<?=$hideMenu=='mobile' ? ' selected': ''?>>Only mobile</option>
                    <option value="never"<?=$hideMenu=='never' ? ' selected': ''?>>Never</option>
                </select>
            </td>
        </tr>
        <tr>
            <td>Dates format:</td>
            <td><input name="dateFormat" value="<?=entities($dateFormat)?>" /></td>
            <td>Login phrase 1:</td>
            <td><input name="stringaLogin1" value="<?=entities($stringaLogin1)?>" /></td>
        </tr>
        <tr>
            <td>Currencies format:</td>
            <td>
                <select name="priceFormat">
                    <option value="vd"<?=$template=='vd' ? ' selected': ''?>>1.234,00&euro;</option>
                    <option value="vp"<?=$template=='vp' ? ' selected': ''?>>&euro; 1.234,00</option>
                    <option value="pd"<?=$template=='pd' ? ' selected': ''?>>1234.00&euro;</option>
                    <option value="pp"<?=$template=='pp' ? ' selected': ''?>>&euro; 1234.00</option>
                </select>
            </td>
            <td>Login phrase 2:</td>
            <td><input name="stringaLogin2" value="<?=entities($stringaLogin2)?>" /></td>
        </tr>
    </table>

    <hr />

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
        </tr>
		<?php
		foreach($this->options['config']['url'] as $idx=>$url){
			if(!is_array($url))
				$url = array('path'=>$url, 'table'=>'utenti_admin');
			?>
            <tr>
                <td>
                    <input type="checkbox" name="delete-<?=$idx?>" value="1" />
                </td>
                <td>
                    <input type="text" name="<?=$idx?>-path" value="<?=entities($url['path'])?>" />
                </td>
                <td>
                    <input type="text" name="<?=$idx?>-table" value="<?=entities($url['table'])?>" />
                </td>
                <td>
                    [<a href="#" onclick="configPages('<?=$idx?>'); return false"> config pages </a>]
                    <input type="hidden" name="<?=$idx?>-pages" value="<?=entities(json_encode($url['pages'], JSON_PRETTY_PRINT))?>" />
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
                <input type="text" name="path" />
            </td>
            <td>
                <input type="text" name="table" />
            </td>
        </tr>
    </table>

    <p>
        <input type="submit" value="Save" />
    </p>
</form>