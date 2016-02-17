<?php

namespace TYPO3\DirectMailSubscription;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\File\BasicFileUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Plugin\AbstractPlugin;

/**
 * FE admin lib
 * $Id: fe_adminLib.inc 10317 2011-01-26 00:56:49Z baschny $
 * Revised for TYPO3 3.6 June/2003 by Kasper Skårhøj
 * This library provides a HTML-template file based framework for Front End creating/editing/deleting records authenticated by email or fe_user login.
 * It is used in the extensions "direct_mail_subscription" and "feuser_admin" (and the deprecated(!) static template "plugin.feadmin.dmailsubscription" and "plugin.feadmin.fe_users" which are the old versions of these two extensions)
 * Further the extensions "t3consultancies" and "t3references" also uses this library but contrary to the "direct_mail_subscription" and "feuser_admin" extensions which relies on external HTML templates which must be adapted these two extensions delivers the HTML template code from inside.
 * Generally the fe_adminLib appears to be hard to use. Personally I feel turned off by all the template-file work involved and since it is very feature rich (and for that sake pretty stable!) there are lots of things that can go wrong - you feel. Therefore I like the concept used by "t3consultancies"/"t3references" since those extensions uses the library by supplying the HTML-template code automatically.
 * Suggestions for improvement and streamlining is welcome so this powerful class could be used more and effectively.
 *
 * @author    Kasper Skårhøj <kasperYYYY@typo3.com>
 * @package TYPO3\DirectMailSubscription
 */
class Plugin extends AbstractPlugin
{

    /**
     * If true, values from the record put into markers going out into HTML will be passed through htmlspecialchars()!
     *
     * @var bool
     */
    protected $recInMarkersHSC = true;

    /**
     * @var array
     */
    protected $dataArr = [];

    /**
     * @var array
     */
    protected $failureMsg = [];

    /**
     * @var string
     */
    protected $theTable = '';

    /**
     * @var int
     */
    protected $thePid = 0;

    /**
     * @var array
     */
    protected $markerArray = [];

    /**
     * @var string
     */
    protected $templateCode = '';

    /**
     * DataHandler command
     *
     * @var string
     */
    protected $cmd;
    protected $preview;
    protected $backURL;

    /**
     * @var int
     */
    protected $recUid;
    protected $failure = 0;        // is set if data did not have the required fields set.
    protected $error = '';

    /**
     * @var int
     */
    protected $saved = 0;        // is set if data is saved
    protected $requiredArr;
    protected $currentArr = [];
    protected $previewLabel = '';
    protected $nc = '';        // '&no_cache=1' if you want that parameter sent.
    protected $additionalUpdateFields = '';
    protected $emailMarkPrefix = 'EMAIL_TEMPLATE_';
    protected $codeLength;
    protected $cmdKey;

    /**
     * @var BasicFileUtility
     */
    protected $fileFunc;

    /**
     * @var array
     */
    protected $filesStoredInUploadFolders = [];

    /**
     * @var array
     */
    protected $unlinkTempFiles = [];

    /**
     * Main function. Called from TypoScript.
     * This
     * - initializes internal variables,
     * - fills in the markerArray with default substitution string
     * - saves/emails if such commands are sent
     * - calls functions for display of the screen for editing/creation/deletion etc.
     *
     * @param string $content Empty string, ignore.
     * @param array $conf TypoScript properties following the USER_INT object which uses this library
     *
     * @return string HTML content
     */
    public function init($content, $conf)
    {
        DebuggerUtility::var_dump($this);
        $this->conf = $conf;

        // template file is fetched.
        $this->templateCode = $this->conf['templateContent'] ? $this->conf['templateContent'] : $this->cObj->fileResource($this->conf['templateFile']);

        // Getting the cmd var
        $this->cmd = (string)GeneralUtility::_GP('cmd');
        // Getting the preview var
        $this->preview = (string)GeneralUtility::_GP('preview');
        // backURL is a given URL to return to when login is performed
        $this->backURL = GeneralUtility::_GP('backURL');
        if (strstr($this->backURL, '"') || strstr($this->backURL, "'") || preg_match('/(javascript|vbscript):/i',
                $this->backURL) || stristr($this->backURL, "fromcharcode") || strstr($this->backURL,
                "<") || strstr($this->backURL, ">")
        ) {
            $this->backURL = '';    // Clear backURL if it seems to contain XSS code - only URLs are allowed
        }
        // Remove host from URL: Make sure that $this->backURL maps to the current site
        $this->backURL = preg_replace('|[A-Za-z]+://[^/]+|', '', $this->backURL);
        // Uid to edit:
        $this->recUid = GeneralUtility::_GP('rU');
        // Authentication code:
        $this->authCode = GeneralUtility::_GP('aC');
        // get table
        $this->theTable = $this->conf['table'];
        // link configuration
        $linkConf = is_array($this->conf['formurl.']) ? $this->conf['formurl.'] : [];
        // pid
        $this->thePid = intval($this->conf['pid']) ? intval($this->conf['pid']) : $this->getTypoScriptFrontendController()->id;
        //
        $this->codeLength = intval($this->conf['authcodeFields.']['codeLength']) ? intval($this->conf['authcodeFields.']['codeLength']) : 8;

        // Setting the hardcoded lists of fields allowed for editing and creation.
        $this->fieldList = implode(',',
            GeneralUtility::trimExplode(',', $GLOBALS['TCA'][$this->theTable]['feInterface']['fe_admin_fieldList'], 1));

        // globally substituted markers, fonts and colors.
        $splitMark = md5(microtime());
        list($this->markerArray['###GW1B###'], $this->markerArray['###GW1E###']) = explode($splitMark,
            $this->cObj->stdWrap($splitMark, $this->conf['wrap1.']));
        list($this->markerArray['###GW2B###'], $this->markerArray['###GW2E###']) = explode($splitMark,
            $this->cObj->stdWrap($splitMark, $this->conf['wrap2.']));
        $this->markerArray['###GC1###'] = $this->cObj->stdWrap($this->conf['color1'], $this->conf['color1.']);
        $this->markerArray['###GC2###'] = $this->cObj->stdWrap($this->conf['color2'], $this->conf['color2.']);
        $this->markerArray['###GC3###'] = $this->cObj->stdWrap($this->conf['color3'], $this->conf['color3.']);

        if (intval($this->conf['no_cache']) && !isset($linkConf['no_cache'])) {    // needed for backwards compatibility
            $linkConf['no_cache'] = 1;
        }
        if (!$linkConf['parameter']) {
            $linkConf['parameter'] = $this->getTypoScriptFrontendController()->id;
        }
        if (!$linkConf['additionalParams']) {    // needed for backwards compatibility
            $linkConf['additionalParams'] = $this->conf['addParams'];
        }

        $formURL = $this->cObj->typoLink_URL($linkConf);
        if (!strstr($formURL, '?')) {
            $formURL .= '?';
        }

        // Initialize markerArray, setting FORM_URL and HIDDENFIELDS
        $this->markerArray['###FORM_URL###'] = $formURL;
        $this->markerArray['###FORM_URL_ENC###'] = rawurlencode($this->markerArray['###FORM_URL###']);
        $this->markerArray['###FORM_URL_HSC###'] = htmlspecialchars($this->markerArray['###FORM_URL###']);

        $this->markerArray['###BACK_URL###'] = $this->backURL;
        $this->markerArray['###BACK_URL_ENC###'] = rawurlencode($this->markerArray['###BACK_URL###']);
        $this->markerArray['###BACK_URL_HSC###'] = htmlspecialchars($this->markerArray['###BACK_URL###']);

        $this->markerArray['###THE_PID###'] = $this->thePid;
        $this->markerArray['###REC_UID###'] = $this->recUid;
        $this->markerArray['###AUTH_CODE###'] = $this->authCode;
        $this->markerArray['###THIS_ID###'] = $this->getTypoScriptFrontendController()->id;
        $this->markerArray['###THIS_URL###'] = htmlspecialchars(GeneralUtility::getIndpEnv('TYPO3_SITE_URL'));
        $this->markerArray['###HIDDENFIELDS###'] =
            ($this->cmd ? '<input type="hidden" name="cmd" value="' . htmlspecialchars($this->cmd) . '" />' : '') .
            ($this->authCode ? '<input type="hidden" name="aC" value="' . htmlspecialchars($this->authCode) . '" />' : '') .
            ($this->backURL ? '<input type="hidden" name="backURL" value="' . htmlspecialchars($this->backURL) . '" />' : '');


        // Setting cmdKey which is either 'edit' or 'create'
        switch ($this->cmd) {
            case 'edit':
                $this->cmdKey = 'edit';
                break;
            default:
                $this->cmdKey = 'create';
                break;
        }
        // Setting requiredArr to the fields in 'required' intersected field the total field list in order to remove invalid fields.
        $this->requiredArr = array_intersect(
            GeneralUtility::trimExplode(',', $this->conf[$this->cmdKey . '.']['required'], 1),
            GeneralUtility::trimExplode(',', $this->conf[$this->cmdKey . '.']['fields'], 1)
        );

        // Setting incoming data. Non-stripped
        $fe = GeneralUtility::_GP('FE');

        $this->dataArr = $fe[$this->theTable];    // Incoming data.

        // Checking template file and table value
        if (!$this->templateCode) {
            $content = 'No template file found: ' . $this->conf['templateFile'];

            return $content;
        }

        if (!$this->theTable || !$this->fieldList) {
            $content = 'Wrong table: ' . $this->theTable;

            return $content;        // Not listed or editable table!
        }

        // *****************
        // If data is submitted, we take care of it here.
        // *******************
        if ($this->cmd == 'delete' && !$this->preview && !GeneralUtility::_GP('doNotSave')) {    // Delete record if delete command is sent + the preview flag is NOT set.
            $this->deleteRecord();
        }
        // If incoming data is seen...
        if (is_array($this->dataArr) && count(ArrayUtility::removeArrayEntryByValue(array_keys($this->dataArr),
                'captcha'))
        ) {
            // Evaluation of data:
            $this->parseValues();
            $this->overrideValues();
            $this->evalValues();
            if ($this->conf['evalFunc']) {
                $this->dataArr = $this->userProcess('evalFunc', $this->dataArr);
            }

            /*
            debug($this->dataArr);
            debug($this->failure);
            debug($this->preview);
            */
            // if not preview and no failures, then set data...
            if (!$this->failure && !$this->preview && !GeneralUtility::_GP('doNotSave')) {    // doNotSave is a global var (eg a 'Cancel' submit button) that prevents the data from being processed
                $this->save();
            } else {
                if ($this->conf['debug']) {
                    debug($this->failure);
                }
            }
        } else {
            $this->defaultValues();    // If no incoming data, this will set the default values.
            $this->preview = 0;    // No preview if data is not received
        }
        if ($this->failure) {
            $this->preview = 0;
        }    // No preview flag if a evaluation failure has occured
        $this->previewLabel = $this->preview ? '_PREVIEW' : '';    // Setting preview label prefix.


        // *********************
        // DISPLAY FORMS:
        // ***********************
        if ($this->saved) {
            // Clear page cache
            $this->clearCacheIfSet();
            $this->setNoCacheHeader();

            // Displaying the page here that says, the record has been saved. You're able to include the saved values by markers.
            switch ($this->cmd) {
                case 'delete':
                    $key = 'DELETE';
                    break;
                case 'edit':
                    $key = 'EDIT';
                    break;
                default:
                    $key = 'CREATE';
                    break;
            }
            // Output message
            $templateCode = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_' . $key . '_SAVED###');
            $this->setCObjects($templateCode, $this->currentArr);
            $markerArray = $this->cObj->fillInMarkerArray($this->markerArray, $this->currentArr, '', true, 'FIELD_',
                $this->recInMarkersHSC);
            $content = $this->cObj->substituteMarkerArray($templateCode, $markerArray);

            // email message:
            $this->compileMail(
                $key . '_SAVED',
                [$this->currentArr],
                $this->currentArr[$this->conf['email.']['field']],
                $this->conf['setfixed.']
            );

        } elseif ($this->error) {    // If there was an error, we return the template-subpart with the error message
            $templateCode = $this->cObj->getSubpart($this->templateCode, $this->error);
            $this->setCObjects($templateCode);
            $content = $this->cObj->substituteMarkerArray($templateCode, $this->markerArray);
        } else {
            // Finally, if there has been no attempt to save. That is either preview or just displaying and empty or not correctly filled form:
            if (!$this->cmd) {
                $this->cmd = $this->conf['defaultCmd'];
            }
            if ($this->conf['debug']) {
                debug('Display form: ' . $this->cmd, 1);
            }
            switch ($this->cmd) {
                case 'setfixed':
                    $content = $this->procesSetFixed();
                    break;
                case 'infomail':
                    $content = $this->sendInfoMail();
                    break;
                case 'delete':
                    $content = $this->displayDeleteScreen();
                    break;
                case 'edit':
                    $content = $this->displayEditScreen();
                    break;
                case 'create':
                    $content = $this->displayCreateScreen();
                    break;
            }
        }

        // Delete temp files:
        foreach ($this->unlinkTempFiles as $tempFileName) {
            GeneralUtility::unlink_tempfile($tempFileName);
        }

        // Return content:
        return $content;
    }

