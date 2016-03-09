<?php


class CronJobsSync extends CronJobs
{
    private $syncActionName;

    public function __construct($moduleName = null)
    {
        $this->syncActionName = $moduleName;

        parent::__construct();
    }

    public function postProcessNewJob()
    {
        if ($this->isNewJobValid() == true)
        {
            $description = Tools::getValue('description');
            if(!strlen(trim($description)))
                return $this->setErrorMessage('You have to set a description of your task.');
            $task        = urlencode(Tools::getValue('task'));
            $hour        = (int)Tools::getValue('hour');
            $day         = (int)Tools::getValue('day');
            $month       = (int)Tools::getValue('month');
            $day_of_week = (int)Tools::getValue('day_of_week');

            $result      = Db::getInstance()->getRow('
                SELECT id_cronjob
                FROM '._DB_PREFIX_.$this->name.'
				WHERE `task` = \''.$task.'\' AND `hour` = \''.$hour.'\' AND `day` = \''.$day.'\'
				AND `month` = \''.$month.'\' AND `day_of_week` = \''.$day_of_week.'\'');

            if ($result == false)
            {
                $id_shop       = (int)Context::getContext()->shop->id;
                $id_shop_group = (int)Context::getContext()->shop->id_shop_group;

                $query         = 'INSERT INTO '._DB_PREFIX_.$this->name.'
					(`description`, `task`, `hour`, `day`, `month`, `day_of_week`, `updated_at`, `active`, `id_shop`, `id_shop_group`)
					VALUES ("'.pSQL($description).'", "'.$task.'",
					"'.$hour.'", "'.$day.'", "'.$month.'", "'.$day_of_week.'", NULL, TRUE,
					'.$id_shop.', '.$id_shop_group.')';

                if (!Db::getInstance()->execute($query) != false)
                    return $this->setErrorMessage('An error happened: the task could not be added.');

                $cronjobId = Db::getInstance()->Insert_ID();
                if (empty($cronjobId))
                    return $this->setErrorMessage('Error retrieving id_cronjob');

                $query3 = 'INSERT INTO `'._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs`(`id_cronjob`, `id_sync_actions`)
					SELECT  "'. $cronjobId.'", `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions`.`id`
					FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions`
					WHERE `name` = "'.$this->syncActionName.'"';

                if (Db::getInstance()->execute($query3) != false)
                    return $this->setSuccessMessage('The task has been successfully added.');
                return $this->setErrorMessage('An error happened: the task could not be added.');
            }

            return $this->setErrorMessage('This cron task already exists.');
        }

        return false;
    }
}
