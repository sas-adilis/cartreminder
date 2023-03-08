<?php
if (!defined('_PS_VERSION_'))
    exit;

class CartReminder extends \Module
{
    public function __construct()
    {
        $this->name = 'cartreminder';
        $this->tab = 'advertising_marketing';
        $this->version = '1.0.0';
        $this->author = 'Adilis';
        $this->need_instance = 0;

        $this->conf_keys = array(
            'PS_FOLLOW_UP_ENABLE_1',
            'PS_FOLLOW_UP_ENABLE_VOUCHER_1',
            'PS_FOLLOW_UP_DAYS_1',
            'PS_FOLLOW_UP_CLEAN_DB'
        );

        $this->bootstrap = true;
        parent::__construct();

        $secure_key = Configuration::get('PS_FOLLOWUP_SECURE_KEY');
        if($secure_key === false) {
            Configuration::updateValue('PS_FOLLOWUP_SECURE_KEY', Tools::strtoupper(Tools::passwdGen(16)));
        }

        $this->displayName = $this->l('Customer follow-up');
        $this->description = $this->l('Follow-up with your customers with daily customized e-mails.');
        $this->confirmUninstall = $this->l('Are you sure you want to delete all settings and your logs?');
    }

    public function install()
    {
        Db::getInstance()->execute('
		CREATE TABLE '._DB_PREFIX_.'log_email (
		`id_log_email` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
		`id_email_type` INT UNSIGNED NOT NULL ,
		`id_cart_rule` INT UNSIGNED NOT NULL ,
		`id_customer` INT UNSIGNED NULL ,
		`id_cart` INT UNSIGNED NULL ,
		`date_add` DATETIME NOT NULL,
		 INDEX `date_add`(`date_add`),
		 INDEX `id_cart`(`id_cart`)
		) ENGINE='._MYSQL_ENGINE_);

        foreach ($this->conf_keys as $key)
            Configuration::updateValue($key, 0);


        return parent::install();
    }

    public function uninstall()
    {
        foreach ($this->conf_keys as $key) {
            Configuration::deleteByName($key);
        }
        if (!Module::isInstalled('followup')) {
            Configuration::deleteByName('PS_FOLLOWUP_SECURE_KEY');
            Db::getInstance()->execute('DROP TABLE ' . _DB_PREFIX_ . 'log_email');
        }
        return parent::uninstall();
    }

    public function getContent()
    {
        $html = '';
        if (Tools::isSubmit('submitFollowUp')) {
            $ok = true;
            foreach ($this->conf_keys as $c)
                if(Tools::getValue($c) !== false) {
                    $ok &= Configuration::updateValue($c, (float)Tools::getValue($c));
                }
            if ($ok) {
                $html .= $this->displayConfirmation($this->l('Settings updated succesfully'));
            } else {
                $html .= $this->displayError($this->l('Error occurred during settings update'));
            }
        }
        $html .= $this->renderForm();
        $html .= $this->renderStats();

        return $html;
    }

    /* Log each sent e-mail */
    private function logEmail($id_cart_rule, $id_customer = null, $id_cart = null)
    {
        $values = array(
            'id_email_type' => 1,
            'id_cart_rule' => (int)$id_cart_rule,
            'date_add' => date('Y-m-d H:i:s')
        );
        if (!empty($id_cart)) {
            $values['id_cart'] = (int)$id_cart;
        }
        if (!empty($id_customer)) {
            $values['id_customer'] = (int)$id_customer;
        }
        Db::getInstance()->insert('log_email', $values);
    }

    private function getLogsEmail() {
        $results = Db::getInstance()->executeS('SELECT id_cart FROM '._DB_PREFIX_.'log_email WHERE id_email_type = 1');
        return array_column($results, 'id_cart');
    }

    private function cancelledCart($count = false)
    {
        $email_logs = $this->getLogsEmail();
        $sql = '
            SELECT c.id_cart, c.id_lang, cu.id_customer, c.id_shop, cu.firstname, cu.lastname, cu.email
            FROM '._DB_PREFIX_.'cart c
            LEFT JOIN '._DB_PREFIX_.'orders o ON (o.id_cart = c.id_cart)
            RIGHT JOIN '._DB_PREFIX_.'customer cu ON (cu.id_customer = c.id_customer)
            WHERE DATE_SUB(CURDATE(),INTERVAL 7 DAY) <= c.date_add AND DATE_SUB(NOW(), INTERVAL 4 HOUR) >= c.date_upd AND o.id_order IS NULL';

        $sql .= Shop::addSqlRestriction(Shop::SHARE_CUSTOMER, 'c');
        if (!empty($email_logs)) {
            $sql .= ' AND c.id_cart NOT IN (' . join(',', $email_logs) . ')';
        }
        $sql .= ' GROUP BY cu.id_customer';

        $emails = Db::getInstance()->executeS($sql);

        if ($count || !count($emails)) {
            return count($emails);
        }

        $conf = Configuration::getMultiple(array('PS_FOLLOW_UP_AMOUNT_1', 'PS_FOLLOW_UP_ENABLE_VOUCHER_1', 'PS_FOLLOW_UP_DAYS_1'));
        foreach ($emails as $email) {
            $cart = new Cart((int)$email['id_cart']);
            if (!Validate::isLoadedObject($cart)) {
                continue;
            }

            $products_cart = $cart->getProducts();
            if(!sizeof($products_cart)) {
                continue;
            }

            $this->context->smarty->assign( array(
                'products_cart' => $products_cart,
                'link' => $this->context->link
            ));

            $tpl_products_cart = $this->context->smarty->fetch(dirname(__FILE__) . '/mails/product_cart.tpl');

            if ($conf['PS_FOLLOW_UP_ENABLE_VOUCHER_1']) {
                $voucher = $this->createDiscount(1, (float)$conf['PS_FOLLOW_UP_AMOUNT_1'], (int)$email['id_customer'], strftime('%Y-%m-%d', strtotime('+'.(int)$conf['PS_FOLLOW_UP_DAYS_1'].' day')), $this->l('Discount for your cancelled cart'));
                if ($voucher !== false) {
                    $template_vars = array(
                        '{email}' => $email['email'],
                        '{lastname}' => $email['lastname'],
                        '{firstname}' => $email['firstname'],
                        '{amount}' => $conf['PS_FOLLOW_UP_AMOUNT_1'],
                        '{days}' => $conf['PS_FOLLOW_UP_DAYS_1'],
                        '{voucher_num}' => $voucher->code,
                        '{products_cart}' => $tpl_products_cart,
                        '{order_url}' => $this->context->link->getPageLink('order')
                    );
                    Mail::Send(
                        (int)$email['id_lang'],
                        'followup_1_voucher',
                        $this->l('Your cart and your discount', false, \Language::getIsoById((int)$email['id_lang'])),
                        $template_vars,
                        $email['email'],
                        $email['firstname'].' '.$email['lastname'],
                        null,
                        null,
                        null,
                        null,
                        dirname(__FILE__).'/mails/'
                    );
                    $this->logEmail((int)$voucher->id, (int)$email['id_customer'], (int)$email['id_cart']);
                }
            } else {
                $template_vars = array(
                    '{email}' => $email['email'],
                    '{lastname}' => $email['lastname'],
                    '{firstname}' => $email['firstname'],
                    '{products_cart}' => $tpl_products_cart,
                    '{order_url}' => $this->context->link->getPageLink('order')
                );
                Mail::Send(
                    (int)$email['id_lang'],
                    'followup_1_no_voucher',
                    $this->l('Your cart', false, \Language::getIsoById((int)$email['id_lang'])),
                    $template_vars,
                    $email['email'],
                    $email['firstname'].' '.$email['lastname'],
                    null,
                    null,
                    null,
                    null,
                    dirname(__FILE__).'/mails/'
                );
                $this->logEmail(0, (int)$email['id_customer'], (int)$email['id_cart']);
            }
        }
    }

    private function createDiscount($amount, $id_customer, $date_validity, $description)
    {
        $cart_rule = new CartRule();
        $cart_rule->reduction_percent = (float)$amount;
        $cart_rule->id_customer = (int)$id_customer;
        $cart_rule->date_to = $date_validity;
        $cart_rule->date_from = date('Y-m-d H:i:s');
        $cart_rule->quantity = 1;
        $cart_rule->quantity_per_user = 1;
        $cart_rule->cart_rule_restriction = 1;
        $cart_rule->minimum_amount = 0;

        $languages = Language::getLanguages(true);
        foreach ($languages as $language)
            $cart_rule->name[(int)$language['id_lang']] = $description;

        $code = 'FLW-1-'.Tools::strtoupper(Tools::passwdGen(10));
        $cart_rule->code = $code;
        $cart_rule->active = 1;
        if (!$cart_rule->add()) {
            return false;
        }

        return $cart_rule;
    }
    public function renderForm()
    {
        $n1 = $this->cancelledCart(true);
        $cron_info = '';
        if (Shop::getContext() === Shop::CONTEXT_SHOP) {
            $cron_info = sprintf(
                $this->l('Define the settings and paste the following URL in the crontab, or call it manually on a daily basis: %s'),
                $this->context->link->getModuleLink($this->name, 'cron', ['secure_key' => Configuration::get('PS_FOLLOWUP_SECURE_KEY')])
            );
        }
        $fields_form_1 = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Information'),
                    'icon' => 'icon-cogs',
                ),
                'description' => $cron_info,
            )
        );

        $fields_form_2 = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Cancelled carts'),
                    'icon' => 'icon-cogs'
                ),
                'description' =>
                    $this->l('For each cancelled cart (with no order), send an email to the customer.').' '.
                    sprintf($this->l('The next process will send %d e-mail(s).'), $n1),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'is_bool' => true, //retro-compat
                        'label' => $this->l('Enable'),
                        'name' => 'PS_FOLLOW_UP_ENABLE_1',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'is_bool' => true, //retro-compat
                        'label' => $this->l('Enable voucher'),
                        'name' => 'PS_FOLLOW_UP_ENABLE_VOUCHER_1',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Discount amount'),
                        'name' => 'PS_FOLLOW_UP_AMOUNT_1',
                        'suffix' => '%',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Discount validity'),
                        'name' => 'PS_FOLLOW_UP_DAYS_1',
                        'suffix' => $this->l('day(s)'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right'
                )
            ),
        );

        $fields_form_6 = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('General'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'is_bool' => true, //retro-compat
                        'label' => $this->l('Delete outdated discounts during each launch to clean database'),
                        'name' => 'PS_FOLLOW_UP_CLEAN_DB',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right'
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->override_folder = '/';
        $helper->module = $this;
        $helper->submit_action = 'submitFollowUp';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array(
            $fields_form_1,
            $fields_form_2,
            $fields_form_6
        ));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'PS_FOLLOW_UP_ENABLE_1' => Tools::getValue('PS_FOLLOW_UP_ENABLE_1', Configuration::get('PS_FOLLOW_UP_ENABLE_1')),
            'PS_FOLLOW_UP_ENABLE_VOUCHER_1' => Tools::getValue('PS_FOLLOW_UP_ENABLE_VOUCHER_1', Configuration::get('PS_FOLLOW_UP_ENABLE_VOUCHER_1')),
            'PS_FOLLOW_UP_DAYS_1' => Tools::getValue('PS_FOLLOW_UP_DAYS_1', Configuration::get('PS_FOLLOW_UP_DAYS_1')),
            'PS_FOLLOW_UP_AMOUNT_1' => Tools::getValue('PS_FOLLOW_UP_AMOUNT_1', Configuration::get('PS_FOLLOW_UP_AMOUNT_1')),
            'PS_FOLLOW_UP_CLEAN_DB' => Tools::getValue('PS_FOLLOW_UP_CLEAN_DB', Configuration::get('PS_FOLLOW_UP_CLEAN_DB')),
        );
    }

    public function renderStats()
    {
        $stats = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
			SELECT DATE_FORMAT(l.date_add, \'%Y-%m-%d\') date_stat, l.id_email_type, COUNT(l.id_log_email) nb,
			(SELECT COUNT(l2.id_cart_rule)
			FROM '._DB_PREFIX_.'log_email l2
			LEFT JOIN '._DB_PREFIX_.'order_cart_rule ocr ON (ocr.id_cart_rule = l2.id_cart_rule)
			LEFT JOIN '._DB_PREFIX_.'orders o ON (o.id_order = ocr.id_order)
			WHERE l2.id_email_type = l.id_email_type AND l2.date_add = l.date_add AND ocr.id_order IS NOT NULL AND o.valid = 1) nb_used
			FROM '._DB_PREFIX_.'log_email l
			WHERE l.date_add >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND l.id_email_type = 1
			GROUP BY DATE_FORMAT(l.date_add, \'%Y-%m-%d\'), l.id_email_type');

        $stats_array = array();
        foreach ($stats as $stat) {
            $stats_array[$stat['date_stat']][$stat['id_email_type']]['nb'] = (int)$stat['nb'];
            $stats_array[$stat['date_stat']][$stat['id_email_type']]['nb_used'] = (int)$stat['nb_used'];
        }

        foreach ($stats_array as $date_stat => $array) {
            $rates = array();
            for ($i = 1; $i != 5; $i++)
                if (isset($stats_array[$date_stat][$i]['nb']) && isset($stats_array[$date_stat][$i]['nb_used']) && $stats_array[$date_stat][$i]['nb_used'] > 0)
                    $rates[$i] = number_format(($stats_array[$date_stat][$i]['nb_used'] / $stats_array[$date_stat][$i]['nb']) * 100, 2, '.', '');
            for ($i = 1; $i != 5; $i++)
            {
                $stats_array[$date_stat][$i]['nb'] = isset($stats_array[$date_stat][$i]['nb']) ? (int)$stats_array[$date_stat][$i]['nb'] : 0;
                $stats_array[$date_stat][$i]['nb_used'] = isset($stats_array[$date_stat][$i]['nb_used']) ? (int)$stats_array[$date_stat][$i]['nb_used'] : 0;
                $stats_array[$date_stat][$i]['rate'] = isset($rates[$i]) ? '<b>'.$rates[$i].'</b>' : '0.00';
            }
            ksort($stats_array[$date_stat]);
        }

        $this->context->smarty->assign(array('stats_array' => $stats_array));
        return $this->display(__FILE__, 'stats.tpl');
    }

    public function cronTask()
    {
        $conf = Configuration::getMultiple(array(
            'PS_FOLLOW_UP_ENABLE_1',
            'PS_FOLLOW_UP_CLEAN_DB'
        ));

        if ($conf['PS_FOLLOW_UP_ENABLE_1']) {
            $this->cancelledCart();
        }

        /* Clean-up database by deleting all outdated discounts */
        if ($conf['PS_FOLLOW_UP_CLEAN_DB'] == 1) {
            $outdated_discounts = Db::getInstance()->executeS('SELECT id_cart_rule FROM '._DB_PREFIX_.'cart_rule WHERE date_to < NOW() AND code LIKE "FLW-1-%"');
            foreach ($outdated_discounts as $outdated_discount) {
                $cart_rule = new CartRule((int)$outdated_discount['id_cart_rule']);
                if (Validate::isLoadedObject($cart_rule)) {
                    $cart_rule->delete();
                }
            }
        }
    }
}