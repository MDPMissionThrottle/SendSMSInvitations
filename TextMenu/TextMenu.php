<?php

require __DIR__ . '/twilio-php-master/Twilio/autoload.php';
use Twilio\Rest\Client;

class TextMenu extends PluginBase {
    protected $storage = 'DbStorage';
    static protected $name = 'TextMenu';
    static protected $description = 'Add a menu item to go to the test template page.';
	
	protected $settings = array(
		'twilio_sid' => array(
			'type' => 'string',
			'label' => 'Twilio Account SID',
		),
		'twilio_auth_token' => array(
			'type' => 'string',
			'label' => 'Twilio Authentication Token',
		),
		'twilio_number' => array(
			'type' => 'string',
			'label' => 'Twilio Phone Number',
			'help' => 'e.g. +14769263945, must include + and the country code (e.g. the US country code is 1)'
		),
		'server_url' => array(
			'type' => 'string',
			'label' => 'Server URL',
			'help' => 'e.g. http://mywebsite.com'
		),
		'attribute_number' => array(
			'type' => 'string',
			'label' => 'Attribute Number for phone number in Participant Table',
			'help' => 'The attribute number that corresponds to the phone number field in the Participant Table (e.g. attribute_1)'
		)
	);

    public function init() {
	    $this->subscribe('beforeSurveyBarRender');
    }

    public function beforeSurveyBarRender() {
	$event = $this->getEvent();
	$surveyId = $event->get('surveyId');

    $model = SurveymenuEntries::model();
	$success = $model->restoreDefaults();

	$menuEntryArray = array(
        "name" => "text_invite_menu_entry",
	    "title" => "text_invite_menu_entry_title",
	    "menu_title" => "Send text invitations",
	    "menu_description" => "Open text invitations template and participant select page.",
	    "menu_icon" => "comment",
	    "menu_icon_type" => "fontawesome",
	    "menu_link" => "admin/pluginhelper?sa=sidebody&surveyid=".(string)$surveyId."&plugin=TextMenu&method=actionIndex",
	    "addSurveyId" => false,
	    "addQuestionGroupId" => false,
	    "addQuestionId" => false,
	    "linkExternal" => false,
	    "hideOnSurveyState" => null,
	    "manualParams" => ""
        );

	SurveymenuEntries::staticAddMenuEntry(2, $menuEntryArray);
   }

