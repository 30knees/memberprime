<?php
/**
 * Module Vip Card for Prestashop 1.6.x.x
 *
 * NOTICE OF LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction,
 * including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 *
 * forked from:
 * @author    Okom3pom <contact@okom3pom.com>
 * @copyright 2008-2018 Okom3pom
 * @version   1.0.10
 * @license   Free
 */

declare(strict_types=1);

if (!defined('_TB_VERSION_')) {
    exit;
}

class memberprime extends Module
{
    /** @var array default config keys */
    protected const CFG_KEYS = [
        'MP_PRODUCT_ID',   // id_product of the membership
        'MP_GROUP_ID',     // id_group to grant
        'MP_VALID_DAYS',   // int – membership length
        'MP_PAID_STATE_ID' // id_order_state that triggers assignment
    ];

    public function __construct()
    {
        $this->name        = 'memberprime';
        $this->tab         = 'pricing_promotion';
        $this->version     = '1.0.0';
        $this->author      = 'Your‑Name';
        $this->need_instance = 0;
        $this->bootstrap   = true;

        parent::__construct();

        $this->displayName = $this->l('Member Prime');
        $this->description = $this->l('Paid membership that grants special prices and shows a “save X €” banner in cart.');
    }

    /* ---------- Install / uninstall ---------- */

    public function install(): bool
    {
        return parent::install()
            && $this->installDb()
            && $this->registerHook([
                'actionValidateOrder',
                'displayShoppingCartFooter',
                'actionCronJob',           // CronJobs module (native) will call this
            ])
            && $this->setDefaults();
    }

    public function uninstall(): bool
    {
        return $this->uninstallDb()
            && parent::uninstall();
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
                  KEY `exp_idx` (`expiration`)         -- speeds up nightly prune
                ) ENGINE="._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';
        return Db::getInstance()->execute($sql);
    }

    private function uninstallDb(): bool
    {
        $sql = 'DROP TABLE IF EXISTS `'._DB_PREFIX_."memberprime`";
        return Db::getInstance()->execute($sql);
    }

    /* ---------- Back‑office configuration ---------- */

    public function getContent(): string
    {
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
        $helper             = new HelperForm();
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->token      = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->submit_action = 'submitMemberPrime';
        $helper->fields_value = $cfg;

        $helper->tpl_vars  = ['fields_value' => $cfg];
        $helper->fields_form = [[
            'form' => [
                'legend' => ['title' => $this->l('MemberPrime settings')],
                'input'  => [
                    ['type'=>'text','label'=>$this->l('Membership product ID'),'name'=>'MP_PRODUCT_ID','required'=>true],
                    ['type'=>'text','label'=>$this->l('Member group ID'),'name'=>'MP_GROUP_ID','required'=>true],
                    ['type'=>'text','label'=>$this->l('Validity (days)'),'name'=>'MP_VALID_DAYS','required'=>true],
                    ['type'=>'text','label'=>$this->l('Order state ID that grants membership'),'name'=>'MP_PAID_STATE_ID','required'=>true],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ]];

        return $helper->generateForm($helper->fields_form);
    }

    /* ---------- Hook: paid order gives membership ---------- */

    public function hookActionValidateOrder(array $params): void
    {
        $order         = new Order((int)$params['order']->id);
        $paidStateId   = (int)Configuration::get('MP_PAID_STATE_ID');
        $productId     = (int)Configuration::get('MP_PRODUCT_ID');
        $memberGroupId = (int)Configuration::get('MP_GROUP_ID');
        $validDays     = (int)Configuration::get('MP_VALID_DAYS');

        if ($order->current_state !== $paidStateId) {
            return;
        }

        // Did this order include the membership product?
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

        // add to group if not already
        if (!$customer->isMemberOfGroup($idGroup)) {
            $customer->addGroups([$idGroup]);
        }

        // calc exp date
        $expiration = (new DateTime())->add(new DateInterval("P{$days}D"))->format('Y-m-d H:i:s');

        Db::getInstance()->execute('REPLACE INTO `'._DB_PREFIX_."memberprime`
            (id_customer, expiration) VALUES ($idCustomer, '$expiration')");
    }

    /* ---------- Hook: cart banner ---------- */

    public function hookDisplayShoppingCartFooter(array $params): string
    {
        /* Load banner stylesheet once per request */
        $this->context->controller->registerStylesheet(
            'module-memberprime-banner',
            'modules/'.$this->name.'/assets/css/front.css',
            ['media' => 'all', 'priority' => 150]
        );
        $customer     = $this->context->customer;
        $memberGroup  = (int)Configuration::get('MP_GROUP_ID');
        $membershipId = (int)Configuration::get('MP_PRODUCT_ID');
        $feeProduct   = new Product($membershipId);

        // if already member or not configured, nothing to show
        if (!$membershipId || !$memberGroup || !$feeProduct->id || $customer->isLogged() && $customer->isMemberOfGroup($memberGroup)) {
            return '';
        }

        // clone cart
        $cartNormal = $this->context->cart;
        $totalNormal = $cartNormal->getOrderTotal(true, Cart::BOTH);

        // emulate member
        $originalGroups = $customer->getGroups();
        $customer->clearGroups();
        $customer->addGroups(array_merge($originalGroups, [$memberGroup]));
        $totalMember = $cartNormal->getOrderTotal(true, Cart::BOTH);

        // roll back groups
        $customer->clearGroups();
        $customer->addGroups($originalGroups);

        $saving = $totalNormal - $totalMember;
        if ($saving <= 0.01) {
            return '';
        }

        $ordersToBreakEven = (int)ceil($feeProduct->getPrice() / $saving);

        $this->context->smarty->assign([
            'membership_price'   => Tools::displayPrice($feeProduct->getPrice()),
            'saving'             => Tools::displayPrice($saving),
            'orders_to_breakeven'=> $ordersToBreakEven,
            'membership_link'    => $this->context->link->getProductLink($feeProduct),
        ]);
        return $this->display(__FILE__, 'views/templates/hook/cartSavings.tpl');
    }
/* ---------- Hook called by the native CronJobs module ---------- */
    public function hookActionCronJob(array $params): void
    {
        $this->cronPruneExpired();
    }
/* ---------- Hook: nightly prune (call via cron or CronJobs module) ---------- */

    public function cronPruneExpired(): void
    {
        $idGroup = (int)Configuration::get('MP_GROUP_ID');
        $rows = Db::getInstance()->executeS('SELECT id_customer FROM `'._DB_PREFIX_."memberprime`
                                             WHERE expiration < NOW()");
        foreach ($rows as $row) {
            $customer = new Customer((int)$row['id_customer']);
            if ($customer->id && $customer->isMemberOfGroup($idGroup)) {
                $customer->removeGroups([$idGroup]);
            }
            Db::getInstance()->delete('memberprime', 'id_customer='.(int)$row['id_customer']);
        }
    }
}
