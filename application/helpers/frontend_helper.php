<?php if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
/*
* LimeSurvey
* Copyright (C) 2007-2012 The LimeSurvey Project Team / Carsten Schmitz
* All rights reserved.
* License: GNU/GPL License v2 or later, see LICENSE.php
* LimeSurvey is free software. This version may have been modified pursuant
* to the GNU General Public License, and as distributed it includes or
* is derivative of works licensed under the GNU General Public License or
* other free or open source software licenses.
* See COPYRIGHT.php for copyright notices and details.
*/

// TODO: Why needed?
require_once(Yii::app()->basePath.'/libraries/MersenneTwister.php');

use \LimeSurvey\PluginManager\PluginEvent;

function loadanswers()
{
    Yii::trace('start', 'survey.loadanswers');
    global $surveyid;
    global $thisstep;
    global $clienttoken;


    $scid = Yii::app()->request->getQuery('scid');
    if (Yii::app()->request->getParam('loadall') == "reload") {
        $sLoadName = Yii::app()->request->getParam('loadname');
        $sLoadPass = Yii::app()->request->getParam('loadpass');
        $oCriteria = new CDbCriteria;
        $oCriteria->join = "LEFT JOIN {{saved_control}} ON t.id={{saved_control}}.srid";
        $oCriteria->condition = "{{saved_control}}.sid=:sid";
        $aParams = array(':sid'=>$surveyid);
        if (isset($scid)) {
//Would only come from email : we don't need it ....
            $oCriteria->addCondition("{{saved_control}}.scid=:scid");
            $aParams[':scid'] = $scid;
        }
        $oCriteria->addCondition("{{saved_control}}.identifier=:identifier");
        $aParams[':identifier'] = $sLoadName;

        if (in_array(Yii::app()->db->getDriverName(), array('mssql', 'sqlsrv', 'dblib'))) {
            // To be validated with mssql, think it's not needed
            $oCriteria->addCondition("(CAST({{saved_control}}.access_code as varchar(64))=:md5_code OR CAST({{saved_control}}.access_code as varchar(64))=:sha256_code)");
        } else {
            $oCriteria->addCondition("({{saved_control}}.access_code=:md5_code OR {{saved_control}}.access_code=:sha256_code)");
        }
        $aParams[':md5_code'] = md5($sLoadPass);
        $aParams[':sha256_code'] = hash('sha256', $sLoadPass);
    } elseif (isset($_SESSION['survey_'.$surveyid]['srid'])) {
        $oCriteria = new CDbCriteria;
        $oCriteria->condition = "id=:id";
        $aParams = array(':id'=>$_SESSION['survey_'.$surveyid]['srid']);
    } else {
        return;
    }
    $oCriteria->params = $aParams;
    $oResponses = SurveyDynamic::model($surveyid)->find($oCriteria);
    if (!$oResponses) {
        return false;
    } else {
        //A match has been found. Let's load the values!
        //If this is from an email, build surveysession first
        $_SESSION['survey_'.$surveyid]['LEMtokenResume'] = true;

        // If survey come from reload (GET or POST); some value need to be found on saved_control, not on survey
        if (Yii::app()->request->getParam('loadall') == "reload") {
            $oSavedSurvey = SavedControl::model()->find(
                "sid = :sid AND identifier = :identifier AND (access_code = :access_code OR access_code = :sha256_code)",
                array(
                    ':sid' => $surveyid,
                    ':identifier' => $sLoadName,
                    ':access_code' => md5($sLoadPass),
                    ':sha256_code' => hash('sha256', $sLoadPass)
                )
            );
            // We don't need to control if we have one, because we do the test before
            $_SESSION['survey_'.$surveyid]['scid'] = $oSavedSurvey->scid;
            $_SESSION['survey_'.$surveyid]['step'] = ($oSavedSurvey->saved_thisstep > 1) ? $oSavedSurvey->saved_thisstep : 1;
            $thisstep = $_SESSION['survey_'.$surveyid]['step'] - 1; // deprecated ?
            $_SESSION['survey_'.$surveyid]['srid'] = $oSavedSurvey->srid; // Seems OK without
            $_SESSION['survey_'.$surveyid]['refurl'] = $oSavedSurvey->refurl;
        }

        // Get if survey is been answered
        $submitdate = $oResponses->submitdate;
        $aRow = $oResponses->attributes;
        foreach ($aRow as $column => $value) {
            if ($column == "token") {
                $clienttoken = $value;
                $token = $value;
            } elseif ($column == 'lastpage' && !isset($_SESSION['survey_'.$surveyid]['step'])) {
                if (is_null($submitdate) || $submitdate == "N") {
                    $_SESSION['survey_'.$surveyid]['step'] = ($value > 1 ? $value : 1);
                    $thisstep = $_SESSION['survey_'.$surveyid]['step'] - 1;
                } else {
                    $_SESSION['survey_'.$surveyid]['maxstep'] = ($value > 1 ? $value : 1);
                }
            } elseif ($column == "datestamp") {
                $_SESSION['survey_'.$surveyid]['datestamp'] = $value;
            }
            if ($column == "startdate") {
                $_SESSION['survey_'.$surveyid]['startdate'] = $value;
            } else {
                //Only make session variables for those in insertarray[]
                if (in_array($column, $_SESSION['survey_'.$surveyid]['insertarray']) && isset($_SESSION['survey_'.$surveyid]['fieldmap'][$column])) {

                    if (($_SESSION['survey_'.$surveyid]['fieldmap'][$column]['type'] == 'N' ||
                    $_SESSION['survey_'.$surveyid]['fieldmap'][$column]['type'] == 'K' ||
                    $_SESSION['survey_'.$surveyid]['fieldmap'][$column]['type'] == 'D') && $value == null) {
// For type N,K,D NULL in DB is to be considered as NoAnswer in any case.
                        // We need to set the _SESSION[field] value to '' in order to evaluate conditions.
                        // This is especially important for the deletenonvalue feature,
                        // otherwise we would erase any answer with condition such as EQUALS-NO-ANSWER on such
                        // question types (NKD)
                        $_SESSION['survey_'.$surveyid][$column] = '';
                    } else {
                        $_SESSION['survey_'.$surveyid][$column] = $value;
                    }
                    if (isset($token) && !empty($token)) {
                        $_SESSION['survey_'.$surveyid][$column] = $value;
                    }
                }  // if (in_array(
            }  // else
        } // foreach
        return true;
    }
}


/**
* This function creates the language selector for a particular survey
*
* @param string  $sSelectedLanguage : lang to be selected (forced)
*
* @return array|false               : array of data if more than one language, else false
*/
function getLanguageChangerDatas($sSelectedLanguage = "")
{
    $surveyid = Yii::app()->getConfig('surveyID');
    Yii::app()->loadHelper("surveytranslator");

    $aSurveyLangs = Survey::model()->findByPk($surveyid)->getAllLanguages();

    // return datas only of there are more than one lanagage
    if (count($aSurveyLangs) > 1) {

        $aAllLanguages = getLanguageData(true);
        $aSurveyLangs  = array_intersect_key($aAllLanguages, array_flip($aSurveyLangs)); // Sort languages by their locale name
        $sClass        = "ls-language-changer-item";
        $sAction       = Yii::app()->request->getParam('action', ''); // Different behaviour if preview

        $routeParams   = array(
            "sid"=>$surveyid,
        );

        // retreive the route of url in preview mode
        if (substr($sAction, 0, 7) == 'preview') {
            $routeParams["action"] = $sAction;
            if (intval(Yii::app()->request->getParam('gid', 0))) {
                $routeParams['gid'] = intval(Yii::app()->request->getParam('gid', 0));
            }

            if ($sAction == 'previewquestion' && intval(Yii::app()->request->getParam('gid', 0)) && intval(Yii::app()->request->getParam('qid', 0))) {
                $routeParams['qid'] = intval(Yii::app()->request->getParam('qid', 0));
            }

            if (!is_null(Yii::app()->request->getParam('token'))) {
                $routeParams['token'] = Yii::app()->request->getParam('token');
            }

            // @todo : add other params (for prefilling by URL in preview mode)
            $sClass     .= " ls-no-js-hidden ls-previewmode-language-dropdown ";
            $sTargetURL  = Yii::app()->getController()->createUrl("survey/index", $routeParams);
        } else {
            $sTargetURL = null;
        }

        $aListLang = array();
        foreach ($aSurveyLangs as $sLangCode => $aSurveyLang) {
            $aListLang[$sLangCode] = html_entity_decode($aSurveyLang['nativedescription'], ENT_COMPAT, 'UTF-8');
        }

        $sSelected = ($sSelectedLanguage) ? $sSelectedLanguage : App()->language;

        $languageChangerDatas = array(
            'sSelected' => $sSelected,
            'aListLang' => $aListLang,
            'sClass'    => $sClass,
            'targetUrl' => $sTargetURL,
        );

        return $languageChangerDatas;
    } else {
        return false;
    }

}

/**
 * This function creates the language selector for the public survey index page
 *
 * @param string $sSelectedLanguage The language in which all information is shown
 * @return array|bool
 */
function getLanguageChangerDatasPublicList($sSelectedLanguage)
{
    $aLanguages = getLanguageDataRestricted(true); // Order by native
    if (count($aLanguages) > 1) {
        $sClass = "ls-language-changer-item";
        foreach ($aLanguages as $sLangCode => $aLanguage) {
                    $aListLang[$sLangCode] = html_entity_decode($aLanguage['nativedescription'], ENT_COMPAT, 'UTF-8').' - '.$aLanguage['description'];
        }
        $sSelected = $sSelectedLanguage;

        $languageChangerDatas = array(
            'sSelected' => $sSelected,
            'aListLang' => $aListLang,
            'sClass'    => $sClass,
        );

        return $languageChangerDatas;
    } else {
        return false;
    }
}

/**
 * Construct flash message container
 * Used in templatereplace to replace {FLASHMESSAGE} in startpage.tstpl
 *
 * @return string
 */
function makeFlashMessage() {
    global $surveyid;
    $html = "";

    $language = Yii::app()->getLanguage();
    $originalPrefix = Yii::app()->user->getStateKeyPrefix();
    // Bug in Yii? Getting the state-key prefix changes the locale, so set the language manually after.
    Yii::app()->setLanguage($language);
    Yii::app()->user->setStateKeyPrefix('frontend');

    $mapYiiToBootstrapClass = array(
        'error' => 'danger',
        'success' => 'success',
        'notice' => 'info'
        // no warning in Yii?
    );

    foreach (Yii::app()->user->getFlashes() as $key => $message) {
        $html .= "<div class='alert alert-" . $mapYiiToBootstrapClass[$key] . " alert-dismissible flash-" . $key . "'>" . $message . "</div>\n";
    }

    Yii::app()->user->setStateKeyPrefix($originalPrefix);

    return $html;
}

