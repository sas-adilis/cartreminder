<?php
/**
 * Google Merchant Center
 *
 * @author    BusinessTech.fr - https://www.businesstech.fr
 * @copyright Business Tech - https://www.businesstech.fr
 * @license   Commercial
 *
 *           ____    _______
 *          |  _ \  |__   __|
 *          | |_) |    | |
 *          |  _ <     | |
 *          | |_) |    | |
 *          |____/     |_|
 */

class CartReminderCronModuleFrontController extends ModuleFrontController
{
    /**
     * method manage post data
     *
     * @throws Exception
     * @return bool
     */
    public function postProcess()
    {
        if (Tools::getIsset('secure_key')) {
            $secure_key = Configuration::get('PS_FOLLOWUP_SECURE_KEY');
            if (!empty($secure_key) && $secure_key === Tools::getValue('secure_key')) {
                if ($this->module->active) {
                    $this->module->cronTask();
                    echo 'Done';
                }
            }
        }
        exit;
    }
}
