<div class="flex-fields">
	<div style="padding-top: 12px">
		Profilo<br/>
		<?php $form['profile']->render(); ?>
	</div>
	<div style="padding-top: 12px">
		Utente<br/>
		<?php $form['user']->render(); ?>
	</div>
	<div style="padding-top: 12px">
		Pagina<br/>
		<?php $form['page']->render(); ?>
	</div>
	<div style="padding-top: 12px">
		Sottopagina<br/>
		<?php $form['subpage']->render(); ?>
	</div>
	<div>
		<?php $form['C']->render(); ?><br/>
		<?php $form['C_special']->render(); ?>
	</div>
	<div>
		<?php $form['R']->render(); ?><br/>
		<?php $form['R_special']->render(); ?>
	</div>
	<div>
		<?php $form['U']->render(); ?><br/>
		<?php $form['U_special']->render(); ?>
	</div>
	<div>
		<?php $form['D']->render(); ?><br/>
		<?php $form['D_special']->render(); ?>
	</div>
	<div>
		<?php $form['L']->render(); ?><br/>
		<?php $form['L_special']->render(); ?>
	</div>
</div>