/**
* checkUploadedFileValidity used in SurveyRuntimeHelper
*/
function checkUploadedFileValidity($surveyid, $move, $backok = null)
{
    global $thisstep;

    $survey = Survey::model()->findByPk($surveyid);


    if (!isset($backok) || $backok != "Y") {
        $fieldmap = createFieldMap($survey, 'full', false, false, $_SESSION['survey_'.$surveyid]['s_lang']);

        if (isset($_POST['fieldnames']) && $_POST['fieldnames'] != "") {
            $fields = explode("|", $_POST['fieldnames']);

            foreach ($fields as $field) {
                if ($fieldmap[$field]['type'] == "|" && !strrpos($fieldmap[$field]['fieldname'], "_filecount")) {
                    $validation = QuestionAttribute::model()->getQuestionAttributes($fieldmap[$field]['qid']);

                    $filecount = 0;

                    $json = $_POST[$field];
                    // if name is blank, its basic, hence check
                    // else, its ajax, don't check, bypass it.

                    if ($json != "" && $json != "[]") {
                        $phparray = json_decode(urldecode($json));
                        if ($phparray[0]->size != "") {
// ajax
                            $filecount = count($phparray);
                        } else {
// basic
                            for ($i = 1; $i <= $validation['max_num_of_files']; $i++) {
                                if (!isset($_FILES[$field."_file_".$i]) || $_FILES[$field."_file_".$i]['name'] == '') {
                                                                    continue;
                                }

                                $filecount++;

                                $file = $_FILES[$field."_file_".$i];

                                // File size validation
                                if ($file['size'] > $validation['max_filesize'] * 1000) {
                                    $filenotvalidated = array();
                                    $filenotvalidated[$field."_file_".$i] = sprintf(gT("Sorry, the uploaded file (%s) is larger than the allowed filesize of %s KB."), $file['size'], $validation['max_filesize']);
                                    $append = true;
                                }

                                // File extension validation
                                $pathinfo = pathinfo(basename($file['name']));
                                $ext = $pathinfo['extension'];

                                $validExtensions = explode(",", $validation['allowed_filetypes']);
                                if (!(in_array($ext, $validExtensions))) {
                                    if (isset($append) && $append) {
                                        $filenotvalidated[$field."_file_".$i] .= sprintf(gT("Sorry, only %s extensions are allowed!"), $validation['allowed_filetypes']);
                                        unset($append);
                                    } else {
                                        $filenotvalidated = array();
                                        $filenotvalidated[$field."_file_".$i] .= sprintf(gT("Sorry, only %s extensions are allowed!"), $validation['allowed_filetypes']);
                                    }
                                }
                            }
                        }
                    } else {
                                            $filecount = 0;
                    }

                    if (isset($validation['min_num_of_files']) && $filecount < $validation['min_num_of_files'] && LimeExpressionManager::QuestionIsRelevant($fieldmap[$field]['qid'])) {
                        $filenotvalidated = array();
                        $filenotvalidated[$field] = gT("The minimum number of files has not been uploaded.");
                    }
                }
            }
        }
        if (isset($filenotvalidated)) {
            if (isset($move) && $move == "moveprev") {
                            $_SESSION['survey_'.$surveyid]['step'] = $thisstep;
            }
            if (isset($move) && $move == "movenext") {
                            $_SESSION['survey_'.$surveyid]['step'] = $thisstep;
            }
            return $filenotvalidated;
        }
    }
    if (!isset($filenotvalidated)) {
            return false;
    } else {
            return $filenotvalidated;
    }
    }

/**
* Takes two single element arrays and adds second to end of first if value exists
* Why not use array_merge($array1,array_filter($array2);
*/
function addtoarray_single($array1, $array2)
{
    //
    if (is_array($array2)) {
        foreach ($array2 as $ar) {
            if ($ar && $ar !== null) {
                $array1[] = $ar;
            }
        }
    }
    return $array1;
}

/**
* Marks a tokens as completed and sends a confirmation email to the participiant.
* If $quotaexit is set to true then the user exited the survey due to a quota
* restriction and the according token is only marked as 'Q'
*
* @param boolean $quotaexit
*/
function submittokens($quotaexit = false)
{
    $surveyid = Yii::app()->getConfig('surveyID');
    if (isset($_SESSION['survey_'.$surveyid]['s_lang'])) {
        $thissurvey = getSurveyInfo($surveyid, $_SESSION['survey_'.$surveyid]['s_lang']);
    } else {
        $thissurvey = getSurveyInfo($surveyid);
    }
    $clienttoken = $_SESSION['survey_'.$surveyid]['token'];

    $emailcharset = Yii::app()->getConfig("emailcharset");
    // Shift the date due to global timeadjust setting
    $today = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig("timeadjust"));

    // check how many uses the token has left
    $token = Token::model($surveyid)->findByAttributes(array('token' => $clienttoken));
    $token->scenario = 'FinalSubmit'; // Do not XSS filter token data

    if ($quotaexit == true) {
        $token->completed = 'Q';
        $token->usesleft--;
    } else {
        if ($token->usesleft <= 1) {
            // Finish the token
            if (isTokenCompletedDatestamped($thissurvey)) {
                $token->completed = $today;
            } else {
                $token->completed = 'Y';
            }
            if (isset($token->participant_id)) {
                $slquery = SurveyLink::model()->find('participant_id = :pid AND survey_id = :sid AND token_id = :tid', array(':pid'=> $token->participant_id, ':sid'=>$surveyid, ':tid'=>$token->tid));
                if ($slquery) {
                    if (isTokenCompletedDatestamped($thissurvey)) {
                        $slquery->date_completed = $today;
                    } else {
                        // Update the survey_links table if necessary, to protect anonymity, use the date_created field date
                        $slquery->date_completed = $slquery->date_created;
                    }
                    $slquery->save();
                }
            }
        }
        $token->usesleft--;
    }
    $token->save();

    if ($quotaexit == false) {
        if ($token && trim(strip_tags($thissurvey['email_confirm'])) != "" && $thissurvey['sendconfirmation'] == "Y") {
            //   if($token->completed == "Y" || $token->completed == $today)
//            {
                $from = "{$thissurvey['adminname']} <{$thissurvey['adminemail']}>";
                $subject = $thissurvey['email_confirm_subj'];

                $aReplacementVars = array();
                $aReplacementVars["ADMINNAME"] = $thissurvey['admin'];
                $aReplacementVars["ADMINEMAIL"] = $thissurvey['adminemail'];
                $aReplacementVars['ADMINEMAIL'] = $thissurvey['adminemail'];
                //Fill with token info, because user can have his information with anonimity control
                $aReplacementVars["FIRSTNAME"] = $token->firstname;
                $aReplacementVars["LASTNAME"] = $token->lastname;
                $aReplacementVars["TOKEN"] = $token->token;
                $aReplacementVars["EMAIL"] = $token->email;
                // added survey url in replacement vars
                $surveylink = Yii::app()->getController()->createAbsoluteUrl("/survey/index/sid/{$surveyid}", array('lang'=>$_SESSION['survey_'.$surveyid]['s_lang'], 'token'=>$token->token));
                $aReplacementVars['SURVEYURL'] = $surveylink;

                $attrfieldnames = getAttributeFieldNames($surveyid);
                foreach ($attrfieldnames as $attr_name) {
                    $aReplacementVars[strtoupper($attr_name)] = $token->$attr_name;
                }

                $redata = array('thissurvey'=>$thissurvey);

                // NOTE: this occurence of template replace should stay here. User from backend could use old replacement keyword
                $subject = templatereplace($subject, $aReplacementVars, $redata, 'email_confirm_subj', false, null, array(), true);

                $subject = html_entity_decode($subject, ENT_QUOTES, $emailcharset);

                if (getEmailFormat($surveyid) == 'html') {
                    $ishtml = true;
                } else {
                    $ishtml = false;
                }

                $message = $thissurvey['email_confirm'];
                //$message=ReplaceFields($message, $fieldsarray, true);
                // NOTE: this occurence of template replace should stay here. User from backend could use old replacement keyword
                $message = templatereplace($message, $aReplacementVars, $redata, 'email_confirm', false, null, array(), true);
                if (!$ishtml) {
                    $message = strip_tags(breakToNewline(html_entity_decode($message, ENT_QUOTES, $emailcharset)));
                } else {
                    $message = html_entity_decode($message, ENT_QUOTES, $emailcharset);
                }

                //Only send confirmation email if there is a valid email address
            $sToAddress = validateEmailAddresses($token->email);
            if ($sToAddress) {
                // #14499: Add first and last name to the "To" of confirmation email
                $to = array($token->firstname." ".$token->lastname." <".$sToAddress[0].">");
                $aAttachments = unserialize($thissurvey['attachments']);

                $aRelevantAttachments = array();
                /*
                 * Iterate through attachments and check them for relevance.
                 */
                if (isset($aAttachments['confirmation'])) {
                    foreach ($aAttachments['confirmation'] as $aAttachment) {
                        $relevance = $aAttachment['relevance'];
                        // If the attachment is relevant it will be added to the mail.
                        if (LimeExpressionManager::ProcessRelevance($relevance) && Yii::app()->is_file($aAttachment['url'],Yii::app()->getConfig('uploaddir').DIRECTORY_SEPARATOR."surveys".DIRECTORY_SEPARATOR.$surveyid,false)) {
                            $aRelevantAttachments[] = $aAttachment['url'];
                        }
                    }
                }
                $event = new PluginEvent('beforeTokenEmail');
                $event->set('survey', $surveyid);
                $event->set('type', 'confirm');
                $event->set('model', 'confirm');
                $event->set('subject', $subject);
                $event->set('to', $to);
                $event->set('body', $message);
                $event->set('from', $from);
                $event->set('bounce', getBounceEmail($surveyid));
                $event->set('token', $token->attributes);
                App()->getPluginManager()->dispatchEvent($event);
                $subject = $event->get('subject');
                $message = $event->get('body');
                $to = $event->get('to');
                $from = $event->get('from');
                $bounce = $event->get('bounce');
                if ($event->get('send', true) != false) {
                    SendEmailMessage($message, $subject, $to, $from, Yii::app()->getConfig("sitename"), $ishtml, $bounce, $aRelevantAttachments);
                }
            }
        //   } else {
                // Leave it to send optional confirmation at closed token
    //          }
        }
    }
}

