<?php
/**
 * 2007-2015 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2015 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

class AdminSyncConfigController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->context = Context::getContext();
        parent::__construct();
    }


    public function setMedia()
    {
        parent::setMedia();
        $this->addJS($this->module->getPathUri() . 'js/prestaneo.js');
        $this->addJS($this->module->getPathUri() . 'js/prestaneoConfig.js');
        $this->addCSS($this->module->getPathUri() . 'css/prestaneoConfig.css');
    }

    public function postProcess()
    {
        $this->module->getContent();
    }

    public function display()
    {
        $smartyVariables = array();
        $smartyVariables = array_merge($smartyVariables,
            array(
                'cronpath'      => $this->context->shop->getBaseURL().'modules/'.$this->module->name.'/cron.php',
                'mod_sync_name' => MOD_SYNC_NAME,
            )
        );
        if(count($smartyVariables)) {
            $this->context->smarty->assign($smartyVariables);
        }

        $this->content = '';
        $this->content.= Overrider::getInstance()->display(__FILE__, 'actions.tpl' );
        $this->content.= $this->module->renderForm();
        if(MOD_FTP) {
            $this->content .= $this->module->renderFormFtp();
        }

        $this->content .= $this->module->renderFormConfiguration();

        $this->content = Overrider::getInstance()->getContent($this->content);
        $this->content = preg_replace(
            '#(\s*\<\s*form.*)(?=\saction)\saction\="[^"]*"([^>]*\>\s*)#i',
            '$1$2',
            $this->content
        );
        $this->context->smarty->assign('content', $this->content );
        return parent::display();
    }
}