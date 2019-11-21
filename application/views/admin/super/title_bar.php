<?php
/**
 * Needs an array now
 * $breadCrumbArray = array('oSurvey'=>$oSurvey, 'oQuestionGroup' => $oQuestionGroup, 'oQuestion' => $oQuestion, 'sSubaction' =>$sSubaction,  'active'=>$active))
 */


if (!isset($oSurvey)) {
    // TODO: Missing lang?
    $oSurvey = Survey::model()->findByPk((int) $surveyid);
}

if (!isset($oQuestion)) {
    // TODO: Missing lang?
    $oQuestion = isset($qid) ? @Question::model()->find('qid=:qid',['qid'=> $qid]) : null;
}

if (!isset($oQuestionGroup)) {
    // TODO: Missing lang?
    $oQuestionGroup = isset($gid) ? @QuestionGroup::model()->find('gid=:gid',['gid'=> $gid]) : null;
}

$subaction = isset($subaction) ? $subaction : null;
$simpleSubaction = isset($title_bar['subaction']) ? $title_bar['subaction'] : null;

$breadCrumbArray = array(
    'oSurvey' => $oSurvey,
    'oQuestion' => $oQuestion,
    'oQuestionGroup' => $oQuestionGroup,
    'sSubaction' => $subaction,
    'sSimpleSubaction' => $simpleSubaction,
    'title' => (isset($title_bar['title']) ? $title_bar['title'] : ' ')
    //'active' => ($oQuestion != null ? $oQuestion->title : ( $oQuestionGroup != null ? $oQuestionGroup->group_name : $oSurvey->defaultlanguage->surveyls_title ) )
);

$breadCrumbArray['extraClass'] = "title-bar-breadcrumb";
?>
<div class='menubar surveymanagerbar ls-space padding left-0'>
    <?php  $this->renderPartial('/admin/survey/breadcrumb', $breadCrumbArray); ?>    
</div>