/**
* Send a submit notification to the email address specified in the notifications tab in the survey settings
*/
function sendSubmitNotifications($surveyid)
{
    // @todo: Remove globals
    global $thissurvey, $maildebug;

    if (trim($thissurvey['adminemail']) == '') {
        return;
    }

    $homeurl = Yii::app()->getController()->createAbsoluteUrl('/admin');

    $sitename = Yii::app()->getConfig("sitename");

    $debug = Yii::app()->getConfig('debug');
    $bIsHTML = ($thissurvey['htmlemail'] == 'Y');

    $aReplacementVars = array();

    if (!isset($_SESSION['survey_'.$surveyid]['srid'])) {
            $srid = null;
    } else {
            $srid = $_SESSION['survey_'.$surveyid]['srid'];
    }
    $aReplacementVars['ADMINNAME'] = $thissurvey['adminname'];
    $aReplacementVars['ADMINEMAIL'] = $thissurvey['adminemail'];
    $aReplacementVars['VIEWRESPONSEURL'] = Yii::app()->getController()->createAbsoluteUrl("/admin/responses/sa/view/surveyid/{$surveyid}/id/{$srid}");
    $aReplacementVars['EDITRESPONSEURL'] = Yii::app()->getController()->createAbsoluteUrl("/admin/dataentry/sa/editdata/subaction/edit/surveyid/{$surveyid}/id/{$srid}");
    $aReplacementVars['STATISTICSURL'] = Yii::app()->getController()->createAbsoluteUrl("/admin/statistics/sa/index/surveyid/{$surveyid}");
    if ($bIsHTML) {
        $aReplacementVars['VIEWRESPONSEURL'] = "<a href='{$aReplacementVars['VIEWRESPONSEURL']}'>{$aReplacementVars['VIEWRESPONSEURL']}</a>";
        $aReplacementVars['EDITRESPONSEURL'] = "<a href='{$aReplacementVars['EDITRESPONSEURL']}'>{$aReplacementVars['EDITRESPONSEURL']}</a>";
        $aReplacementVars['STATISTICSURL'] = "<a href='{$aReplacementVars['STATISTICSURL']}'>{$aReplacementVars['STATISTICSURL']}</a>";
    }
    $aReplacementVars['ANSWERTABLE'] = '';
    $aEmailResponseTo = array();
    $aEmailNotificationTo = array();
    $sResponseData = "";

    if (!empty($thissurvey['emailnotificationto'])) {
        $aRecipient = explode(";", ReplaceFields($thissurvey['emailnotificationto'], array('{ADMINEMAIL}' =>$thissurvey['adminemail']), true));
        foreach ($aRecipient as $sRecipient) {
            $sRecipient = trim($sRecipient);
            if (validateEmailAddress($sRecipient)) {
                $aEmailNotificationTo[] = $sRecipient;
            }
        }
    }

    if (!empty($thissurvey['emailresponseto'])) {
        // there was no token used so lets remove the token field from insertarray
        if (!isset($_SESSION['survey_'.$surveyid]['token']) && $_SESSION['survey_'.$surveyid]['insertarray'][0] == 'token') {
            unset($_SESSION['survey_'.$surveyid]['insertarray'][0]);
        }
        //Make an array of email addresses to send to
        $aRecipient = explode(";", ReplaceFields($thissurvey['emailresponseto'], array('{ADMINEMAIL}' =>$thissurvey['adminemail']), true));
        foreach ($aRecipient as $sRecipient) {
            $sRecipient = trim($sRecipient);
            if (validateEmailAddress($sRecipient)) {
                $aEmailResponseTo[] = $sRecipient;
            }
        }

        $aFullResponseTable = getFullResponseTable($surveyid, $_SESSION['survey_'.$surveyid]['srid'], $_SESSION['survey_'.$surveyid]['s_lang']);
        $ResultTableHTML = "<table class='printouttable' >\n";
        $ResultTableText = "\n\n";
        $oldgid = 0;
        $oldqid = 0;
        Yii::import('application.helpers.viewHelper');
        foreach ($aFullResponseTable as $sFieldname=>$fname) {
            if (substr($sFieldname, 0, 4) == 'gid_') {
                $ResultTableHTML .= "\t<tr class='printanswersgroup'><td colspan='2'>".viewHelper::flatEllipsizeText($fname[0], true, 0)."</td></tr>\n";
                $ResultTableText .= "\n{$fname[0]}\n\n";
            } elseif (substr($sFieldname, 0, 4) == 'qid_') {
                $ResultTableHTML .= "\t<tr class='printanswersquestionhead'><td  colspan='2'>".viewHelper::flatEllipsizeText($fname[0], true, 0)."</td></tr>\n";
                $ResultTableText .= "\n{$fname[0]}\n";
            } else {
                $ResultTableHTML .= "\t<tr class='printanswersquestion'><td>".viewHelper::flatEllipsizeText("{$fname[0]} {$fname[1]}", true, 0)."</td><td class='printanswersanswertext'>".CHtml::encode($fname[2])."</td></tr>\n";
                $ResultTableText .= "     {$fname[0]} {$fname[1]}: {$fname[2]}\n";
            }
        }

        $ResultTableHTML .= "</table>\n";
        $ResultTableText .= "\n\n";
        if ($bIsHTML) {
            $aReplacementVars['ANSWERTABLE'] = $ResultTableHTML;
        } else {
            $aReplacementVars['ANSWERTABLE'] = $ResultTableText;
        }
    }

    $sFrom = $thissurvey['adminname'].' <'.$thissurvey['adminemail'].'>';

    $aAttachments = unserialize($thissurvey['attachments']);

    $aRelevantAttachments = array();
    /*
     * Iterate through attachments and check them for relevance.
     */
    if (isset($aAttachments['admin_notification'])) {
        foreach ($aAttachments['admin_notification'] as $aAttachment) {
            $relevance = $aAttachment['relevance'];
            // If the attachment is relevant it will be added to the mail.
            if (LimeExpressionManager::ProcessRelevance($relevance) && Yii::app()->is_file($aAttachment['url'],Yii::app()->getConfig('uploaddir').DIRECTORY_SEPARATOR."surveys".DIRECTORY_SEPARATOR.$surveyid,false)) {
                $aRelevantAttachments[] = $aAttachment['url'];
            }
        }
    }

    $redata = compact(array_keys(get_defined_vars()));
    if (count($aEmailNotificationTo) > 0) {
        // NOTE: those occurences of template replace should stay here. User from backend could use old replacement keyword
        $sMessage = templatereplace($thissurvey['email_admin_notification'], $aReplacementVars, $redata, 'admin_notification', $thissurvey['anonymized'] == "Y", null, array(), true);
        $sSubject = templatereplace($thissurvey['email_admin_notification_subj'], $aReplacementVars, $redata, 'admin_notification_subj', ($thissurvey['anonymized'] == "Y"), null, array(), true);
        foreach ($aEmailNotificationTo as $sRecipient) {
        if (!SendEmailMessage($sMessage, $sSubject, $sRecipient, $sFrom, $sitename, $bIsHTML, getBounceEmail($surveyid), $aRelevantAttachments)) {
                if ($debug > 0) {
                    echo '<br />Email could not be sent. Reason: '.CHtml::encode($maildebug).'<br/>';
                }
            }
        }
    }

    $aRelevantAttachments = array();
    /*
     * Iterate through attachments and check them for relevance.
     */
    if (isset($aAttachments['admin_detailed_notification'])) {
        foreach ($aAttachments['admin_detailed_notification'] as $aAttachment) {
            $relevance = $aAttachment['relevance'];
            // If the attachment is relevant it will be added to the mail.
            if (LimeExpressionManager::ProcessRelevance($relevance) && Yii::app()->is_file($aAttachment['url'],Yii::app()->getConfig('uploaddir').DIRECTORY_SEPARATOR."surveys".DIRECTORY_SEPARATOR.$surveyid,false)) {
                $aRelevantAttachments[] = $aAttachment['url'];
            }
        }
    }
    if (count($aEmailResponseTo) > 0) {
        // NOTE: those occurences of template replace should stay here. User from backend could use old replacement keyword
        $sMessage = templatereplace($thissurvey['email_admin_responses'], $aReplacementVars, $redata, 'detailed_admin_notification', $thissurvey['anonymized'] == "Y", null, array(), true);
        $sSubject = templatereplace($thissurvey['email_admin_responses_subj'], $aReplacementVars, $redata, 'detailed_admin_notification_subj', $thissurvey['anonymized'] == "Y", null, array(), true);
        foreach ($aEmailResponseTo as $sRecipient) {
        if (!SendEmailMessage($sMessage, $sSubject, $sRecipient, $sFrom, $sitename, $bIsHTML, getBounceEmail($surveyid), $aRelevantAttachments)) {
                if ($debug > 0) {
                    echo '<br />Email could not be sent. Reason: '.CHtml::encode($maildebug).'<br/>';
                }
            }
        }
    }


}

/**
 * submitfailed : used in em_manager_helper.php
 *
 * "Unexpected error"
 *
 * Will send e-mail to adminemail if defined.
 *
 * @param string $errormsg
 * @param string $query  Will be included in sent email
 * @return string Error message
 */
function submitfailed($errormsg = '', $query = null)
{
    global $debug;
    global $thissurvey;
    global $subquery, $surveyid;

    $completed = "<p><span class='fa fa-exclamation-triangle'></span>&nbsp;<strong>"
    . gT("Did Not Save")."</strong></p>"
    . "<p>"
    . gT("An unexpected error has occurred and your responses cannot be saved.")
    . "</p>";
    if ($thissurvey['adminemail']) {
        $completed .= "<p>";
        $completed .= gT("Your responses have not been lost and have been emailed to the survey administrator and will be entered into our database at a later point.");
        $completed .= "</p>";
        if ($debug > 0) {
            $completed .= 'Error message: '.htmlspecialchars($errormsg).'<br />';
        }
        $email = gT("An error occurred saving a response to survey id", "unescaped")." ".$thissurvey['name']." - $surveyid\n\n";
        $email .= gT("DATA TO BE ENTERED", "unescaped").":\n";
        foreach ($_SESSION['survey_'.$surveyid]['insertarray'] as $value) {
            if (isset($_SESSION['survey_'.$surveyid][$value])) {
                $email .= "$value: {$_SESSION['survey_'.$surveyid][$value]}\n";
            } else {
                $email .= "$value: N/A\n";
            }
        }
        $email .= "\n".gT("SQL CODE THAT FAILED", "unescaped").":\n"
        . "$subquery\n\n"
        . ($query ? $query : '')."\n\n"  // In case we have no global subquery, but an argument to the function
        . gT("ERROR MESSAGE", "unescaped").":\n"
        . $errormsg."\n\n";
        SendEmailMessage($email, gT("Error saving results", "unescaped"), $thissurvey['adminemail'], $thissurvey['adminemail'], "LimeSurvey", false, getBounceEmail($surveyid));
    } else {
        $completed .= "<a href='javascript:location.reload()'>".gT("Try to submit again")."</a><br /><br />\n";
        $completed .= $subquery;
    }
    return $completed;
}

/**
 * This function builds all the required session variables when a survey is first started and
 * it loads any answer defaults from command line or from the table defaultvalues
 * It is called from the related format script (group.php, question.php, survey.php)
 * if the survey has just started.
 *
 * @param int $surveyid
 * @param boolean $preview Defaults to false
 * @return void
 */