    /*****************************************
     * Data processing
     *****************************************/

    /**
     * Performs processing on the values found in the input data array, $this->dataArr.
     * The processing is done according to configuration found in TypoScript
     * Examples of this could be to force a value to an integer, remove all non-alphanumeric characters, trimming a value, upper/lowercase it, or process it due to special types like files submitted etc.
     * Called from init() if the $this->dataArr is found to be an array
     *
     * @return    void
     * @see init()
     */
    function parseValues()
    {
        if (is_array($this->conf['parseValues.'])) {
            foreach ($this->conf['parseValues.'] as $theField => $theValue) {
                $listOfCommands = GeneralUtility::trimExplode(',', $theValue, 1);
                foreach ($listOfCommands as $cmd) {
                    $cmdParts = preg_split('/\[|\]/',
                        $cmd);    // Point is to enable parameters after each command enclosed in brackets [..]. These will be in position 1 in the array.
                    $theCmd = trim($cmdParts[0]);
                    switch ($theCmd) {
                        case 'int':
                            $this->dataArr[$theField] = intval($this->dataArr[$theField]);
                            break;
                        case 'lower':
                        case 'upper':
                            $this->dataArr[$theField] = $this->cObj->caseshift($this->dataArr[$theField], $theCmd);
                            break;
                        case 'nospace':
                            $this->dataArr[$theField] = str_replace(' ', '', $this->dataArr[$theField]);
                            break;
                        case 'alpha':
                            $this->dataArr[$theField] = preg_replace('/[^a-zA-Z]/', '', $this->dataArr[$theField]);
                            break;
                        case 'num':
                            $this->dataArr[$theField] = preg_replace('/[^0-9]/', '', $this->dataArr[$theField]);
                            break;
                        case 'alphanum':
                            $this->dataArr[$theField] = preg_replace('/[^a-zA-Z0-9]/', '', $this->dataArr[$theField]);
                            break;
                        case 'alphanum_x':
                            $this->dataArr[$theField] = preg_replace('/[^a-zA-Z0-9_-]/', '', $this->dataArr[$theField]);
                            break;
                        case 'trim':
                            $this->dataArr[$theField] = trim($this->dataArr[$theField]);
                            break;
                        case 'random':
                            $this->dataArr[$theField] = substr(md5(uniqid(microtime(), 1)), 0, intval($cmdParts[1]));
                            break;
                        case 'files':
                            if ($this->cmdKey == 'create' && !GeneralUtility::_GP('doNotSave')) {
                                $this->processFiles($cmdParts, $theField);
                            } else {
                                unset($this->dataArr[$theField]);
                            }    // Fields with files cannot be edited - only created.
                            break;
                        case 'setEmptyIfAbsent':
                            if (!isset($this->dataArr[$theField])) {
                                $this->dataArr[$theField] = '';
                            }
                            break;
                        case 'multiple':
                            if (is_array($this->dataArr[$theField])) {
                                $this->dataArr[$theField] = implode(',', $this->dataArr[$theField]);
                            }
                            break;
                        case 'checkArray':
                            if (is_array($this->dataArr[$theField])) {
                                $val = 0;
                                foreach ($this->dataArr[$theField] as $kk => $vv) {
                                    $kk = MathUtility::forceIntegerInRange($kk, 0);

                                    if ($kk <= 30) {
                                        if ($vv) {
                                            $val |= pow(2, $kk);
                                        }
                                    }
                                }
                                $this->dataArr[$theField] = $val;
                            } else {
                                $this->dataArr[$theField] = 0;
                            }
                            break;
                        case 'uniqueHashInt':
                            $otherFields = GeneralUtility::trimExplode(';', $cmdParts[1], 1);
                            $hashArray = [];
                            foreach ($otherFields as $fN) {
                                $vv = $this->dataArr[$fN];
                                $vv = preg_replace('/[[:space:]]/', '', $vv);
                                $vv = preg_replace('/[^[:alnum:]]/', '', $vv);
                                $vv = strtolower($vv);
                                $hashArray[] = $vv;
                            }
                            $this->dataArr[$theField] = hexdec(substr(md5(serialize($hashArray)), 0, 8));
                            break;
                    }
                }
            }
        }
    }

