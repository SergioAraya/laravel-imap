<?php
/*
* File:     Client.php
* Category: -
* Author:   M. Goldenbaum
* Created:  19.01.17 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\IMAP;

use Webklex\IMAP\Exceptions\ConnectionFailedException;
use Webklex\IMAP\Exceptions\GetMessagesFailedException;
use Webklex\IMAP\Exceptions\MessageSearchValidationException;
use Webklex\IMAP\Support\FolderCollection;
use Webklex\IMAP\Support\MessageCollection;

/**
 * Class Client
 *
 * @package Webklex\IMAP
 */
class Client {

    /**
     * @var bool|resource
     */
    public $connection = false;

    /**
     * Server hostname.
     *
     * @var string
     */
    public $host;

    /**
     * Server port.
     *
     * @var int
     */
    public $port;

    /**
     * Server encryption.
     * Supported: none, ssl or tls.
     *
     * @var string
     */
    public $encryption;

    /**
     * If server has to validate cert.
     *
     * @var mixed
     */
    public $validate_cert;

    /**
     * Account username/
     *
     * @var mixed
     */
    public $username;

    /**
     * Account password.
     *
     * @var string
     */
    public $password;

    /**
     * Read only parameter.
     *
     * @var bool
     */
    protected $read_only = false;

    /**
     * Active folder.
     *
     * @var Folder
     */
    protected $activeFolder = false;

    /**
     * Connected parameter
     * @var bool
     */
    protected $connected = false;

    /**
     * Client constructor.
     *
     * @param array $config
     */
    public function __construct($config = []) {
        $this->setConfig($config);
    }

    /**
     * Client destructor
     */
    public function __destruct() {
        $this->disconnect();
    }

    /**
     * Set the Client configuration
     *
     * @param array $config
     *
     * @return self
     */
    public function setConfig(array $config) {
        $defaultAccount = config('imap.default');
        $defaultConfig  = config("imap.accounts.$defaultAccount");

        foreach($defaultConfig as $key => $default){
            $this->$key = isset($config[$key]) ? $config[$key] : $default;
        }

        return $this;
    }

    /**
     * Get the current imap resource
     *
     * @return resource
     */
    public function getConnection(){
        return $this->connection;
    }

    /**
     * Set read only property and reconnect if it's necessary.
     *
     * @param bool $readOnly
     *
     * @return self
     */
    public function setReadOnly($readOnly = true) {
        $this->read_only = $readOnly;

        return $this;
    }

    /**
     * Determine if connection was established.
     *
     * @return bool
     */
    public function isConnected() {
        return $this->connected;
    }

    /**
     * Determine if connection is in read only mode.
     *
     * @return bool
     */
    public function isReadOnly() {
        return $this->read_only;
    }

    /**
     * Determine if connection was established and connect if not.
     *
     * @throws ConnectionFailedException
     */
    public function checkConnection() {
        if (!$this->isConnected()) {
            $this->connect();
        }
    }

    /**
     * Connect to server.
     *
     * @param int $attempts
     *
     * @return $this
     * @throws ConnectionFailedException
     */
    public function connect($attempts = 3) {
        $this->disconnect();

        try {
            $this->connection = imap_open(
                $this->getAddress(),
                $this->username,
                $this->password,
                $this->getOptions(),
                $attempts,
                config('imap.options.open')
            );
            $this->connected = !! $this->connection;
        } catch (\ErrorException $e) {
            $errors = imap_errors();
            $message = $e->getMessage().'. '.implode("; ", (is_array($errors) ? $errors : array()));

            throw new ConnectionFailedException($message);
        }

        return $this;
    }

    /**
     * Disconnect from server.
     *
     * @return $this
     */
    public function disconnect() {
        if ($this->isConnected()) {
            $this->connected = ! imap_close($this->connection, CL_EXPUNGE);
        }

        return $this;
    }

    /**
     * Get a folder instance by a folder name
     * ---------------------------------------------
     * PLEASE NOTE: This is an experimental function
     * ---------------------------------------------
     * @param string        $folder_name
     * @param int           $attributes
     * @param null|string   $delimiter
     *
     * @return Folder
     */
    public function getFolder($folder_name, $attributes = 32, $delimiter = null){

        $delimiter = $delimiter == null ? config('imap.options.delimiter', '/') : $delimiter;

        $oFolder = new Folder($this, (object)[
            'name'       => $this->getAddress().$folder_name,
            'attributes' => $attributes,
            'delimiter'  => $delimiter
        ]);

        return $oFolder;
    }

    /**
     * Get folders list.
     * If hierarchical order is set to true, it will make a tree of folders, otherwise it will return flat array.
     *
     * @param bool $hierarchical
     * @param null $parent_folder
     *
     * @return FolderCollection
     * @throws ConnectionFailedException
     */
    public function getFolders($hierarchical = true, $parent_folder = null) {
        $this->checkConnection();
        $folders = FolderCollection::make([]);

        $pattern = $parent_folder.($hierarchical ? '%' : '*');

        $items = imap_getmailboxes($this->connection, $this->getAddress(), $pattern);
        foreach ($items as $item) {
            $folder = new Folder($this, $item);

            if ($hierarchical && $folder->hasChildren()) {
                $pattern = $folder->fullName.$folder->delimiter.'%';

                $children = $this->getFolders(true, $pattern);
                $folder->setChildren($children);
            }

            $folders->push($folder);
        }

        return $folders;
    }