function buildsurveysession($surveyid, $preview = false)
{
    /// Yii::trace('start', 'survey.buildsurveysession');
    global $clienttoken;
    global $tokensexist;

    $survey = Survey::model()->findByPk($surveyid);

    $preview                          = ($preview) ? $preview : Yii::app()->getConfig('previewmode');
    $sLangCode                        = App()->language;
    $thissurvey                       = getSurveyInfo($surveyid, $sLangCode);
    $oTemplate                        = Template::model()->getInstance('', $surveyid);
    App()->getController()->sTemplate = $oTemplate->sTemplateName; // It's going to be hard to be sure this is used ....
    $sTemplateViewPath                = $oTemplate->viewPath;


    // Reset all the session variables and start again
    resetAllSessionVariables($surveyid);

    // NOTE: All of this is already done in survey controller.
    // We keep it here only for Travis Tested thar are still not using Selenium
    // As soon as the tests are rewrote to use selenium, those lines can be removed
    $lang       = $_SESSION['survey_'.$surveyid]['s_lang'];
    if (empty($lang)){

        // Multi lingual support order : by REQUEST, if not by Token->language else by survey default language

           if (returnGlobal('lang', true)) {
               $language_to_set = returnGlobal('lang', true);
           } elseif (isset($oTokenEntry) && $oTokenEntry) {
               // If survey have token : we have a $oTokenEntry
               // Can use $oTokenEntry = Token::model($surveyid)->findByAttributes(array('token'=>$clienttoken)); if we move on another function : this par don't validate the token validity
               $language_to_set = $oTokenEntry->language;
           } else {
               $language_to_set = $thissurvey['language'];
           }
            // Always SetSurveyLanguage : surveys controller SetSurveyLanguage too, if different : broke survey (#09769)
           SetSurveyLanguage($surveyid, $language_to_set);
    }


    UpdateGroupList($surveyid, $_SESSION['survey_'.$surveyid]['s_lang']);

    $totalquestions               = $survey->countTotalQuestions;
    $iTotalGroupsWithoutQuestions = QuestionGroup::model()->getTotalGroupsWithoutQuestions($surveyid);

    $_SESSION['survey_'.$surveyid]['totalquestions'] = $survey->countInputQuestions;

    // 2. SESSION VARIABLE: totalsteps
    setTotalSteps($surveyid, $thissurvey, $totalquestions);

    // Break out and crash if there are no questions!
    if (($totalquestions == 0 || $iTotalGroupsWithoutQuestions > 0) && !$preview) {
        breakOutAndCrash($sTemplateViewPath, $totalquestions, $iTotalGroupsWithoutQuestions, $thissurvey);
    }

    //Perform a case insensitive natural sort on group name then question title of a multidimensional array
    //    usort($arows, 'groupOrderThenQuestionOrder');

    //3. SESSION VARIABLE - insertarray
    //An array containing information about used to insert the data into the db at the submit stage
    //4. SESSION VARIABLE - fieldarray
    //See rem at end..

    if ($tokensexist == 1 && $clienttoken) {
        $_SESSION['survey_'.$surveyid]['token'] = $clienttoken;
    }

    if ($thissurvey['anonymized'] == "N") {
        $_SESSION['survey_'.$surveyid]['insertarray'][] = "token";
    }

    $fieldmap = $_SESSION['survey_'.$surveyid]['fieldmap'] = createFieldMap($survey, 'full', true, false, $_SESSION['survey_'.$surveyid]['s_lang']);

    // first call to initFieldArray
    initFieldArray($surveyid, $fieldmap);

    // Prefill questions/answers from command line params
    prefillFromCommandLine($surveyid);

    if (isset($_SESSION['survey_'.$surveyid]['fieldarray'])) {
        $_SESSION['survey_'.$surveyid]['fieldarray'] = array_values($_SESSION['survey_'.$surveyid]['fieldarray']);
    }

    //Check if a passthru label and value have been included in the query url
    checkPassthruLabel($surveyid, $preview, $fieldmap);

    Yii::trace('end', 'survey.buildsurveysession');

}

/**
 * Check if a passthru label and value have been included in the query url
 * @param int $surveyid
 * @param boolean $preview
 * @return void
 */
function checkPassthruLabel($surveyid, $preview, $fieldmap)
{
    $oResult = SurveyURLParameter::model()->getParametersForSurvey($surveyid);
    foreach ($oResult->readAll() as $aRow) {
        if (isset($_GET[$aRow['parameter']]) && !$preview) {
            $_SESSION['survey_'.$surveyid]['urlparams'][$aRow['parameter']] = $_GET[$aRow['parameter']];
            if ($aRow['targetqid'] != '') {
                foreach ($fieldmap as $sFieldname=>$aField) {
                    if ($aRow['targetsqid'] != '') {
                        if ($aField['qid'] == $aRow['targetqid'] && $aField['sqid'] == $aRow['targetsqid']) {
                            $_SESSION['survey_'.$surveyid]['startingValues'][$sFieldname] = $_GET[$aRow['parameter']];
                            $_SESSION['survey_'.$surveyid]['startingValues'][$aRow['parameter']] = $_GET[$aRow['parameter']];
                        }
                    } else {
                        if ($aField['qid'] == $aRow['targetqid']) {
                            $_SESSION['survey_'.$surveyid]['startingValues'][$sFieldname] = $_GET[$aRow['parameter']];
                            $_SESSION['survey_'.$surveyid]['startingValues'][$aRow['parameter']] = $_GET[$aRow['parameter']];
                        }
                    }
                }

            }
        }
    }
}

/**
 * Prefill startvalues from command line param
 * @param integer $surveyid
 * @return void
 */
function prefillFromCommandLine($surveyid)
{
    // This keys will never be prefilled
    $reservedGetValues = array(
        'token',
        'sid',
        'gid',
        'qid',
        'lang',
        'newtest',
        'action',
        'seed'
    );

    if (!isset($_SESSION['survey_'.$surveyid]['startingValues'])) {
        $startingValues = array();
    } else {
        $startingValues = $_SESSION['survey_'.$surveyid]['startingValues'];
    }
    if (Yii::app()->getRequest()->getRequestType()=='GET') {
        $getValues = array_diff_key($_GET,array_combine($reservedGetValues, $reservedGetValues));
        if(!empty($getValues)) {
            $qcode2sgqa = array();
            Yii::import('application.helpers.viewHelper');
            foreach ($_SESSION['survey_'.$surveyid]['fieldmap'] as $sgqa => $details) {
                $qcode2sgqa[viewHelper::getFieldCode($details,array('LEMcompat'=>true))] = $sgqa;
            }
            foreach ($getValues as $k=>$v) {
                if (isset($_SESSION['survey_'.$surveyid]['fieldmap'][$k])) {
                    // sXgXqa prefilling
                    $startingValues[$k] = $v;
                } elseif( array_key_exists($k,$qcode2sgqa) ) {
                    // EM code prefilling
                    $startingValues[$qcode2sgqa[$k]] = $v;
                }
            }
        }
    }
    $_SESSION['survey_'.$surveyid]['startingValues'] = $startingValues;
}

/**
 * @param array $fieldmap
 * @param integer $surveyid
 * @return void
 */
function initFieldArray($surveyid, array $fieldmap)
{
    // Reset field array if called more than once (should not happen)
    $_SESSION['survey_'.$surveyid]['fieldarray'] = array();

    foreach ($fieldmap as $key => $field) {

        if (isset($field['qid']) && $field['qid'] != '') {
            $_SESSION['survey_'.$surveyid]['fieldnamesInfo'][$field['fieldname']]   = $field['sid'].'X'.$field['gid'].'X'.$field['qid'];
            $_SESSION['survey_'.$surveyid]['insertarray'][]                         = $field['fieldname'];
            //fieldarray ARRAY CONTENTS -
            //            [0]=questions.qid,
            //            [1]=fieldname,
            //            [2]=questions.title,
            //            [3]=questions.question
            //            [4]=questions.type,
            //            [5]=questions.gid,
            //            [6]=questions.mandatory,
            //            [7]=conditionsexist,
            //            [8]=usedinconditions
            //            [8]=usedinconditions
            //            [9]=used in group.php for question count
            //            [10]=new group id for question in randomization group (GroupbyGroup Mode)

            if (!isset($_SESSION['survey_'.$surveyid]['fieldarray'][$field['sid'].'X'.$field['gid'].'X'.$field['qid']])) {
                //JUST IN CASE : PRECAUTION!
                //following variables are set only if $style=="full" in createFieldMap() in common_helper.
                //so, if $style = "short", set some default values here!
                if (isset($field['title'])) {
                                    $title = $field['title'];
                } else {
                                    $title = "";
                }

                if (isset($field['question'])) {
                                    $question = $field['question'];
                } else {
                                    $question = "";
                }

                if (isset($field['mandatory'])) {
                                    $mandatory = $field['mandatory'];
                } else {
                                    $mandatory = 'N';
                }

                if (isset($field['hasconditions'])) {
                                    $hasconditions = $field['hasconditions'];
                } else {
                                    $hasconditions = 'N';
                }

                if (isset($field['usedinconditions'])) {
                                    $usedinconditions = $field['usedinconditions'];
                } else {
                                    $usedinconditions = 'N';
                }

                $_SESSION['survey_'.$surveyid]['fieldarray'][$field['sid'].'X'.$field['gid'].'X'.$field['qid']] = array($field['qid'],
                $field['sid'].'X'.$field['gid'].'X'.$field['qid'],
                $title,
                $question,
                $field['type'],
                $field['gid'],
                $mandatory,
                $hasconditions,
                $usedinconditions);
            }

            if (isset($field['random_gid'])) {
                $_SESSION['survey_'.$surveyid]['fieldarray'][$field['sid'].'X'.$field['gid'].'X'.$field['qid']][10] = $field['random_gid'];
            }
        }
    }
}


/**
 * Apply randomizationGroup and randomizationQuestion to session fieldmap
 * @param int $surveyid
 * @param boolean $preview
 * @return void
 */
function randomizationGroupsAndQuestions($surveyid, $preview = false, $fieldmap = array())
{
    // Initialize the randomizer. Seed will be stored in response.
    // TODO: rewrite this THE YII WAY !!!! (application/third_party + internal config for namespace + aliases; etc)
    ls\mersenne\setSeed($surveyid);

    $fieldmap = (empty($fieldmap)) ? $_SESSION['survey_'.$surveyid]['fieldmap'] : $fieldmap;

    list($fieldmap, $randomized1) = randomizationGroup($surveyid, $fieldmap, $preview); // Randomization groups for groups
    list($fieldmap, $randomized2) = randomizationQuestion($surveyid, $fieldmap, $preview); // Randomization groups for questions

    $randomized = $randomized1 || $randomized2; ;

    if ($randomized === true) {
        $fieldmap = finalizeRandomization($fieldmap);

        $_SESSION['survey_'.$surveyid]['fieldmap-'.$surveyid.$_SESSION['survey_'.$surveyid]['s_lang']] = $fieldmap;
        $_SESSION['survey_'.$surveyid]['fieldmap-'.$surveyid.'-randMaster']                            = 'fieldmap-'.$surveyid.$_SESSION['survey_'.$surveyid]['s_lang'];
    }

    $_SESSION['survey_'.$surveyid]['fieldmap'] = $fieldmap;

    return $fieldmap;
}

/**
 * Randomization group for groups
 * @param int $surveyid
 * @param array $fieldmap
 * @param boolean $preview
 * @return array ($fieldmap, $randomized)
 */
function randomizationGroup($surveyid, array $fieldmap, $preview)
{
    // Randomization groups for groups
    $aRandomGroups   = array();
    $aGIDCompleteMap = array();

    // First find all groups and their groups IDS
    $criteria = new CDbCriteria;
    $criteria->addColumnCondition(array('sid' => $surveyid, 'language' => $_SESSION['survey_'.$surveyid]['s_lang']));
    $criteria->addCondition("randomization_group != ''");

    $oData = QuestionGroup::model()->findAll($criteria);

    foreach ($oData as $aGroup) {
        $aRandomGroups[$aGroup['randomization_group']][] = $aGroup['gid'];
    }

    // Shuffle each group and create a map for old GID => new GID
    foreach ($aRandomGroups as $sGroupName=>$aGIDs) {
        $aShuffledIDs    = $aGIDs;
        $aShuffledIDs    = ls\mersenne\shuffle($aShuffledIDs);
        $aGIDCompleteMap = $aGIDCompleteMap + array_combine($aGIDs, $aShuffledIDs);
    }

    $_SESSION['survey_'.$surveyid]['groupReMap'] = $aGIDCompleteMap;

    $randomized = false; // So we can trigger reorder once for group and question randomization

    // Now adjust the grouplist
    if (count($aRandomGroups) > 0 && !$preview) {

        $randomized = true; // So we can trigger reorder once for group and question randomization

        // Now adjust the grouplist
        Yii::import('application.helpers.frontend_helper', true); // make sure frontend helper is loaded ???? We are inside frontend_helper..... TODO: check if it can be removed
        UpdateGroupList($surveyid, $_SESSION['survey_'.$surveyid]['s_lang']);
        // ... and the fieldmap

        // First create a fieldmap with GID as key
        foreach ($fieldmap as $aField) {
            if (isset($aField['gid'])) {
                $GroupFieldMap[$aField['gid']][] = $aField;
            } else {
                $GroupFieldMap['other'][] = $aField;
            }
        }

        // swap it
        foreach ($GroupFieldMap as $iOldGid => $fields) {
            $iNewGid = $iOldGid;
            if (isset($aGIDCompleteMap[$iOldGid])) {
                $iNewGid = $aGIDCompleteMap[$iOldGid];
            }
            $newGroupFieldMap[$iNewGid] = $GroupFieldMap[$iNewGid];
        }

        $GroupFieldMap = $newGroupFieldMap;
        // and convert it back to a fieldmap
        unset($fieldmap);

        foreach ($GroupFieldMap as $aGroupFields) {
            foreach ($aGroupFields as $aField) {
                if (isset($aField['fieldname'])) {
                    $fieldmap[$aField['fieldname']] = $aField; // isset() because of the shuffled flag above
                }
            }
        }
    }

    return array($fieldmap, $randomized);
}

