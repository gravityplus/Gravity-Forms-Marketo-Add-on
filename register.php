<?php
	global $current_user;
	get_currentuserinfo();
?>

<div class="alert_gray wrap alignleft" style="width:30%; max-width:450px; min-width:250px; padding: 10px 25px 0 25px; margin: 0 15px 15px 0;">

	<h2 style="font-size:2.5em; margin-bottom:.25em;"><?php _e("Get a Marketo Account Today!", "gravity-forms-marketo"); ?></h2>

	<h3 style="margin-bottom:.5em; line-height:1.1"><?php _e("Do you need a Marketo account? Fill out this form and a representative will contact you.", "gravity-forms-marketo"); ?></h3>

	<div id="lpeCDiv_372665" class="lpeCElement new_element"><span class="lpContentsItem formSpan">
	  <div id="socialSignOnHoldingPen" class="cf_widget cf_widget_socialsignon">
	  <script src="https://pages2.marketo.com/js/mktFormSupport.js"></script>

	<script type="text/javascript">
	  var formEdit = false;

	  var socialSignOn = {
	    isEnabled: false,
	    enabledNetworks: [''],
	    cfId: '',
	    codeSnippet: ''
	  };
	</script>

	  </div>
	<style type="text/css">
		form.lpeRegForm ul {font-size: 12px; color: black; font-family: ; } form.lpeRegForm ul input {font-size: 12px; color: black; font-family: ; } form.lpeRegForm ul input[type='text'] {font-size: 12px; color: black; font-family: ; } form.lpeRegForm ul textarea {font-size: 12px; color: black; font-family: ; } form.lpeRegForm ul select {font-size: 12px; color: black; font-family: ; } form.lpeRegForm li {margin-bottom: 10px; } form.lpeRegForm .mktInput {padding-left: 0px; } form.lpeRegForm label {width: 160px; } form.lpeRegForm select {width: 254px; }
		.mktFormMsg { color: red; background: white; line-height: 1.4; font-weight: bold;}
		form input[type='number']::-webkit-outer-spin-button,
		form input[type='number']::-webkit-inner-spin-button {
		    -webkit-appearance: none;
		    margin: 0;
		}
		input.mktFormText,textarea.mktFormTextArea { padding: 4px; font-size: 1.2em;}
	</style><!--[if IE]><style type='text/css'>form.lpeRegForm li {margin-bottom: 8px; } form.lpeRegForm select {width: 156px; } </style><![endif]-->
	<script type="text/javascript">
	var profiling = {
	  isEnabled: false,
	  numberOfProfilingFields: 3,
	  alwaysShowFields: [ 'mktDummyEntry']
	};
	var mktFormLanguage = 'English'
	</script>
	<script type="text/javascript"> function mktoGetForm() {return document.getElementById('mktForm_1243'); }</script>

	<form class="lpeRegForm formNotEmpty" method="post" target="_blank" enctype="application/x-www-form-urlencoded" action="https://micro.marketo.com/post-proxy/index.php?kill_mkt_trk=1" id="mktForm_1243" name="mktForm_1243">
		<div>
			<input name="ReferrerCompany" type="hidden" value="Katz Web Services, Inc." />
			<input name="ReferrerFullName" type="hidden" value="Zack Katz" />
			<input name="ReferrerEmailAddress" type="hidden" value="zack@katzwebservices.com" />
			<input name="ReferrerPhoneNumber" type="hidden" value="970-882-1477" />
			<input name="ReferrerJobTitle" type="hidden" value="Other" />
			<input name="Referrer_Is_Partner" type="hidden" value="No" />
			<input name="Referring_Partner__c" type="hidden" value="None of the Above" />
			<input type="hidden" name="_marketo_comments" value="" />
			<input type="hidden" name="lpId" value="19933" />
			<input type="hidden" name="subId" value="6" />
			<input type="hidden" name="munchkinId" value="561-HYG-937" />
			<input type="hidden" name="kw" value="" />
			<input type="hidden" name="cr" value="" />
			<input type="hidden" name="searchstr" value="" />
			<input type="hidden" name="lpurl" value="<?php echo add_query_arg(array('cr' => '{creative}', 'kw' => '{keyword}')); ?>" />
			<input type="hidden" name="formid" value="1243" />
			<input type="hidden" name="returnURL" value="<?php echo add_query_arg(array()); ?>" />
			<input type="hidden" name="retURL" value="<?php echo add_query_arg(array()); ?>" />
			<input type="hidden" name="returnLPId" value="19839" />
			<input type="hidden" name="_mkt_disp" value="return" />
			<input type="hidden" name="_mkt_trk" value="id:561-HYG-937&amp;token:_mch-marketo.com-1369064234974-10151" />
			<input type="hidden" name="RFCompID" />
			<input type="hidden" name="RFChanged" value="true" />
			<input type="hidden" name="RFSessionID" />
			<input type="hidden" name="ReferralHistory" id="ReferralHistory" value="No" />
			<input type="hidden" name="LeadSource" value="Partner Referral - Qualified by SDR" />
			<input type="hidden" name="Lead_Source_Comments__c" value="Marketo Alliance Referral" />
		</div>
		<ul>
			<li>
				<label for="FirstName"><?php _e('First Name:', 'gravity-forms-marketo'); ?></label>
				<span class="mktInput"><input class="widefat mktFormText mktFormString mktFReq" name="FirstName" id="FirstName" type="text" value="<?php echo isset($current_user->data->first_name) ? esc_html($current_user->data->first_name) : ''; ?>" maxlength="255" /><span class="mktFormMsg error inline"></span></span>
			</li>
			<li>
				<label for="LastName"><?php _e('Last Name:', 'gravity-forms-marketo'); ?></label>
				<span class="mktInput"><input class="widefat mktFormText mktFormString mktFReq" name="LastName" id="LastName" type="text" value="<?php echo isset($current_user->data->last_name) ? esc_html($current_user->data->last_name) : ''; ?>" maxlength="255" /><span class="mktFormMsg error inline"></span></span>
			</li>
			<li>
				<label for="MarketoEmail"><?php _e('Email Address:', 'gravity-forms-marketo'); ?></label>
				<span class="mktInput"><input class="widefat mktFormText mktFormEmail mktFReq" name="Email" id="MarketoEmail" type="text" value="<?php _e(get_bloginfo('admin_email')) ?>" maxlength="255" /><span class="mktFormMsg error inline"></span></span>
			</li>
			<li>
				<label for="MarketoPhone"><?php _e('Phone Number:', 'gravity-forms-marketo'); ?></label>
				<span class="mktInput"><input class="widefat mktFormText mktFormPhone mktFReq" name="Phone" id="MarketoPhone" type="text" value="" maxlength="255" /><span class="mktFormMsg error inline"></span></span>
			</li>
			<li>
				<label for="MarketoCompany"><?php _e('Company Name:', 'gravity-forms-marketo'); ?></label>
				<span class="mktInput"><input class="widefat mktFormText mktFormString mktFReq" name="Company" id="MarketoCompany" type="text" value="<?php echo isset($current_user->data->company) ? esc_html($current_user->data->company) : ''; ?>" maxlength="255" /><div id="displayFrame" class="divDisplayFrame" style="visibility: hidden; border-width: medium; width: 400px; height: 220px; display: table-caption; position: absolute; z-index: 100; background-color: rgb(255, 255, 255); "></div><span class="mktFormMsg error inline"></span></span>
			</li>
			<li>
				<label for="MarketoTitle"><?php _e('Job Title:', 'gravity-forms-marketo'); ?></label>
				<span class="mktInput"><input class="widefat mktFormText mktFormPicklist" name="Title" id="MarketoTitle" type="text" value="<?php echo isset($current_user->data->title) ? esc_html($current_user->data->title) : ''; ?>" maxlength="255" /><span class="mktFormMsg error inline"></span></span>
			</li>
			<li>
				<label for="NumberOfEmployees"><?php _e('Number of Employees in Company:', 'gravity-forms-marketo'); ?></label>
				<span class="mktInput"><input class="widefat mktFormText mktFormInt" name="NumberOfEmployees" id="NumberOfEmployees" type="number" value="" /><span class="mktFormMsg error inline"></span></span>
			</li>
			<li>
				<label for="CRM_System__c"><?php _e('Your CRM System:', 'gravity-forms-marketo'); ?></label>
				<span class="mktInput">
					<select class="mktFormSelect mktFReq" name="CRM_System__c" id="CRM_System__c" size="1">
						<option value="Choose One" selected="selected">Choose One</option>
						<option value="Salesforce">Salesforce</option>
						<option value="Netsuite">Netsuite</option>
						<option value="Oracle">Oracle</option>
						<option value="MS Dynamics">MS Dynamics</option>
						<option value="SugarCRM">SugarCRM</option>
						<option value="Goldmine">Goldmine</option>
						<option value="Sales Logix">Sales Logix</option>
						<option value="ACT!">ACT!</option>
						<option value="Built in House">Built in House</option>
						<option value="SAP">SAP</option>
						<option value="Unknown">Unknown</option>
						<option value="None">None</option>
						<option value="Other">Other</option>
					</select>
					<span class="mktFormMsg error inline"></span>
				</span>
			</li>
			<li>
				<label for="ReferrerNotes"><?php _e('Any Additional Information?', 'gravity-forms-marketo'); ?></label>
				<span class="mktInput"><textarea class="widefat mktFormTextarea mktFormString" name="ReferrerNotes" id="ReferrerNotes" cols="20" rows="2" /></textarea><span class="mktFormMsg error inline"></span></span>
			</li>
			<li>
				<input class="button button-primary button-large" type="submit" value="<?php _e('Get Started', 'gravity-forms-marketo'); ?>" name="submitButton" onclick="formSubmit(document.getElementById(&quot;mktForm_1243&quot;)); return false;" />
			</li>
		</ul>
	</form>

	<script src="https://pages2.marketo.com/js/mktFormSupport.js"></script>
	<script type="text/javascript">
	function formSubmit(elt) {
	  return Mkto.formSubmit(elt);
	}
	function formReset(elt) {
	  return Mkto.formReset(elt);
	}
	</script>
	</span></div>
</div>
