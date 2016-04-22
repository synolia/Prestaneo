<?php

abstract class Importer extends Prestaneo implements IImporter
{
    protected       $_manager;
    protected       $_mover;
    protected       $_logger;
    public static   $icon = 'download-alt';

    /** @var UtilsMailer _mailer */
    private $_mailer = false;

    protected static $_processErrorsSent        = false;
    protected static $_processNotificationsSent = false;
    protected static $_processNotifications     = array();
    protected static $_processErrors            = array();

    /**
     * Initialize Manager, Mover and Logger
     */
    public function __construct()
    {
        $this->_manager              = new Manager();
        $this->_logger               = new Logger($this->_manager);
        $this->_mover                = new Mover($this->_manager);
        self::$_processErrors        = array();
        self::$_processNotifications = array();
    }

    public function start()
    {
        $this->_logger->writeLog(date('d/m/Y H:i:s').' Begining of ' . get_class($this));
        Utils::exec('System')->setErrorReporting(E_ALL ^ (E_STRICT | E_NOTICE))->apply();
    }

    public function end()
    {
        $this->_logger->writeLog(date('d/m/Y H:i:s').' End of '. get_class($this));
        if(_PS_MODE_DEV_ || Parameter::load($_GET, 'debug', 'int')) {
            $this->sendErrors();
            $this->sendNotifications();
        }
    }

    public function log($message)
    {
        $this->_logger->writeLog($message);
        return;
    }

    public function logError($error)
    {
        self::$_processErrors[] = $error;
        return $this->log($error);
    }

    public static function getHtmlErrors()
    {
        if(count(self::$_processErrors))
            return implode('<br />'.chr(13).chr(10), self::$_processErrors);
        else
            return false;
    }

    public function sendErrors($email = false, $name = false)
    {
        if(self::$_processErrorsSent)
            return true;
        self::$_processErrorsSent = true;
        if(!$email)
            $email = Configuration::get('PS_SHOP_EMAIL');
        if(!$name)
            $name = Configuration::get('PS_SHOP_NAME');
        $errors = self::getHtmlErrors();
        if(!$errors)
            return true;
        $mailer = $this->_getMailer();
        return $mailer->sendError($email, $name, $errors);
    }

    public function logNotification($notification)
    {
        self::$_processNotifications[] = $notification;
        return $this->log($notification);
    }

    public static function getHtmlNotifications()
    {
        if(count(self::$_processNotifications))
            return implode('<br />'.chr(13).chr(10), self::$_processNotifications);
        else
            return false;
    }

    public function sendNotifications($email = false, $name = false)
    {
        if(self::$_processNotificationsSent)
            return true;
        self::$_processNotificationsSent = true;
        if(!$email)
            $email = Configuration::get('PS_SHOP_EMAIL');
        if(!$name)
            $name = Configuration::get('PS_SHOP_NAME');
        $notifications = self::getHtmlNotifications();
        if(!$notifications)
            return true;
        $mailer = $this->_getMailer();
        return $mailer->sendNotification($email, $name, $notifications);
    }

    protected function _getMailer()
    {
        if(!$this->_mailer)
            $this->_mailer = Utils::exec('Mailer');
        return $this->_mailer;
    }

    /**
     * @return mixed
     *
     * Template Method to use in your import script
     */
    final public function import()
    {
        $this->start();
        $result = $this->process();
        $this->end();

        return $result;
    }
}