/**
 * Randomization group for questions
 * @param int $surveyid
 * @param array $fieldmap
 * @param boolean $preview
 * @return array ($fieldmap, $randomized)
 */
function randomizationQuestion($surveyid, array $fieldmap, $preview)
{
    $randomized   = false;
    $randomGroups = array();

    // Find all defined randomization groups through question attribute values
    // TODO: move the sql queries to a model
    if (in_array(Yii::app()->db->getDriverName(), array('mssql', 'sqlsrv', 'dblib'))) {
        $rgquery = "SELECT attr.qid, CAST(value as varchar(255)) as value FROM {{question_attributes}} as attr right join {{questions}} as quests on attr.qid=quests.qid WHERE attribute='random_group' and CAST(value as varchar(255)) <> '' and sid=$surveyid GROUP BY attr.qid, CAST(value as varchar(255))";
    } else {
        $rgquery = "SELECT attr.qid, value FROM {{question_attributes}} as attr right join {{questions}} as quests on attr.qid=quests.qid WHERE attribute='random_group' and value <> '' and sid=$surveyid GROUP BY attr.qid, value";
    }

    $rgresult = dbExecuteAssoc($rgquery);

    foreach ($rgresult->readAll() as $rgrow) {
        $randomGroups[$rgrow['value']][] = $rgrow['qid']; // Get the question IDs for each randomization group
    }

    // If we have randomization groups set, then lets cycle through each group and
    // replace questions in the group with a randomly chosen one from the same group
    if (count($randomGroups) > 0 && !$preview) {
        $randomized    = true; // So we can trigger reorder once for group and question randomization
        $copyFieldMap  = array();
        $oldQuestOrder = array();
        $newQuestOrder = array();
        $randGroupNames = array();

        foreach ($randomGroups as $key=>$value) {
            $oldQuestOrder[$key] = $randomGroups[$key];
            $newQuestOrder[$key] = $oldQuestOrder[$key];
            // We shuffle the question list to get a random key->qid which will be used to swap from the old key
            $newQuestOrder[$key] = ls\mersenne\shuffle($newQuestOrder[$key]);
            $randGroupNames[] = $key;
        }

        // Loop through the fieldmap and swap each question as they come up
        foreach ($fieldmap as $fieldkey => $fieldval) {
            $found = 0;

            foreach ($randomGroups as $gkey => $gval) {

                // We found a qid that is in the randomization group
                if (isset($fieldval['qid']) && in_array($fieldval['qid'], $oldQuestOrder[$gkey])) {
                    // Get the swapped question
                    $idx = array_search($fieldval['qid'], $oldQuestOrder[$gkey]);

                    foreach ($fieldmap as $key => $field) {

                        if (isset($field['qid']) && $field['qid'] == $newQuestOrder[$gkey][$idx]) {
                            $field['random_gid'] = $fieldval['gid']; // It is possible to swap to another group
                            $copyFieldMap[$key]  = $field;
                        }
                    }
                    $found = 1;
                    break;
                } else {
                    $found = 2;
                }
            }

            if ($found == 2) {
                $copyFieldMap[$fieldkey] = $fieldval;
            }
            reset($randomGroups);
        }
        $fieldmap = $copyFieldMap;
    }

    return array($fieldmap, $randomized);
}

/**
 * Stuff?
 * @param array $fieldmap
 * @return array Fieldmap
 */
function finalizeRandomization($fieldmap)
{
    // reset the sequencing counts
    $gseq = -1;
    $_gid = -1;
    $qseq = -1;
    $_qid = -1;
    $copyFieldMap = array();

    foreach ($fieldmap as $key => $val) {

        if ($val['gid'] != '') {

            if (isset($val['random_gid'])) {
                $gid = $val['random_gid'];
            } else {
                $gid = $val['gid'];
            }

            if ($gid != $_gid) {
                $_gid = $gid;
                ++$gseq;
            }
        }

        if ($val['qid'] != '' && $val['qid'] != $_qid) {
            $_qid = $val['qid'];
            ++$qseq;
        }

        if ($val['gid'] != '' && $val['qid'] != '') {
            $val['groupSeq']    = $gseq;
            $val['questionSeq'] = $qseq;
        }

        $copyFieldMap[$key] = $val;
    }
    return $copyFieldMap;
}

/**
 * Test if token is valid
 * @param array $subscenarios
 * @param array $thissurvey
 * @param array $aEnterTokenData
 * @param string $clienttoken
 * @return string[] ($renderToken, $FlashError)
 */
function testIfTokenIsValid(array $subscenarios, array $thissurvey, array $aEnterTokenData, $clienttoken)
{
    $FlashError = '';
    if (!$subscenarios['tokenValid']) {

        //Check if there is a clienttoken set
        if ((!isset($clienttoken) || $clienttoken == "")) {
            if (isset($thissurvey) && $thissurvey['allowregister'] == "Y") {
                $renderToken = 'register';
            } else {
                $renderToken = 'main';
            }
        } else {
            //token was wrong
            $errorMsg    = gT("The token you have provided is either not valid, or has already been used.");
            $FlashError .= $errorMsg;
            $renderToken = 'main';
        }
    } else {
        $aEnterTokenData['visibleToken'] = $clienttoken;
        $aEnterTokenData['token'] = $clienttoken;
        $renderToken = 'correct';
    }

    return array($renderToken, $FlashError, $aEnterTokenData);
}

/**
 * Returns which way should be rendered
 * @param string $renderToken
 * @param string $renderCaptcha
 * @return string
 */
function getRenderWay($renderToken, $renderCaptcha)
{
    $renderWay = "";
    if ($renderToken !== $renderCaptcha) {
        if ($renderToken === "register") {
            $renderWay = "register";
        }
        if ($renderCaptcha === "correct" || $renderToken === "correct") {
            $renderWay = "main";
        }
        if ($renderCaptcha === "") {
            $renderWay = $renderToken;
        } else if ($renderToken === "") {
            $renderWay = $renderCaptcha;
        }
    } else {
        $renderWay = $renderToken;
    }
    return $renderWay;
}

/**
 * Render token, captcha or register form
 * @param string $renderWay
 * @param array $scenarios
 * @param string $sTemplateViewPath
 * @param array $aEnterTokenData
 * @param int $surveyid
 * @return void
 */
function renderRenderWayForm($renderWay, array $scenarios, $sTemplateViewPath, $aEnterTokenData, $surveyid, $aSurveyInfo=null)
{
    switch ($renderWay) {
        case "main": //Token required, maybe Captcha required
            // Datas for the form
            $aForm                    = array();
            $aForm['sType']           = ($scenarios['tokenRequired']) ? 'token' : 'captcha';
            $aForm['token']           = array_key_exists('token', $aEnterTokenData) ? $aEnterTokenData['token'] : null;
            $aForm['aEnterErrors']    = $aEnterTokenData['aEnterErrors'];
            $aForm['bCaptchaEnabled'] = (isset($aEnterTokenData['bCaptchaEnabled'])) ? $aEnterTokenData['bCaptchaEnabled'] : '';
            if ($aForm['bCaptchaEnabled']) {
                Yii::app()->getController()->createAction('captcha');
            }
            $oSurvey = Survey::model()->findByPk($surveyid);
            if(empty($aSurveyInfo)) {
                $aSurveyInfo  =  getsurveyinfo($surveyid,App()->getLanguage());
            }
            // Rendering layout_user_forms.twig
            $thissurvey                     = $oSurvey->attributes;
            $thissurvey["aForm"]            = $aForm;
            $thissurvey['surveyUrl']        = App()->createUrl("/survey/index", array("sid"=>$surveyid));
            $thissurvey['include_content']  = 'userforms';

            Yii::app()->clientScript->registerScriptFile(Yii::app()->getConfig("generalscripts").'nojs.js', CClientScript::POS_HEAD);
            
            // Language selector
            if ($aSurveyInfo['alanguageChanger']['show']){
                $aSurveyInfo['alanguageChanger']['datas']['targetUrl'] = $thissurvey['surveyUrl'];
            }
            $thissurvey['alanguageChanger'] = $aSurveyInfo['alanguageChanger'];

            $aData['aSurveyInfo'] = $thissurvey;

            $aData['aSurveyInfo'] = array_merge($aSurveyInfo, $aData['aSurveyInfo']);

            Yii::app()->twigRenderer->renderTemplateFromFile("layout_user_forms.twig", $aData, false);
            break;

        case "register": //Register new user
            // Add the event and test if done
            Yii::app()->runController("register/index/sid/{$surveyid}");
            Yii::app()->end();
            break;

        case "correct": //Nothing to hold back, render survey
        default:
            break;
    }
}

/**
 * Resets all session variables for this survey
 * @param int $surveyid
 * @return void
 */
function resetAllSessionVariables($surveyid)
{
    Yii:app()->session->regenerateID(true);
    unset($_SESSION['survey_'.$surveyid]['grouplist']);
    unset($_SESSION['survey_'.$surveyid]['fieldarray']);
    unset($_SESSION['survey_'.$surveyid]['insertarray']);
    unset($_SESSION['survey_'.$surveyid]['fieldnamesInfo']);
    unset($_SESSION['survey_'.$surveyid]['fieldmap-'.$surveyid.'-randMaster']);
    unset($_SESSION['survey_'.$surveyid]['groupReMap']);
    $_SESSION['survey_'.$surveyid]['fieldnamesInfo'] = Array();
}

/**
 * The number of "pages" that will be presented in this survey
 * The number of pages to be presented will differ depending on the survey format
 * Set totalsteps in session
 * @param int $surveyid
 * @param array $thissurvey
 * @param integer $totalquestions
 * @return void
 */
function setTotalSteps($surveyid, array $thissurvey, $totalquestions)
{
    switch ($thissurvey['format']) {
        case "A":
            $_SESSION['survey_'.$surveyid]['totalsteps'] = 1;
            break;

        case "G":
            if (isset($_SESSION['survey_'.$surveyid]['grouplist'])) {
                $_SESSION['survey_'.$surveyid]['totalsteps'] = count($_SESSION['survey_'.$surveyid]['grouplist']);
            }
            break;

        case "S":
            $_SESSION['survey_'.$surveyid]['totalsteps'] = $totalquestions;
    }
}

