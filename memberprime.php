<?php
/*
 * Member Prime – paid membership that grants special prices
 * Compatible with thirty bees 1.4 / 1.5 (PHP 8.2) and PrestaShop 1.6
 * Licence: Free
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

class Memberprime extends Module
{
    /** configuration keys */
    protected const CFG_KEYS = [
        'MP_PRODUCT_ID',
        'MP_GROUP_ID',
        'MP_VALID_DAYS',
        'MP_PAID_STATE_ID',
    ];

    /* ---------- basic module info ---------- */

    public function __construct()
    {
        $this->name          = 'memberprime';
        $this->tab           = 'pricing_promotion';
        $this->version       = '1.0.0';
        $this->author        = '30bees';
        $this->need_instance = 0;
        $this->bootstrap     = true;

        parent::__construct();

        $this->displayName = $this->l('Member Prime');
        $this->description = $this->l(
            'Sell a paid membership that puts customers in a special group and shows savings in the cart.'
        );
    }

    /* ---------- install / uninstall ---------- */

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('displayShoppingCartFooter')
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
        $sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_."memberprime` (
                    `id_memberprime` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `id_customer`    INT UNSIGNED NOT NULL,
                    `expiration`     DATETIME      NOT NULL,
                    PRIMARY KEY (`id_memberprime`),
                    UNIQUE KEY `customer` (`id_customer`),
                    KEY `exp_idx` (`expiration`)
                ) ENGINE="._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';
        return Db::getInstance()->execute($sql);
    }

    private function uninstallDb(): bool
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_."memberprime`");
    }

    /* ---------- back‑office config ---------- */

    public function getContent()
    {
        $this->html = '';
        if (Tools::isSubmit('submitMemberPrime')) {
            foreach (self::CFG_KEYS as $k) {
                Configuration::updateValue($k, Tools::getValue($k));
            }
            $this->html .= $this->displayConfirmation($this->l('Settings updated'));
        }

        $fields = [];
        foreach (self::CFG_KEYS as $k) {
            $fields[$k] = Configuration::get($k);
        }

        $this->html .= $this->renderForm($fields);
        return $this->html;
    }

    private function renderForm(array $cfg): string
    {
        $helper                        = new HelperForm();
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->token                 = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex          = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->submit_action         = 'submitMemberPrime';
        $helper->tpl_vars              = ['fields_value' => $cfg];

        $fields_form = [[
            'form' => [
                'legend' => ['title' => $this->l('Member Prime settings')],
                'input'  => [
                    ['type'=>'text','label'=>$this->l('Membership product ID'),      'name'=>'MP_PRODUCT_ID','required'=>true],
                    ['type'=>'text','label'=>$this->l('Member group ID'),            'name'=>'MP_GROUP_ID',  'required'=>true],
                    ['type'=>'text','label'=>$this->l('Validity (days)'),            'name'=>'MP_VALID_DAYS','required'=>true],
                    ['type'=>'text','label'=>$this->l('Order‑state ID (grants it)'), 'name'=>'MP_PAID_STATE_ID','required'=>true],
                ],
                'submit'=>['title'=>$this->l('Save')],
            ],
        ]];

        return $helper->generateForm($fields_form);
    }

    /* ---------- order hook: grant membership ---------- */

    public function hookActionValidateOrder($params)
    {
        $order         = new Order((int)$params['order']->id);
        $paidStateId   = (int)Configuration::get('MP_PAID_STATE_ID');
        $productId     = (int)Configuration::get('MP_PRODUCT_ID');
        $memberGroupId = (int)Configuration::get('MP_GROUP_ID');
        $validDays     = (int)Configuration::get('MP_VALID_DAYS');

        if (!$paidStateId || $order->current_state != $paidStateId) {
            return;
        }

        foreach ($order->getProductsDetail() as $prod) {
            if ((int)$prod['product_id'] === $productId) {
                $this->grantMembership((int)$order->id_customer, $memberGroupId, $validDays);
                break;
            }
        }
    }

    private function grantMembership(int $idCustomer, int $idGroup, int $days): void
    {
        $customer = new Customer($idCustomer);
        if (!$customer->id) {
            return;
        }

        if (!in_array($idGroup, $customer->getGroups(), true)) {
            $customer->addGroups([$idGroup]);
        }

        try {
            $expiration = (new DateTime())
                ->add(new DateInterval('P'.(int)$days.'D'))
                ->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $expiration = (new DateTime())
                ->add(new DateInterval('P365D'))
                ->format('Y-m-d H:i:s');
        }

        Db::getInstance()->execute('REPLACE INTO `'._DB_PREFIX_."memberprime`
            (id_customer, expiration) VALUES ($idCustomer, '".pSQL($expiration)."')");
    }

    /* ---------- cart hook: show savings banner ---------- */

    public function hookDisplayShoppingCartFooter($params)
    {
        /* load banner CSS (TB 1.4 addCSS / TB 1.5+ registerStylesheet) */
        $cssRel = 'modules/'.$this->name.'/views/css/front.css';
        if (method_exists($this->context->controller, 'registerStylesheet')) {
            $this->context->controller->registerStylesheet(
                'memberprime-banner', $cssRel, ['media'=>'all','priority'=>150]
            );
        } else {
            $this->context->controller->addCSS($this->_path.'views/css/front.css', 'all');
        }

        $customer     = $this->context->customer;
        $memberGroup  = (int)Configuration::get('MP_GROUP_ID');
        $membershipId = (int)Configuration::get('MP_PRODUCT_ID');
        $feeProduct   = new Product($membershipId);

        /* already a member or mis‑configured → no banner */
        if (
            !$membershipId || !$memberGroup || !$feeProduct->id ||
            ($customer->isLogged() && in_array($memberGroup, $customer->getGroups(), true))
        ) {
            return '';
        }

        /* normal total */
        $cart        = $this->context->cart;
        $totalNormal = $cart->getOrderTotal(true, Cart::BOTH);

        /* simulate member total using a cloned cart */
        $memberCart = new Cart($cart->id);
        $memberCart->id_customer         = $customer->id ?: 0;
        $memberCart->id_address_delivery = $cart->id_address_delivery;
        $memberCart->id_address_invoice  = $cart->id_address_invoice;
        $memberCart->id_currency         = $cart->id_currency;
        $memberCart->id_lang             = $cart->id_lang;
        $memberCart->save();

        $savedGroups = $customer->isLogged() ? $customer->getGroups() : [];
        if ($customer->isLogged() && !in_array($memberGroup, $savedGroups, true)) {
            $customer->addGroups([$memberGroup]);
        }
        $totalMember = $memberCart->getOrderTotal(true, Cart::BOTH);

        if ($customer->isLogged()) {
            $customer->cleanGroups();              // ← fixed: was clearGroups()
            if ($savedGroups) {
                $customer->addGroups($savedGroups);
            }
        }

        $saving = $totalNormal - $totalMember;
        if ($saving <= 0.01) {
            return '';
        }

        $ordersToBreakEven = (int)ceil($feeProduct->getPrice() / $saving);

        $this->context->smarty->assign([
            'membership_price'    => Tools::displayPrice($feeProduct->getPrice()),
            'saving'              => Tools::displayPrice($saving),
            'orders_to_breakeven' => $ordersToBreakEven,
            'membership_link'     => $this->context->link->getProductLink($feeProduct),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/cartSavings.tpl');
    }

    /* ---------- cron hook & manual cron ---------- */

    public function hookActionCronJob($params)
    {
        $this->cronPruneExpired();
    }

    public function cronPruneExpired(): void
    {
        $idGroup = (int)Configuration::get('MP_GROUP_ID');
        $rows = Db::getInstance()->executeS(
            'SELECT id_customer FROM `'._DB_PREFIX_."memberprime` WHERE expiration < NOW()"
        );
        foreach ($rows as $row) {
            $idCust   = (int)$row['id_customer'];
            $customer = new Customer($idCust);
            if ($customer->id && in_array($idGroup, $customer->getGroups(), true)) {
                $customer->removeGroups([$idGroup]);
            }
            Db::getInstance()->delete('memberprime', 'id_customer='.(int)$idCust);
        }
    }
}
