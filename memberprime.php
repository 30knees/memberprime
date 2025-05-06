<?php
/*
 * Member Prime – paid membership that grants special prices
 * Compatible with thirty bees 1.4 / 1.5 (PHP 8.2) and PrestaShop 1.6
 * With temporary membership when product is in cart
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
        $this->version       = '1.0.7';
        $this->author        = '30bees';
        $this->bootstrap     = true;
        parent::__construct();

        $this->displayName = $this->l('Member Prime');
        $this->description = $this->l(
            'Sell a paid membership that puts customers in a special group and shows savings in the cart.'
        );
        
        // Check cart on every page load to update temporary membership status
        $this->updateTemporaryMembership();
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
            && $this->registerHook('actionCartSave')         // Cart update hook
            && $this->registerHook('actionAuthentication')   // Login hook
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

    /**
     * Hook into cart save to check for membership product
     */
    public function hookActionCartSave($params)
    {
        $this->updateTemporaryMembership();
    }

    /**
     * Hook into authentication to restore temporary membership after login
     */
    public function hookActionAuthentication($params)
    {
        $this->updateTemporaryMembership();
    }

    /**
     * Check if cart contains membership product and update temporary membership status
     * Then force a page reload to refresh prices
     */
    private function updateTemporaryMembership()
    {
        // Get configuration
        $membershipProductId = (int)Configuration::get('MP_PRODUCT_ID');
        $memberGroupId = (int)Configuration::get('MP_GROUP_ID');
        
        if (!$membershipProductId || !$memberGroupId) {
            return;
        }

        // Skip if customer isn't logged in
        if (!isset($this->context->customer) || !$this->context->customer->id) {
            return;
        }
        
        // Get customer and cart
        $customer = $this->context->customer;
        $cart = $this->context->cart;
        
        if (!$cart || !$cart->id) {
            return;
        }
        
        // Check if customer already has a real membership
        $hasMembership = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `'._DB_PREFIX_."memberprime` 
            WHERE id_customer = ".(int)$customer->id." 
            AND expiration > NOW()"
        );
        
        if ($hasMembership) {
            // Customer has a real membership, nothing to do
            $this->log("Customer {$customer->id} already has an active membership");
            return;
        }
        
        // Check if membership product is in cart
        $membershipInCart = false;
        $cartProducts = $cart->getProducts();
        
        foreach ($cartProducts as $product) {
            if ((int)$product['id_product'] === $membershipProductId) {
                $membershipInCart = true;
                break;
            }
        }
        
        // Customer's current groups
        $currentGroups = $customer->getGroups();
        $inMemberGroup = in_array($memberGroupId, $currentGroups, true);
        
        // Debug info
        $this->log("Membership in cart: " . ($membershipInCart ? 'Yes' : 'No'));
        $this->log("Customer in member group: " . ($inMemberGroup ? 'Yes' : 'No'));
        
        // Need to update customer's group membership
        if ($membershipInCart && !$inMemberGroup) {
            // Add customer to member group temporarily
            $this->log("Adding customer {$customer->id} to member group temporarily (product in cart)");
            
            // Add to group 
            $customer->addGroups([$memberGroupId]);
            
            // Force the context to update
            $this->context->customer = new Customer((int)$customer->id);
            
            // Save a cookie to track temporary membership
            $this->context->cookie->mp_temp_membership = true;
            $this->context->cookie->write();
            
            // Need to reload page to see the changes
            if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                // Check if we're just after adding membership to cart
                if (isset($_GET['add']) && (int)$_GET['add'] === $membershipProductId) {
                    // Redirect to the cart page to see member prices
                    if (!headers_sent()) {
                        Tools::redirect('index.php?controller=cart');
                        exit;
                    }
                } else {
                    // Reload the current page to update prices
                    if (!headers_sent()) {
                        Tools::redirect($_SERVER['REQUEST_URI']);
                        exit;
                    }
                }
            }
        } elseif (!$membershipInCart && $inMemberGroup && isset($this->context->cookie->mp_temp_membership)) {
            // Remove temporary membership
            $this->log("Removing customer {$customer->id} from member group (product removed from cart)");
            $customer->removeGroups([$memberGroupId]);
            
            // Remove cookie
            unset($this->context->cookie->mp_temp_membership);
            $this->context->cookie->write();
            
            // Force the context to update
            $this->context->customer = new Customer((int)$customer->id);
            
            // Need to reload page to see the changes
            if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                // We need to reload the current page to update prices
                if (!headers_sent() && isset($_GET['delete']) && (int)$_GET['delete'] === $membershipProductId) {
                    $currentUrl = $_SERVER['REQUEST_URI'];
                    Tools::redirect($currentUrl);
                    exit;
                }
            }
        }
    }

    /* ------------ order hook: grant membership ------------ */
    public function hookActionValidateOrder($params)
    {
        $order = new Order((int)$params['order']->id);
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
                
                // Remove temporary membership flag since they now have a real membership
                if (isset($this->context->cookie->mp_temp_membership)) {
                    unset($this->context->cookie->mp_temp_membership);
                    $this->context->cookie->write();
                }
                
                break;
            }
        }
    }

    private function grantMembership(int $idCustomer, int $idGroup, int $days): void
    {
        $cust = new Customer($idCustomer);
        if (!$cust->id) {return;}

        if (!in_array($idGroup, $cust->getGroups(), true)) {
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
    // Always register the CSS for cart and order pages  
    if (!in_array($this->context->controller->php_self, ['cart', 'order', 'product', 'category', 'search'])) {
        return;
    }
    
    $css = $this->_path.'views/css/front.css';
    if (method_exists($this->context->controller, 'registerStylesheet')) {
        $this->context->controller->registerStylesheet('memberprime-banner', $css, ['media'=>'all']);
    } else {
        $this->context->controller->addCSS($css, 'all');
    }
    
    // Check if we need to apply the member price discount
    $cust = $this->context->customer;
    $grp = (int)Configuration::get('MP_GROUP_ID');
    $prodId = (int)Configuration::get('MP_PRODUCT_ID');
    
    // Initialize output variable to collect all JavaScript
    $output = '';
    
    // Add JavaScript to refresh the page when the membership product is added to cart
    if ($prodId) {
        $output .= '<script type="text/javascript">
            document.addEventListener("DOMContentLoaded", function() {
                // Listen for add to cart button clicks
                var addToCartButtons = document.querySelectorAll(".ajax_add_to_cart_button, .add-to-cart, [name=Submit], #add_to_cart button");
                addToCartButtons.forEach(function(button) {
                    button.addEventListener("click", function() {
                        // Check if this is the membership product
                        var productId = this.getAttribute("data-id-product");
                        if (!productId) {
                            var form = this.closest("form");
                            if (form) {
                                var productInput = form.querySelector("[name=id_product]");
                                if (productInput) {
                                    productId = productInput.value;
                                }
                            }
                        }
                        
                        if (productId == ' . $prodId . ') {
                            // This is the membership product, handle special
                            setTimeout(function() {
                                // Reload the page after a short delay
                                window.location.reload();
                            }, 500);
                        }
                    });
                });
            });
        </script>';
    }
    
    // Check if we need to apply temporary member pricing
    $cart = $this->context->cart;
    if (!$cart || !$cart->id) {
		// If showing member prices, add this simple price override script
if ($showMemberPrices) {
    $memberDiscount = $this->getMemberDiscountRate();
    
    $output .= '<script type="text/javascript">
        // Simpler price override implementation 
        document.addEventListener("DOMContentLoaded", function() {
            console.log("MemberPrime: Applying member pricing");
            
            // Apply discount to all price elements
            function applyMemberDiscount() {
                console.log("MemberPrime: Searching for price elements");
                
                // Find all price elements using common selectors
                var priceSelectors = [
                    ".price", ".price-tag", ".product-price", 
                    ".our_price_display", "#our_price_display",
                    "[itemprop=\'price\']", ".content_price span", 
                    ".price_display", ".price_container",
                    ".cart_total_price", ".total_price", 
                    ".cart-prices-line .price",
                    ".cart_block_product_price", ".cart_unit .price",
                    ".cart-info .price", ".cart-prices-line .value"
                ];
                
                // Use a more comprehensive selector
                var allPriceElements = document.querySelectorAll(priceSelectors.join(","));
                console.log("MemberPrime: Found " + allPriceElements.length + " price elements");
                
                // Apply discount to each price element
                allPriceElements.forEach(function(element) {
                    // Skip if this element has already been processed
                    if (element.classList.contains("member-price-processed")) {
                        return;
                    }
                    
                    // Skip elements inside .memberprime-banner
                    if (element.closest(".memberprime-banner")) {
                        return;
                    }
                    
                    // Get the element\'s text content (the price)
                    var priceText = element.textContent.trim();
                    console.log("MemberPrime: Found price: " + priceText);
                    
                    // Extract the price value - handle different number formats
                    var price = parseFloat(priceText.replace(/[^0-9.,]/g, "")
                        .replace(",", "."));
                    
                    if (!isNaN(price) && price > 0) {
                        // Calculate the discounted price
                        var discountRate = ' . $memberDiscount . ';
                        var discountedPrice = price * (1 - discountRate / 100);
                        
                        // Find the currency symbol/format
                        // This assumes currency symbol is everything that\'s not a digit, dot or comma
                        var currencyFormat = priceText.match(/[^0-9.,]+/g);
                        var currencySymbol = currencyFormat ? currencyFormat.join("") : "";
                        
                        // Format the new price with the same currency symbol
                        var formattedDiscountedPrice = currencySymbol + discountedPrice.toFixed(2);
                        
                        // Save original content for reference
                        var originalPrice = priceText;
                        
                        // Create new elements for display
                        var container = document.createElement("span");
                        container.classList.add("member-price-container");
                        
                        var originalPriceEl = document.createElement("span");
                        originalPriceEl.classList.add("original-price");
                        originalPriceEl.style.textDecoration = "line-through";
                        originalPriceEl.style.color = "#999";
                        originalPriceEl.style.fontSize = "0.8em";
                        originalPriceEl.textContent = originalPrice;
                        
                        var discountedPriceEl = document.createElement("span");
                        discountedPriceEl.classList.add("member-price");
                        discountedPriceEl.style.color = "#c00";
                        discountedPriceEl.style.fontWeight = "bold";
                        discountedPriceEl.textContent = formattedDiscountedPrice;
                        
                        // Add to container
                        container.appendChild(originalPriceEl);
                        container.appendChild(document.createTextNode(" "));
                        container.appendChild(discountedPriceEl);
                        
                        // Replace the original content
                        element.innerHTML = "";
                        element.appendChild(container);
                        
                        // Mark as processed
                        element.classList.add("member-price-processed");
                        
                        console.log("MemberPrime: Replaced price: " + originalPrice + 
                            " with discounted price: " + formattedDiscountedPrice);
                    }
                });
                
                // Re-run every second to catch dynamically added content
                setTimeout(applyMemberDiscount, 1000);
            }
            
            // Start the process
            applyMemberDiscount();
        });
    </script>';
}
        return $output;
    }
    
    // Check if membership product is in the cart
    $membershipInCart = false;
    foreach ($cart->getProducts() as $product) {
        if ((int)$product['id_product'] === $prodId) {
            $membershipInCart = true;
            break;
        }
    }
    
    // Check if customer is already a permanent member
    $hasPermanentMembership = false;
    if ($cust->isLogged()) {
        $hasPermanentMembership = (bool)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . "memberprime` 
            WHERE id_customer = " . (int)$cust->id . " 
            AND expiration > NOW()"
        );
    }
    
    // If customer is a member or has membership in cart, we'll show member prices
    $showMemberPrices = $hasPermanentMembership || $membershipInCart;
    
    // Log status
    $this->log("Show member prices: " . ($showMemberPrices ? 'Yes' : 'No'));
    $this->log("Membership in cart: " . ($membershipInCart ? 'Yes' : 'No'));
    $this->log("Has permanent membership: " . ($hasPermanentMembership ? 'Yes' : 'No'));
    
    if ($showMemberPrices) {
        // Add JavaScript to override prices on the page
        $output .= '<script type="text/javascript">
            // Load member prices
            var memberGroupId = ' . $grp . ';
            var membershipProductId = ' . $prodId . ';
            
            document.addEventListener("DOMContentLoaded", function() {
                // Add a flag to indicate we\'re viewing member prices
                document.body.classList.add("viewing-member-prices");
                
                // Get all products on the page
                var products = document.querySelectorAll(".product-container, [data-id-product]");
                products.forEach(function(product) {
                    var productId = product.getAttribute("data-id-product");
                    if (!productId) {
                        var link = product.querySelector("a.product_img_link, a.product-name, a.product_name");
                        if (link) {
                            var href = link.getAttribute("href");
                            var match = href && href.match(/id_product=(\d+)/);
                            if (match) {
                                productId = match[1];
                            }
                        }
                    }
                    
                    // Skip the membership product itself
                    if (productId == membershipProductId) {
                        return;
                    }
                    
                    // Find the price elements
                    var priceElements = product.querySelectorAll(".price, .old_price, .content_price .price");
                    priceElements.forEach(function(priceElement) {
                        // Get the current price
                        var originalPrice = priceElement.textContent.trim();
                        // Parse the price (remove currency symbol, etc.)
                        var price = parseFloat(originalPrice.replace(/[^0-9.,]/g, "").replace(",", "."));
                        if (!isNaN(price)) {
                            // Apply a discount (for example, 10%)
                            var discount = ' . $this->getMemberDiscountRate() . ';
                            var discountedPrice = price * (1 - discount / 100);
                            // Format the price (simple approach)
                            var formattedPrice = originalPrice.replace(price.toString(), discountedPrice.toFixed(2));
                            // Update the price
                            priceElement.innerHTML = "<span class=\"original-price\" style=\"text-decoration: line-through; color: #999; font-size: 0.8em;\">" + originalPrice + "</span> <span class=\"member-price\" style=\"color: #c00;\">" + formattedPrice + "</span>";
                        }
                    });
                });
                
                // Handle product page
                if (typeof productPrice !== "undefined") {
                    // Product page global variable
                    var originalPrice = productPrice;
                    var discount = ' . $this->getMemberDiscountRate() . ';
                    var discountedPrice = originalPrice * (1 - discount / 100);
                    
                    // Override the productPrice variable
                    productPrice = discountedPrice;
                    
                    // Update displayed prices
                    var ourPriceElements = document.querySelectorAll("#our_price_display, .our_price_display");
                    ourPriceElements.forEach(function(el) {
                        var priceText = el.textContent.trim();
                        var price = parseFloat(priceText.replace(/[^0-9.,]/g, "").replace(",", "."));
                        if (!isNaN(price)) {
                            var discountedPrice = price * (1 - discount / 100);
                            var currencySymbol = priceText.replace(/[0-9.,]/g, "").trim();
                            el.innerHTML = "<span class=\"original-price\" style=\"text-decoration: line-through; color: #999; font-size: 0.8em;\">" + priceText + "</span> <span class=\"member-price\" style=\"color: #c00;\">" + currencySymbol + " " + discountedPrice.toFixed(2) + "</span>";
                        }
                    });
                }
            });
        </script>';
    }
    
    // Generate and add the banner if needed
    $banner = $this->renderSavingsBanner();
    if (!empty($banner)) {
        // Escape for JavaScript insertion
        $banner = addslashes(str_replace(["\r", "\n"], '', $banner));
        
        // Add JavaScript to inject the banner
        $output .= '<script type="text/javascript">
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
    
    return $output;
}

/**
 * Helper method to get the member discount rate
 * Either from global group settings or a default of 10%
 */
private function getMemberDiscountRate(): float
{
    $groupId = (int)Configuration::get('MP_GROUP_ID');
    if (!$groupId) {
        return 10.0; // Default 10% discount
    }
    
    // Try to get the group discount
    $group = new Group($groupId);
    if (Validate::isLoadedObject($group) && $group->reduction > 0) {
        return (float)$group->reduction;
    }
    
    // Default to 10% if no group discount is configured
    return 10.0;
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
            
            // Make sure the customer is in the member group if they should be
            if (isset($this->context->cookie->mp_temp_membership) && !in_array($grp, $customerGroups, true)) {
                $this->log("Re-adding customer to member group based on cookie");
                $cust->addGroups([$grp]);
                
                // Force the context to update
                $this->context->customer = new Customer((int)$cust->id);
            }
        }

        // Check if we should show the banner
        if (!$prodId || !$grp || !$feeProduct->id) {
            $this->log("Not showing banner - missing product or group configuration");
            return '';
        }
        
        // If customer is already a permanent member, don't show any banner
        $hasPermanentMembership = false;
        if ($cust->isLogged()) {
            $hasPermanentMembership = (bool)Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `'._DB_PREFIX_."memberprime` 
                WHERE id_customer = ".(int)$cust->id." 
                AND expiration > NOW()"
            );
        }
        
        if ($hasPermanentMembership) {
            $this->log("Not showing banner - customer is already a permanent member");
            return '';
        }

        $cart = $this->context->cart;
        if (!$cart || !$cart->id) {
            $this->log("Not showing banner - no cart found");
            return '';
        }
        
        // Check if membership product is already in cart
        $membershipInCart = false;
        foreach ($cart->getProducts() as $product) {
            if ((int)$product['id_product'] === $prodId) {
                $membershipInCart = true;
                break;
            }
        }

        // If membership is in cart, always show the member prices banner
        if ($membershipInCart) {
            $this->log("Showing member prices banner - membership product in cart");
            
            // Get the product link and fix any double slash issues
            $productLink = $this->context->link->getProductLink($feeProduct);
            $productLink = str_replace('://', '://', str_replace('//', '/', $productLink));
            
            $this->context->smarty->assign([
                'membership_price' => Tools::displayPrice($feeProduct->getPrice()),
                'membership_link' => $productLink,
            ]);
            
            // Check if the member prices template exists
            $templatePath = __DIR__ . '/views/templates/hook/memberPrices.tpl';
            if (!file_exists($templatePath)) {
                // Create the file if it doesn't exist
                $content = '{*
 * Banner shown in cart when membership product is in cart
 * $membership_price
 * $membership_link
 *}
<div class="memberprime-banner memberprime-active panel">
  <p>
    {l s=\'You are seeing Member prices! Complete your order to activate your membership for %s / year.\' sprintf=$membership_price mod=\'memberprime\'}
  </p>
</div>';
                file_put_contents($templatePath, $content);
            }
            
            // Render the member prices template
            return $this->display(__FILE__, 'views/templates/hook/memberPrices.tpl');
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