/**
 * @todo Rename
 * @todo Move HTML to view
 * @param string $sTemplateViewPath
 * @param int $totalquestions
 * @param int $iTotalGroupsWithoutQuestions
 * @param array $thissurvey
 * @return void
 */
function breakOutAndCrash($sTemplateViewPath, $totalquestions, $iTotalGroupsWithoutQuestions, array $thissurvey)
{

    $sTitle = gT("This survey cannot be tested or completed for the following reason(s):");
    $sMessage = '';

    if ($totalquestions == 0) {
        $sMessage = gT("There are no questions in this survey.");
    }

    if ($iTotalGroupsWithoutQuestions > 0) {
        $sMessage = gT("There are empty question groups in this survey - please create at least one question within a question group.");
    }

    renderError($sTitle, $sMessage, $thissurvey, $sTemplateViewPath);
}

/**
 * @param string $sTemplateViewPath
 */
function renderError($sTitle = '', $sMessage, $thissurvey, $sTemplateViewPath)
{
    // Template settings
    $surveyid = $thissurvey['sid'];
    //$oTemplate         = Template::model()->getInstance('', $surveyid);
    //$oTemplate->registerAssets();

    $aError = array();
    $aError['title']      = ($sTitle != '') ? $sTitle : gT("This survey cannot be tested or completed for the following reason(s):");
    $aError['message']    = $sMessage;
    $thissurvey['aError'] = $aError;

    Yii::app()->twigRenderer->renderTemplateFromFile("layout_errors.twig", array('oSurvey'=>Survey::model()->findByPk($surveyid), 'aSurveyInfo'=>$thissurvey), false);
}

/**
 * TODO: call this function from surveyRuntimeHelper
 * TODO: remove surveymover()
 */
function getNavigatorDatas()
{
    global $surveyid, $thissurvey;

    $aNavigator = array();
    $aNavigator['show'] = true;

    $sMoveNext          = "movenext";
    $sMovePrev          = "";
    $iSessionStep       = (isset($_SESSION['survey_'.$surveyid]['step'])) ? $_SESSION['survey_'.$surveyid]['step'] : false;
    $iSessionMaxStep    = (isset($_SESSION['survey_'.$surveyid]['maxstep'])) ? $_SESSION['survey_'.$surveyid]['maxstep'] : false;
    $iSessionTotalSteps = (isset($_SESSION['survey_'.$surveyid]['totalsteps'])) ? $_SESSION['survey_'.$surveyid]['totalsteps'] : false;

    // Count down
    $aNavigator['disabled'] = '';
    if ($thissurvey['navigationdelay'] > 0 && ($iSessionMaxStep !== false && $iSessionMaxStep == $iSessionStep)) {
        $aNavigator['disabled'] = " disabled";
        App()->getClientScript()->registerScriptFile(Yii::app()->getConfig('generalscripts')."/navigator-countdown.js");
        App()->getClientScript()->registerScript('navigator_countdown', "navigator_countdown(".$thissurvey['navigationdelay'].");\n", LSYii_ClientScript::POS_POSTSCRIPT);
    }

    // Previous ?
    if ($thissurvey['format'] != "A" && ($thissurvey['allowprev'] != "N")
        && $iSessionStep
        && !($iSessionStep == 1 && $thissurvey['showwelcome'] == 'N')
        && !Yii::app()->getConfig('previewmode')
    ) {
        $sMovePrev = "moveprev";
    }

    // Submit ?
    if ($iSessionStep && ($iSessionStep == $iSessionTotalSteps)
        || $thissurvey['format'] == 'A'
        ) {
        $sMoveNext = "movesubmit";
    }

    // todo Remove Next if needed (exemple quota show previous only: maybe other, but actually don't use surveymover)
    if (Yii::app()->getConfig('previewmode')) {
        $sMoveNext = "";
    }


    $aNavigator['aMovePrev']['show']  = ($sMovePrev != '');
    $aNavigator['aMoveNext']['show']  = ($sMoveNext != '');
    $aNavigator['aMoveNext']['value'] = $sMoveNext;


    // SAVE BUTTON
    if ($thissurvey['allowsave'] == "Y") {

        App()->getClientScript()->registerScript("activateActionLink", "activateActionLink();\n", LSYii_ClientScript::POS_POSTSCRIPT);

        // Fill some test here, more clear ....
        $bAnonymized                = $thissurvey["anonymized"] == 'Y';
        $bTokenanswerspersistence   = $thissurvey['tokenanswerspersistence'] == 'Y' && tableExists('tokens_'.$surveyid);
        $bAlreadySaved              = isset($_SESSION['survey_'.$surveyid]['scid']);
        $iSessionStep               = (isset($_SESSION['survey_'.$surveyid]['step']) ? $_SESSION['survey_'.$surveyid]['step'] : false);
        $iSessionMaxStep            = (isset($_SESSION['survey_'.$surveyid]['maxstep']) ? $_SESSION['survey_'.$surveyid]['maxstep'] : false);

        // Find out if the user has any saved data
        if ($thissurvey['format'] == 'A') {
            if ((!$bTokenanswerspersistence || $bAnonymized) && !$bAlreadySaved) {
                $aNavigator['load']['show'] = true;
            }
            $aNavigator['save']['show'] = true;
        } elseif (!$iSessionStep) {

            //Welcome page, show load (but not save)
            if ((!$bTokenanswerspersistence || $bAnonymized) && !$bAlreadySaved) {
                $aNavigator['load']['show'] = true;
            }

            if ($thissurvey['showwelcome'] == "N") {
                $aNavigator['save']['show'] = true;
            }
        } elseif ($iSessionMaxStep == 1 && $thissurvey['showwelcome'] == "N") {
            //First page, show LOAD and SAVE
            if ((!$bTokenanswerspersistence || $bAnonymized) && !$bAlreadySaved) {
                $aNavigator['load']['show'] = true;
            }

            $aNavigator['save']['show'] = true;
        } elseif (getMove() != "movelast") {
            // Not on last page or submited survey
            $aNavigator['save']['show'] = true;
        } 
    }

    return $aNavigator;
}

/**
 * Caculate assessement scores
 *
 * @param integer $surveyid
 * @param boolean $onlyCurrent : only current ( ASSESSMENT_CURRENT_TOTAL )
 * @return array
 */
function doAssessment($surveyid, $onlyCurrent = true)
{
    /* Default : show nothing */
    $assessment = array(
        'show' => false,
        'total' => array(
            'show' => false,
        ),
        'subtotal' => array(
            'show' => false,
        ),
        'total_score' => "", // Current total is set to 0 if assessments == "Y", empty string if not.
        'subtotal_score' => array(), // Score by group, used only on endpage currently
    );
    $oSurvey = Survey::model()->findByPk($surveyid);
    if ($oSurvey->assessments != "Y") {
        return array(
            'show'=> false,
            'datas' => $assessment,
            'currentotal' => '',
        );
    }
    $currentLanguage = App()->getLanguage();
    $baselang = $oSurvey->language;
    if (!isset($_SESSION['survey_'.$surveyid]['s_lang'])) {
        /* Then not inside survey … can surely return directly */
        return array(
            'show'=> false,
            'datas' => $assessment,
            'currentotal' => '',
        );
    }
    /* Always count and count only one time … */
    $fieldmap = createFieldMap($oSurvey, "full", false, false, $currentLanguage);
    $total    = 0;
    $groups   = array();
    foreach ($fieldmap as $field) {
        // Init Assessment Value
        $assessmentValue = null;
        if (in_array($field['type'], array('1', 'F', 'H', 'W', 'Z', 'L', '!', 'M', 'O', 'P'))) {
            $fieldmap[$field['fieldname']]['assessment_value'] = 0;
            if (isset($_SESSION['survey_'.$surveyid][$field['fieldname']])) {
                //Multiflexi choice  - result is the assessment attribute value
                if (($field['type'] == "M") || ($field['type'] == "P")) {
                    if ($_SESSION['survey_'.$surveyid][$field['fieldname']] == "Y") {
                        $aAttributes     = QuestionAttribute::model()->getQuestionAttributes($field['qid']);
                        $assessmentValue = (int) $aAttributes['assessment_value'];
                    }
                } else {
                    // Single choice question
                    $oAssessementAnswer = Answer::model()->find(array(
                        'select' => 'code,assessment_value',
                        'condition' => 'qid = :qid and language = :language and code = :code', // Same assessment_value for all language, get primary
                        'params' => array(":qid" => $field['qid'],":language" => $baselang, ":code" => $_SESSION['survey_'.$surveyid][$field['fieldname']])
                    ));
                    if ($oAssessementAnswer) {
                        $assessmentValue    = $oAssessementAnswer->assessment_value;
                    }
                }

                $fieldmap[$field['fieldname']]['assessment_value'] = $assessmentValue;
            }
            $groups[] = $field['gid'];
        }

        // If this is a question (and not a survey field, like ID), save asessment value
        if ($field['qid'] > 0) {
            /**
             * Allow Plugin to update assessment value
             */
            // Prepare Event Info
            $event = new PluginEvent('afterSurveyQuestionAssessment');
            $event->set('surveyId', $surveyid);
            $event->set('lang', $currentLanguage);
            $event->set('gid', $field['gid']);
            $event->set('qid', $field['qid']);

            if (array_key_exists('sqid', $field)) {
                $event->set('sqid', $field['sqid']);
            }

            if (array_key_exists('aid', $field)) {
                $event->set('aid', $field['aid']);
            }

            $event->set('assessmentValue', $assessmentValue);
            if (isset($_SESSION['survey_'.$surveyid][$field['fieldname']])) {
                $event->set('response', $_SESSION['survey_'.$surveyid][$field['fieldname']]);
            }
            // Dispatch Event and Get new assessment value
            App()->getPluginManager()->dispatchEvent($event);
            $updatedAssessmentValue = $event->get('assessmentValue', $assessmentValue);

            /**
             * Save assessment value on the response
             */
            $fieldmap[$field['fieldname']]['assessment_value'] = $updatedAssessmentValue;
            $total = $total + $updatedAssessmentValue;
        }
    }
    $assessment['total_score'] = $total;
    if($onlyCurrent) {
        return array(
            'show'=> false,
            'datas' => $assessment,
            'currentotal' => $total,
        );
    }
    /* count by group */
    $groups = array_unique($groups);
    $subtotal = array();
    foreach ($groups as $group) {
        $grouptotal = 0;
        foreach ($fieldmap as $field) {
            if ($field['gid'] == $group && isset($field['assessment_value'])) {
                if (isset ($_SESSION['survey_'.$surveyid][$field['fieldname']])) {
                    $grouptotal = $grouptotal + $field['assessment_value'];
                }
            }
        }
        $subtotal[$group] = $grouptotal;
    }

    /* Get current assesment (can be only for last page …) */
    $aoAssessements = Assessment::model()->findAll(array(
        'condition' => "sid = :sid and language = :language",
        'order' => 'scope,id', // No real order in assessment, here : group first (why ?) and by creation
        'params' => array(':sid' => $surveyid,':language' => $currentLanguage)
    ));
    if(!empty($aoAssessements)) {
        foreach ($aoAssessements as $oAssessement) {
            if ($oAssessement->scope == "G") {
                /* send only current valid assessments */
                if($oAssessement->minimum <= $subtotal[$oAssessement->gid] && $subtotal[$oAssessement->gid] <= $oAssessement->maximum) {
                    $assessment['group'][$oAssessement->gid][] = array(
                        "name"    => $oAssessement->name,
                        "min"     => $oAssessement->minimum,
                        "max"     => $oAssessement->maximum,
                        "message" => $oAssessement->message
                    );
                }
            } else {
                /* send only current valid assessments */
                if($oAssessement->minimum <= $total && $total <= $oAssessement->maximum) {
                    $assessment['total']['show'] = true;
                    $assessment['total'][] = array(
                        "name"    => $oAssessement->name,
                        "min"     => $oAssessement->minimum,
                        "max"     => $oAssessement->maximum,
                        "message" => $oAssessement->message
                    );
                }
            }
        }

        if (!empty($subtotal) && !empty($assessment['group'])) {
            $assessment['subtotal']['show']  = true;
            $assessment['subtotal']['datas'] = $subtotal;
        }
        $assessment['subtotal_score'] = $subtotal;
        $assessment['total_score']    = $total;
        //$aDatas     = array('total' => $total, 'assessment' => $assessment, 'subtotal' => $subtotal, );
        
        return array(
            'show'=>($assessment['subtotal']['show'] || $assessment['total']['show']),
            'datas' => $assessment,
            'currentotal' => $total,
        );

    }
}