    /**
     * Processing of files.
     * NOTICE: for now files can be handled only on creation of records. But a more advanced feature is that PREVIEW of files is handled.
     *
     * @param array $cmdParts Array with cmd-parts (from parseValues()). This will for example contain information about allowed file extensions and max size of uploaded files.
     * @param string $theField The fieldname with the files.
     *
     * @return void
     * @see parseValues()
     */
    protected function processFiles($cmdParts, $theField)
    {
        // First, make an array with the filename and file reference, whether the file is just uploaded or a preview
        $filesArr = [];

        if (is_string($this->dataArr[$theField])) {        // files from preview.
            $tmpArr = explode(',', $this->dataArr[$theField]);
            foreach ($tmpArr as $val) {
                $valParts = explode('|', $val);
                $filesArr[] = [
                    'name'     => $valParts[1],
                    'tmp_name' => PATH_site . 'typo3temp/' . $valParts[0]
                ];
            }
        } elseif (is_array($_FILES['FE'][$this->theTable][$theField]['name'])) {    // Files from upload
            foreach ($_FILES['FE'][$this->theTable][$theField]['name'] as $kk => $vv) {
                if ($vv) {
                    $tmpFile = GeneralUtility::upload_to_tempfile($_FILES['FE'][$this->theTable][$theField]['tmp_name'][$kk]);
                    if ($tmpFile) {
                        $this->unlinkTempFiles[] = $tmpFile;
                        $filesArr[] = [
                            'name'     => $vv,
                            'tmp_name' => $tmpFile
                        ];
                    }
                }
            }
        } elseif (is_array($_FILES['FE']['name'][$this->theTable][$theField])) {    // Files from upload
            foreach ($_FILES['FE']['name'][$this->theTable][$theField] as $kk => $vv) {
                if ($vv) {
                    $tmpFile = GeneralUtility::upload_to_tempfile($_FILES['FE']['tmp_name'][$this->theTable][$theField][$kk]);
                    if ($tmpFile) {
                        $this->unlinkTempFiles[] = $tmpFile;
                        $filesArr[] = [
                            'name'     => $vv,
                            'tmp_name' => $tmpFile
                        ];
                    }
                }
            }
        }

        // Then verify the files in that array; check existence, extension and size
        $this->dataArr[$theField] = '';
        $finalFilesArr = [];
        if (count($filesArr)) {
            $extArray = GeneralUtility::trimExplode(';', strtolower($cmdParts[1]), 1);
            $maxSize = intval($cmdParts[3]);
            foreach ($filesArr as $infoArr) {
                $fI = pathinfo($infoArr['name']);
                if (GeneralUtility::verifyFilenameAgainstDenyPattern($fI['name'])) {
                    if (!count($extArray) || in_array(strtolower($fI['extension']), $extArray)) {
                        $tmpFile = $infoArr['tmp_name'];
                        if (@is_file($tmpFile)) {
                            if (!$maxSize || filesize($tmpFile) < $maxSize * 1024) {
                                $finalFilesArr[] = $infoArr;
                            } elseif ($this->conf['debug']) {
                                debug('Size is beyond ' . $maxSize . ' kb (' . filesize($tmpFile) . ' bytes) and the file cannot be saved.');
                            }
                        } elseif ($this->conf['debug']) {
                            debug('Surprisingly there was no file for ' . $vv . ' in ' . $tmpFile);
                        }
                    } elseif ($this->conf['debug']) {
                        debug('Extension "' . $fI['extension'] . '" not allowed');
                    }
                } elseif ($this->conf['debug']) {
                    debug('Filename matched illegal pattern.');
                }
            }
        }
        // Copy the files in the resulting array to the proper positions based on preview/non-preview.
        $fileNameList = [];
        $uploadPath = '';
        foreach ($finalFilesArr as $infoArr) {
            if ($this->isPreview()) {        // If the form is a preview form (and data is therefore not going into the database...) do this.
                $this->createFileFuncObj();
                $fI = pathinfo($infoArr['name']);
                $tmpFilename = $this->theTable . '_' . GeneralUtility::shortmd5(uniqid($infoArr['name'])) . '.' . $fI['extension'];
                $theDestFile = $this->fileFunc->getUniqueName(
                    $this->fileFunc->cleanFileName($tmpFilename),
                    PATH_site . 'typo3temp/'
                );
                GeneralUtility::upload_copy_move($infoArr['tmp_name'], $theDestFile);
                // Setting the filename in the list
                $fI2 = pathinfo($theDestFile);
                $fileNameList[] = $fI2['basename'] . '|' . $infoArr['name'];
            } else {
                $this->createFileFuncObj();
                $this->getTypoScriptFrontendController()->includeTCA();
                if (is_array($GLOBALS['TCA'][$this->theTable]['columns'][$theField])) {
                    $uploadPath = $GLOBALS['TCA'][$this->theTable]['columns'][$theField]['config']['uploadfolder'];
                }
                if ($uploadPath !== '') {
                    $theDestFile = $this->fileFunc->getUniqueName($this->fileFunc->cleanFileName($infoArr['name']),
                        PATH_site . $uploadPath);
                    GeneralUtility::upload_copy_move($infoArr['tmp_name'], $theDestFile);
                    // Setting the filename in the list
                    $fI2 = pathinfo($theDestFile);
                    $fileNameList[] = $fI2['basename'];
                    $this->filesStoredInUploadFolders[] = $theDestFile;
                }
            }
            // Implode the list of filenames
            $this->dataArr[$theField] = implode(',', $fileNameList);
        }
    }

    /**
     * Overriding values in $this->dataArr if configured for that in TypoScript ([edit/create].overrideValues)
     *
     * @return    void
     * @see init()
     */
    protected function overrideValues()
    {
        // Addition of overriding values
        if (is_array($this->conf[$this->cmdKey . '.']['overrideValues.'])) {
            foreach ($this->conf[$this->cmdKey . '.']['overrideValues.'] as $theField => $theValue) {
                $this->dataArr[$theField] = $theValue;
            }
        }
    }

    /**
     * Called if there is no input array in $this->dataArr. Then this function sets the default values configured in TypoScript
     *
     * @return    void
     * @see init()
     */
    protected function defaultValues()
    {
        // Addition of default values
        if (is_array($this->conf[$this->cmdKey . '.']['defaultValues.'])) {
            foreach ($this->conf[$this->cmdKey . '.']['defaultValues.'] as $theField => $theValue) {
                $this->dataArr[$theField] = $theValue;
            }
        }
    }

    /**
     * This will evaluate the input values from $this->dataArr to see if they conforms with the requirements configured in TypoScript per field.
     * For example this could be checking if a field contains a valid email address, a unique value, a value within a certain range etc.
     * It will populate arrays like $this->failure and $this->failureMsg with error messages (which can later be displayed in the template). Mostly it does NOT alter $this->dataArr (such parsing of values was done by parseValues())
     * Works based on configuration in TypoScript key [create/edit].evalValues
     *
     * @return    void
     * @see init(), parseValues()
     */
    function evalValues()
    {
        // Check required, set failure if not ok.
        $tempArr = [];
        foreach ($this->requiredArr as $theField) {
            if (!trim($this->dataArr[$theField])) {
                $tempArr[] = $theField;
            }
        }

        // Evaluate: This evaluates for more advanced things than 'required' does. But it returns the same error code, so you must let the required-message tell, if further evaluation has failed!
        $recExist = 0;
        if (is_array($this->conf[$this->cmdKey . '.']['evalValues.'])) {
            switch ($this->cmd) {
                case 'edit':
                    if (isset($this->dataArr['pid'])) {            // This may be tricked if the input has the pid-field set but the edit-field list does NOT allow the pid to be edited. Then the pid may be false.
                        $recordTestPid = intval($this->dataArr['pid']);
                    } else {
                        $tempRecArr = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($this->theTable,
                            $this->dataArr['uid']);
                        $recordTestPid = intval($tempRecArr['pid']);
                    }
                    $recExist = 1;
                    break;
                default:
                    if (is_callable(['t3lib_utility_Math', 'convertToPositiveInteger'])) {
                        $pid = MathUtility::convertToPositiveInteger($this->dataArr['pid']);
                    } else {
                        $pid = MathUtility::convertToPositiveInteger($this->dataArr['pid']);
                    }
                    $recordTestPid = $this->thePid ? $this->thePid : $pid;
                    break;
            }

            foreach ($this->conf[$this->cmdKey . '.']['evalValues.'] as $theField => $theValue) {
                $listOfCommands = GeneralUtility::trimExplode(',', $theValue, 1);
                foreach ($listOfCommands as $cmd) {
                    $cmdParts = preg_split('/\[|\]/',
                        $cmd);    // Point is to enable parameters after each command enclosed in brackets [..]. These will be in position 1 in the array.
                    $theCmd = trim($cmdParts[0]);
                    switch ($theCmd) {
                        case 'uniqueGlobal':
                            if ($DBrows = $this->getTypoScriptFrontendController()->sys_page->getRecordsByField($this->theTable,
                                $theField,
                                $this->dataArr[$theField], '', '', '', '1')
                            ) {
                                if (!$recExist || $DBrows[0]['uid'] != $this->dataArr['uid']) {    // Only issue an error if the record is not existing (if new...) and if the record with the false value selected was not our self.
                                    $tempArr[] = $theField;
                                    $this->failureMsg[$theField][] = $this->getFailure($theField, $theCmd,
                                        'The value existed already. Enter a new value.');
                                }
                            }
                            break;
                        case 'uniqueLocal':
                            if ($DBrows = $this->getTypoScriptFrontendController()->sys_page->getRecordsByField($this->theTable,
                                $theField,
                                $this->dataArr[$theField], 'AND pid IN (' . $recordTestPid . ')', '', '', '1')
                            ) {
                                if (!$recExist || $DBrows[0]['uid'] != $this->dataArr['uid']) {    // Only issue an error if the record is not existing (if new...) and if the record with the false value selected was not our self.
                                    $tempArr[] = $theField;
                                    $this->failureMsg[$theField][] = $this->getFailure($theField, $theCmd,
                                        'The value existed already. Enter a new value.');
                                }
                            }
                            break;
                        case 'twice':
                            if (strcmp($this->dataArr[$theField], $this->dataArr[$theField . '_again'])) {
                                $tempArr[] = $theField;
                                $this->failureMsg[$theField][] = $this->getFailure($theField, $theCmd,
                                    'You must enter the same value twice');
                            }
                            break;
                        case 'email':
                            if (!GeneralUtility::validEmail($this->dataArr[$theField])) {
                                $tempArr[] = $theField;
                                $this->failureMsg[$theField][] = $this->getFailure($theField, $theCmd,
                                    'You must enter a valid email address');
                            }
                            break;
                        case 'required':
                            if (!trim($this->dataArr[$theField])) {
                                $tempArr[] = $theField;
                                $this->failureMsg[$theField][] = $this->getFailure($theField, $theCmd,
                                    'You must enter a value!');
                            }
                            break;
                        case 'atLeast':
                            $chars = intval($cmdParts[1]);
                            if (strlen($this->dataArr[$theField]) < $chars) {
                                $tempArr[] = $theField;
                                $this->failureMsg[$theField][] = sprintf($this->getFailure($theField, $theCmd,
                                    'You must enter at least %s characters!'), $chars);
                            }
                            break;
                        case 'atMost':
                            $chars = intval($cmdParts[1]);
                            if (strlen($this->dataArr[$theField]) > $chars) {
                                $tempArr[] = $theField;
                                $this->failureMsg[$theField][] = sprintf($this->getFailure($theField, $theCmd,
                                    'You must enter at most %s characters!'), $chars);
                            }
                            break;
                        case 'inBranch':
                            $pars = explode(';', $cmdParts[1]);
                            if (intval($pars[0])) {
                                $pid_list = $this->cObj->getTreeList(
                                    intval($pars[0]),
                                    intval($pars[1]) ? intval($pars[1]) : 999,
                                    intval($pars[2])
                                );
                                if (!$pid_list || !GeneralUtility::inList($pid_list, $this->dataArr[$theField])) {
                                    $tempArr[] = $theField;
                                    $this->failureMsg[$theField][] = sprintf($this->getFailure($theField, $theCmd,
                                        'The value was not a valid valud from this list: %s'), $pid_list);
                                }
                            }
                            break;
                        case 'unsetEmpty':
                            if (!$this->dataArr[$theField]) {
                                $hash = array_flip($tempArr);
                                unset($hash[$theField]);
                                $tempArr = array_keys($hash);
                                unset($this->failureMsg[$theField]);
                                unset($this->dataArr[$theField]);    // This should prevent the field from entering the database.
                            }
                            break;
                    }
                }
                $this->markerArray['###EVAL_ERROR_FIELD_' . $theField . '###'] = is_array($this->failureMsg[$theField]) ? implode('<br />',
                    $this->failureMsg[$theField]) : '';
            }
            $tempArr[] = $this->checkCaptcha();
        }

        $this->failure = implode(',', $tempArr);     //$failure will show which fields were not OK
    }