    public function actionIndex($surveyid) {
        if (!tableExists("{{tokens_{$surveyid}}}")){
            return 'ERROR: No Participants Table';
		}
		$t = array_keys(App()->db->schema->getTable("{{tokens_{$surveyid}}}")->columns);
		$temp = App()->db->createCommand('SELECT * FROM '.(string)App()->db->schema->getTable("{{tokens_{$surveyid}}}")->name)->queryAll();
		
		$header = "<h3>Send text invitations</h3>
			<h5>For information about how to use this plugin, refer to the <a href='https://github.com/MDPMissionThrottle/SendSMSInvitations/blob/master/README.md#sendSurvey'>documentation</a>.</h5><br />
			<script type='text/javascript' charset='utf8' src='https://cdn.datatables.net/1.10.20/js/jquery.dataTables.js'></script>
			<link rel='stylesheet' type='text/css' href='https://cdn.datatables.net/1.10.20/css/jquery.dataTables.css'>

			<script>
			total = ".(string)count($temp).";
			selected = {};
			allParticipants = {};
			</script>
			";

    	$table = "<table id='participants' class='display'>
			<thead>
			<th style='text-align:center'>Select All <input value='' id='selectAll' type='checkbox'></th>
			<th>ID</th>
			<th>First Name</th>
			<th>Last Name</th>
			<th>Phone Number</th>
			</tr>
			</thead>
			<tbody>
			";
		
			foreach ($temp as $key => $value) {	
				$temp[$key][$this->get('attribute_number')] = "+".preg_replace('/[^0-9]/', "", $value[$this->get('attribute_number')]);
				//$temp[$key][$this->get('attribute_number')] = "+" + $temp[$key][$this->get('attribute_number')];
			}


foreach ($temp as $key => $value) {
	if ($value['emailstatus'] != 'OK') continue;
			$table = $table."<tr>
			<td style='text-align:center'><input value='".$value[$this->get('attribute_number')].
				"|".$value['firstname'].
				"|".$value['lastname'].
				"|".$value['token'].
				"' class='selectOne' type='checkbox'></td>
			<td>".$value['tid']."</td>
			<td>".$value['firstname']."</td>
			<td>".$value['lastname']."</td>
			<td>".$value[$this->get('attribute_number')]."</td>
			</tr>
			<script>
				allParticipants['".$value[$this->get('attribute_number')]."'] = '".$value[$this->get('attribute_number')].
                                "|".$value['firstname'].
                                "|".$value['lastname'].
				"|".$value['token']."'
				</script>
			";
		}

		$tableEnd = "</tbody></table>
		<script>
		$('input:checkbox').load(function(event) {
			if (selected[$(event.target).val().split('|')[0]] !== '') {
				$(event.target).prop('checked', 'true');
			}
			else {
				$(event.target).prop('checked', 'false');
			}
		});

		$(document).ready(function() {
		if (performance.getEntriesByType('navigation')[0]['type'] !== 'reload') {
			location.reload();
		}
		$('#participants').DataTable( {
			columnDefs: [ {
				orderable: false,
				targets: 0 } ],
			order: [[ 1, 'asc' ]],
			'drawCallback': function() {
				if(JSON.stringify(selected).length === JSON.stringify(allParticipants).length) {
					console.log('TRUEEEE');
					$('#selectAll').prop('checked', true);	
				}
				$('input:checkbox').not('#selectAll').each(function(index) {
					if (selected.hasOwnProperty( $(this).val().split('|')[0]) ) {
						$(this).prop('checked', true);
					}
					else {
						$(this).prop('checked', false);
					}
				});
			}
		});
		$('form').submit(function(event) {
			if(!Object.values(selected).length) {
				alert('No participants selected.');
				return false;
			}
			var i = 0;
			const numbers = Object.values(selected);
			var wrong = [];
			for (const num of numbers) {
				var temp = num.toString().split('|')[0];
				if (temp.toString().charAt(0) != '+' || (temp.toString().length <= 11 || temp.toString().length >= 15)) {
					wrong.push(temp);
				}
				$(event.target).append('<input type=\'hidden\' name=\'number'+ i.toString() + '\' value=' + num + '>');
				i++;
			}
			if (wrong.length > 0) {
				var output = 'None of the surveys have been sent because the following phone numbers are in the wrong format: ';
				for (var i = 0; i < wrong.length; i++) {
					if (i != 0) output += ', ';
					output += wrong[i];
				}

				alert(output);
				return false;
			}
			console.log($(event.target).html());
			$(event.target).append('<input type=\'hidden\' name=\'count\' value=' + i + '>');
			return true;
			});
		});
	
		$('.selectOne').click(function(event) {
			if ($(event.target).prop('checked')) {
				selected[$(event.target).val().split('|')[0]] = $(event.target).val();
			}
			else {
				delete selected[$(event.target).val().split('|')[0]];
			}
			});

		$('#selectAll').click(function(event) {
			$('input:checkbox').not(this).prop('checked', this.checked);
			if($(this).prop('checked')) {
				selected = allParticipants;	
			}
			else {
				selected = {};
			}
		});
		
		
	</script>
			";

    	$textForm = CHtml::form(App()->api->createUrl('admin/pluginhelper', array('sa' => 'sidebody', 'plugin' => $this->getName(), 'method' => 'sendTexts')).'&surveyid='.$surveyid);

		$textForm = $textForm."
			<label class='control-label'>Message:</label></br>
			<textarea rows='10' cols='70' name='message' style='width:100%; padding:2rem'>
Hello {FIRSTNAME} {LASTNAME},
You have been invited to participate in a survey. 
To participate, please click on the link below.
				
Sincerely, Administrator (your-email@example.net)
----------------------------------------------
Click here to do the survey:
{SURVEYURL}

Click here to opt out of this survey:
{OPTOUTURL}
			</textarea></br>
			<button class='btn btn-default'>Send via Text</button>
			</form>
			";
		return $header.$table.$tableEnd.$textForm;
    }
  
    public function sendTexts($surveyid) {
	
		$account_sid = $this->get('twilio_sid');
		$auth_token = $this->get('twilio_auth_token');
		$twilio_number = $this->get('twilio_number');
		$server_url = $this->get('server_url');

	
		$client = new Client($account_sid, $auth_token);
		for($i=0; $i<=$_POST["count"]-1; $i++){
			$temp = $_POST["message"];
			$temp = str_replace(['{FIRSTNAME}', '{LASTNAME}', '{SURVEYURL}', '{OPTOUTURL}'], [explode('|',$_POST["number".$i])[1], explode('|',$_POST["number".$i])[2], $server_url.$surveyid.'?lang=en&token='.explode('|',$_POST["number".$i])[3], $server_url.'optout/tokens/'.$surveyid.'?langcode=en&token='.explode('|', $_POST["number".$i])[3]], $temp);
				$client->messages->create(
					explode('|', $_POST["number".$i])[0],
					array(
						'from' => $twilio_number,
						'body' => $temp
					)
				);
        }
        return "<div style='text-align:center'><h2>Your survey has been sent.</h2><a class='btn btn-default' href='".App()->api->createUrl('admin/pluginhelper', array('sa' => 'sidebody', 'plugin' => 'TextMenu', 'method' => 'actionIndex')).'&surveyid='.$surveyid."'>Go back to 'Send via Text'</a></div>";
    }
}