/**
* Update SESSION VARIABLE: grouplist
* A list of groups in this survey, ordered by group name.
* @param string $language
* @param integer $surveyid
*/
function UpdateGroupList($surveyid, $language)
{

    unset ($_SESSION['survey_'.$surveyid]['grouplist']);

    // TODO: replace by group model method
    $query     = "SELECT * FROM {{groups}} WHERE sid=$surveyid AND language='".$language."' ORDER BY group_order";
    $result    = dbExecuteAssoc($query) or safeDie("Couldn't get group list<br />$query<br />"); //Checked
    $groupList = array();

    foreach ($result->readAll() as $row) {
        $group = array(
            'gid'         => $row['gid'],
            'group_name'  => $row['group_name'],
            'description' =>  $row['description']);
        $groupList[] = $group;
        $gidList[$row['gid']] = $group;
    }

    if (!Yii::app()->getConfig('previewmode') && isset($_SESSION['survey_'.$surveyid]['groupReMap']) && count($_SESSION['survey_'.$surveyid]['groupReMap']) > 0) {
        // Now adjust the grouplist
        $groupRemap    = $_SESSION['survey_'.$surveyid]['groupReMap'];
        $groupListCopy = $groupList;

        foreach ($groupList as $gseq => $info) {
            $gid = $info['gid'];
            if (isset($groupRemap[$gid])) {
                $gid = $groupRemap[$gid];
            }
            $groupListCopy[$gseq] = $gidList[$gid];
        }
        $groupList = $groupListCopy;
        }
        $_SESSION['survey_'.$surveyid]['grouplist'] = $groupList;
}

/**
* FieldArray contains all necessary information regarding the questions
* This function is needed to update it in case the survey is switched to another language
* @todo: Make 'fieldarray' obsolete by replacing with EM session info
*/
function updateFieldArray()
{
    global $surveyid;


    if (isset($_SESSION['survey_'.$surveyid]['fieldarray'])) {
        foreach ($_SESSION['survey_'.$surveyid]['fieldarray'] as $key => $value) {
            $questionarray = &$_SESSION['survey_'.$surveyid]['fieldarray'][$key];
            $query = "SELECT title, question FROM {{questions}} WHERE qid=".$questionarray[0]." AND language='".$_SESSION['survey_'.$surveyid]['s_lang']."'";
            $usrow = Yii::app()->db->createCommand($query)->queryRow();
            if ($usrow) {
                $questionarray[2] = $usrow['title'];
                $questionarray[3] = $usrow['question'];
            }
            unset($questionarray);
        }
    }
}

/**
* checkCompletedQuota() returns matched quotas information for the current response
* @param integer $surveyid - Survey identification number
* @param bool $return - set to true to return information, false do the quota
* @return array|void - nested array, Quotas->Members->Fields, includes quota information matched in session.
*/
function checkCompletedQuota($surveyid, $return = false)
{
    /* Check if session is set */
    if (!isset(App()->session['survey_'.$surveyid]['srid'])) {
        return;
    }
    /* Check is Response is already submitted : only when "do" the quota: allow to send information about quota */
    $oResponse = Response::model($surveyid)->findByPk(App()->session['survey_'.$surveyid]['srid']);
    if (!$return && $oResponse && !is_null($oResponse->submitdate)) {
        return;
    }
    // EM call 2 times quotas with 3 lines of php code, then use static.
    static $aMatchedQuotas;
    if (!$aMatchedQuotas) {
        $aMatchedQuotas = array();
        /** @var Quota[] $aQuotas */
        $aQuotas = Quota::model()->findAllByAttributes(array('sid' => $surveyid));
        // if(!$aQuotasInfo || empty($aQuotaInfos)) {
        if (!$aQuotas || empty($aQuotas)) {
            return $aMatchedQuotas;
        }

        // OK, we have some quota, then find if this $_SESSION have some set
        // foreach ($aQuotasInfos as $aQuotaInfo)
        foreach ($aQuotas as $oQuota) {
            // if(!$aQuotaInfo['active']) {
            if (!$oQuota->active) {
                continue;
            }
            // if(count($aQuotaInfo['members'])===0) {
            if (count($oQuota->quotaMembers) === 0) {
                continue;
            }
            $iMatchedAnswers = 0;
            $bPostedField = false;

            ////Create filtering
            // Array of field with quota array value
            $aQuotaFields = array();
            // Array of fieldnames with relevance value : EM fill $_SESSION with default value even is irrelevant (em_manager_helper line 6548)
            $aQuotaRelevantFieldnames = array();
            // To count number of hidden questions
            $aQuotaQid = array();
            //Fill the necessary filter arrays
            foreach ($oQuota->quotaMembers as $oQuotaMember) {
                $aQuotaMember = $oQuotaMember->memberInfo;
                $aQuotaFields[$aQuotaMember['fieldname']][] = $aQuotaMember['value'];
                $aQuotaRelevantFieldnames[$aQuotaMember['fieldname']] = isset($_SESSION['survey_'.$surveyid]['relevanceStatus'][$aQuotaMember['qid']]) && $_SESSION['survey_'.$surveyid]['relevanceStatus'][$aQuotaMember['qid']];
                $aQuotaQid[] = $aQuotaMember['qid'];
            }
            $aQuotaQid = array_unique($aQuotaQid);

            ////Filter
            // For each field : test if actual responses is in quota (and is relevant)
            foreach ($aQuotaFields as $sFieldName=>$aValues) {
                $bInQuota = isset($_SESSION['survey_'.$surveyid][$sFieldName]) && in_array($_SESSION['survey_'.$surveyid][$sFieldName], $aValues);
                if ($bInQuota && $aQuotaRelevantFieldnames[$sFieldName]) {
                    $iMatchedAnswers++;
                }
                if (!is_null(App()->request->getPost($sFieldName))) {
// Need only one posted value
                    $bPostedField = true;
                    $aPostedQuotaFields[$sFieldName] = App()->getRequest()->getPost($sFieldName);
                }
            }


            // Condition to count quota :
            // Answers are the same in quota + an answer is submitted at this time (bPostedField)
            //  OR all questions is hidden (bAllHidden)
            $bAllHidden = QuestionAttribute::model()
                ->countByAttributes(array('qid'=>$aQuotaQid), 'attribute=:attribute', array(':attribute'=>'hidden')) == count($aQuotaQid);

            if ($iMatchedAnswers == count($aQuotaFields) && ($bPostedField || $bAllHidden)) {
                if ($oQuota->qlimit == 0) {
// Always add the quota if qlimit==0
                    $aMatchedQuotas[] = $oQuota->viewArray;
                } else {
                    $iCompleted = $oQuota->completeCount;
                    if (!is_null($iCompleted) && ((int) $iCompleted >= (int) $oQuota->qlimit)) {
                        // This remove invalid quota and not completed
                        $aMatchedQuotas[] = $oQuota->viewArray;
                    }
                }
            }
        }
    }
    if ($return) {
        return $aMatchedQuotas;
    }
    if (empty($aMatchedQuotas)) {
        return;
    }

    // Now we have all the information we need about the quotas and their status.
    // We need to construct the page and do all needed action
    $aSurveyInfo = getSurveyInfo($surveyid, $_SESSION['survey_'.$surveyid]['s_lang']);

    $sClientToken = isset($_SESSION['survey_'.$surveyid]['token']) ? $_SESSION['survey_'.$surveyid]['token'] : "";
    // $redata for templatereplace
    $aDataReplacement = array(
        'thissurvey'=>$aSurveyInfo,
        'clienttoken'=>$sClientToken,
        'token'=>$sClientToken,
    );

    // We take only the first matched quota, no need for each
    $aMatchedQuota = $aMatchedQuotas[0];
    // If a token is used then mark the token as completed, do it before event : this allow plugin to update token information
    $event = new PluginEvent('afterSurveyQuota');
    $event->set('surveyId', $surveyid);
    $event->set('responseId', $_SESSION['survey_'.$surveyid]['srid']); // We allways have a responseId
    $event->set('aMatchedQuotas', $aMatchedQuotas); // Give all the matched quota : the first is the active
    App()->getPluginManager()->dispatchEvent($event);
    $blocks = array();

    foreach ($event->getAllContent() as $blockData) {
        /* @var $blockData PluginEventContent */
        $blocks[] = CHtml::tag('div', array('id' => $blockData->getCssId(), 'class' => $blockData->getCssClass()), $blockData->getContent());
    }

    // Allow plugin to update message, url, url description and action
    $sMessage = $event->get('message', $aMatchedQuota['quotals_message']);
    $sUrl = $event->get('url', $aMatchedQuota['quotals_url']);
    $sUrlDescription = $event->get('urldescrip', $aMatchedQuota['quotals_urldescrip']);
    $sAction = $event->get('action', $aMatchedQuota['action']);
    /* Tag if we close or not the survey */
    $closeSurvey = ($sAction == "1" || App()->getRequest()->getPost('move') == 'confirmquota');
    $sAutoloadUrl = $event->get('autoloadurl', $aMatchedQuota['autoload_url']);
    // Doing the action and show the page
    if ($sAction == \Quota::ACTION_TERMINATE && $sClientToken) {
        submittokens(true);
    }
    // Construct the default message
    $sMessage        = templatereplace($sMessage, array(), $aDataReplacement, 'QuotaMessage', $aSurveyInfo['anonymized'] != 'N', null, array(), true);
    $sUrl            = passthruReplace($sUrl, $aSurveyInfo);
    $sUrl            = templatereplace($sUrl, array(), $aDataReplacement, 'QuotaUrl', $aSurveyInfo['anonymized'] != 'N', null, array(), true);
    $sUrlDescription = templatereplace($sUrlDescription, array(), $aDataReplacement, 'QuotaUrldescription', $aSurveyInfo['anonymized'] != 'N', null, array(), true);


    // Datas for twig view
    $thissurvey['aQuotas']                       = array();
    $thissurvey['aQuotas']['sMessage']           = $sMessage;
    $thissurvey['aQuotas']['bShowNavigator']     = !$closeSurvey;
    $thissurvey['aQuotas']['sClientToken']       = $sClientToken;
    $thissurvey['aQuotas']['sQuotaStep']         = 'returnfromquota';
    $thissurvey['aQuotas']['aPostedQuotaFields'] = isset($aPostedQuotaFields) ? $aPostedQuotaFields : '';
    $thissurvey['aQuotas']['sPluginBlocks']      = implode("\n", $blocks);
    $thissurvey['aQuotas']['sUrlDescription']    = $sUrlDescription;
    $thissurvey['aQuotas']['sUrl']               = $sUrl;
    $thissurvey['active']                        = 'Y';


    $thissurvey['aQuotas']['hiddeninputs'] = '<input type="hidden" name="sid"      value="'.$surveyid.'" />
                                              <input type="hidden" name="token"    value="'.$thissurvey['aQuotas']['sClientToken'].'" />
                                              <input type="hidden" name="thisstep" value="'.(isset($_SESSION['survey_'.$surveyid]['step']) ? $_SESSION['survey_'.$surveyid]['step'] : 0).'" />';


    if (!empty($thissurvey['aQuotas']['aPostedQuotaFields'])){
        foreach ($thissurvey['aQuotas']['aPostedQuotaFields'] as $field => $post) {
            $thissurvey['aQuotas']['hiddeninputs'] .= '<input type="hidden" name="'.$field.'"   value="'.$post.'" />';
        }
    }


    //field,post in aSurveyInfo.aQuotas.aPostedQuotaFields %}

    if ($closeSurvey) {
        killSurveySession($surveyid);

        if ($sAutoloadUrl == 1 && $sUrl != "") {
            header("Location: ".$sUrl);
        }
    }
    $thissurvey['include_content'] = 'quotas';
    Yii::app()->twigRenderer->renderTemplateFromFile("layout_global.twig", array('oSurvey'=>Survey::model()->findByPk($surveyid), 'aSurveyInfo'=>$thissurvey), false);
}