    /**
     * check captcha
     *
     * @return bool @captcha:TRUE if captcha not loaded or captcha is correct, FALSE on wrong captcha
     */
    function checkCaptcha()
    {
        $captcha = true;

        if (ExtensionManagementUtility::isLoaded('captcha') && isset($this->dataArr['captcha'])) {
            session_start();
            $captchaStr = $_SESSION['tx_captcha_string'];
            $_SESSION['tx_captcha_string'] = '';

            if (empty($captchaStr) || ($this->dataArr['captcha'] !== $captchaStr)) {
                $captcha = false;
                $theField = 'captcha';
                $this->failureMsg[$theField][] = $this->getFailure($theField, 'captcha', 'Wrong captcha!');
                $errorMsg = is_array($this->failureMsg[$theField]) ? implode('<br />',
                    $this->failureMsg[$theField]) : '';
                $this->markerArray['###CAPTCHA###'] = $this->getCaptcha($errorMsg);
            }
        }

        if (isset($theField)) {
            return $theField;
        }

        return $captcha;
    }


    /**
     * Preforms user processing of input array - triggered right after the function call to evalValues() IF TypoScript property "evalFunc" was set.
     *
     * @param string $mConfKey Key pointing to the property in TypoScript holding the configuration for this processing (here: "evalFunc.*"). Well: at least its safe to say that "parentObj" in this array passed to the function is a reference back to this object.
     * @param array $passVar The $this->dataArr passed for processing
     *
     * @return array The processed $passVar ($this->dataArr)
     * @see init(), evalValues()
     */
    function userProcess($mConfKey, $passVar)
    {
        if ($this->conf[$mConfKey]) {
            $funcConf = $this->conf[$mConfKey . '.'];
            $funcConf['parentObj'] = $this;
            $passVar = $this->getTypoScriptFrontendController()->cObj->callUserFunction($this->conf[$mConfKey],
                $funcConf, $passVar);
        }

        return $passVar;
    }

    /**
     * User processing of contnet
     *
     * @param string $confVal Value of the TypoScript object triggering the processing.
     * @param array $confArr Properties of the TypoScript object triggering the processing. The key "parentObj" in this array is passed to the function as a reference back to this object.
     * @param mixed $passVar Input variable to process
     *
     * @return mixed Processed input variable, $passVar
     * @see userProcess(), save(), modifyDataArrForFormUpdate()
     */
    function userProcess_alt($confVal, $confArr, $passVar)
    {
        if ($confVal) {
            $funcConf = $confArr;
            $funcConf['parentObj'] = $this;
            $passVar = $this->getTypoScriptFrontendController()->cObj->callUserFunction($confVal, $funcConf, $passVar);
        }

        return $passVar;
    }

    /*****************************************
     * Database manipulation functions
     *****************************************/

    /**
     * Performs the saving of records, either edited or created.
     *
     * @return    void
     * @see init()
     */
    function save()
    {
        switch ($this->cmd) {
            case 'edit':
                $theUid = $this->dataArr['uid'];
                $origArr = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($this->theTable,
                    $theUid);        // Fetches the original record to check permissions
                if ($this->conf['edit'] && ($this->getTypoScriptFrontendController()->loginUser || $this->aCAuth($origArr))) {    // Must be logged in in order to edit  (OR be validated by email)
                    $newFieldList = implode(',', array_intersect(explode(',', $this->fieldList),
                        GeneralUtility::trimExplode(',', $this->conf['edit.']['fields'], 1)));
                    if ($this->aCAuth($origArr) || $this->cObj->DBmayFEUserEdit($this->theTable, $origArr,
                            $this->getTypoScriptFrontendController()->fe_user->user, $this->conf['allowedGroups'],
                            $this->conf['fe_userEditSelf'])
                    ) {
                        $this->cObj->DBgetUpdate($this->theTable, $theUid, $this->dataArr, $newFieldList, true);
                        $this->currentArr = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($this->theTable,
                            $theUid);
                        $this->userProcess_alt($this->conf['edit.']['userFunc_afterSave'],
                            $this->conf['edit.']['userFunc_afterSave.'],
                            ['rec' => $this->currentArr, 'origRec' => $origArr]);
                        $this->saved = 1;
                    } else {
                        $this->error = '###TEMPLATE_NO_PERMISSIONS###';
                    }
                }
                break;
            default:
                if ($this->conf['create']) {
                    $newFieldList = implode(',', array_intersect(explode(',', $this->fieldList),
                        GeneralUtility::trimExplode(',', $this->conf['create.']['fields'], 1)));
                    $this->cObj->DBgetInsert($this->theTable, $this->thePid, $this->dataArr, $newFieldList, true);
                    $newId = $this->databaseConnection->sql_insert_id();

                    if ($this->theTable == 'fe_users' && $this->conf['fe_userOwnSelf']) {        // enables users, creating logins, to own them self.
                        $extraList = '';
                        $dataArr = [];
                        if ($GLOBALS['TCA'][$this->theTable]['ctrl']['fe_cruser_id']) {
                            $field = $GLOBALS['TCA'][$this->theTable]['ctrl']['fe_cruser_id'];
                            $dataArr[$field] = $newId;
                            $extraList .= ',' . $field;
                        }
                        if ($GLOBALS['TCA'][$this->theTable]['ctrl']['fe_crgroup_id']) {
                            $field = $GLOBALS['TCA'][$this->theTable]['ctrl']['fe_crgroup_id'];
                            list($dataArr[$field]) = explode(',', $this->dataArr['usergroup']);
                            $dataArr[$field] = intval($dataArr[$field]);
                            $extraList .= ',' . $field;
                        }
                        if (count($dataArr)) {
                            $this->cObj->DBgetUpdate($this->theTable, $newId, $dataArr, $extraList, true);
                        }
                    }

                    $this->currentArr = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($this->theTable,
                        $newId);
                    $this->userProcess_alt($this->conf['create.']['userFunc_afterSave'],
                        $this->conf['create.']['userFunc_afterSave.'], ['rec' => $this->currentArr]);

                    // reloading the currentArr from the DB so that any DB changes in userFunc is taken into account
                    unset($this->currentArr);
                    $this->currentArr = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($this->theTable,
                        $newId);

                    $this->saved = 1;
                }
                break;
        }
    }

    /**
     * Deletes the record from table/uid, $this->theTable/$this->recUid, IF the fe-user has permission to do so.
     * If the deleted flag should just be set, then it is done so. Otherwise the record truely is deleted along with any attached files.
     * Called from init() if "cmd" was set to "delete" (and some other conditions)
     *
     * @return string void
     * @see init()
     */
    protected function deleteRecord()
    {
        if ($this->conf['delete']) {    // If deleting is enabled
            $origArr = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($this->theTable, $this->recUid);
            if ($this->getTypoScriptFrontendController()->loginUser || $this->aCAuth($origArr)) {    // Must be logged in OR be authenticated by the aC code in order to delete
                // If the recUid selects a record.... (no check here)
                if (is_array($origArr)) {
                    if ($this->aCAuth($origArr) || $this->cObj->DBmayFEUserEdit($this->theTable, $origArr,
                            $this->getTypoScriptFrontendController()->fe_user->user, $this->conf['allowedGroups'],
                            $this->conf['fe_userEditSelf'])
                    ) {    // Display the form, if access granted.
                        if (!$GLOBALS['TCA'][$this->theTable]['ctrl']['delete']) {    // If the record is fully deleted... then remove the image (or any file) attached.
                            $this->deleteFilesFromRecord($this->recUid);
                        }
                        $this->cObj->DBgetDelete($this->theTable, $this->recUid, true);
                        $this->currentArr = $origArr;
                        $this->saved = 1;
                    } else {
                        $this->error = '###TEMPLATE_NO_PERMISSIONS###';
                    }
                }
            }
        }
    }

