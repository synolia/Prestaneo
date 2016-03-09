<?php

/**
 * Class UtilsSystem
 *
 * Calcule
 */

Class UtilsMailer extends Utils
{
    public static $moduleInstance = false;
    private $_error='';

    public function __construct()
    {
        self::$moduleInstance = Module::getInstanceByName(MOD_SYNC_NAME);
    }

    public function sendError($recipientEmail, $recipientName, $error, $object='', $templatePath = '', $templateName = 'error', $templateVars = array())
    {
        if(empty($object))
            $object = Mail::l('Une erreur de synchronisation est survenue sur le site', Context::getContext()->language->id);
        if(!array_key_exists('{error_content}', $templateVars))
            $templateVars['{error_content}'] = $error;

        $result = $this->sendMail(
            $recipientEmail,
            $recipientName,
            $object,
            $error,
            $templatePath,
            $templateName,
            $templateVars
        );

        self::$moduleInstance->setError($error);
        if(!$result)
            self::$moduleInstance->setError($this->_error);
        return $result;
    }

    public function sendNotification($recipientEmail, $recipientName, $notification, $object='', $templatePath = '', $templateName = 'notification', $templateVars = array())
    {
        if(empty($object))
            $object = Mail::l('Rapport de synchronisation', Context::getContext()->language->id);
        if(!array_key_exists('{content}', $templateVars))
            $templateVars['{content}'] = $notification;

        $result = $this->sendMail(
            $recipientEmail,
            $recipientName,
            $object,
            $notification,
            $templatePath,
            $templateName,
            $templateVars
        );

        self::$moduleInstance->setConfirmations($notification);
        if(!$result)
            self::$moduleInstance->setError($this->_error);
        return $result;
    }

    public function sendMail($recipientEmail, $recipientName, $object, $content, $templatePath = '', $templateName = 'notification', $templateVars = array())
    {
        try{
            if($templatePath == '')
                $templatePath = dirname(self::$moduleInstance->getLocalFilePath()).'/mails/';

            if(!array_key_exists('{content}', $templateVars))
                $templateVars['{content}'] = $content;
            if(empty($object))
                $object = Mail::l('Rapport de synchronisation', Context::getContext()->language->id);

            $return = Mail::Send(
                Context::getContext()->language->id,
                $templateName,
                $object,
                $templateVars,
                $recipientEmail,
                $recipientName,
                null,
                null,
                null,
                null,
                $templatePath,
                false,
                null,
                null//Bcc
            );
            if(!$return)
                throw new Exception('Can\'t send mail, returned '.$return);
        }catch (Exception $e){
            $error = $e->getMessage();
            $return = false;
        }
        if(_PS_MODE_DEV_ && !$return)
            throw new Exception (Tools::displayError($error));
        elseif(!$return)
            $this->_error = $error;
        return $return;
    }

    public function getError()
    {
        return $this->_error;
    }
}