/**
 * encodeEmail : encode admin email in public part
 *
 * @param mixed $mail
 * @param mixed $text
 * @param mixed $class
 * @param mixed $params
 * @return mixed|string
 */
function encodeEmail($mail, $text = "", $class = "", $params = array())
{
    $encmail = "";
    for ($i = 0; $i < strlen($mail); $i++) {
        $encMod = rand(0, 2);
        switch ($encMod) {
            case 0: // None
                $encmail .= substr($mail, $i, 1);
                break;
            case 1: // Decimal
                $encmail .= "&#".ord(substr($mail, $i, 1)).';';
                break;
            case 2: // Hexadecimal
                $encmail .= "&#x".dechex(ord(substr($mail, $i, 1))).';';
                break;
        }
    }

    if (!$text) {
        $text = $encmail;
    }
    return $text;
}

/**
* GetReferringUrl() returns the referring URL
* @return string
*/
function getReferringUrl()
{
    // read it from server variable
    if (isset($_SERVER["HTTP_REFERER"])) {
        if (!Yii::app()->getConfig('strip_query_from_referer_url')) {
            return $_SERVER["HTTP_REFERER"];
        } else {
            $aRefurl = explode("?", $_SERVER["HTTP_REFERER"]);
            return $aRefurl[0];
        }
    } else {
        return null;
    }
}

/**
* Shows the welcome page, used in group by group and question by question mode
*/
function display_first_page($thissurvey, $aSurveyInfo)
{
    global $token, $surveyid;

    $thissurvey                 = $aSurveyInfo;
    $thissurvey['aNavigator']   = getNavigatorDatas();

    LimeExpressionManager::StartProcessingPage();
    LimeExpressionManager::StartProcessingGroup(-1, false, $surveyid); // start on welcome page

    // WHY HERE ?????
    $_SESSION['survey_'.$surveyid]['LEMpostKey'] = mt_rand();

    $loadsecurity = returnGlobal('loadsecurity', true);

    $thissurvey['EM']['ScriptsAndHiddenInputs']  = "<input type='hidden' name='sid' value='$surveyid' id='sid' />\n";
    $thissurvey['EM']['ScriptsAndHiddenInputs'] .= "<input type='hidden' name='lastgroupname' value='_WELCOME_SCREEN_' id='lastgroupname' />\n"; //This is to ensure consistency with mandatory checks, and new group test
    $thissurvey['EM']['ScriptsAndHiddenInputs'] .= "<input type='hidden' name='LEMpostKey' value='{$_SESSION['survey_'.$surveyid]['LEMpostKey']}' id='LEMpostKey' />\n";
    $thissurvey['EM']['ScriptsAndHiddenInputs'] .= "<input type='hidden' name='thisstep' id='thisstep' value='0' />\n";

    if (isset($token) && !empty($token)) {
        $thissurvey['EM']['ScriptsAndHiddenInputs'] .= "\n<input type='hidden' name='token' value='$token' id='token' />\n";
    }

    if (isset($loadsecurity)) {
        $thissurvey['EM']['ScriptsAndHiddenInputs'] .= "\n<input type='hidden' name='loadsecurity' value='$loadsecurity' id='loadsecurity' />\n";
    }

    $thissurvey['EM']['ScriptsAndHiddenInputs'] .= LimeExpressionManager::GetRelevanceAndTailoringJavaScript();

    Yii::app()->clientScript->registerScriptFile(Yii::app()->getConfig("generalscripts").'nojs.js', CClientScript::POS_HEAD);
    LimeExpressionManager::FinishProcessingPage();

    $thissurvey['surveyUrl'] = Yii::app()->getController()->createUrl("survey/index", array("sid"=>$surveyid)); // For form action (will remove newtest)
    $thissurvey['attr']['welcomecontainer'] = $thissurvey['attr']['surveyname'] = $thissurvey['attr']['description'] = $thissurvey['attr']['welcome'] = $thissurvey['attr']['questioncount'] = '';

    $thissurvey['include_content'] = 'firstpage';

    Yii::app()->twigRenderer->renderTemplateFromFile("layout_global.twig", array('oSurvey'=>Survey::model()->findByPk($surveyid), 'aSurveyInfo'=>$thissurvey), false);
}

/**
* killSurveySession : reset $_SESSION part for the survey
* @param int $iSurveyID
*/
function killSurveySession($iSurveyID)
{
    // Unset the session
    unset($_SESSION['survey_'.$iSurveyID]);
    // Force EM to refresh
    LimeExpressionManager::SetDirtyFlag();

    //  unsetting LEMsingleton from session so new survey execution would start with new LEM instance
    //  SetDirtyFlag() method doesn't reset LEM properly
    //  this solution fixes bug: https://bugs.limesurvey.org/view.php?id=10162
    unset($_SESSION["LEMsingleton"]);
}

/**
* Resets all question timers by expiring the related cookie - this needs to be called before any output is done
* @todo Make cookie survey ID aware
*/
function resetTimers()
{
    $cookie = new CHttpCookie('limesurvey_timers', '');
    $cookie->expire = time() - 3600;
    Yii::app()->request->cookies['limesurvey_timers'] = $cookie;
}

/**
* Set the public survey language
* Control if language exist in this survey, else set to survey default language
* if $surveyid <= 0 : set the language to default site language
* @param int $surveyid
* @param string $sLanguage
*/
function SetSurveyLanguage($surveyid, $sLanguage)
{
    $surveyid         = sanitize_int($surveyid);
    $default_language = Yii::app()->getConfig('defaultlang');

    if (isset($surveyid) && $surveyid > 0) {

        $default_survey_language     = Survey::model()->findByPk($surveyid)->language;
        $additional_survey_languages = Survey::model()->findByPk($surveyid)->getAdditionalLanguages();

        if (
            empty($sLanguage)                                                   //check if there
            || (!in_array($sLanguage, $additional_survey_languages))            //Is the language in the survey-language array
            || ($default_survey_language == $sLanguage)                         //Is the $default_language the chosen language?
            ) {
            // Language not supported, fall back to survey's default language
            $_SESSION['survey_'.$surveyid]['s_lang'] = $default_survey_language;
        } else {
            $_SESSION['survey_'.$surveyid]['s_lang'] = $sLanguage;
        }

        App()->setLanguage($_SESSION['survey_'.$surveyid]['s_lang']);
        Yii::app()->loadHelper('surveytranslator');
        LimeExpressionManager::SetEMLanguage($_SESSION['survey_'.$surveyid]['s_lang']);
    } else {

        if (!$sLanguage) {
            $sLanguage = $default_language;
        }

        $_SESSION['survey_'.$surveyid]['s_lang'] = $sLanguage;
        App()->setLanguage($_SESSION['survey_'.$surveyid]['s_lang']);
    }

}

/**
* getMove get move button clicked
* @return string
**/
function getMove()
{
    $aAcceptedMove = array('default', 'movenext', 'movesubmit', 'moveprev', 'saveall', 'loadall', 'clearall', 'changelang');
    // We can control is save and load are OK : todo fix according to survey settings
    // Maybe allow $aAcceptedMove in Plugin
    $move = Yii::app()->request->getParam('move');
    /* @deprecated since we use button and not input with different value. */
    foreach ($aAcceptedMove as $sAccepteMove) {
        if (Yii::app()->request->getParam($sAccepteMove)) {
                    $move = $sAccepteMove;
        }
    }
    /* default move (user don't click on a button, but use enter in a input:text or a select */
    if ($move == 'default') {
        $surveyid = Yii::app()->getConfig('surveyID');
        $thissurvey = getsurveyinfo($surveyid);
        $iSessionStep = (isset($_SESSION['survey_'.$surveyid]['step'])) ? $_SESSION['survey_'.$surveyid]['step'] : false;
        $iSessionTotalSteps = (isset($_SESSION['survey_'.$surveyid]['totalsteps'])) ? $_SESSION['survey_'.$surveyid]['totalsteps'] : false;
        if ($iSessionStep && ($iSessionStep == $iSessionTotalSteps) || $thissurvey['format'] == 'A') {
            $move = "movesubmit";
        } else {
            $move = "movenext";
        }
    }
    return $move;
}

/**
 * Get the margin class for side-body div depending
 * on side-menu behaviour config and page (edit or not
 * etc).
 *
 * @param boolean $sideMenustate - False for pages with collapsed side-menu
 * @return string
 * @throws CException
 */
function getSideBodyClass($sideMenustate = false)
{
    $sideMenuBehaviour = getGlobalSetting('sideMenuBehaviour');

    $class = "";

    if ($sideMenuBehaviour == 'adaptive' || $sideMenuBehaviour == '') {
        // Adaptive and closed, as in edit question
        if (!$sideMenustate) {
            $class = 'side-body-margin';
        }
    } elseif ($sideMenuBehaviour == 'alwaysClosed') {
        $class = 'side-body-margin';
    } elseif ($sideMenuBehaviour == 'alwaysOpen') {
        // No margin class
    } else {
        throw new \CException("Unknown value for sideMenuBehaviour: $sideMenuBehaviour");
    }

    return ""; $class;

}

/**
 * For later use, don't remove.
 * @return array<string>
 */
function cookieConsentLocalization()
{
    return array(
        gT('By continuing this survey you approve the data protection policy of the service provider.'),
        gT('OK'),
        gT('View policy'),
        gT('Please be patient until you are forwarded to the final URL.')
    );
}