    /**
     * Deletes the files attached to a record and updates the record.
     * Table/uid is $this->theTable/$uid
     *
     * @param integer $uid Uid number of the record to delete from $this->theTable
     *
     * @return    void
     * @access private
     * @see deleteRecord()
     */
    protected function deleteFilesFromRecord($uid)
    {
        $table = $this->theTable;
        $rec = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($table, $uid);

        foreach ($GLOBALS['TCA'][$table]['columns'] as $field => $conf) {
            if ($conf['config']['type'] == 'group' && $conf['config']['internal_type'] == 'file') {

                $this->databaseConnection->exec_UPDATEquery($table, 'uid=' . intval($uid), [$field => '']);

                $delFileArr = explode(',', $rec[$field]);
                foreach ($delFileArr as $n) {
                    if ($n) {
                        $fpath = $conf['config']['uploadfolder'] . '/' . $n;
                        unlink($fpath);
                    }
                }
            }
        }
    }

    /*****************************************
     * Command "display" functions
     *****************************************/

    /**
     * Creates the preview display of delete actions
     *
     * @return    string        HTML content
     * @see init()
     */
    function displayDeleteScreen()
    {
        $content = '';
        if ($this->conf['delete']) {    // If deleting is enabled
            $origArr = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($this->theTable, $this->recUid);
            if ($this->getTypoScriptFrontendController()->loginUser || $this->aCAuth($origArr)) {    // Must be logged in OR be authenticated by the aC code in order to delete
                // If the recUid selects a record.... (no check here)
                if (is_array($origArr)) {
                    if ($this->aCAuth($origArr) || $this->cObj->DBmayFEUserEdit($this->theTable, $origArr,
                            $this->getTypoScriptFrontendController()->fe_user->user, $this->conf['allowedGroups'],
                            $this->conf['fe_userEditSelf'])
                    ) {    // Display the form, if access granted.
                        $this->markerArray['###HIDDENFIELDS###'] .= '<input type="hidden" name="rU" value="' . $this->recUid . '" />';
                        $content = $this->getPlainTemplate('###TEMPLATE_DELETE_PREVIEW###', $origArr);
                    } else {    // Else display error, that you could not edit that particular record...
                        $content = $this->getPlainTemplate('###TEMPLATE_NO_PERMISSIONS###');
                    }
                }
            } else {    // Finally this is if there is no login user. This must tell that you must login. Perhaps link to a page with create-user or login information.
                $content = $this->getPlainTemplate('###TEMPLATE_AUTH###');
            }
        } else {
            $content = 'Delete-option is not set in TypoScript';
        }

        return $content;
    }

    /**
     * Creates the "create" screen for records
     *
     * @return string HTML content
     * @see init()
     */
    protected function displayCreateScreen()
    {
        $content = '';
        if ($this->conf['create']) {
            $templateCode = $this->cObj->getSubpart($this->templateCode,
                ((!$this->getTypoScriptFrontendController()->loginUser || $this->conf['create.']['noSpecialLoginForm']) ? '###TEMPLATE_CREATE' . $this->previewLabel . '###' : '###TEMPLATE_CREATE_LOGIN' . $this->previewLabel . '###'));
            $failure = GeneralUtility::_GP('noWarnings') ? '' : $this->failure;
            if (!$failure) {
                $templateCode = $this->cObj->substituteSubpart($templateCode, '###SUB_REQUIRED_FIELDS_WARNING###', '');
            }

            $templateCode = $this->removeRequired($templateCode, $failure);
            $this->setCObjects($templateCode);

            if (!is_array($this->dataArr)) {
                $this->dataArr = [];
            }

            $markerArray = $this->cObj->fillInMarkerArray($this->markerArray, $this->dataArr, '', true, 'FIELD_',
                $this->recInMarkersHSC);
            if ($this->conf['create.']['preview'] && !$this->previewLabel) {
                $markerArray['###HIDDENFIELDS###'] .= '<input type="hidden" name="preview" value="1" />';
            }

            /* CAPTCHA */
            if (!$this->markerArray['###CAPTCHA###']) {
                $markerArray['###CAPTCHA###'] = $this->getCaptcha();
            }

            $content = $this->cObj->substituteMarkerArray($templateCode, $markerArray);
            //$content .= $this->cObj->getUpdateJS($this->modifyDataArrForFormUpdate($this->dataArr),
            //    $this->theTable . '_form', 'FE[' . $this->theTable . ']',
            //    $this->fieldList . $this->additionalUpdateFields);
        }

        return $content;
    }

    /**
     * Creates the edit-screen for records
     *
     * @return    string        HTML content
     * @see init()
     */
    protected function displayEditScreen()
    {
        if ($this->conf['edit']) {    // If editing is enabled
            $origArr = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($this->theTable,
                $this->dataArr['uid'] ? $this->dataArr['uid'] : $this->recUid);

            if ($this->getTypoScriptFrontendController()->loginUser || $this->aCAuth($origArr)) {    // Must be logged in OR be authenticated by the aC code in order to edit
                // If the recUid selects a record.... (no check here)
                if (is_array($origArr)) {
                    if ($this->aCAuth($origArr) || $this->cObj->DBmayFEUserEdit($this->theTable, $origArr,
                            $this->getTypoScriptFrontendController()->fe_user->user, $this->conf['allowedGroups'],
                            $this->conf['fe_userEditSelf'])
                    ) {    // Display the form, if access granted.
                        $content = $this->displayEditForm($origArr);
                    } else {    // Else display error, that you could not edit that particular record...
                        $content = $this->getPlainTemplate('###TEMPLATE_NO_PERMISSIONS###');
                    }
                } elseif ($this->getTypoScriptFrontendController()->loginUser) {    // If the recUid did not select a record, we display a menu of records. (eg. if no recUid)
                    $lockPid = $this->conf['edit.']['menuLockPid'] ? ' AND pid=' . intval($this->thePid) : '';

                    $res = $this->databaseConnection->exec_SELECTquery('*', $this->theTable,
                        '1 ' . $lockPid . $this->cObj->DBmayFEUserEditSelect($this->theTable,
                            $this->getTypoScriptFrontendController()->fe_user->user, $this->conf['allowedGroups'],
                            $this->conf['fe_userEditSelf']) . $this->getTypoScriptFrontendController()->sys_page->deleteClause($this->theTable));

                    if ($this->databaseConnection->sql_num_rows($res)) {    // If there are menu-items ...
                        $templateCode = $this->getPlainTemplate('###TEMPLATE_EDITMENU###');
                        $out = '';
                        $itemCode = $this->cObj->getSubpart($templateCode, '###ITEM###');
                        while ($menuRow = $this->databaseConnection->sql_fetch_assoc($res)) {
                            $markerArray = $this->cObj->fillInMarkerArray([], $menuRow, '', true, 'FIELD_',
                                $this->recInMarkersHSC);
                            $markerArray = $this->setCObjects($itemCode, $menuRow, $markerArray, 'ITEM_');
                            $out .= $this->cObj->substituteMarkerArray($itemCode, $markerArray);
                        }
                        $content = $this->cObj->substituteSubpart($templateCode, '###ALLITEMS###', $out);
                    } else {    // If there are not menu items....
                        $content = $this->getPlainTemplate('###TEMPLATE_EDITMENU_NOITEMS###');
                    }
                } else {
                    $content = $this->getPlainTemplate('###TEMPLATE_AUTH###');
                }
            } else {    // Finally this is if there is no login user. This must tell that you must login. Perhaps link to a page with create-user or login information.
                $content = $this->getPlainTemplate('###TEMPLATE_AUTH###');
            }
        } else {
            $content = 'Edit-option is not set in TypoScript';
        }

        return $content;
    }

    /**
     * Subfunction for displayEditScreen(); Takes a record and creates an edit form based on the template code for it.
     * This function is called if the user is editing a record and permitted to do so. Checked in displayEditScreen()
     *
     * @param array $origArr The array with the record to edit
     *
     * @return string HTML content
     * @access private
     * @see displayEditScreen()
     */
    function displayEditForm($origArr)
    {
        $currentArr = is_array($this->dataArr) ? $this->dataArr + $origArr : $origArr;

        if ($this->conf['debug']) {
            debug('displayEditForm(): ' . '###TEMPLATE_EDIT' . $this->previewLabel . '###', 1);
        }
        $templateCode = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_EDIT' . $this->previewLabel . '###');
        $failure = GeneralUtility::_GP('noWarnings') ? '' : $this->failure;
        if (!$failure) {
            $templateCode = $this->cObj->substituteSubpart($templateCode, '###SUB_REQUIRED_FIELDS_WARNING###', '');
        }

        $templateCode = $this->removeRequired($templateCode, $failure);

        $this->setCObjects($templateCode, $currentArr);

        $markerArray = $this->cObj->fillInMarkerArray($this->markerArray, $currentArr, '', true, 'FIELD_',
            $this->recInMarkersHSC);

        $markerArray['###HIDDENFIELDS###'] .= '<input type="hidden" name="FE[' . $this->theTable . '][uid]" value="' . $currentArr['uid'] . '" />';
        if ($this->conf['edit.']['preview'] && !$this->previewLabel) {
            $markerArray['###HIDDENFIELDS###'] .= '<input type="hidden" name="preview" value="1" />';
        }

        /* CAPTCHA */
        if (!$this->markerArray['###CAPTCHA###']) {
            $markerArray['###CAPTCHA###'] = $this->getCaptcha();
        }

        $content = $this->cObj->substituteMarkerArray($templateCode, $markerArray);
        $content .= $this->cObj->getUpdateJS($this->modifyDataArrForFormUpdate($currentArr), $this->theTable . '_form',
            'FE[' . $this->theTable . ']', $this->fieldList . $this->additionalUpdateFields);

        return $content;
    }

