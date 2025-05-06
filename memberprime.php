<?php
/*
 * Member Prime – paid membership that grants special prices
 * Compatible with thirty bees 1.4 / 1.5 (PHP 8.2) and PrestaShop 1.6
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
        // database table ps_log  →  visible in BO › Logs
        if (class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog('[MemberPrime] '.$msg, 1, false, null, 0, true);
        }

        // flat file  var/logs/memberprime.log  →  always available
        $dir = defined('_PS_LOG_DIR_') ? _PS_LOG_DIR_ : _PS_ROOT_DIR_.'/var/logs/';
        @file_put_contents($dir.'memberprime.log', date('[Y-m-d H:i:s] ').$msg."\n", FILE_APPEND);
    }

    /* ------------ basic info ------------ */
    public function __construct()
    {
        $this->name          = 'memberprime';
        $this->tab           = 'pricing_promotion';
        $this->version       = '1.0.3';
        $this->author        = '30bees';
        $this->bootstrap     = true;
        parent::__construct();

        $this->displayName = $this->l('Member Prime');
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
            && $this->registerHook('displayShoppingCart')    // Add this common hook
            && $this->registerHook('displayTop')             // Try more hooks
            && $this->registerHook('displayCartExtraProductActions')
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
                'legend'=>['title'=>$this->l('Member Prime settings')],
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

    /* ------------ header hook: enqueue CSS early and add JS fallback ------------ */
    public function hookDisplayHeader()
    {
        // Only load on cart and order pages
        if (!in_array($this->context->controller->php_self, ['cart', 'order'])) {
            return;
        }
        
        // Add CSS
        $css = $this->_path.'views/css/front.css';
        if (method_exists($this->context->controller, 'registerStylesheet')) {
            $this->context->controller->registerStylesheet('memberprime-banner', $css, ['media'=>'all']);
        } else {
            $this->context->controller->addCSS($css, 'all');
        }
        
        // Check if we should show the banner
        $cust = $this->context->customer;
        $grp = (int)Configuration::get('MP_GROUP_ID');
        
        // If customer is already a member, don't add the JS
        if ($cust->isLogged() && in_array($grp, $cust->getGroups(), true)) {
            return;
        }
        
        // Add JavaScript to inject the banner if hooks aren't working
        $cart = $this->context->cart;
        if (!$cart || !$cart->id) {
            return;
        }
        
        // Generate the banner content
        $banner = $this->renderSavingsBanner();
        
        if (!empty($banner)) {
            // Escape for JavaScript insertion
            $banner = addslashes(str_replace(["\r", "\n"], '', $banner));
            
            // Add JavaScript to inject the banner
            return '<script type="text/javascript">
                document.addEventListener("DOMContentLoaded", function() {
                    // Try to insert before the cart summary
                    var banner = \'' . $banner . '\';
                    
                    // Target common cart elements from various themes
                    var targets = [
                        "#cart_summary",
                        ".cart-overview",
                        ".shopping_cart",
                        ".cart-container",
                        "#order-detail-content",
                        ".delivery_options_address",
                        ".checkout-step",
                        "[id^=cart]",
                        ".order-confirmation"
                    ];
                    
                    // Try each target
                    var inserted = false;
                    for (var i = 0; i < targets.length; i++) {
                        var target = document.querySelector(targets[i]);
                        if (target) {
                            // Create a container
                            var container = document.createElement("div");
                            container.innerHTML = banner;
                            target.parentNode.insertBefore(container, target);
                            inserted = true;
                            break;
                        }
                    }
                    
                    // If all else fails, try appending to the body
                    if (!inserted) {
                        console.log("MemberPrime: No target found, appending to body");
                        var container = document.createElement("div");
                        container.innerHTML = banner;
                        container.style.margin = "20px 0";
                        var body = document.querySelector("body");
                        if (body) {
                            body.appendChild(container);
                        }
                    }
                });
            </script>';
        }
    }

    /* ------------ cart hooks ------------ */
    public function hookDisplayShoppingCartFooter() {
        $content = $this->renderSavingsBanner();
        $this->log("DisplayShoppingCartFooter hook returned: " . (empty($content) ? "EMPTY" : "CONTENT LENGTH: " . strlen($content)));
        return $content;
    }
    
    public function hookDisplayBeforeCarrier() {
        $content = $this->renderSavingsBanner();
        $this->log("DisplayBeforeCarrier hook returned: " . (empty($content) ? "EMPTY" : "CONTENT LENGTH: " . strlen($content)));
        return $content;
    }
    
    public function hookDisplayShoppingCart() {
        $content = $this->renderSavingsBanner();
        $this->log("DisplayShoppingCart hook returned: " . (empty($content) ? "EMPTY" : "CONTENT LENGTH: " . strlen($content)));
        return $content;
    }
    
    public function hookDisplayTop() {
        // Only show on cart and checkout pages
        if (!in_array($this->context->controller->php_self, ['cart', 'order'])) {
            return '';
        }
        
        $output = $this->renderSavingsBanner();
        $this->log("DisplayTop hook returned: " . (empty($output) ? "EMPTY" : "CONTENT LENGTH: " . strlen($output)));
        return $output;
    }
    
    public function hookDisplayCartExtraProductActions() {
        $output = $this->renderSavingsBanner();
        $this->log("DisplayCartExtraProductActions hook returned: " . (empty($output) ? "EMPTY" : "CONTENT LENGTH: " . strlen($output)));
        return $output;
    }

    /**
     * Calculate the price for a cart with specific customer group
     * This method handles thirty bees specific pricing calculations
     * PHP 8.2 compatible version that doesn't modify core classes
     * 
     * @param Cart $cart The cart to calculate
     * @param int $idGroup Customer group ID
     * @return float Total price
     */
    private function calculateCartTotalForGroup(Cart $cart, int $idGroup): float
    {
        if (!$cart->id) {
            return 0;
        }

        // Get cart products
        $products = $cart->getProducts();
        if (empty($products)) {
            return 0;
        }

        $total = 0;
        
        // Process each product in the cart
        foreach ($products as $product) {
            $productObj = new Product((int)$product['id_product']);
            $idProductAttribute = (int)$product['id_product_attribute'];
            $quantity = (int)$product['cart_quantity'];
            
            // Create specific price output variable for pass-by-reference
            $specificPriceOutput = null;
            
            // Get the base product price without any group reduction
            $basePrice = $productObj->getPrice(
                true, 
                $idProductAttribute, 
                6, // precision digits
                null, // shop ID (null = default)
                false, // use tax
                true, // use specific price
                $quantity,
                false, // force associated user
                null, // Customer ID
                null, // Cart ID
                null, // Address ID
                $specificPriceOutput, // Pass by reference parameter
                true, // with ecotax
                false, // DO NOT use group reduction for base price
                null, // context
                true // use customer price
            );
            
            // Calculate the member group price separately
            // Manually apply group reduction to simulate the member price
            $groupReduction = GroupReduction::getValueForProduct($productObj->id, $idGroup);
            $productPrice = $basePrice;
            
            if ($groupReduction > 0) {
                $productPrice = $basePrice * (1 - $groupReduction/100);
            }
            
            $this->log("Product ID: {$product['id_product']}, Base price: $basePrice, Group discount: $groupReduction%, Price for group $idGroup: $productPrice, Qty: $quantity");
            
            $total += $productPrice * $quantity;
        }
        
        // Add shipping and discounts
        $shipping = $cart->getPackageShippingCost();
        $total += $shipping;
        
        return $total;
    }

    private function renderSavingsBanner(): string
    {
        $this->log("Starting renderSavingsBanner()");
        
        $cust = $this->context->customer;
        $grp = (int)Configuration::get('MP_GROUP_ID');
        $prodId = (int)Configuration::get('MP_PRODUCT_ID');
        $feeProduct = new Product($prodId);

        // Log configuration values
        $this->log("Group ID: $grp, Product ID: $prodId, Product exists: " . ($feeProduct->id ? 'Yes' : 'No'));
        $this->log("Customer logged in: " . ($cust->isLogged() ? 'Yes' : 'No'));
        
        if ($cust->isLogged()) {
            $customerGroups = $cust->getGroups();
            $this->log("Customer in group: " . (in_array($grp, $customerGroups, true) ? 'Yes' : 'No'));
        }

        // Check if we should show the banner
        if (!$prodId || !$grp || !$feeProduct->id) {
            $this->log("Not showing banner - missing product or group configuration");
            return '';
        }
        
        // If customer is already a member, don't show the banner
        if ($cust->isLogged() && in_array($grp, $cust->getGroups(), true)) {
            $this->log("Not showing banner - customer is already a member");
            return '';
        }

        $cart = $this->context->cart;
        if (!$cart || !$cart->id) {
            $this->log("Not showing banner - no cart found");
            return '';
        }
        
        // Calculate normal price directly from the cart object
        $normalTotal = $cart->getOrderTotal(true, Cart::BOTH);
        $this->log("Normal total from cart: $normalTotal");
        
        // Calculate member price using our custom method
        $memberTotal = $this->calculateCartTotalForGroup($cart, $grp);
        $this->log("Member total from custom calculation: $memberTotal");

        $saving = $normalTotal - $memberTotal;
        $this->log(sprintf('normal=%.2f member=%.2f saving=%.2f', $normalTotal, $memberTotal, $saving));
        
        if ($saving <= 0.01) {
            $this->log("Not showing banner - no savings found");
            return '';
        }

        // Check if template file exists and is readable
        $templatePath = __DIR__ . '/views/templates/hook/cartSavings.tpl';
        if (!file_exists($templatePath) || !is_readable($templatePath)) {
            $this->log("ERROR: Template file not found or not readable: $templatePath");
            return '<div class="memberprime-banner panel">Template file not found!</div>';
        }
        
        // Debug template content
        $templateContent = file_get_contents($templatePath);
        $this->log("Template content length: " . strlen($templateContent));
        
        // Get the product link and fix any double slash issues
        $productLink = $this->context->link->getProductLink($feeProduct);
        // Fix double slash issue
        $productLink = str_replace('://', '://', str_replace('//', '/', $productLink));
        
        $this->context->smarty->assign([
            'membership_price' => Tools::displayPrice($feeProduct->getPrice()),
            'saving' => Tools::displayPrice($saving),
            'orders_to_breakeven' => (int)ceil($feeProduct->getPrice()/$saving),
            'membership_link' => $productLink,
        ]);
        
        $this->log("Rendering banner template");
        
        // Try direct template rendering as fallback
        if (method_exists($this->context->smarty, 'fetch')) {
            try {
                $output = $this->context->smarty->fetch($templatePath);
                $this->log("Direct template render length: " . strlen($output));
                if (!empty($output)) {
                    return $output;
                }
            } catch (Exception $e) {
                $this->log("Error fetching template directly: " . $e->getMessage());
            }
        }
        
        // Standard module display method
        $output = $this->display(__FILE__, 'views/templates/hook/cartSavings.tpl');
        $this->log("Standard template render length: " . strlen($output));
        return $output;
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
