<?php
/*
 * Member Prime – paid membership that grants special prices
 * Compatible with thirty bees 1.4 / 1.5 (PHP 8.2) and PrestaShop 1.6
 * Licence: Free
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

class Memberprime extends Module
{
    /** configuration keys */
    private const CFG_KEYS = ['MP_PRODUCT_ID','MP_GROUP_ID','MP_VALID_DAYS','MP_PAID_STATE_ID'];

    /* ------------ robust logger ------------ */
    private function log(string $msg): void
    {
        // database table ps_log  →  visible in BO › Logs
        if (class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog('[MemberPrime] '.$msg, 1, false, null, 0, true);
        }

        // flat file  var/logs/memberprime.log  →  always available
        $dir = defined('_PS_LOG_DIR_') ? _PS_LOG_DIR_ : _PS_ROOT_DIR_.'/var/logs/';
        @file_put_contents($dir.'memberprime.log', date('[Y-m-d H:i:s] ').$msg."\n", FILE_APPEND);
    }

    /* ------------ basic info ------------ */

    public function __construct()
    {
        $this->name          = 'memberprime';
        $this->tab           = 'pricing_promotion';
        $this->version       = '1.0.0';
        $this->author        = '30bees';
        $this->bootstrap     = true;
        parent::__construct();

        $this->displayName = $this->l('Member Prime');
        $this->description = $this->l(
            'Sell a paid membership that puts customers in a special group and shows savings in the cart.'
        );
    }

    /* ------------ install / uninstall ------------ */

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('displayShoppingCartFooter')
            && $this->registerHook('displayBeforeCarrier')   // OPC
            && $this->registerHook('displayHeader')          // enqueue CSS early
            && $this->registerHook('actionCronJob')
            && $this->setDefaults();
    }

    public function uninstall()
    {
        return $this->uninstallDb() && parent::uninstall();
    }

    private function setDefaults(): bool
    {
        foreach (self::CFG_KEYS as $k) {
            Configuration::updateValue($k, '');
        }
        return true;
    }

    private function installDb(): bool
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_."memberprime` (
                id_memberprime INT UNSIGNED AUTO_INCREMENT,
                id_customer    INT UNSIGNED NOT NULL,
                expiration     DATETIME     NOT NULL,
                PRIMARY KEY (id_memberprime),
                UNIQUE KEY customer (id_customer),
                KEY exp_idx (expiration)
            ) ENGINE="._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;'
        );
    }

    private function uninstallDb(): bool
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_."memberprime`");
    }

    /* ------------ back‑office form (unchanged from last version) ------------ */

    public function getContent()
    {
        if (Tools::isSubmit('submitMemberPrime')) {
            foreach (self::CFG_KEYS as $k) {
                Configuration::updateValue($k, Tools::getValue($k));
            }
            return $this->displayConfirmation($this->l('Settings updated')).$this->renderForm();
        }
        return $this->renderForm();
    }

 private function renderForm(): string
{
    $helper                        = new HelperForm();
    $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
    $helper->token                 = Tools::getAdminTokenLite('AdminModules');
    $helper->currentIndex          = AdminController::$currentIndex.'&configure='.$this->name;
    $helper->submit_action         = 'submitMemberPrime';

    /* --- fix: build keyed array instead of numeric --- */
    $values = [];
    foreach (self::CFG_KEYS as $k) {
        $values[$k] = Configuration::get($k);
    }
    $helper->fields_value = $values;
    /* -------------------------------------------------- */

    /* build the form definition */
    $fields_form = [[
        'form'=>[
            'legend'=>['title'=>$this->l('Member Prime settings')],
            'input'=>[
                ['type'=>'text','label'=>$this->l('Membership product ID'),'name'=>'MP_PRODUCT_ID','required'=>true],
                ['type'=>'text','label'=>$this->l('Member group ID'),'name'=>'MP_GROUP_ID','required'=>true],
                ['type'=>'text','label'=>$this->l('Validity (days)'),'name'=>'MP_VALID_DAYS','required'=>true],
                ['type'=>'text','label'=>$this->l('Order‑state ID (grants it)'),'name'=>'MP_PAID_STATE_ID','required'=>true],
            ],
            'submit'=>['title'=>$this->l('Save')],
        ],
    ]];

    return $helper->generateForm($fields_form);
}

    /* ------------ order hook: grant membership ------------ */

    public function hookActionValidateOrder($params)
    {
        $order       = new Order((int)$params['order']->id);
        $paidStateId = (int)Configuration::get('MP_PAID_STATE_ID');
        if (!$paidStateId || $order->current_state != $paidStateId) {
            return;
        }

        $membershipId = (int)Configuration::get('MP_PRODUCT_ID');
        foreach ($order->getProductsDetail() as $p) {
            if ((int)$p['product_id'] === $membershipId) {
                $this->grantMembership(
                    (int)$order->id_customer,
                    (int)Configuration::get('MP_GROUP_ID'),
                    (int)Configuration::get('MP_VALID_DAYS')
                );
                break;
            }
        }
    }

    private function grantMembership(int $idCustomer,int $idGroup,int $days): void
    {
        $cust = new Customer($idCustomer);
        if (!$cust->id) {return;}

        if (!in_array($idGroup,$cust->getGroups(),true)) {
            $cust->addGroups([$idGroup]);
        }

        $exp = (new DateTime())->add(new DateInterval('P'.$days.'D'))->format('Y-m-d H:i:s');
        Db::getInstance()->execute('REPLACE INTO `'._DB_PREFIX_."memberprime`
            (id_customer,expiration) VALUES ($idCustomer,'".pSQL($exp)."')");
        $this->log("membership granted to $idCustomer until $exp");
    }

    /* ------------ header hook: enqueue CSS early ------------ */
    public function hookDisplayHeader()
    {
        if (!in_array($this->context->controller->php_self,['cart','order'])) {return;}
        $css = $this->_path.'views/css/front.css';
        if (method_exists($this->context->controller,'registerStylesheet')) {
            $this->context->controller->registerStylesheet('memberprime-banner',$css,['media'=>'all']);
        } else {
            $this->context->controller->addCSS($css,'all');
        }
    }

    /* ------------ cart hooks ------------ */
    public function hookDisplayShoppingCartFooter() {return $this->renderSavingsBanner();}
    public function hookDisplayBeforeCarrier()      {return $this->renderSavingsBanner();}

    private function renderSavingsBanner(): string
    {
        $cust        = $this->context->customer;
        $grp         = (int)Configuration::get('MP_GROUP_ID');
        $prodId      = (int)Configuration::get('MP_PRODUCT_ID');
        $feeProduct  = new Product($prodId);

        if (!$prodId || !$grp || !$feeProduct->id || ($cust->isLogged() && in_array($grp,$cust->getGroups(),true))) {
            return '';
        }

        $cart        = $this->context->cart;
        $normalTotal = $cart->getOrderTotal(true,Cart::BOTH);

        /* simulate member total */
        if ($cust->isLogged()) {
            $saved = $cust->getGroups();
            if (!in_array($grp,$saved,true)) {$cust->addGroups([$grp]);}
            $memberTotal = $cart->getOrderTotal(true,Cart::BOTH);
            $cust->cleanGroups(); $cust->addGroups($saved);
        } else {
            $real = $this->context->customer;
            $fake = new Customer(); $fake->groups=[$grp]; $fake->id_default_group=$grp;
            $this->context->customer=$fake;
            $memberTotal = $cart->getOrderTotal(true,Cart::BOTH);
            $this->context->customer=$real;
        }

        $saving = $normalTotal - $memberTotal;
        $this->log(sprintf('normal=%.2f member=%.2f saving=%.2f',$normalTotal,$memberTotal,$saving));
        if ($saving<=0.01){return '';}

        $this->context->smarty->assign([
            'membership_price'=>Tools::displayPrice($feeProduct->getPrice()),
            'saving'=>Tools::displayPrice($saving),
            'orders_to_breakeven'=>(int)ceil($feeProduct->getPrice()/$saving),
            'membership_link'=>$this->context->link->getProductLink($feeProduct),
        ]);
        return $this->display(__FILE__,'views/templates/hook/cartSavings.tpl');
    }

    /* ------------ cron ------------ */
    public function hookActionCronJob() {$this->cronPruneExpired();}

    public function cronPruneExpired(): void
    {
        $grp=(int)Configuration::get('MP_GROUP_ID');
        foreach (Db::getInstance()->executeS(
            'SELECT id_customer FROM `'._DB_PREFIX_."memberprime` WHERE expiration<NOW()"
        ) as $r) {
            $c=new Customer((int)$r['id_customer']);
            if ($c->id && in_array($grp,$c->getGroups(),true)){$c->removeGroups([$grp]);}
            Db::getInstance()->delete('memberprime','id_customer='.(int)$r['id_customer']);
        }
    }
}