    /**
     * Processes socalled "setfixed" commands. These are commands setting a certain field in a certain record to a certain value. Like a link you can click in an email which will unhide a record to enable something. Or likewise a link which can delete a record by a single click.
     * The idea is that only some allowed actions like this is allowed depending on the configured TypoScript.
     *
     * @return    string        HTML content displaying the status of the action
     */
    function procesSetFixed()
    {
        $content = '';
        if ($this->conf['setfixed']) {
            $theUid = intval($this->recUid);
            $origArr = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($this->theTable, $theUid);
            $fD = GeneralUtility::_GP('fD');
            $sFK = GeneralUtility::_GP('sFK');

            $valuesConfiguredInTypoScript = isset($this->conf['setfixed.'][$sFK . '.']) ? $this->conf['setfixed.'][$sFK . '.'] : [];
            $fields = $valuesConfiguredInTypoScript;
            unset($fields['_FIELDLIST']);
            $fields = array_keys($fields);
            if (isset($valuesConfiguredInTypoScript['_FIELDLIST'])) {
                $fields = array_merge($fields,
                    GeneralUtility::trimExplode(',', $valuesConfiguredInTypoScript['_FIELDLIST']));
            }
            $valuesConfiguredInTypoScript['_FIELDLIST'] = implode(',', array_unique($fields));

            $fieldArr = [];
            if (!empty($valuesConfiguredInTypoScript) || $sFK == 'DELETE') {
                foreach ($valuesConfiguredInTypoScript as $field => $value) {
                    $origArr[$field] = $value;
                    $fieldArr[] = $field;
                }
                $theCode = $this->setfixedHash($origArr, $origArr['_FIELDLIST']);
                if (!strcmp($this->authCode, $theCode)) {
                    if ($sFK == 'DELETE') {
                        $this->cObj->DBgetDelete($this->theTable, $theUid, true);
                    } else {
                        $newFieldList = implode(',', array_intersect(GeneralUtility::trimExplode(',', $this->fieldList),
                            GeneralUtility::trimExplode(',', implode($fieldArr, ','), 1)));
                        unset($valuesConfiguredInTypoScript['_FIELDLIST']);
                        $this->cObj->DBgetUpdate($this->theTable, $theUid, $valuesConfiguredInTypoScript, $newFieldList,
                            true);

                        $this->currentArr = $this->getTypoScriptFrontendController()->sys_page->getRawRecord($this->theTable,
                            $theUid);
                        $this->userProcess_alt($this->conf['setfixed.']['userFunc_afterSave'],
                            $this->conf['setfixed.']['userFunc_afterSave.'],
                            ['rec' => $this->currentArr, 'origRec' => $origArr]);
                    }

                    // Outputting template
                    $this->markerArray = $this->cObj->fillInMarkerArray($this->markerArray, $origArr, '', true,
                        'FIELD_', $this->recInMarkersHSC);
                    $content = $this->getPlainTemplate('###TEMPLATE_SETFIXED_OK_' . $sFK . '###');
                    if (!$content) {
                        $content = $this->getPlainTemplate('###TEMPLATE_SETFIXED_OK###');
                    }

                    // Compiling email
                    $this->compileMail(
                        'SETFIXED_' . $sFK,
                        [$origArr],
                        $origArr[$this->conf['email.']['field']],
                        $this->conf['setfixed.']
                    );
                    // Clearing cache if set:
                    $this->clearCacheIfSet();
                    $this->setNoCacheHeader();

                } else {
                    $content = $this->getPlainTemplate('###TEMPLATE_SETFIXED_FAILED###');
                }
            } else {
                $content = $this->getPlainTemplate('###TEMPLATE_SETFIXED_FAILED###');
            }
        }

        return $content;
    }

    /*****************************************
     * Template processing functions
     *****************************************/
    /**
     * Remove required parts from template code string
     *     Works like this:
     *         - You insert subparts like this ###SUB_REQUIRED_FIELD_'.$theField.'### in the template that tells what is required for the field, if it's not correct filled in.
     *         - These subparts are all removed, except if the field is listed in $failure string!
     *        Only fields that are found in $this->requiredArr is processed.
     *
     * @param string $templateCode The template HTML code
     * @param string $failure Comma list of fields which has errors (and therefore should not be removed)
     *
     * @return string The processed template HTML code
     */
    protected function removeRequired($templateCode, $failure)
    {
        foreach ($this->requiredArr as $theField) {
            if (!GeneralUtility::inList($failure, $theField)) {
                $templateCode = $this->cObj->substituteSubpart($templateCode,
                    '###SUB_REQUIRED_FIELD_' . $theField . '###', '');
            }
        }

        return $templateCode;
    }

    /**
     * Returns template subpart HTML code for the key given
     *
     * @param string $key Subpart marker to return subpart for.
     * @param array $r Optional data record array. If set, then all fields herein will also be substituted if found as markers in the template
     *
     * @return    string        The subpart with all markers found in current $this->markerArray substituted.
     * @see tslib_cObj::fillInMarkerArray()
     */
    protected function getPlainTemplate($key, $r = [])
    {
        if ($this->conf['debug']) {
            debug('getPlainTemplate(): ' . $key, 1);
        }
        $templateCode = $this->cObj->getSubpart($this->templateCode, $key);
        $this->setCObjects($templateCode, is_array($r) ? $r : []);

        /* CAPTCHA */
        if (!$this->markerArray['###CAPTCHA###']) {
            $this->markerArray['###CAPTCHA###'] = $this->getCaptcha();
        }

        $markerArray = is_array($r) ? $this->cObj->fillInMarkerArray($this->markerArray, $r, '', true, 'FIELD_',
            $this->recInMarkersHSC) : $this->markerArray;


        $content = $this->cObj->substituteMarkerArray($templateCode, $markerArray);

        return $content;
    }

    /**
     * Modifies input array for passing on to tslib_cObj::getUpdateJS() which produces some JavaScript for form evaluation or the like.
     *
     * @param array $inputArr The data array
     *
     * @return array The processed input array
     * @see displayCreateScreen(), displayEditForm(), tslib_cObj::getUpdateJS()
     */
    protected function modifyDataArrForFormUpdate($inputArr)
    {
        if (is_array($this->conf[$this->cmdKey . '.']['evalValues.'])) {
            foreach ($this->conf[$this->cmdKey . '.']['evalValues.'] as $theField => $theValue) {
                $listOfCommands = GeneralUtility::trimExplode(',', $theValue, 1);
                foreach ($listOfCommands as $cmd) {
                    $cmdParts = preg_split('/\[|\]/',
                        $cmd);    // Point is to enable parameters after each command enclosed in brackets [..]. These will be in position 1 in the array.
                    $theCmd = trim($cmdParts[0]);
                    switch ($theCmd) {
                        case 'twice':
                            if (isset($inputArr[$theField])) {
                                if (!isset($inputArr[$theField . '_again'])) {
                                    $inputArr[$theField . '_again'] = $inputArr[$theField];
                                }
                                $this->additionalUpdateFields .= ',' . $theField . '_again';
                            }
                            break;
                    }
                }
            }
        }
        if (is_array($this->conf['parseValues.'])) {
            foreach ($this->conf['parseValues.'] as $theField => $theValue) {
                $listOfCommands = GeneralUtility::trimExplode(',', $theValue, 1);
                foreach ($listOfCommands as $cmd) {
                    $cmdParts = preg_split('/\[|\]/',
                        $cmd);    // Point is to enable parameters after each command enclosed in brackets [..]. These will be in position 1 in the array.
                    $theCmd = trim($cmdParts[0]);
                    switch ($theCmd) {
                        case 'multiple':
                            if (isset($inputArr[$theField]) && !$this->isPreview()) {
                                $inputArr[$theField] = explode(',', $inputArr[$theField]);
                            }
                            break;
                        case 'checkArray':
                            if ($inputArr[$theField] && !$this->isPreview()) {
                                for ($a = 0; $a <= 30; $a++) {
                                    if ($inputArr[$theField] & pow(2, $a)) {
                                        $alt_theField = $theField . '][' . $a;
                                        $inputArr[$alt_theField] = 1;
                                        $this->additionalUpdateFields .= ',' . $alt_theField;
                                    }
                                }
                            }
                            break;
                    }
                }
            }
        }

        $inputArr = $this->userProcess_alt(
            $this->conf['userFunc_updateArray'],
            $this->conf['userFunc_updateArray.'],
            $inputArr
        );

        return $this->escapeHTML($inputArr);
    }

