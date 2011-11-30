<?php
/***********************************************
* File      :   maildir.php
* Project   :   Z-Push
* Descr     :   This backend is based on
*               'BackendDiff' which handles the
*               intricacies of generating
*               differentials from static
*               snapshots. This means that the
*               implementation here needs no
*               state information, and can simply
*               return the current state of the
*               messages. The diffbackend will
*               then compare the current state
*               to the known last state of the PDA
*               and generate change increments
*               from that.
*
* Created   :   01.10.2007
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

include_once('lib/diffbackend.php');
include_once('include/mimeDecode.php');


class BackendMaildir extends BackendDiff {
    /**----------------------------------------------------------------------------------------------------------
     * default backend methods
     */

    /**
     * Authenticates the user - NOT EFFECTIVELY IMPLEMENTED
     * Normally some kind of password check would be done here.
     * Alternatively, the password could be ignored and an Apache
     * authentication via mod_auth_* could be done
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     */
    public function Logon($username, $domain, $password) {
        return true;
    }

    /**
     * Logs off
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        return true;
    }

    /**
     * Sends an e-mail
     * Not implemented here
     *
     * @param string        $rfc822     raw mail submitted by the mobile
     * @param string        $forward    id of the message to be attached below $rfc822
     * @param string        $reply      id of the message to be attached below $rfc822
     * @param string        $parent     id of the folder containing $forward or $reply
     * @param boolean       $saveInSent indicates if the mail should be saved in the Sent folder
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function SendMail($rfc822, $forward = false, $reply = false, $parent = false, $saveInSent = true) {
        return false;
    }

    /**
     * Returns the waste basket
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        return false;
    }

    /**
     * Returns the content of the named attachment as stream. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment.
     * Any information necessary to find the attachment must be encoded in that 'attname' property.
     * Data is written directly (with print $data;)
     *
     * @param string        $attname
     *
     * @access public
     * @return SyncItemOperationsAttachment
     * @throws StatusException
     */
    public function GetAttachmentData($attname) {
        list($id, $part) = explode(":", $attname);

        $fn = $this->findMessage($id);
        if ($fn == false)
            throw new StatusException(sprintf("BackendMaildir->GetAttachmentData('%s'): Error, requested message/attachment can not be found", $attname), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        // Parse e-mail
        $rfc822 = file_get_contents($this->getPath() . "/$fn");

        $message = Mail_mimeDecode::decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $rfc822, 'crlf' => "\n", 'charset' => 'utf-8'));

        include_once('include/stringstreamwrapper.php');
        $attachment = new SyncItemOperationsAttachment();
        $attachment->data = StringStreamWrapper::Open($message->parts[$part]->body);
        if (isset($message->parts[$part]->ctype_primary) && isset($message->parts[$part]->ctype_secondary))
            $attachment->contenttype = $message->parts[$part]->ctype_primary .'/'.$message->parts[$part]->ctype_secondary;

        return $attachment;
    }

    /**----------------------------------------------------------------------------------------------------------
     * implemented DiffBackend methods
     */


    /**
     * Returns a list (array) of folders.
     * In simple implementations like this one, probably just one folder is returned.
     *
     * @access public
     * @return array
     */
    public function GetFolderList() {
        $folders = array();

        $inbox = array();
        $inbox["id"] = "root";
        $inbox["parent"] = "0";
        $inbox["mod"] = "Inbox";

        $folders[]=$inbox;

        $sub = array();
        $sub["id"] = "sub";
        $sub["parent"] = "root";
        $sub["mod"] = "Sub";

//        $folders[]=$sub;

        return $folders;
    }

    /**
     * Returns an actual SyncFolder object
     *
     * @param string        $id           id of the folder
     *
     * @access public
     * @return object       SyncFolder with information
     */
    public function GetFolder($id) {
        if($id == "root") {
            $inbox = new SyncFolder();

            $inbox->serverid = $id;
            $inbox->parentid = "0"; // Root
            $inbox->displayname = "Inbox";
            $inbox->type = SYNC_FOLDER_TYPE_INBOX;

            return $inbox;
        } else if($id == "sub") {
            $inbox = new SyncFolder();
            $inbox->serverid = $id;
            $inbox->parentid = "root";
            $inbox->displayname = "Sub";
            $inbox->type = SYNC_FOLDER_TYPE_OTHER;

            return $inbox;
        } else {
            return false;
        }
    }


    /**
     * Returns folder stats. An associative array with properties is expected.
     *
     * @param string        $id             id of the folder
     *
     * @access public
     * @return array
     */
    public function StatFolder($id) {
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;

        return $stat;
    }


    /**
     * Creates or modifies a folder
     * not implemented
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $oldid          if empty -> new folder created, else folder is to be renamed
     * @param string        $displayname    new folder name (to be created, or to be renamed to)
     * @param int           $type           folder type
     *
     * @access public
     * @return boolean                      status
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type){
        return false;
    }

    /**
     * Deletes a folder
     *
     * @param string        $id
     * @param string        $parent         is normally false
     *
     * @access public
     * @return boolean                      status - false if e.g. does not exist
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function DeleteFolder($id, $parentid){
        return false;
    }

    /**
     * Returns a list (array) of messages
     *
     * @param string        $folderid       id of the parent folder
     * @param long          $cutoffdate     timestamp in the past from which on messages should be returned
     *
     * @access public
     * @return array/false  array with messages or false if folder is not available
     */
    public function GetMessageList($folderid, $cutoffdate) {
        $this->moveNewToCur();

        if($folderid != "root")
            return false;

        // return stats of all messages in a dir. We can do this faster than
        // just calling statMessage() on each message; We still need fstat()
        // information though, so listing 10000 messages is going to be
        // rather slow (depending on filesystem, etc)

        // we also have to filter by the specified cutoffdate so only the
        // last X days are retrieved. Normally, this would mean that we'd
        // have to open each message, get the Received: header, and check
        // whether that is in the filter range. Because this is much too slow, we
        // are depending on the creation date of the message instead, which should
        // normally be just about the same, unless you just did some kind of import.

        $messages = array();
        $dirname = $this->getPath();

        $dir = opendir($dirname);

        if(!$dir)
            return false;

        while($entry = readdir($dir)) {
            if($entry{0} == ".")
                continue;

            $message = array();

            $stat = stat("$dirname/$entry");

            if($stat["mtime"] < $cutoffdate) {
                // message is out of range for curoffdate, ignore it
                continue;
            }

            $message["mod"] = $stat["mtime"];

            $matches = array();

            // Flags according to http://cr.yp.to/proto/maildir.html (pretty authoritative - qmail author's website)
            if(!preg_match("/([^:]+):2,([PRSTDF]*)/",$entry,$matches))
                continue;
            $message["id"] = $matches[1];
            $message["flags"] = 0;

            if(strpos($matches[2],"S") !== false) {
                $message["flags"] |= 1; // 'seen' aka 'read' is the only flag we want to know about
            }

            array_push($messages, $message);
        }

        return $messages;
    }

    /**
     * Returns the actual SyncXXX object type.
     *
     * @param string            $folderid           id of the parent folder
     * @param string            $id                 id of the message
     * @param ContentParameters $contentparameters  parameters of the requested message (truncation, mimesupport etc)
     *
     * @access public
     * @return object/false     false if the message could not be retrieved
     */
    public function GetMessage($folderid, $id, $truncsize, $mimesupport = 0) {
        if($folderid != 'root')
            return false;

        $fn = $this->findMessage($id);

        // Get flags, etc
        $stat = $this->StatMessage($folderid, $id);

        // Parse e-mail
        $rfc822 = file_get_contents($this->getPath() . "/" . $fn);

        $message = Mail_mimeDecode::decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $rfc822, 'crlf' => "\n", 'charset' => 'utf-8'));

        $output = new SyncMail();

        $output->body = str_replace("\n", "\r\n", $this->getBody($message));
        $output->bodysize = strlen($output->body);
        $output->bodytruncated = 0; // We don't implement truncation in this backend
        $output->datereceived = $this->parseReceivedDate($message->headers["received"][0]);
        $output->displayto = $message->headers["to"];
        $output->importance = $message->headers["x-priority"];
        $output->messageclass = "IPM.Note";
        $output->subject = $message->headers["subject"];
        $output->read = $stat["flags"];
        $output->to = $message->headers["to"];
        $output->cc = $message->headers["cc"];
        $output->from = $message->headers["from"];
        $output->reply_to = isset($message->headers["reply-to"]) ? $message->headers["reply-to"] : null;

        // Attachments are only searched in the top-level part
        $n = 0;
        if(isset($message->parts)) {
            foreach($message->parts as $part) {
                if($part->ctype_primary == "application") {
                    $attachment = new SyncAttachment();
                    $attachment->attsize = strlen($part->body);

                    if(isset($part->d_parameters['filename']))
                        $attname = $part->d_parameters['filename'];
                    else if(isset($part->ctype_parameters['name']))
                        $attname = $part->ctype_parameters['name'];
                    else if(isset($part->headers['content-description']))
                        $attname = $part->headers['content-description'];
                    else $attname = "unknown attachment";

                    $attachment->displayname = $attname;
                    $attachment->attname = $id . ":" . $n;
                    $attachment->attmethod = 1;
                    $attachment->attoid = isset($part->headers['content-id']) ? $part->headers['content-id'] : "";

                    array_push($output->attachments, $attachment);
                }
                $n++;
            }
        }

        return $output;
    }

    /**
     * Returns message stats, analogous to the folder stats from StatFolder().
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return array
     */
    public function StatMessage($folderid, $id) {
        $dirname = $this->getPath();
        $fn = $this->findMessage($id);
        if(!$fn)
            return false;

        $stat = stat("$dirname/$fn");

        $entry = array();
        $entry["id"] = $id;
        $entry["flags"] = 0;

        if(strpos($fn,"S"))
            $entry["flags"] |= 1;
        $entry["mod"] = $stat["mtime"];

        return $entry;
    }

    /**
     * Called when a message has been changed on the mobile.
     * This functionality is not available for emails.
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param SyncXXX       $message        the SyncObject containing a message
     *
     * @access public
     * @return array                        same return value as StatMessage()
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function ChangeMessage($folderid, $id, $message) {
        return false;
    }

    /**
     * Changes the 'read' flag of a message on disk
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param int           $flags          read flag of the message
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetReadFlag($folderid, $id, $flags) {
        if($folderid != 'root')
            return false;

        $fn = $this->findMessage($id);

        if(!$fn)
            return true; // message may have been deleted

        if(!preg_match("/([^:]+):2,([PRSTDF]*)/",$fn,$matches))
            return false;

        // remove 'seen' (S) flag
        if(!$flags) {
            $newflags = str_replace("S","",$matches[2]);
        } else {
            // make sure we don't double add the 'S' flag
            $newflags = str_replace("S","",$matches[2]) . "S";
        }

        $newfn = $matches[1] . ":2," . $newflags;
        // rename if required
        if($fn != $newfn)
            rename($this->getPath() ."/$fn", $this->getPath() . "/$newfn");

        return true;
    }

    /**
     * Called when the user has requested to delete (really delete) a message
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function DeleteMessage($folderid, $id) {
        if($folderid != 'root')
            return false;

        $fn = $this->findMessage($id);

        if(!$fn)
            return true; // success because message has been deleted already

        if(!unlink($this->getPath() . "/$fn")) {
            return true; // success - message may have been deleted in the mean time (since findMessage)
        }

        return true;
    }

    /**
     * Called when the user moves an item on the PDA from one folder to another
     * not implemented
     *
     * @param string        $folderid       id of the source folder
     * @param string        $id             id of the message
     * @param string        $newfolderid    id of the destination folder
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_MOVEITEMSSTATUS_* exceptions
     */
    public function MoveMessage($folderid, $id, $newfolderid) {
        return false;
    }


    /**----------------------------------------------------------------------------------------------------------
     * private maildir-specific internals
     */

    /**
     * Searches for the message
     *
     * @param string        $id        id of the message
     *
     * @access private
     * @return string
     */
    private function findMessage($id) {
        // We could use 'this->folderid' for path info but we currently
        // only support a single INBOX. We also have to use a glob '*'
        // because we don't know the flags of the message we're looking for.

        $dirname = $this->getPath();
        $dir = opendir($dirname);

        while($entry = readdir($dir)) {
            if(strpos($entry,$id) === 0)
                return $entry;
        }
        return false; // not found
    }

    /**
     * Parses the message and return only the plaintext body
     *
     * @param string        $message        html message
     *
     * @access private
     * @return string       plaintext message
     */
    private function getBody($message) {
        $body = "";
        $htmlbody = "";

        $this->getBodyRecursive($message, "plain", $body);

        if(!isset($body) || $body === "") {
            $this->getBodyRecursive($message, "html", $body);
            // remove css-style tags
            $body = preg_replace("/<style.*?<\/style>/is", "", $body);
            // remove all other html
            $body = strip_tags($body);
        }

        return $body;
    }

    /**
     * Get all parts in the message with specified type and concatenate them together, unless the
     * Content-Disposition is 'attachment', in which case the text is apparently an attachment
     *
     * @param string        $message        mimedecode message(part)
     * @param string        $message        message subtype
     * @param string        &$body          body reference
     *
     * @access private
     * @return
     */
    private function getBodyRecursive($message, $subtype, &$body) {
        if(!isset($message->ctype_primary)) return;
        if(strcasecmp($message->ctype_primary,"text")==0 && strcasecmp($message->ctype_secondary,$subtype)==0 && isset($message->body))
            $body .= $message->body;

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
            foreach($message->parts as $part) {
                if(!isset($part->disposition) || strcasecmp($part->disposition,"attachment"))  {
                    $this->getBodyRecursive($part, $subtype, $body);
                }
            }
        }
    }

    /**
     * Parses the received date
     *
     * @param string        $received        received date string
     *
     * @access private
     * @return long
     */
    private function parseReceivedDate($received) {
        $pos = strpos($received, ";");
        if(!$pos)
            return false;

        $datestr = substr($received, $pos+1);
        $datestr = ltrim($datestr);

        return strtotime($datestr);
    }

    /**
     * Moves everything in Maildir/new/* to Maildir/cur/
     *
     * @access private
     * @return
     */
    private function moveNewToCur() {
        $newdirname = MAILDIR_BASE . "/" . $this->store . "/" . MAILDIR_SUBDIR . "/new";

        $newdir = opendir($newdirname);

        while($newentry = readdir($newdir)) {
            if($newentry{0} == ".")
                continue;

            // link/unlink == move. This is the way to move the message according to cr.yp.to
            link($newdirname . "/" . $newentry, $this->getPath() . "/" . $newentry . ":2,");
            unlink($newdirname . "/" . $newentry);
        }
    }

    /**
     * The path we're working on
     *
     * @access private
     * @return string
     */
    private function getPath() {
        return MAILDIR_BASE . "/" . $this->store . "/" . MAILDIR_SUBDIR . "/cur";
    }
}

?>