    /**
     * Open folder.
     *
     * @param Folder $folder
     * @param int    $attempts
     *
     * @throws ConnectionFailedException
     */
    public function openFolder(Folder $folder, $attempts = 3) {
        $this->checkConnection();

        if ($this->activeFolder !== $folder) {
            $this->activeFolder = $folder;

            imap_reopen($this->connection, $folder->path, $this->getOptions(), $attempts);
        }
    }

    /**
     * Create a new Folder
     *
     * @param $name
     *
     * @return bool
     */
    public function createFolder($name){
        return imap_createmailbox($this->connection, imap_utf7_encode($name));
    }

    /**
     * Get messages from folder.
     *
     * @param Folder  $folder
     * @param string  $criteria
     * @param integer $fetch_options
     * @param boolean $parse_body
     *
     * @return MessageCollection
     * @throws ConnectionFailedException
     * @throws GetMessagesFailedException
     * @throws MessageSearchValidationException
     *
     * @deprecated 1.0.5.2:2.0.0 No longer needed. Use Folder::getMessages() instead
     * @see Folder::getMessages()
     */
    public function getMessages(Folder $folder, $criteria = 'ALL', $fetch_options = null, $parse_body = true) {
        return $folder->getMessages($criteria, $fetch_options, $parse_body);
    }

    /**
     * Get all unseen messages from folder
     *
     * @param Folder $folder
     * @param string $criteria
     * @param null   $fetch_options
     * @param bool   $parse_body
     *
     * @return MessageCollection
     * @throws ConnectionFailedException
     * @throws GetMessagesFailedException
     * @throws MessageSearchValidationException
     *
     * @deprecated 1.0.5:2.0.0 No longer needed. Use Folder::getMessages('UNSEEN') instead
     * @see Folder::getMessages()
     */
    public function getUnseenMessages(Folder $folder, $criteria = 'UNSEEN', $fetch_options = null, $parse_body = true) {
        return $folder->getUnseenMessages($criteria, $fetch_options, $parse_body);
    }

    /**
     * Search messages by a given search criteria
     *
     * @param array   $where
     * @param Folder  $folder
     * @param null    $fetch_options
     * @param boolean $parse_body
     * @param string  $charset
     *
     * @return MessageCollection
     * @throws ConnectionFailedException
     * @throws GetMessagesFailedException
     * @throws MessageSearchValidationException
     *
     * @deprecated 1.0.5:2.0.0 No longer needed. Use Folder::searchMessages() instead
     * @see Folder::searchMessages()
     *
     */
    public function searchMessages(array $where, Folder $folder, $fetch_options = null, $parse_body = true, $charset = "UTF-8") {
        return $folder->searchMessages($where, $fetch_options, $parse_body, $charset);
    }

    /**
     * Get option for imap_open and imap_reopen.
     * It supports only isReadOnly feature.
     *
     * @return int
     */
    protected function getOptions() {
        return ($this->isReadOnly()) ? OP_READONLY : 0;
    }

    /**
     * Get full address of mailbox.
     *
     * @return string
     */
    protected function getAddress() {
        $address = "{".$this->host.":".$this->port."/imap";
        if (!$this->validate_cert) {
            $address .= '/novalidate-cert';
        }
        if ($this->encryption == 'ssl') {
            $address .= '/ssl';
        }
        $address .= '}';

        return $address;
    }

    /**
     * Retrieve the quota level settings, and usage statics per mailbox
     *
     * @return array
     */
    public function getQuota(){
        return imap_get_quota($this->connection, 'user.'.$this->username);
    }

    /**
     * Retrieve the quota settings per user
     *
     * @param string $quota_root
     *
     * @return array
     */
    public function getQuotaRoot($quota_root = 'INBOX'){
        return imap_get_quotaroot($this->connection, $quota_root);
    }

    /**
     * Gets the number of messages in the current mailbox
     *
     * @return int
     */
    public function countMessages(){
        return imap_num_msg($this->connection);
    }

    /**
     * Gets the number of recent messages in current mailbox
     *
     * @return int
     */
    public function countRecentMessages(){
        return imap_num_recent($this->connection);
    }

    /**
     * Returns all IMAP alert messages that have occurred
     *
     * @return array
     */
    public function getAlerts(){
        return imap_alerts();
    }

    /**
     * Returns all of the IMAP errors that have occurred
     *
     * @return array
     */
    public function getErrors(){
        return imap_errors();
    }

    /**
     * Gets the last IMAP error that occurred during this page request
     *
     * @return string
     */
    public function getLastError(){
        return imap_last_error();
    }

    /**
     * Delete all messages marked for deletion
     *
     * @return bool
     */
    public function expunge(){
        return imap_expunge($this->connection);
    }

    /**
     * Check current mailbox
     *
     * @return object {
     *      Date    [string(37) "Wed, 8 Mar 2017 22:17:54 +0100 (CET)"]             current system time formatted according to » RFC2822
     *      Driver  [string(4) "imap"]                                              protocol used to access this mailbox: POP3, IMAP, NNTP
     *      Mailbox ["{root@example.com:993/imap/user="root@example.com"}INBOX"]    the mailbox name
     *      Nmsgs   [int(1)]                                                        number of messages in the mailbox
     *      Recent  [int(0)]                                                        number of recent messages in the mailbox
     * }
     */
    public function checkCurrentMailbox(){
        return imap_check($this->connection);
    }
}