    /**
     * Will render TypoScript cObjects (configured in $this->conf['cObjects.']) and add their content to keys in a markerArray, either the array passed to the function or the internal one ($this->markerArray) if the input $markerArray is not set.
     *
     * @param string $templateCode The current template code string. Is used to check if the marker string is found and if not, the content object is not rendered!
     * @param array $currentArr An alternative data record array (if empty then $this->dataArr is used)
     * @param mixed $markerArray An alternative markerArray to fill in (instead of $this->markerArray). If you want to set the cobjects in the internal $this->markerArray, then just set this to non-array value.
     * @param string $specialPrefix Optional prefix to set for the marker strings.
     *
     * @return array The processed $markerArray (if given).
     */
    protected function setCObjects($templateCode, $currentArr = [], $markerArray = '', $specialPrefix = '')
    {
        if (is_array($this->conf['cObjects.'])) {

            foreach ($this->conf['cObjects.'] as $theKey => $theConf) {
                if (!strstr($theKey, '.')) {
                    if (strstr($templateCode, '###' . $specialPrefix . 'CE_' . $theKey . '###')) {
                        $cObjCode = $this->cObj->cObjGetSingle($this->conf['cObjects.'][$theKey],
                            $this->conf['cObjects.'][$theKey . '.'], 'cObjects.' . $theKey);

                        if (!is_array($markerArray)) {
                            $this->markerArray['###' . $specialPrefix . 'CE_' . $theKey . '###'] = $cObjCode;
                        } else {
                            $markerArray['###' . $specialPrefix . 'CE_' . $theKey . '###'] = $cObjCode;
                        }
                    }
                    if (strstr($templateCode, '###' . $specialPrefix . 'PCE_' . $theKey . '###')) {
                        /** @var $local_cObj ContentObjectRenderer */
                        $local_cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
                        $local_cObj->start(count($currentArr) ? $currentArr : $this->dataArr, $this->theTable);
                        $cObjCode = $local_cObj->cObjGetSingle($this->conf['cObjects.'][$theKey],
                            $this->conf['cObjects.'][$theKey . '.'], 'cObjects.' . $theKey);

                        if (!is_array($markerArray)) {
                            $this->markerArray['###' . $specialPrefix . 'PCE_' . $theKey . '###'] = $cObjCode;
                        } else {
                            $markerArray['###' . $specialPrefix . 'PCE_' . $theKey . '###'] = $cObjCode;
                        }
                    }
                }
            }
        }

        return $markerArray;
    }

    /**
     * return the captcha code. Uses the TEMPLATE_CAPTCHA subpart to
     *
     * @param string $errorMsg : the captcha error message
     *
     * @return string html    $captcha: the captcha code;
     */
    protected function getCaptcha($errorMsg = '')
    {
        if (ExtensionManagementUtility::isLoaded('captcha')) {
            $templateCodeCaptcha = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_CAPTCHA###');
            $markerArrayCaptcha['###CAPTCHA_IMG###'] = '<img src="/' . ExtensionManagementUtility::siteRelPath('captcha') . 'captcha/captcha.php" alt="" />';

            if (!empty($errorMsg)) {
                $markerArrayCaptcha['###EVAL_ERROR_FIELD_captcha###'] = $errorMsg;
            } else {
                $templateCodeCaptcha = $this->cObj->substituteSubpart($templateCodeCaptcha,
                    '###SUB_REQUIRED_FIELD_captcha###', '');
            }

            $captcha = $this->cObj->substituteMarkerArray($templateCodeCaptcha, $markerArrayCaptcha);
        } else {
            $captcha = '';
        }

        return $captcha;
    }

    /*****************************************
     * Emailing
     *****************************************/

    /**
     * Sends info mail to user
     *
     * @return string HTML content message
     * @see init(),compileMail(), sendMail()
     */
    protected function sendInfoMail()
    {
        if ($this->conf['infomail'] && $this->conf['email.']['field']) {
            $fetch = GeneralUtility::_GP('fetch');

            if ($fetch) {
                $this->evalValues();
            }

            //check the failureMsg array, since evalValues is called
            $captcha = true;
            if (is_array($this->failureMsg['captcha'])) {
                $captcha = false;
            }

            if ($fetch && $captcha) {
                // Getting infomail config.
                $key = trim(GeneralUtility::_GP('key'));
                if (is_array($this->conf['infomail.'][$key . '.'])) {
                    $config = $this->conf['infomail.'][$key . '.'];
                } else {
                    $config = $this->conf['infomail.']['default.'];
                }
                $pidLock = '';
                if (!$config['dontLockPid']) {
                    $pidLock = 'AND pid IN (' . $this->thePid . ') ';
                }

                //get PID recursively
                if ($this->conf["pidRecursive"]) {
                    $pidList = $this->cObj->getTreeList($this->thePid, 100) . ',' . $this->thePid;
                    $pidLock = "AND pid IN (" . $pidList . ")";
                }

                // Getting records
                $fetchInt = MathUtility::canBeInterpretedAsInteger($fetch);

                if ($fetchInt) {
                    $DBrows = $this->getTypoScriptFrontendController()->sys_page->getRecordsByField($this->theTable,
                        'uid', $fetch, $pidLock,
                        '', '', '1');
                } elseif ($fetch) {    // $this->conf['email.']['field'] must be a valid field in the table!
                    $DBrows = $this->getTypoScriptFrontendController()->sys_page->getRecordsByField($this->theTable,
                        $this->conf['email.']['field'], $fetch, $pidLock, '', '', '100');
                }

                // Processing records
                if (isset($DBrows) && is_array($DBrows)) {
                    $recipient = $DBrows[0][$this->conf['email.']['field']];
                    $this->compileMail($config['label'], $DBrows, $recipient, $this->conf['setfixed.']);
                    $content = $this->getPlainTemplate('###TEMPLATE_INFOMAIL_SENT###');
                } else {
                    $content = $this->getPlainTemplate('###TEMPLATE_INFOMAIL_NORECORD###');
                }

            } else {
                $content = $this->getPlainTemplate('###TEMPLATE_INFOMAIL###');
                $content = $this->removeRequired($content, $this->failure);
            }
        } else {
            $content = 'Error: infomail option is not available or emailField is not setup in TypoScript';
        }

        return $content;
    }

    /**
     * Compiles and sends a mail based on input values + template parts. Looks for a normal and an "-admin" template and may send both kinds of emails. See documentation in TSref.
     *
     * @param string $key A key which together with $this->emailMarkPrefix will identify the part from the template code to use for the email.
     * @param array $DBrows An array of records which fields are substituted in the templates
     * @param mixed $recipient Mail recipient. If string then its supposed to be an email address. If integer then its a uid of a fe_users record which is looked up and the email address from here is used for sending the mail.
     * @param array $setFixedConfig Additional fields to set in the markerArray used in the substitution process
     *
     * @return    void
     */
    protected function compileMail($key, $DBrows, $recipient, $setFixedConfig = [])
    {
        $this->getTimeTracker()->push('compileMail');
        $key = $this->emailMarkPrefix . $key;

        $userContent['all'] = trim($this->cObj->getSubpart($this->templateCode, '###' . $key . '###'));
        $adminContent['all'] = trim($this->cObj->getSubpart($this->templateCode, '###' . $key . '-ADMIN###'));
        $userContent['rec'] = $this->cObj->getSubpart($userContent['all'], '###SUB_RECORD###');
        $adminContent['rec'] = $this->cObj->getSubpart($adminContent['all'], '###SUB_RECORD###');

        foreach ($DBrows as $r) {
            $markerArray = $this->cObj->fillInMarkerArray($this->markerArray, $r, '', 0);
            $markerArray = $this->setCObjects($userContent['rec'] . $adminContent['rec'], $r, $markerArray, 'ITEM_');
            $markerArray['###SYS_AUTHCODE###'] = $this->authCode($r);
            $markerArray = $this->setfixed($markerArray, $setFixedConfig, $r);

            if ($userContent['rec']) {
                $userContent['accum'] .= $this->cObj->substituteMarkerArray($userContent['rec'], $markerArray);
            }
            if ($adminContent['rec']) {
                $adminContent['accum'] .= $this->cObj->substituteMarkerArray($adminContent['rec'], $markerArray);
            }
        }

        if ($userContent['all']) {
            $userContent['final'] .= $this->cObj->substituteSubpart($userContent['all'], '###SUB_RECORD###',
                $userContent['accum']);
        }
        if ($adminContent['all']) {
            $adminContent['final'] .= $this->cObj->substituteSubpart($adminContent['all'], '###SUB_RECORD###',
                $adminContent['accum']);
        }

        $recipientID = MathUtility::canBeInterpretedAsInteger($recipient);
        if ($recipientID) {
            $fe_userRec = $this->getTypoScriptFrontendController()->sys_page->getRawRecord('fe_users', $recipient);
            $recipient = $fe_userRec['email'];
        }

        $this->getTimeTracker()->setTSlogMessage('Template key: ###' . $key . '###, userContentLength: ' . strlen($userContent['final']) . ', adminContentLength: ' . strlen($adminContent['final']));

        //TODO: add optional Swiftmailer see #13129
        // send to admin, if set
        if ($this->conf['email.']['admin'] && $adminContent['final']) {
            $this->sendMail($this->conf['email.']['admin'], $adminContent['final']);
        }

        // send to recipient
        if ($userContent['final']) {
            $this->sendMail($recipient, $userContent['final']);
        }


        $this->getTimeTracker()->pull();
    }

