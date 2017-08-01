<?php
/**
 * EGroupware - Mail - worker class
 *
 * @link http://www.egroupware.org
 * @package api
 * @subpackage amil
 * @author Stylite AG [info@stylite.de]
 * @copyright (c) 2013-2016 by Stylite AG <info-AT-stylite.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

namespace EGroupware\Api;

use Horde_Imap_Client;
use Horde_Imap_Client_Ids;
use Horde_Imap_Client_Fetch_Query;
use Horde_Imap_Client_Data_Fetch;
use Horde_Mime_Part;
use Horde_Imap_Client_Search_Query;
use Horde_Idna;
use Horde_Imap_Client_DateTime;
use Horde_Mime_Headers;
use Horde_Compress;
use Horde_Mime_Magic;
use Horde_Mail_Rfc822;
use Horde_Mail_Rfc822_List;
use Horde_Mime_Mdn;
use EGroupware\Api;
use EGroupware\Api\Mail;
use EGroupware\Api\Mail\EWS\Lib;

use tidy;

/**
 * Mail worker class
 *  -provides backend functionality for all classes in Mail
 *  -provides classes that may be used by other apps too
 *
 * @link https://github.com/horde/horde/blob/master/imp/lib/Contents.php
 */
class Mail_EWS extends Mail
{
    protected function __construct($_displayCharset='utf-8',$_restoreSession=true, $_profileID=0, $_oldImapServerObject=false, $_reuseCache=null)
    {
        if (is_null($_reuseCache)) $_reuseCache = $_restoreSession;
        if (!empty($_displayCharset)) self::$displayCharset = $_displayCharset;
        // not nummeric, we assume we only want an empty class object
        if (!is_numeric($_profileID)) return true;
        if ($_restoreSession)
        {
            //error_log(__METHOD__." Session restore ".function_backtrace());
            $this->restoreSessionData();
            $lv_mailbox = $this->sessionData['mailbox'];
            $firstMessage = $this->sessionData['previewMessage'];
        }
        else
        {
            $this->restoreSessionData();
            $lv_mailbox = $this->sessionData['mailbox'];
            $firstMessage = $this->sessionData['previewMessage'];
            $this->sessionData = array();
        }
        if (!$_reuseCache) $this->forcePrefReload($_profileID,!$_reuseCache);
        try
        {
            $this->profileID = self::validateProfileID($_profileID);
            $this->accountid	= $GLOBALS['egw_info']['user']['account_id'];

            //error_log(__METHOD__.' ('.__LINE__.') '." ProfileID ".$this->profileID.' called from:'.function_backtrace());
            $acc = Mail\Account::read($this->profileID);
        }
        catch (\Exception $e)
        {
            throw new Exception(__METHOD__." failed to instanciate Mail for $_profileID / ".$this->profileID." with error:".$e->getMessage());
        }
        //error_log(__METHOD__.' ('.__LINE__.') '.array2string($acc->imapServer()));
        $this->icServer = ($_oldImapServerObject?$acc->oldImapServer():$acc->imapServer());
        $this->ogServer = $acc->smtpServer();
        // TODO: merge mailprefs into userprefs, for easy treatment
        $this->mailPreferences = $GLOBALS['egw_info']['user']['preferences']['mail'];
        $this->htmlOptions  = $this->mailPreferences['htmlOptions'];
        if (isset($this->icServer->ImapServerId) && !empty($this->icServer->ImapServerId))
        {
            $_profileID = $this->profileID = $GLOBALS['egw_info']['user']['preferences']['mail']['ActiveProfileID'] = $this->icServer->ImapServerId;
        }

        if (is_null(self::$mailConfig)) self::$mailConfig = Config::read('mail');
    }
    function getFolderArrays ($_nodePath = null, $_onlyTopLevel = false, $_search= 2, $_subscribedOnly = false, $_getCounter = false) {
        $efolders = Lib::getTreeFolders( $this->profileID );
        $foldersList = array();
        foreach ( $efolders as $folder ) {
            $foldersList[ $folder['name'] ] = array(
                'MAILBOX'	=>	$folder['name'] ,
                'ATTRIBUTES'	=>	array(
                    '\\hasChildren', '\\subscribed', 
                ),
                'ID' => $folder['id'],
                'CAN_DELETE' => $folder['delete'],
                'delimiter'	=> '/',
                'SUBSCRIBED' =>	1,
                'counter' => array(
                    'MESSAGES' => '1',
                    'RECENT' => '0',
                    'UIDNEXT' => '2',
                    'UIDVALIDITY' => '1465840832',
                    'UNSEEN' => '0',
                )
            );				
        }
        //error_log(__METHOD__."(".print_r($foldersList, true).")");
        return $foldersList;
    }	
    function getJunkFolder($_checkexistance=TRUE)
    {
        return false;
    }
    function getDraftFolder($_checkexistance=TRUE)
    {
        return false;
    }
    function getTemplateFolder($_checkexistance=TRUE)
    {
        return false;
    }
    function getTrashFolder($_checkexistance=TRUE)
    {
        return false;
    }
    function getSentFolder($_checkexistance=TRUE)
    {
        return false;
    }
    function getOutboxFolder($_checkexistance=TRUE)
    {
        return false;
    }
    function getArchiveFolder($_checkexistance=TRUE)
    {
        return false;
    }
    function getHeaders($_folderName, $_startMessage, $_numberOfMessages, $_sort, $_reverse, $_filter, $_thisUIDOnly=null, $_cacheResult=true, $_fetchPreviews=false) 
    {
        // Get default folder if none
        /* if ( !$folder ) { */
        /* 	list($fullprofile, $fullfolder) = mail_bo::getProfileDefaultFolder( $profile ); */
        /* 	$folder = explode('::', $fullfolder)[1]; */
        /* } */

        list( $folderName, $folderID ) = explode( '::', $_folderName );

        $sort_map = array(
            'subject'		=> 'item:Subject',
            'size'			=> 'item:Size',
            'date'			=> 'item:DateTimeSent',
            'arrival'	    => 'item:DateTimeCreated',
            'uid'			=> 'item:ItemId',
            'attachments'	=> 'item:HasAttachments',
            'seen'			=> 'item:IsRead',
            'toaddress'	    => 'item:DisplayTo',
            'fromaddress'	=> 'message:From',
            'address'	    => 'message:From',
        );
        $sort = array(
            'order' => $sort_map[ $_sort ],
            'sort' => 'Descending',
        );
        $_startMessage--;

        $array = Lib::getMails( $this->profileID, $folderID, $_startMessage, $_numberOfMessages, $sort, $_filter );
        if ( empty( $array['rows'] ) )
            return array();

        $emails = array();
        foreach ( $array['rows'] as $index => &$email ) {
            if ( !$email->HasAttachments ) {
                $attachments = array();
            }
            else {
                // For emails with attachment, do extra request to get attachment info
                $attachments = $this->getAttachments( $email->ItemId->Id );
            }
            // Set fields
            // Appended ChangeKey to ID with default delimiter. This means that uid is still recognized since array is exploded,
            // and in specific cases ChangeKey is available for use (eg. set read/unread )

            // Convert datetimes
            $properties = array( 'DateTimeSent', 'DateTimeCreated');
            $zone = new \DateTimeZone( $GLOBALS['egw_info']['server']['server_timezone'] );
            foreach ( $properties as $property ) {
                $date = new \DateTime($email->$property);    		
                $date->setTimezone($zone);
                $email->$property = $date->format('c');	
            }			

            $emails[] = array(
                'subject' => $email->Subject,
                'size' => $email->Size,
                'date' => $email->DateTimeSent,
                'internaldate' => $email->DateTimeCreated,
                'mimetype' => 'multipart/alternative',
                'uid' => $email->ItemId->Id .'||'. $email->ItemId->ChangeKey,
                'attachments' => $attachments,
                // 'uid' => "e$index",
                'priority' => 3,
                'recent' => '',
                'flagged' => '',
                'answered' => '',
                'forwarded' => '',
                'deleted' => '',
                'seen' => $email->IsRead,
                'draft' => '',
                'mdnsent' => '',
                'mdnnotsent' => '',
                'label1' => '',
                'label2' => '',
                'label3' => '',
                'label4' => '',
                'label5' => '',
                'sender_address' => $email->From->Mailbox->Name,
                'to_address' => $email->DisplayTo,
                'cc_addresses' => array( $email->DisplayCc ),
            );
        }

        return array( 'header' => $emails, 'info' => array( 'total' => $array['count'] ) );
    }
    public function getAttachments( $mailID ) {
        $email = Lib::getMailBody( $this->profileID, $mailID );

        // Format data (one or many)
        $files = array();
        $items = array();
        if ( $email->Attachments->FileAttachment )
            $files = ( is_array( $email->Attachments->FileAttachment ) ? $email->Attachments->FileAttachment : array( $email->Attachments->FileAttachment ) );
        if ( $email->Attachments->ItemAttachment )
            $items = ( is_array( $email->Attachments->ItemAttachment ) ? $email->Attachments->ItemAttachment : array( $email->Attachments->ItemAttachment ) );
        $data = array_merge( $files, $items );

        // Format for use in header
        $attachments = array();
        foreach ( $data as $attachment ) {
            $attachments[] = array(
                'size' => '0',
                'filename' => $attachment->Name,
                'type' => $attachment->ContentType,
                'mimeType' => $attachment->ContentType,
                'uid' => $attachment->AttachmentId->Id,
                'cid' => '',
                'partID' => $attachment->AttachmentId->Id,
                'name' => $attachment->Name,
            );
        }
        return $attachments;
    }
}