    /**
     * Actually sends the requested mails (through $this->cObj->sendNotifyEmail or through $this->sendHTMLMail).
     * As of TYPO3 v4.3 with autoloader, a check for $GLOBALS['TSFE']->config['config']['incT3Lib_htmlmail'] has been included for backwards compatibility.
     *
     * @param string $recipient Recipient email address (or list)
     * @param string $content Content for the regular email to user
     *
     * @return void
     * @see compileMail(), sendInfoMail()
     */
    protected function sendMail($recipient, $content = '')
    {
        // Prepare the Mailer instance
        // init the swiftmailer object
        /** @var $mailer MailMessage */
        $mailer = GeneralUtility::makeInstance(MailMessage::class);
        $mailer->setFrom([$this->conf['email.']['from'] => $this->conf['email.']['fromName']]);
        $mailer->setReplyTo([$this->conf['email.']['from'] => $this->conf['email.']['fromName']]);
        $mailer->setTo([$recipient]);

        if ($this->isHTMLContent($content)) {
            //set HTML Subject
            $parts = preg_split('/<title>|<\/title>/i', $content, 3);
            $subject = trim($parts[1]) ? trim($parts[1]) : 'TYPO3 FE Admin message';
            $mailer->setSubject($subject);

            //set HTML Content
            $mailer->setBody($content, 'text/html');

        } else {
            // set subject from plain
            $parts = explode(LF, trim($content), 2); // First line is subject
            $subject = trim($parts[0]);
            $plain_message = trim($parts[1]);
            $mailer->setSubject($subject);

            // set plain text
            $mailer->setBody($plain_message, 'text/plain');
        }

        $mailer->send();
    }

    /**
     * Detects if content is HTML (looking for <html> tag as first and last in string)
     *
     * @param string $c Content string to test
     *
     * @return boolean Returns true if the content begins and ends with <html></html>-tags
     */
    protected function isHTMLContent($c)
    {
        $c = trim($c);
        $first = strtolower(substr($c, 0, 6));
        $last = strtolower(substr($c, -7));
        if ($first . $last == '<html></html>') {
            return true;
        }

        return false;
    }

    /*****************************************
     * Various helper functions
     *****************************************/
    /**
     * Returns true if authentication is OK based on the "aC" code which is a GET parameter set from outside with a hash string which must match some internal hash string.
     * This allows to authenticate editing without having a fe_users login
     * Uses $this->authCode which is set in init() by "GeneralUtility::_GP('aC');"
     *
     * @param array $r The data array for which to evaluate authentication
     *
     * @return boolean True if authenticated OK
     * @see authCode(), init()
     */
    function aCAuth($r)
    {
        if ($this->authCode && !strcmp($this->authCode, $this->authCode($r))) {
            return true;
        }
    }

    /**
     * Creating authentication hash string based on input record and the fields listed in TypoScript property "authcodeFields"
     *
     * @param array $r The data record
     * @param string $extra Additional string to include in the hash
     *
     * @return string Hash string of $this->codeLength (if TypoScript "authcodeFields" was set)
     * @see aCAuth()
     */
    protected function authCode($r, $extra = '')
    {
        $l = $this->codeLength;
        if ($this->conf['authcodeFields']) {
            $fieldArr = GeneralUtility::trimExplode(',', $this->conf['authcodeFields'], 1);
            $value = '';
            foreach ($fieldArr as $field) {
                $value .= $r[$field] . '|';
            }
            $value .= $extra . '|' . $this->conf['authcodeFields.']['addKey'];
            if ($this->conf['authcodeFields.']['addDate']) {
                $value .= '|' . date($this->conf['authcodeFields.']['addDate']);
            }
            $value .= $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'];

            return substr(md5($value), 0, $l);
        }

        return '';
    }

    /**
     * Adding keys to the marker array with "setfixed" GET parameters
     *
     * @param array $markerArray Marker-array to modify/add a key to.
     * @param array $setfixed TypoScript properties configuring "setfixed" for the plugin. Basically this is $this->conf['setfixed.'] passed along.
     * @param array $r The data record
     *
     * @return    array        Processed $markerArray
     * @see compileMail()
     */
    protected function setfixed($markerArray, $setfixed, $r)
    {
        if (is_array($setfixed)) {
            foreach ($setfixed as $theKey => $data) {
                if (!strcmp($theKey, 'DELETE')) {
                    $data = $setfixed['DELETE.'] ?: [];
                    $recCopy = $r;
                    $string = '&cmd=setfixed&sFK=' . rawurlencode($theKey) . '&rU=' . $r['uid'];
                    $string .= '&aC=' . $this->setfixedHash($recCopy, $data['_FIELDLIST']);
                    $markerArray['###SYS_SETFIXED_DELETE###'] = $string;
                    $markerArray['###SYS_SETFIXED_HSC_DELETE###'] = htmlspecialchars($string);
                } elseif (strstr($theKey, '.')) {
                    $fields = $data;
                    unset($fields['_FIELDLIST']);
                    $fields = array_keys($fields);
                    if (isset($data['_FIELDLIST'])) {
                        $fields = array_merge($fields, GeneralUtility::trimExplode(',', $data['_FIELDLIST']));
                    }
                    $data['_FIELDLIST'] = implode(',', array_unique($fields));

                    $theKey = substr($theKey, 0, -1);
                    if (is_array($data)) {
                        $recCopy = $r;
                        $string = '&cmd=setfixed&sFK=' . rawurlencode($theKey) . '&rU=' . $r['uid'];
                        foreach ($data as $fieldName => $fieldValue) {
                            $string .= '&fD%5B' . $fieldName . '%5D=' . rawurlencode($fieldValue);
                            $recCopy[$fieldName] = $fieldValue;
                        }
                        $string .= '&aC=' . $this->setfixedHash($recCopy, $data['_FIELDLIST']);
                        $markerArray['###SYS_SETFIXED_' . $theKey . '###'] = $string;
                        $markerArray['###SYS_SETFIXED_HSC_' . $theKey . '###'] = htmlspecialchars($string);
                    }
                }
            }
        }

        return $markerArray;
    }

    /**
     * Creating hash string for setFixed. Much similar to authCode()
     *
     * @param array $recCopy : The data record
     * @param string $fields : List of fields to use
     *
     * @return string Hash string of $this->codeLength (if TypoScript "authcodeFields" was set)
     * @see setfixed(),authCode()
     */
    protected function setfixedHash($recCopy, $fields = '')
    {
        if ($fields) {
            $fieldArr = GeneralUtility::trimExplode(',', $fields, 1);
            foreach ($fieldArr as $k => $v) {
                $recCopy_temp[$k] = $recCopy[$v];
            }
        } else {
            $recCopy_temp = $recCopy;
        }
        $encStr = implode('|',
                $recCopy_temp) . '|' . $this->conf['authcodeFields.']['addKey'] . '|' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'];
        $hash = substr(md5($encStr), 0, $this->codeLength);

        return $hash;
    }


    /**
     * Returns true if preview display is on.
     *
     * @return    boolean
     */
    protected function isPreview()
    {
        return ($this->conf[$this->cmdKey . '.']['preview'] && $this->preview);
    }

    /**
     * Creates an instance of class "t3lib_basicFileFunctions" in $this->fileFunc (if not already done)
     *
     * @return    void
     */
    protected function createFileFuncObj()
    {
        if (!$this->fileFunc) {
            $this->fileFunc = GeneralUtility::makeInstance(BasicFileUtility::class);
        }
    }

    /**
     * If TypoScript property clearCacheOfPages is set then all page ids in this value will have their cache cleared
     *
     * @return    void
     */
    function clearCacheIfSet()
    {
        if ($this->conf['clearCacheOfPages']) {
            $cc_pidList = $this->databaseConnection->cleanIntList($this->conf['clearCacheOfPages']);
            $this->getTypoScriptFrontendController()->clearPageCacheContent_pidList($cc_pidList);
        }
    }

    /**
     * Set http header, so content won't be cached or indexed by search engine
     *
     * @return void
     */
    protected function setNoCacheHeader()
    {
        //send no-cache header
        // HTTP 1.1
        header('Cache-Control: no-cache, must-revalidate');
        // HTTP 1.0
        header('Pragma: no-cache');
        // robots
        header('X-Robots-Tag: noindex, nofollow');
    }

    /**
     * Returns an error message for the field/command combination inputted. The error message is looked up in the TypoScript properties (evalErrors.[fieldname].[command]) and if empty then the $label value is returned
     *
     * @param string $theField Field name
     * @param string $theCmd Command identifier string
     * @param string $label Alternative label, shown if no other error string was found
     *
     * @return    string        The error message string
     */
    function getFailure($theField, $theCmd, $label)
    {
        return isset($this->conf['evalErrors.'][$theField . '.'][$theCmd]) ? $this->conf['evalErrors.'][$theField . '.'][$theCmd] : $label;
    }

    /**
     * Will escape HTML-tags
     *
     * @param mixed $var The unescaped data
     *
     * @return mixed The processed input data
     */
    function escapeHTML($var)
    {
        if (is_array($var)) {
            foreach ($var as $k => $value) {
                $var[$k] = $this->escapeHTML($var[$k]);
            }
        } else {
            $var = htmlspecialchars($var, ENT_NOQUOTES);
        }

        return $var;
    }

    /**
     * @return TypoScriptFrontendController
     */
    protected function getTypoScriptFrontendController()
    {
        return $GLOBALS['TSFE'];
    }

    /**
     * @return TimeTracker
     */
    protected function getTimeTracker()
    {
        return $GLOBALS['TT'];
    }
}
